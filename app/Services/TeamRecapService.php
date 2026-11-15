<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\CompanyLocation;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * A month of the team in one line per person per outlet: days scheduled, worked, late, absent and off,
 * with scheduled and worked hours. Covers the workers in the user's data access.
 */
final class TeamRecapService
{
    private const TYPE_LABELS = ['employee' => 'Karyawan', 'intern' => 'Magang', 'freelancer' => 'Freelancer'];

    /** Rows read per query, so a month of a large Klien is streamed instead of loaded at once. */
    private const CHUNK_SIZE = 2000;

    /** @var array<string, array{model: class-string<Model>, number: string}> */
    private const WORKER_TYPES = [
        'employee' => ['model' => Employee::class, 'number' => 'nip'],
        'intern' => ['model' => Intern::class, 'number' => 'nis'],
        'freelancer' => ['model' => Freelancer::class, 'number' => 'freelancer_number'],
    ];

    /**
     * @return list<array{
     *     worker: array{type: string, id: int, number: string, name: string, company: string|null, division: string|null},
     *     outlet: string|null,
     *     scheduled_days: int,
     *     present_days: int,
     *     late_days: int,
     *     late_minutes: int,
     *     absent_days: int,
     *     off_days: int,
     *     scheduled_hours: float,
     *     worked_hours: float,
     * }>
     */
    public function recap(User $user, CarbonInterface $month): array
    {
        $start = Carbon::parse($month)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $today = today()->toDateString();
        // A half-open range: the last day still matches where dates are stored with a time (SQLite).
        $from = $start->toDateString();
        $until = $end->copy()->addDay()->toDateString();

        /**
         * One entry per worker per day, built from plain rows so a month of a large Klien never holds models.
         *
         * @var array<string, array{worker: string, date: string, schedule?: array{shift_id: int|null, location_id: int|null, is_day_off: bool}, attendance?: array{checked_in: bool, location_id: int|null, late_minutes: int, worked_minutes: int}}> $days
         */
        $days = [];

        ShiftSchedule::query()
            ->visibleTo($user)
            ->where('date', '>=', $from)
            ->where('date', '<', $until)
            ->select(['id', 'date', 'employee_id', 'intern_id', 'freelancer_id', 'shift_id', 'company_location_id', 'is_day_off'])
            ->toBase()
            ->lazyById(self::CHUNK_SIZE, 'shift_schedules.id', 'id')
            ->each(function (object $schedule) use (&$days): void {
                if (! $worker = $this->workerKey($schedule)) {
                    return;
                }

                $date = substr((string) $schedule->date, 0, 10);
                $days["{$worker}:{$date}"]['worker'] = $worker;
                $days["{$worker}:{$date}"]['date'] = $date;
                $days["{$worker}:{$date}"]['schedule'] = [
                    'shift_id' => $schedule->shift_id !== null ? (int) $schedule->shift_id : null,
                    'location_id' => $schedule->company_location_id !== null ? (int) $schedule->company_location_id : null,
                    'is_day_off' => (bool) $schedule->is_day_off,
                ];
            });

        Attendance::query()
            ->visibleTo($user)
            ->where('date', '>=', $from)
            ->where('date', '<', $until)
            ->select(['id', 'date', 'employee_id', 'intern_id', 'freelancer_id', 'company_location_id', 'check_in', 'check_out', 'late_minutes'])
            ->toBase()
            ->lazyById(self::CHUNK_SIZE, 'attendances.id', 'id')
            ->each(function (object $attendance) use (&$days): void {
                if (! $worker = $this->workerKey($attendance)) {
                    return;
                }

                $date = substr((string) $attendance->date, 0, 10);
                $key = "{$worker}:{$date}";
                $days[$key]['worker'] = $worker;
                $days[$key]['date'] = $date;

                // The first segment decides presence and the outlet; every segment adds its lateness and work time.
                $days[$key]['attendance'] ??= [
                    'checked_in' => filled($attendance->check_in),
                    'location_id' => $attendance->company_location_id !== null ? (int) $attendance->company_location_id : null,
                    'late_minutes' => 0,
                    'worked_minutes' => 0,
                ];
                $days[$key]['attendance']['late_minutes'] += (int) $attendance->late_minutes;
                $days[$key]['attendance']['worked_minutes'] += $this->workedMinutes($attendance->check_in, $attendance->check_out);
            });

        $shiftMinutes = $this->shiftMinutes(array_column(array_column($days, 'schedule'), 'shift_id'));
        $outletNames = $this->outletNames([
            ...array_column(array_column($days, 'schedule'), 'location_id'),
            ...array_column(array_column($days, 'attendance'), 'location_id'),
        ]);
        $workers = $this->workers(array_unique(array_column($days, 'worker')));

        $rows = [];

        foreach ($days as $day) {
            if (! $worker = $workers[$day['worker']] ?? null) {
                continue;
            }

            $schedule = $day['schedule'] ?? null;
            $attendance = $day['attendance'] ?? null;
            $outletId = collect([$schedule['location_id'] ?? null, $attendance['location_id'] ?? null])
                ->first(fn (?int $id): bool => $id !== null && isset($outletNames[$id]));
            $rowKey = $day['worker'].':'.($outletId ?? '');

            $rows[$rowKey] ??= [
                'worker' => $worker,
                'outlet' => $outletId !== null ? $outletNames[$outletId] : null,
                'scheduled_days' => 0,
                'present_days' => 0,
                'late_days' => 0,
                'late_minutes' => 0,
                'absent_days' => 0,
                'off_days' => 0,
                'scheduled_minutes' => 0,
                'worked_minutes' => 0,
            ];
            $row = &$rows[$rowKey];

            if ($schedule['is_day_off'] ?? false) {
                $row['off_days']++;
            } elseif ($schedule) {
                $row['scheduled_days']++;
                $row['scheduled_minutes'] += $schedule['shift_id'] !== null ? ($shiftMinutes[$schedule['shift_id']] ?? 0) : 0;
            }

            if ($attendance['checked_in'] ?? false) {
                $row['present_days']++;

                // A split shift counts as one late day however many segments were late.
                if ($attendance['late_minutes'] > 0) {
                    $row['late_days']++;
                    $row['late_minutes'] += $attendance['late_minutes'];
                }

                $row['worked_minutes'] += $attendance['worked_minutes'];
            } elseif ($schedule && ! $schedule['is_day_off'] && $day['date'] < $today) {
                $row['absent_days']++;
            }

            unset($row);
        }

        return collect($rows)
            ->map(function (array $row): array {
                $row['scheduled_hours'] = round($row['scheduled_minutes'] / 60, 2);
                $row['worked_hours'] = round($row['worked_minutes'] / 60, 2);
                unset($row['scheduled_minutes'], $row['worked_minutes']);

                return $row;
            })
            ->sortBy(fn (array $row): string => ($row['outlet'] ?? "\u{FFFF}").'|'.$row['worker']['name'])
            ->values()
            ->all();
    }

    /**
     * Write the recap to a temporary .xlsx file and return its path.
     */
    public function export(User $user, CarbonInterface $month): string
    {
        $rows = $this->recap($user, $month);
        $path = tempnam(sys_get_temp_dir(), 'rekap').'.xlsx';
        $bold = (new Style)->setFontBold();

        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Rekap '.Carbon::parse($month)->locale('id')->translatedFormat('M Y'));
        $sheet->setColumnWidth(24, 1, 2, 3);
        $sheet->setColumnWidth(16, 4);
        $sheet->setColumnWidth(26, 5);
        $sheet->setColumnWidth(12, 6);
        $sheet->setColumnWidthForRange(13, 7, 14);

        $writer->addRow(Row::fromValues([
            'Badan Usaha', 'Divisi / Brand', 'Outlet', 'ID Pekerja', 'Nama', 'Jenis',
            'Hari Terjadwal', 'Hari Masuk', 'Tidak Hadir', 'Libur', 'Terlambat (kali)', 'Total Telat (menit)',
            'Jam Terjadwal', 'Jam Kerja',
        ], $bold));

        $totals = array_fill_keys(['scheduled_days', 'present_days', 'absent_days', 'off_days', 'late_days', 'late_minutes', 'scheduled_hours', 'worked_hours'], 0);

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues([
                $row['worker']['company'] ?? '-',
                $row['worker']['division'] ?? '-',
                $row['outlet'] ?? '-',
                $row['worker']['number'],
                $row['worker']['name'],
                self::TYPE_LABELS[$row['worker']['type']],
                $row['scheduled_days'],
                $row['present_days'],
                $row['absent_days'],
                $row['off_days'],
                $row['late_days'],
                $row['late_minutes'],
                $row['scheduled_hours'],
                $row['worked_hours'],
            ]));

            foreach (array_keys($totals) as $key) {
                $totals[$key] += $row[$key];
            }
        }

        $writer->addRow(Row::fromValues([
            'Total', '', '', '', count($rows).' baris', '',
            $totals['scheduled_days'], $totals['present_days'], $totals['absent_days'], $totals['off_days'],
            $totals['late_days'], $totals['late_minutes'], round($totals['scheduled_hours'], 2), round($totals['worked_hours'], 2),
        ], $bold));

        $writer->close();

        return $path;
    }

    /**
     * This month and the eleven before it, newest first: "2026-11" => "November 2026".
     *
     * @return array<string, string>
     */
    public static function monthOptions(): array
    {
        return collect(range(0, 11))
            ->mapWithKeys(fn (int $back): array => [
                today()->startOfMonth()->subMonths($back)->format('Y-m') => today()->startOfMonth()->subMonths($back)->locale('id')->translatedFormat('F Y'),
            ])
            ->all();
    }

    public function fileName(CarbonInterface $month): string
    {
        return 'rekap-tim-'.Carbon::parse($month)->format('Y-m').'.xlsx';
    }

    /**
     * "employee:12" for the worker a schedule or attendance row belongs to.
     */
    private function workerKey(object $record): ?string
    {
        foreach (array_keys(self::WORKER_TYPES) as $type) {
            if ($record->{"{$type}_id"} !== null) {
                return $type.':'.(int) $record->{"{$type}_id"};
            }
        }

        return null;
    }

    private function workedMinutes(?string $checkIn, ?string $checkOut): int
    {
        if (blank($checkIn) || blank($checkOut)) {
            return 0;
        }

        return (int) max(0, Carbon::parse($checkIn)->diffInMinutes(Carbon::parse($checkOut)));
    }

    /**
     * Scheduled minutes per shift id.
     *
     * @param  array<int, int|null>  $shiftIds
     * @return array<int, int>
     */
    private function shiftMinutes(array $shiftIds): array
    {
        $shiftIds = array_values(array_unique(array_filter($shiftIds, fn (?int $id): bool => $id !== null)));

        if ($shiftIds === []) {
            return [];
        }

        return Shift::query()
            ->whereIn('id', $shiftIds)
            ->get()
            ->mapWithKeys(fn (Shift $shift): array => [(int) $shift->id => $shift->scheduledMinutes()])
            ->all();
    }

    /**
     * Outlet names per location id.
     *
     * @param  array<int, int|null>  $locationIds
     * @return array<int, string>
     */
    private function outletNames(array $locationIds): array
    {
        $locationIds = array_values(array_unique(array_filter($locationIds, fn (?int $id): bool => $id !== null)));

        if ($locationIds === []) {
            return [];
        }

        return CompanyLocation::query()
            ->whereIn('id', $locationIds)
            ->pluck('name', 'id')
            ->mapWithKeys(fn (?string $name, int|string $id): array => [(int) $id => (string) $name])
            ->all();
    }

    /**
     * Worker details per worker key, with only the columns the recap shows.
     *
     * @param  array<int, string>  $workerKeys
     * @return array<string, array{type: string, id: int, number: string, name: string, company: string|null, division: string|null}>
     */
    private function workers(array $workerKeys): array
    {
        $idsByType = [];

        foreach ($workerKeys as $workerKey) {
            [$type, $id] = explode(':', $workerKey);
            $idsByType[$type][] = (int) $id;
        }

        $workers = [];

        foreach ($idsByType as $type => $ids) {
            $definition = self::WORKER_TYPES[$type];

            foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
                $definition['model']::query()
                    ->whereIn('id', $chunk)
                    ->with(['company:id,name', 'division:id,name'])
                    ->get(['id', 'company_id', 'division_id', 'full_name', $definition['number']])
                    ->each(function (Model $worker) use ($type, $definition, &$workers): void {
                        $workers["{$type}:{$worker->getKey()}"] = [
                            'type' => $type,
                            'id' => (int) $worker->getKey(),
                            'number' => (string) $worker->{$definition['number']},
                            'name' => (string) $worker->full_name,
                            'company' => $worker->company?->name,
                            'division' => $worker->division?->name,
                        ];
                    });
            }
        }

        return $workers;
    }
}
