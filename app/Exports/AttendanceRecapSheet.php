<?php

namespace App\Exports;

use App\Services\Attendance\LedgerQuery;
use App\Services\AttendanceLedger;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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
 * with the worker's totals for the period right after their identity and further totals after the dates.
 *
 * Every figure comes from the attendance ledger ({@see AttendanceLedger}, through {@see AttendanceReportData}):
 * the sheet lists every worker with a planned or recorded day and computes nothing itself.
 */
class AttendanceRecapSheet implements FromArray, WithColumnWidths, WithEvents, WithStrictNullComparison, WithTitle
{
    private const WORKER_HEADINGS = ['Nama', 'NIP / ID', 'Tipe', 'Badan Usaha', 'Divisi'];

    private const TOTAL_HEADINGS = ['Hari Masuk', 'Terlambat (kali)', 'Telat (menit)', 'Pulang Cepat (menit)', 'Lembur (jam)', 'Jumlah Jam'];

    private const DAY_HEADINGS = ['Masuk', 'Pulang', 'Jam'];

    /** Totals added later: they follow the dates, so the columns before them stay where they were. */
    private const MORE_HEADINGS = [
        'Cuti (hari)', 'Tidak Hadir (hari)', 'Libur (hari)', 'Lupa Absen Pulang (hari)',
        'Jam Normal', 'Lembur Otomatis (jam)', 'Lembur Pengajuan (jam)',
    ];

    /** How many of the further totals are hours (the last ones). */
    private const MORE_HOUR_COLUMNS = 3;

    private const HEADING_ROWS = 2;

    private const HOURS_FORMAT = '0.00';

    private const LATE_NOTE = 'Keterangan: jam masuk berwarna kuning = terlambat. Jam dalam desimal (7,50 = 7 jam 30 menit).';

    private readonly AttendanceReportData $data;

    /**
     * $source is the report itself, what to ask the ledger, or attendance rows: the workers of those rows
     * are then reported for $from to $to (without them, for the dates of the rows).
     *
     * @param  AttendanceReportData|LedgerQuery|Collection<int, mixed>  $source
     */
    public function __construct(
        AttendanceReportData|LedgerQuery|Collection $source,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
    ) {
        $this->data = match (true) {
            $source instanceof AttendanceReportData => $source,
            $source instanceof LedgerQuery => new AttendanceReportData($source),
            default => AttendanceReportData::fromRecords($source, $from, $to),
        };
    }

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

        array_push($groupHeadings, 'Total Lainnya', ...array_fill(0, count(self::MORE_HEADINGS) - 1, null));
        array_push($columnHeadings, ...self::MORE_HEADINGS);

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

            array_push(
                $row,
                $worker['leave_days'],
                $worker['absent_days'],
                $worker['off_days'],
                $worker['missing_check_out_days'],
                $worker['normal_minutes'] / 60,
                $worker['auto_overtime_minutes'] / 60,
                $worker['request_overtime_minutes'] / 60,
            );

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

        foreach (array_keys(self::MORE_HEADINGS) as $offset) {
            $widths[$this->moreColumn($offset)] = 12;
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
        $firstMoreColumn = $this->moreColumn(0);
        $lastColumn = $this->moreColumn(count(self::MORE_HEADINGS) - 1);

        foreach (array_keys(self::WORKER_HEADINGS) as $index) {
            $column = $this->columnLetter($index + 1);
            $sheet->mergeCells("{$column}1:{$column}2");
        }

        $sheet->mergeCells("{$firstTotalColumn}1:{$lastTotalColumn}1");
        $sheet->mergeCells("{$firstMoreColumn}1:{$lastColumn}1");

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
        $firstMoreHourColumn = $this->moreColumn(count(self::MORE_HEADINGS) - self::MORE_HOUR_COLUMNS);
        $sheet->getStyle("{$firstMoreHourColumn}{$firstRow}:{$lastColumn}{$lastRow}")->getNumberFormat()->setFormatCode(self::HOURS_FORMAT);

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
     * @return list<CarbonImmutable>
     */
    private function dates(): array
    {
        return $this->data->dates();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function workers(): array
    {
        return $this->data->workers();
    }

    /**
     * Column letter of a date's group: offset 0 is check-in, 1 check-out, 2 hours.
     */
    private function dayColumn(int $dateIndex, int $offset = 0): string
    {
        return $this->columnLetter(count(self::WORKER_HEADINGS) + count(self::TOTAL_HEADINGS) + 1 + $dateIndex * count(self::DAY_HEADINGS) + $offset);
    }

    /**
     * Column letter of a total that follows the dates.
     */
    private function moreColumn(int $offset): string
    {
        return $this->columnLetter(count(self::WORKER_HEADINGS) + count(self::TOTAL_HEADINGS) + 1 + count($this->dates()) * count(self::DAY_HEADINGS) + $offset);
    }

    private function columnLetter(int $columnIndex): string
    {
        return Coordinate::stringFromColumnIndex($columnIndex);
    }
}
