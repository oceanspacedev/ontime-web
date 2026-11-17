<?php

namespace App\Exports;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\OvertimeRequest;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The report as a grid: one row per worker and one group of columns (check-in, check-out, hours) per date,
 * with the worker's totals for the period right after their identity.
 */
class AttendanceRecapSheet implements FromArray, WithColumnWidths, WithEvents, WithStrictNullComparison, WithTitle
{
    private const WORKER_HEADINGS = ['Nama', 'NIP / ID', 'Tipe', 'Badan Usaha', 'Divisi'];

    private const TOTAL_HEADINGS = ['Hari Masuk', 'Terlambat (kali)', 'Telat (menit)', 'Pulang Cepat (menit)', 'Lembur (jam)', 'Jumlah Jam'];

    private const DAY_HEADINGS = ['Masuk', 'Pulang', 'Jam'];

    private const HEADING_ROWS = 2;

    private const HOURS_FORMAT = '0.00';

    private const LATE_NOTE = 'Keterangan: jam masuk berwarna kuning = terlambat. Jam dalam desimal (7,50 = 7 jam 30 menit).';

    /**
     * A longer range shows only the days that have data, so a mistyped year cannot produce thousands of columns.
     */
    private const MAX_RANGE_DAYS = 366;

    /** @var list<Carbon>|null */
    private ?array $dates = null;

    /** @var list<array<string, mixed>>|null */
    private ?array $workers = null;

    public function __construct(
        protected Collection $records,
        protected ?CarbonInterface $from = null,
        protected ?CarbonInterface $to = null,
    ) {}

    public function title(): string
    {
        return 'Rekap';
    }

    /**
     * @return list<list<string|int|float|null>>
     */
    public function array(): array
    {
        $dates = $this->dates();

        $groupHeadings = [...self::WORKER_HEADINGS, 'Total', ...array_fill(0, count(self::TOTAL_HEADINGS) - 1, null)];
        $columnHeadings = [...array_fill(0, count(self::WORKER_HEADINGS), null), ...self::TOTAL_HEADINGS];

        foreach ($dates as $date) {
            array_push($groupHeadings, $date->locale('id')->translatedFormat('D, d M Y'), ...array_fill(0, count(self::DAY_HEADINGS) - 1, null));
            array_push($columnHeadings, ...self::DAY_HEADINGS);
        }

        $rows = [$groupHeadings, $columnHeadings];

        foreach ($this->workers() as $worker) {
            $row = [
                $worker['name'],
                $worker['number'],
                $worker['type'],
                $worker['company'],
                $worker['division'],
                $worker['present_days'],
                $worker['late_days'],
                $worker['late_minutes'],
                $worker['early_leave_minutes'],
                $worker['overtime_minutes'] / 60,
                $worker['worked_minutes'] / 60,
            ];

            foreach ($dates as $date) {
                $day = $worker['days'][$date->toDateString()] ?? null;

                array_push(
                    $row,
                    $day['check_in'] ?? null,
                    $day['check_out'] ?? null,
                    isset($day['worked_minutes']) ? $day['worked_minutes'] / 60 : null,
                );
            }

            $rows[] = $row;
        }

        return [...$rows, [null], [self::LATE_NOTE]];
    }

    /**
     * @return array<string, int>
     */
    public function columnWidths(): array
    {
        $widths = ['A' => 28, 'B' => 16, 'C' => 12, 'D' => 24, 'E' => 20];

        foreach (array_keys(self::TOTAL_HEADINGS) as $offset) {
            $widths[$this->columnLetter(count(self::WORKER_HEADINGS) + 1 + $offset)] = 10;
        }

        foreach (array_keys($this->dates()) as $dateIndex) {
            foreach (array_keys(self::DAY_HEADINGS) as $offset) {
                $widths[$this->dayColumn($dateIndex, $offset)] = 8;
            }
        }

        return $widths;
    }

    /**
     * @return array<class-string, callable>
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => fn (AfterSheet $event) => $this->format($event->sheet->getDelegate()),
        ];
    }

    /**
     * Merge the grouped headings, freeze the names and headings, and mark late and non-working days.
     */
    private function format(Worksheet $sheet): void
    {
        $dates = $this->dates();
        $workers = $this->workers();
        $firstRow = self::HEADING_ROWS + 1;
        $lastRow = self::HEADING_ROWS + count($workers);
        $firstTotalColumn = $this->columnLetter(count(self::WORKER_HEADINGS) + 1);
        $lastTotalColumn = $this->columnLetter(count(self::WORKER_HEADINGS) + count(self::TOTAL_HEADINGS));
        $lastColumn = $dates === [] ? $lastTotalColumn : $this->dayColumn(count($dates) - 1, count(self::DAY_HEADINGS) - 1);

        foreach (array_keys(self::WORKER_HEADINGS) as $index) {
            $column = $this->columnLetter($index + 1);
            $sheet->mergeCells("{$column}1:{$column}2");
        }

        $sheet->mergeCells("{$firstTotalColumn}1:{$lastTotalColumn}1");

        $sheet->getStyle("A1:{$lastColumn}2")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F2937']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);

        foreach ($dates as $dateIndex => $date) {
            $first = $this->dayColumn($dateIndex);
            $last = $this->dayColumn($dateIndex, count(self::DAY_HEADINGS) - 1);
            $sheet->mergeCells("{$first}1:{$last}1");

            if ($date->isSunday()) {
                $sheet->getStyle("{$first}1:{$last}2")->getFill()->getStartColor()->setRGB('B91C1C');
            }

            if ($lastRow >= $firstRow) {
                $sheet->getStyle("{$last}{$firstRow}:{$last}{$lastRow}")->getNumberFormat()->setFormatCode(self::HOURS_FORMAT);
            }
        }

        $sheet->getRowDimension(1)->setRowHeight(24);
        $sheet->getRowDimension(2)->setRowHeight(32);
        $sheet->freezePane("B{$firstRow}");

        $sheet->getStyle("A1:{$lastColumn}".max($lastRow, self::HEADING_ROWS))
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D1D5DB');

        if ($lastRow < $firstRow) {
            return;
        }

        $sheet->getStyle("A{$firstRow}:{$lastColumn}{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getStyle("C{$firstRow}:C{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("{$firstTotalColumn}{$firstRow}:{$lastColumn}{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("{$lastTotalColumn}{$firstRow}:{$lastTotalColumn}{$lastRow}")->getNumberFormat()->setFormatCode(self::HOURS_FORMAT);
        $sheet->getStyle("{$lastTotalColumn}{$firstRow}:{$lastTotalColumn}{$lastRow}")->getFont()->setBold(true);
        $overtimeColumn = $this->columnLetter(count(self::WORKER_HEADINGS) + 1 + array_search('Lembur (jam)', self::TOTAL_HEADINGS, true));
        $sheet->getStyle("{$overtimeColumn}{$firstRow}:{$overtimeColumn}{$lastRow}")->getNumberFormat()->setFormatCode(self::HOURS_FORMAT);

        foreach ($workers as $index => $worker) {
            $row = $firstRow + $index;

            // Written as text so a long numeric ID keeps every digit and a name is never read as a formula.
            $sheet->setCellValueExplicit("A{$row}", $worker['name'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("B{$row}", $worker['number'], DataType::TYPE_STRING);

            foreach ($dates as $dateIndex => $date) {
                $day = $worker['days'][$date->toDateString()] ?? null;
                $first = $this->dayColumn($dateIndex);

                if ($day === null) {
                    continue;
                }

                if (! $day['is_present']) {
                    $sheet->mergeCells("{$first}{$row}:".$this->dayColumn($dateIndex, count(self::DAY_HEADINGS) - 1).$row);
                    $sheet->getStyle("{$first}{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F3F4F6');
                } elseif ($day['late_minutes'] > 0) {
                    $sheet->getStyle("{$first}{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FDE68A');
                }
            }
        }
    }

    /**
     * Every day of the selected range, or of the records when no range was given.
     *
     * @return list<Carbon>
     */
    private function dates(): array
    {
        if ($this->dates !== null) {
            return $this->dates;
        }

        $recordDates = $this->records->pluck('date')->filter();
        $from = $this->from ?? $recordDates->min();
        $to = $this->to ?? $recordDates->max();

        if (! $from || ! $to) {
            return $this->dates = [];
        }

        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->startOfDay();

        if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            if ($recordDates->isEmpty()) {
                return $this->dates = [];
            }

            $from = $from->max(Carbon::parse($recordDates->min())->startOfDay());
            $to = $to->min(Carbon::parse($recordDates->max())->startOfDay());
        }

        if ($from->greaterThan($to)) {
            return $this->dates = [];
        }

        return $this->dates = array_values(CarbonPeriod::create($from, $to)->toArray());
    }

    /**
     * One entry per worker, sorted by company, division and name, with their days keyed by date.
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
     *     days: array<string, array{check_in: string, check_out: string|null, worked_minutes: int|null, late_minutes: int, is_present: bool}>,
     * }>
     */
    private function workers(): array
    {
        if ($this->workers !== null) {
            return $this->workers;
        }

        $overtime = $this->approvedOvertimeMinutes();

        return $this->workers = $this->records
            ->filter(fn (Attendance $attendance): bool => $attendance->getWorkerProfile() !== null && $attendance->date !== null)
            ->groupBy(fn (Attendance $attendance): string => $attendance->getWorkerProfile()::class.':'.$attendance->getWorkerProfile()->getKey())
            ->map(function (Collection $attendances) use ($overtime): array {
                $worker = $attendances->first()->getWorkerProfile();
                $days = $attendances
                    ->groupBy(fn (Attendance $attendance): string => $attendance->date->toDateString())
                    ->map(fn (Collection $dayAttendances): array => $this->summarizeDay($dayAttendances));

                return [
                    'name' => (string) $worker->full_name,
                    'number' => (string) ($this->workerNumber($worker) ?? '-'),
                    'type' => $this->workerTypeLabel($worker),
                    'company' => $worker->company?->name ?? '-',
                    'division' => $worker->division?->name ?? '-',
                    'present_days' => $days->where('is_present', true)->count(),
                    'late_days' => $days->where('late_minutes', '>', 0)->count(),
                    'late_minutes' => (int) $days->sum('late_minutes'),
                    'early_leave_minutes' => (int) $attendances->sum('early_leave_minutes'),
                    'overtime_minutes' => $overtime[$worker::class.':'.$worker->getKey()] ?? 0,
                    'worked_minutes' => (int) $days->sum('worked_minutes'),
                    'days' => $days->all(),
                ];
            })
            ->sortBy([['company', 'asc'], ['division', 'asc'], ['name', 'asc']])
            ->values()
            ->all();
    }

    /**
     * One worker's day: a single attendance, or one per segment of a split shift (shown on separate lines).
     * A day without a check-in shows its status (leave, absent, off) in place of the check-in time. A rejected
     * segment is not worked time: it adds no presence, lateness or hours, and a day with only rejected segments
     * shows "Ditolak". A pending segment still counts, as in laporan absensi.
     *
     * @param  Collection<int, Attendance>  $attendances
     * @return array{check_in: string, check_out: string|null, worked_minutes: int|null, late_minutes: int, is_present: bool}
     */
    private function summarizeDay(Collection $attendances): array
    {
        $segments = $attendances
            ->filter(fn (Attendance $attendance): bool => $attendance->check_in !== null && $attendance->status !== AttendanceStatus::Rejected)
            ->sortBy('check_in')
            ->values();

        if ($segments->isEmpty()) {
            return [
                'check_in' => ($attendances->firstWhere('status', AttendanceStatus::Rejected) ?? $attendances->first())->status?->getLabel() ?? '-',
                'check_out' => null,
                'worked_minutes' => null,
                'late_minutes' => 0,
                'is_present' => false,
            ];
        }

        $workedMinutes = $segments->map(fn (Attendance $attendance): ?int => $attendance->workedMinutes())->reject(fn (?int $minutes): bool => $minutes === null);

        return [
            'check_in' => $segments->map(fn (Attendance $attendance): string => $attendance->check_in->format('H:i'))->implode("\n"),
            'check_out' => $segments->map(fn (Attendance $attendance): string => $attendance->check_out?->format('H:i') ?? '-')->implode("\n"),
            'worked_minutes' => $workedMinutes->isEmpty() ? null : (int) $workedMinutes->sum(),
            'late_minutes' => (int) $segments->sum(fn (Attendance $attendance): int => $attendance->reportedLateMinutes()),
            'is_present' => true,
        ];
    }

    /**
     * Approved overtime (lembur) of the listed workers in the report's dates, keyed by worker class and id:
     * the actual duration once filled in, otherwise the approved request.
     *
     * @return array<string, int>
     */
    private function approvedOvertimeMinutes(): array
    {
        $dates = $this->dates();

        if ($dates === []) {
            return [];
        }

        $columns = [Employee::class => 'employee_id', Intern::class => 'intern_id', Freelancer::class => 'freelancer_id'];
        $ids = [];

        foreach ($this->records as $attendance) {
            if ($worker = $attendance->getWorkerProfile()) {
                $ids[$columns[$worker::class] ?? 'freelancer_id'][] = $worker->getKey();
            }
        }

        if ($ids === []) {
            return [];
        }

        $minutes = [];

        OvertimeRequest::query()
            ->where('status', 'approved')
            // A half-open range: the last day still matches where dates are stored with a time (SQLite).
            ->where('date', '>=', $dates[0]->toDateString())
            ->where('date', '<', end($dates)->copy()->addDay()->toDateString())
            ->where(function ($query) use ($ids): void {
                foreach ($ids as $column => $workerIds) {
                    $query->orWhereIn($column, array_unique($workerIds));
                }
            })
            ->get(['employee_id', 'intern_id', 'freelancer_id', 'duration_minutes', 'actual_duration_minutes'])
            ->each(function (OvertimeRequest $overtime) use ($columns, &$minutes): void {
                foreach ($columns as $class => $column) {
                    if ($overtime->{$column} !== null) {
                        $key = $class.':'.$overtime->{$column};
                        $minutes[$key] = ($minutes[$key] ?? 0) + (int) ($overtime->actual_duration_minutes ?? $overtime->duration_minutes);

                        return;
                    }
                }
            });

        return $minutes;
    }

    private function workerNumber(Model $worker): ?string
    {
        return match (true) {
            $worker instanceof Employee => $worker->nip,
            $worker instanceof Intern => $worker->nis,
            $worker instanceof Freelancer => $worker->freelancer_number,
            default => null,
        };
    }

    private function workerTypeLabel(Model $worker): string
    {
        return match (true) {
            $worker instanceof Employee => 'Karyawan',
            $worker instanceof Intern => 'Magang',
            default => 'Freelancer',
        };
    }

    /**
     * Column letter of a date's group: offset 0 is check-in, 1 check-out, 2 hours.
     */
    private function dayColumn(int $dateIndex, int $offset = 0): string
    {
        return $this->columnLetter(count(self::WORKER_HEADINGS) + count(self::TOTAL_HEADINGS) + 1 + $dateIndex * count(self::DAY_HEADINGS) + $offset);
    }

    private function columnLetter(int $columnIndex): string
    {
        return Coordinate::stringFromColumnIndex($columnIndex);
    }
}
