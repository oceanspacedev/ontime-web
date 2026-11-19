<?php

namespace App\Exports;

use App\Enums\AttendanceStatus;
use App\Services\Attendance\LedgerQuery;
use App\Services\AttendanceLedger;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What the attendance report workbook shows, read once from the attendance ledger
 * ({@see AttendanceLedger::days()}) and kept as plain arrays: the workers with their days and totals for
 * the recap grid, and one row per attendance (or per absent or leave day) for the detail list.
 *
 * Nothing is computed here: presence, lateness, hours and overtime are the ledger's figures, the ones the
 * team recap and laporan absensi give.
 */
final class AttendanceReportData
{
    /**
     * A longer range shows only the days that have data, so a mistyped year cannot produce thousands of columns.
     */
    public const MAX_RANGE_DAYS = 366;

    private const TYPE_LABELS = ['employee' => 'Karyawan', 'intern' => 'Magang', 'freelancer' => 'Freelancer'];

    /** Totals of a worker, summed from the ledger's totals of their days. */
    private const TOTAL_KEYS = [
        'present_days', 'late_days', 'late_minutes', 'early_leave_minutes', 'overtime_minutes', 'worked_minutes',
        'leave_days', 'absent_days', 'off_days', 'missing_check_out_days', 'normal_minutes',
        'auto_overtime_minutes', 'request_overtime_minutes',
    ];

    /** Rows read per query for the photos, GPS and notes of the detail list. */
    private const CHUNK_SIZE = 1000;

    /** @var list<CarbonImmutable>|null */
    private ?array $dates = null;

    /** @var list<array<string, mixed>>|null */
    private ?array $workers = null;

    /** @var list<array<string, mixed>>|null */
    private ?array $details = null;

    public function __construct(private readonly ?LedgerQuery $query) {}

    /**
     * The report of the workers of the given attendance rows, for the selected range or, without one, the
     * dates of the rows.
     *
     * @param  Collection<int, mixed>  $records
     */
    public static function fromRecords(Collection $records, ?CarbonInterface $from = null, ?CarbonInterface $to = null): self
    {
        $recordDates = $records->pluck('date')->filter();
        $from ??= $recordDates->min();
        $to ??= $recordDates->max();

        if (! $from || ! $to) {
            return new self(null);
        }

        $from = CarbonImmutable::parse($from)->startOfDay();
        $to = CarbonImmutable::parse($to)->startOfDay();

        if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            if ($recordDates->isEmpty()) {
                return new self(null);
            }

            $from = $from->max(CarbonImmutable::parse($recordDates->min())->startOfDay());
            $to = $to->min(CarbonImmutable::parse($recordDates->max())->startOfDay());
        }

        if ($from->greaterThan($to)) {
            return new self(null);
        }

        $keys = [];

        foreach ($records as $record) {
            foreach (array_keys(self::TYPE_LABELS) as $type) {
                if ($record->{"{$type}_id"} !== null) {
                    $keys[] = $type.':'.(int) $record->{"{$type}_id"};
                }
            }
        }

        return new self(LedgerQuery::forWorkers($keys, $from, $to));
    }

    /**
     * Every day of the range; of a range longer than a year only the days from the first to the last one with data.
     *
     * @return list<CarbonImmutable>
     */
    public function dates(): array
    {
        $this->build();

        return $this->dates;
    }

    /**
     * One entry per worker with a planned or recorded day, sorted by company, division and name.
     *
     * @return list<array{
     *     name: string,
     *     number: string,
     *     type: string,
     *     company: string,
     *     division: string,
     *     present_days: int,
     *     late_days: int,
     *     late_minutes: int,
     *     early_leave_minutes: int,
     *     overtime_minutes: int,
     *     worked_minutes: int,
     *     leave_days: int,
     *     absent_days: int,
     *     off_days: int,
     *     missing_check_out_days: int,
     *     normal_minutes: int,
     *     auto_overtime_minutes: int,
     *     request_overtime_minutes: int,
     *     days: array<string, array{check_in: string, check_out: string|null, worked_minutes: int|null, late_minutes: int, is_present: bool}>,
     * }>
     */
    public function workers(): array
    {
        $this->build();

        return $this->workers;
    }

    /**
     * One row per attendance, and one per absent or leave day, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function details(): array
    {
        $this->build();

        return $this->details;
    }

    private function build(): void
    {
        if ($this->workers !== null) {
            return;
        }

        $this->workers = [];
        $this->details = [];
        $this->dates = [];

        if ($this->query === null) {
            return;
        }

        $workers = [];
        $details = [];
        $firstDate = null;
        $lastDate = null;

        foreach (app(AttendanceLedger::class)->days($this->query) as $day) {
            $key = $day['worker']['type'].':'.$day['worker']['id'];
            $identity = [
                'name' => (string) $day['worker']['name'],
                'number' => filled($day['worker']['number']) ? (string) $day['worker']['number'] : '-',
                'type' => self::TYPE_LABELS[$day['worker']['type']],
                'company' => $day['worker']['company'] ?? '-',
                'division' => $day['worker']['division'] ?? '-',
            ];

            $workers[$key] ??= $identity + array_fill_keys(self::TOTAL_KEYS, 0) + ['days' => []];

            foreach (self::TOTAL_KEYS as $total) {
                $workers[$key][$total] += $day['totals'][$total];
            }

            if ($cell = $this->cell($day)) {
                $workers[$key]['days'][$day['date']] = $cell;
            }

            $firstDate = min($firstDate ?? $day['date'], $day['date']);
            $lastDate = max($lastDate ?? $day['date'], $day['date']);

            array_push($details, ...$this->detailRows($day, $identity));
        }

        $workers = array_values($workers);
        usort($workers, fn (array $a, array $b): int => [$a['company'], $a['division'], $a['name']] <=> [$b['company'], $b['division'], $b['name']]);
        usort($details, fn (array $a, array $b): int => [$b['date'], $a['name'], $a['check_in']] <=> [$a['date'], $b['name'], $b['check_in']]);

        $this->workers = $workers;
        $this->details = $this->withRecordColumns($details);

        $from = $this->query->from;
        $to = $this->query->to;

        if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            if ($firstDate === null) {
                return;
            }

            $from = CarbonImmutable::parse($firstDate);
            $to = CarbonImmutable::parse($lastDate);
        }

        for ($date = $from; $date->lte($to); $date = $date->addDay()) {
            $this->dates[] = $date;
        }
    }

    /**
     * One worker's day in the grid: the check-ins and check-outs of the sessions worked (one per line),
     * or, for a day without work, what the day is: leave, a rejected attendance, absent or a day off.
     * Null when there is nothing to show (a day still to come, a day not scheduled).
     *
     * @param  array<string, mixed>  $day
     * @return array{check_in: string, check_out: string|null, worked_minutes: int|null, late_minutes: int, is_present: bool}|null
     */
    private function cell(array $day): ?array
    {
        $worked = array_values(array_filter($day['lines'], fn (array $line): bool => $line['is_worked']));

        if ($worked === []) {
            $label = match (true) {
                $day['status'] === 'leave' => AttendanceStatus::Leave->getLabel(),
                // A rejected attendance says why the day is not worked (it still counts as absent when expected).
                array_filter($day['lines'], fn (array $line): bool => $line['status'] === 'rejected') !== [] => AttendanceStatus::Rejected->getLabel(),
                $day['status'] === 'absent' => AttendanceStatus::Absent->getLabel(),
                $day['status'] === 'off' => AttendanceStatus::Off->getLabel(),
                default => null,
            };

            return $label === null ? null : ['check_in' => $label, 'check_out' => null, 'worked_minutes' => null, 'late_minutes' => 0, 'is_present' => false];
        }

        usort($worked, fn (array $a, array $b): int => (string) $a['check_in'] <=> (string) $b['check_in']);
        $closed = array_filter($worked, fn (array $line): bool => $line['check_out'] !== null);

        return [
            'check_in' => implode("\n", array_map(fn (array $line): string => self::time($line['check_in'], 5) ?? '-', $worked)),
            'check_out' => implode("\n", array_map(fn (array $line): string => self::time($line['check_out'], 5) ?? '-', $worked)),
            'worked_minutes' => $closed === [] ? null : array_sum(array_column($closed, 'worked_minutes')),
            'late_minutes' => $day['totals']['late_minutes'],
            'is_present' => true,
        ];
    }

    /**
     * The detail rows of a worker-day: its attendance rows, else one row saying the day was absent or on leave.
     *
     * @param  array<string, mixed>  $day
     * @param  array{name: string, number: string, type: string, company: string, division: string}  $identity
     * @return list<array<string, mixed>>
     */
    private function detailRows(array $day, array $identity): array
    {
        $rows = [];

        foreach ($day['lines'] as $line) {
            if ($line['attendance_id'] === null) {
                continue;
            }

            $rows[] = $identity + [
                'attendance_id' => $line['attendance_id'],
                'date' => $day['date'],
                'check_in' => $line['check_in'],
                'check_out' => $line['check_out'],
                'worked_minutes' => $line['is_worked'] && $line['check_out'] !== null ? $line['worked_minutes'] : null,
                'status' => $line['status'],
                'late_minutes' => $line['late_minutes'],
                'early_leave_minutes' => $line['early_leave_minutes'],
                'shift' => $line['shift']['name'] ?? null,
                'outlet' => $line['outlet']['name'] ?? null,
                'schedule' => $line['scheduled_start'] !== null ? self::time($line['scheduled_start'], 5).' - '.self::time($line['scheduled_end'], 5) : null,
                'auto_overtime_minutes' => $line['auto_overtime_minutes'],
                'is_corrected' => $line['is_corrected'],
            ];
        }

        if ($rows === [] && in_array($day['status'], ['absent', 'leave'], true)) {
            $line = $day['lines'][0] ?? [];

            $rows[] = $identity + [
                'attendance_id' => null,
                'date' => $day['date'],
                'check_in' => null,
                'check_out' => null,
                'worked_minutes' => null,
                'status' => $day['status'],
                'late_minutes' => 0,
                'early_leave_minutes' => 0,
                'shift' => $line['shift']['name'] ?? null,
                'outlet' => $line['outlet']['name'] ?? null,
                'schedule' => null,
                'auto_overtime_minutes' => 0,
                'is_corrected' => false,
            ];
        }

        return $rows;
    }

    /**
     * Add what only the stored attendance row has: its status, photos, GPS and notes.
     *
     * @param  list<array<string, mixed>>  $details
     * @return list<array<string, mixed>>
     */
    private function withRecordColumns(array $details): array
    {
        $records = [];

        foreach (array_chunk(array_values(array_filter(array_column($details, 'attendance_id'))), self::CHUNK_SIZE) as $ids) {
            $rows = DB::table('attendances')->whereIn('id', $ids)
                ->get(['id', 'status', 'check_in_photo', 'check_out_photo', 'check_in_lat', 'check_in_lng', 'check_out_lat', 'check_out_lng', 'notes']);

            foreach ($rows as $row) {
                $records[(int) $row->id] = $row;
            }
        }

        foreach ($details as $index => $detail) {
            $record = $detail['attendance_id'] !== null ? ($records[$detail['attendance_id']] ?? null) : null;
            $status = AttendanceStatus::tryFrom((string) ($record->status ?? $detail['status']));

            $details[$index] += [
                'status_label' => $status?->getLabel() ?? match ((string) ($record->status ?? $detail['status'])) {
                    'present' => 'Tepat Waktu / Hadir',
                    'pending' => AttendanceStatus::PendingApproval->getLabel(),
                    default => (string) ($record->status ?? '-'),
                },
                'check_in_photo' => $record->check_in_photo ?? null,
                'check_out_photo' => $record->check_out_photo ?? null,
                'check_in_lat' => $record->check_in_lat ?? null,
                'check_in_lng' => $record->check_in_lng ?? null,
                'check_out_lat' => $record->check_out_lat ?? null,
                'check_out_lng' => $record->check_out_lng ?? null,
                'notes' => $record->notes ?? null,
            ];
        }

        return $details;
    }

    /**
     * The time of a "Y-m-d H:i:s" moment: 5 characters for hours and minutes, 8 with seconds.
     */
    public static function time(?string $moment, int $length): ?string
    {
        return filled($moment) ? substr((string) $moment, 11, $length) : null;
    }
}
