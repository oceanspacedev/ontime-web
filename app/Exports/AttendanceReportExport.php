<?php

namespace App\Exports;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * The attendance report workbook: a recap grid (workers down, dates across) and the full detail list.
 */
class AttendanceReportExport implements WithMultipleSheets
{
    public function __construct(
        protected Collection $records,
        protected ?CarbonInterface $from = null,
        protected ?CarbonInterface $to = null,
    ) {}

    /**
     * @return array{0: AttendanceRecapSheet, 1: AttendanceDetailSheet}
     */
    public function sheets(): array
    {
        return [
            new AttendanceRecapSheet($this->records, $this->from, $this->to),
            new AttendanceDetailSheet($this->records),
        ];
    }
}
