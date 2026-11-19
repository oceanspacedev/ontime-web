<?php

namespace App\Services;

use App\Models\User;
use App\Services\Attendance\LedgerQuery;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * A month of the team in one line per person per outlet: days scheduled, worked, late, absent, on leave
 * and off, with scheduled, normal, worked and overtime hours. Covers the workers in the user's data
 * access and the people from elsewhere who worked at their outlets.
 *
 * A presenter over the attendance ledger ({@see AttendanceLedger::outletRows()}), which holds every rule:
 * the figures here are the ones laporan absensi and the attendance lists show. A day off or a leave day
 * is on the line of the worker's home outlet of the month. The total counts each worker-day once, also
 * when it was worked at two outlets.
 */
final class TeamRecapService
{
    private const TYPE_LABELS = ['employee' => 'Karyawan', 'intern' => 'Magang', 'freelancer' => 'Freelancer'];

    /** Day and session counters shown as they are counted. */
    private const COUNTERS = [
        'leave_days', 'missing_check_out_days', 'incomplete_days', 'sessions_scheduled', 'sessions_worked', 'sessions_missed',
    ];

    public function __construct(private readonly AttendanceLedger $ledger) {}

    /**
     * @return list<array{
     *     worker: array{type: string, id: int, number: string, name: string, company: string|null, division: string|null},
     *     outlet: string|null,
     *     scheduled_days: int,
     *     present_days: int,
     *     pending_days: int,
     *     late_days: int,
     *     late_minutes: int,
     *     early_leave_days: int,
     *     early_leave_minutes: int,
     *     absent_days: int,
     *     off_days: int,
     *     scheduled_hours: float,
     *     worked_hours: float,
     *     overtime_count: int,
     *     overtime_hours: float,
     *     leave_days: int,
     *     missing_check_out_days: int,
     *     incomplete_days: int,
     *     sessions_scheduled: int,
     *     sessions_worked: int,
     *     sessions_missed: int,
     *     normal_hours: float,
     *     auto_overtime_hours: float,
     *     request_overtime_hours: float,
     *     scheduled_days_to_date: int,
     *     scheduled_hours_to_date: float,
     *     overtime_unattended_count: int,
     * }>
     */
    public function recap(User $user, CarbonInterface $month): array
    {
        return $this->report($user, $month)['rows'];
    }

    /**
     * The recap lines and their total. The total has the keys of a line without worker and outlet; its
     * day counts are distinct worker-days, so they can be lower than the sum of the lines.
     *
     * @return array{rows: list<array<string, mixed>>, total: array<string, int|float>}
     */
    public function report(User $user, CarbonInterface $month): array
    {
        $ledger = $this->ledger->outletRows(LedgerQuery::month($user, $month));

        return [
            'rows' => array_map(fn (array $row): array => ['worker' => $row['worker'], 'outlet' => $row['outlet']['name'] ?? null] + $this->figures($row), $ledger['rows']),
            'total' => $this->figures($ledger['total']),
        ];
    }

    /**
     * Write the recap to a temporary .xlsx file and return its path.
     */
    public function export(User $user, CarbonInterface $month): string
    {
        ['rows' => $rows, 'total' => $total] = $this->report($user, $month);
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
        $sheet->setColumnWidthForRange(13, 7, 26);

        $writer->addRow(Row::fromValues([
            'Badan Usaha', 'Divisi / Brand', 'Outlet', 'ID Pekerja', 'Nama', 'Jenis',
            'Hari Terjadwal', 'Hari Masuk', 'Tidak Hadir', 'Libur', 'Terlambat (kali)', 'Total Telat (menit)',
            'Pulang Cepat (kali)', 'Total Pulang Cepat (menit)', 'Jam Terjadwal', 'Jam Kerja',
            'Lembur (kali)', 'Total Lembur (jam)', 'Menunggu Persetujuan (hari)',
            'Cuti (hari)', 'Lupa Absen Pulang (hari)', 'Sesi Terlewat', 'Jam Normal',
            'Lembur Otomatis (jam)', 'Lembur Pengajuan (jam)', 'Hari Terjadwal s.d. kemarin',
        ], $bold));

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues([
                $row['worker']['company'] ?? '-',
                $row['worker']['division'] ?? '-',
                $row['outlet'] ?? '-',
                $row['worker']['number'],
                $row['worker']['name'],
                self::TYPE_LABELS[$row['worker']['type']],
                ...$this->cells($row),
            ]));
        }

        $writer->addRow(Row::fromValues(['Total', '', '', '', count($rows).' baris', '', ...$this->cells($total)], $bold));

        $writer->close();

        return $path;
    }

    /**
     * The figures of a ledger row (or of the ledger total) in the keys of a recap line: the keys the app
     * already reads first and unchanged, the newer ones after them.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, int|float>
     */
    private function figures(array $row): array
    {
        $hours = fn (string $key): float => round($row[$key] / 60, 2);

        $figures = [
            'scheduled_days' => $row['scheduled_days'],
            'present_days' => $row['present_days'],
            'pending_days' => $row['pending_days'],
            'late_days' => $row['late_days'],
            'late_minutes' => $row['late_minutes'],
            'early_leave_days' => $row['early_leave_days'],
            'early_leave_minutes' => $row['early_leave_minutes'],
            'absent_days' => $row['absent_days'],
            'off_days' => $row['off_days'],
            'scheduled_hours' => $hours('scheduled_minutes'),
            'worked_hours' => $hours('worked_minutes'),
            // Overtime next to the hours worked: automatic (above the shift's normal hours) plus approved requests.
            'overtime_count' => $row['overtime_count'],
            'overtime_hours' => $hours('overtime_minutes'),
        ];

        foreach (self::COUNTERS as $key) {
            $figures[$key] = $row[$key];
        }

        return $figures + [
            'normal_hours' => $hours('normal_minutes'),
            'auto_overtime_hours' => $hours('auto_overtime_minutes'),
            'request_overtime_hours' => $hours('request_overtime_minutes'),
            'scheduled_days_to_date' => $row['scheduled_days_to_date'],
            'scheduled_hours_to_date' => $hours('scheduled_minutes_to_date'),
            'overtime_unattended_count' => $row['overtime_unattended_count'],
        ];
    }

    /**
     * The figure cells of an Excel line, in the order of the headings.
     *
     * @param  array<string, mixed>  $figures
     * @return list<int|float>
     */
    private function cells(array $figures): array
    {
        return [
            $figures['scheduled_days'], $figures['present_days'], $figures['absent_days'], $figures['off_days'],
            $figures['late_days'], $figures['late_minutes'], $figures['early_leave_days'], $figures['early_leave_minutes'],
            $figures['scheduled_hours'], $figures['worked_hours'], $figures['overtime_count'], $figures['overtime_hours'], $figures['pending_days'],
            $figures['leave_days'], $figures['missing_check_out_days'], $figures['sessions_missed'], $figures['normal_hours'],
            $figures['auto_overtime_hours'], $figures['request_overtime_hours'], $figures['scheduled_days_to_date'],
        ];
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

    /**
     * The first day of a "2026-11" month. The "!" resets the day, so the 31st never overflows a shorter
     * month into the next one.
     */
    public static function parseMonth(string $month): Carbon
    {
        return Carbon::createFromFormat('!Y-m', $month)->startOfMonth();
    }

    public function fileName(CarbonInterface $month): string
    {
        return 'rekap-tim-'.Carbon::parse($month)->format('Y-m').'.xlsx';
    }
}
