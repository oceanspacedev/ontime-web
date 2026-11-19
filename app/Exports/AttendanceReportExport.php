<?php

namespace App\Exports;

use App\Services\Attendance\LedgerQuery;
use App\Services\AttendanceLedger;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * The attendance report workbook: a recap grid (workers down, dates across) and the full detail list.
 * Both sheets show one reading of the attendance ledger ({@see AttendanceLedger}).
 */
class AttendanceReportExport implements WithMultipleSheets
{
    private readonly AttendanceReportData $data;

    /**
     * $source is what to ask the ledger (null = an empty report), or attendance rows: the workers of those
     * rows are then reported for $from to $to (without them, for the dates of the rows).
     *
     * @param  LedgerQuery|Collection<int, mixed>|null  $source
     */
    public function __construct(
        LedgerQuery|Collection|null $source,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
    ) {
        $this->data = $source instanceof Collection
            ? AttendanceReportData::fromRecords($source, $from, $to)
            : new AttendanceReportData($source);
    }

    /**
     * @return array{0: AttendanceRecapSheet, 1: AttendanceDetailSheet}
     */
    public function sheets(): array
    {
        return [
            new AttendanceRecapSheet($this->data),
            new AttendanceDetailSheet($this->data),
        ];
    }
}
