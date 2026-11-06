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
        $today = today();

        $schedules = ShiftSchedule::query()
            ->visibleTo($user)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->with(['shift', 'location', ...ShiftSchedule::workerEagerLoads()])
            ->get();

        $attendances = Attendance::query()
            ->visibleTo($user)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->with(['location', 'employee.company', 'employee.division', 'intern.company', 'intern.division', 'freelancer.company', 'freelancer.division'])
            ->get();

        /** @var array<string, array{schedule: ShiftSchedule|null, attendance: Attendance|null}> $days */
        $days = [];

        foreach ($schedules as $schedule) {
            if ($key = $this->dayKey($schedule->getWorkerProfile(), $schedule->date)) {
                $days[$key]['schedule'] = $schedule;
            }
        }

        foreach ($attendances as $attendance) {
            if ($key = $this->dayKey($attendance->getWorkerProfile(), $attendance->date)) {
                $days[$key]['attendance'] = $attendance;
            }
        }

        $rows = [];

        foreach ($days as $day) {
            $schedule = $day['schedule'] ?? null;
            $attendance = $day['attendance'] ?? null;
            $worker = $schedule?->getWorkerProfile() ?? $attendance?->getWorkerProfile();
            $outlet = $schedule?->location ?? $attendance?->location;
            $type = $this->workerType($worker);
            $rowKey = "{$type}:{$worker->getKey()}:".($outlet?->id ?? '');

            $rows[$rowKey] ??= [
                'worker' => [
                    'type' => $type,
                    'id' => (int) $worker->getKey(),
                    'number' => (string) $this->workerNumber($worker),
                    'name' => (string) $worker->full_name,
                    'company' => $worker->company?->name,
                    'division' => $worker->division?->name,
                ],
                'outlet' => $outlet instanceof CompanyLocation ? $outlet->name : null,
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

            if ($schedule?->is_day_off) {
                $row['off_days']++;
            } elseif ($schedule) {
                $row['scheduled_days']++;
                $row['scheduled_minutes'] += $this->shiftMinutes($schedule->shift);
            }

            if ($attendance?->check_in) {
                $row['present_days']++;

                if ($attendance->late_minutes > 0) {
                    $row['late_days']++;
                    $row['late_minutes'] += (int) $attendance->late_minutes;
                }

                if ($attendance->check_out) {
                    $row['worked_minutes'] += (int) $attendance->check_in->diffInMinutes($attendance->check_out);
                }
            } elseif ($schedule && ! $schedule->is_day_off && $schedule->date->lessThan($today)) {
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

    private function dayKey(?Model $worker, CarbonInterface $date): ?string
    {
        return $worker ? $this->workerType($worker).':'.$worker->getKey().':'.$date->toDateString() : null;
    }

    private function workerType(Model $worker): string
    {
        return match (true) {
            $worker instanceof Employee => 'employee',
            $worker instanceof Intern => 'intern',
            default => 'freelancer',
        };
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

    private function shiftMinutes(?Shift $shift): int
    {
        if (! $shift) {
            return 0;
        }

        $start = Carbon::parse((string) $shift->start_time);
        $end = Carbon::parse((string) $shift->end_time);

        if ($end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        return (int) $start->diffInMinutes($end);
    }
}
