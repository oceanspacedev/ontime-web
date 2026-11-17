<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\CompanyLocation;
use App\Models\CompanyPolicy;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\Resignation;
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
 *
 * The recap follows the roster: a no-show is a rostered working day (ExpectedWorkDays, the same rule as
 * laporan absensi) up to yesterday without a worked segment or approved leave; a rostered day on approved
 * leave is neither present nor absent. A split shift is one present day when any segment was worked, with
 * the hours of every worked segment. A rejected segment is not worked; a pending one is (as in laporan
 * absensi).
 */
final class TeamRecapService
{
    private const TYPE_LABELS = ['employee' => 'Karyawan', 'intern' => 'Magang', 'freelancer' => 'Freelancer'];

    /** Rows read per query, so a month of a large Klien is streamed instead of loaded at once. */
    private const CHUNK_SIZE = 2000;

    /** @var array<string, array{model: class-string<Model>, number: string, from: string, until: string|null}> */
    private const WORKER_TYPES = [
        'employee' => ['model' => Employee::class, 'number' => 'nip', 'from' => 'join_date', 'until' => null],
        'intern' => ['model' => Intern::class, 'number' => 'nis', 'from' => 'start_date', 'until' => 'end_date'],
        'freelancer' => ['model' => Freelancer::class, 'number' => 'freelancer_number', 'from' => 'start_date', 'until' => 'end_date'],
    ];

    /** Worker statuses that still expect attendance; an older resignation of such an employee is history. */
    private const WORKING_STATUSES = ['active', 'extended'];

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
     *     overtime_count: int,
     *     overtime_hours: float,
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
        $lastCountableDay = ExpectedWorkDays::lastCountableDay()->toDateString();
        // A half-open range: the last day still matches where dates are stored with a time (SQLite).
        $from = $start->toDateString();
        $until = $end->copy()->addDay()->toDateString();

        /**
         * One entry per worker per day, built from plain rows so a month of a large Klien never holds models.
         *
         * @var array<string, array{worker: string, date: string, schedule?: array{shift_id: int|null, location_id: int|null, is_day_off: bool}, attendance?: array{checked_in: bool, location_id: int|null, late_minutes: int, early_leave_minutes: int, worked_minutes: int, pending: bool}}> $days
         */
        $days = [];

        // Schedules and attendance follow the worker and the outlet: days worked at an outlet in scope stay in
        // this recap after the worker moved to another company or brand, so a schedule is never read without
        // its attendance (which would turn a worked day into an absence).
        DataAccessService::scopeWorkerOrOutletRecords(ShiftSchedule::query(), $user, ShiftSchedule::workerRelations())
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

        DataAccessService::scopeWorkerOrOutletRecords(Attendance::query(), $user, Attendance::workerRelations())
            ->where('date', '>=', $from)
            ->where('date', '<', $until)
            ->select(['id', 'date', 'employee_id', 'intern_id', 'freelancer_id', 'company_location_id', 'check_in', 'check_out', 'late_minutes', 'check_in_late_minutes', 'early_leave_minutes', 'status'])
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

                $days[$key]['attendance'] ??= [
                    'checked_in' => false,
                    'location_id' => null,
                    'late_minutes' => 0,
                    'early_leave_minutes' => 0,
                    'worked_minutes' => 0,
                    'pending' => false,
                ];

                // Any worked segment makes the day present, whatever the row order; the first worked segment gives
                // the outlet, and every worked segment adds its lateness and work time. A rejected one adds nothing.
                if (blank($attendance->check_in) || $attendance->status === AttendanceStatus::Rejected->value) {
                    return;
                }

                if (! $days[$key]['attendance']['checked_in']) {
                    $days[$key]['attendance']['checked_in'] = true;
                    $days[$key]['attendance']['location_id'] = $attendance->company_location_id !== null ? (int) $attendance->company_location_id : null;
                }

                // A check-in waiting for approval reports the lateness judged at check-in, not 0 (on time).
                $isPending = $attendance->status === AttendanceStatus::PendingApproval->value;
                $days[$key]['attendance']['late_minutes'] += (int) ($isPending ? ($attendance->check_in_late_minutes ?? $attendance->late_minutes) : $attendance->late_minutes);
                $days[$key]['attendance']['pending'] = $days[$key]['attendance']['pending'] || $isPending;
                $days[$key]['attendance']['early_leave_minutes'] += (int) $attendance->early_leave_minutes;
                $days[$key]['attendance']['worked_minutes'] += $this->workedMinutes($attendance->check_in, $attendance->check_out);
            });

        // Approved overtime (lembur) is paid on the day it was worked: the actual duration once filled in,
        // otherwise the approved request. Pending and rejected overtime is not counted.
        OvertimeRequest::query()
            ->visibleTo($user)
            ->where('status', 'approved')
            ->where('date', '>=', $from)
            ->where('date', '<', $until)
            ->toBase()
            ->get(['employee_id', 'intern_id', 'freelancer_id', 'date', 'duration_minutes', 'actual_duration_minutes'])
            ->each(function (object $overtime) use (&$days): void {
                if (! $worker = $this->workerKey($overtime)) {
                    return;
                }

                $date = substr((string) $overtime->date, 0, 10);
                $key = "{$worker}:{$date}";
                $days[$key]['worker'] = $worker;
                $days[$key]['date'] = $date;
                $days[$key]['overtime_count'] = ($days[$key]['overtime_count'] ?? 0) + 1;
                $days[$key]['overtime_minutes'] = ($days[$key]['overtime_minutes'] ?? 0) + (int) ($overtime->actual_duration_minutes ?? $overtime->duration_minutes);
            });

        $workers = $this->workers(array_unique(array_column($days, 'worker')));
        $shiftMinutes = $this->shiftMinutes([
            ...array_column(array_column($days, 'schedule'), 'shift_id'),
            ...array_column($workers, 'default_shift_id'),
        ]);
        $outletNames = $this->outletNames([
            ...array_column(array_column($days, 'schedule'), 'location_id'),
            ...array_column(array_column($days, 'attendance'), 'location_id'),
        ]);
        $leaveDays = $this->approvedLeaveDays($user, $start, $end, array_keys($workers));
        $calendar = ExpectedWorkDays::between($start, $end);

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
                'pending_days' => 0,
                'late_days' => 0,
                'late_minutes' => 0,
                'early_leave_days' => 0,
                'early_leave_minutes' => 0,
                'overtime_count' => 0,
                'overtime_minutes' => 0,
                'absent_days' => 0,
                'off_days' => 0,
                'scheduled_minutes' => 0,
                'worked_minutes' => 0,
            ];
            $row = &$rows[$rowKey];

            $row['overtime_count'] += $day['overtime_count'] ?? 0;
            $row['overtime_minutes'] += $day['overtime_minutes'] ?? 0;

            // A day off that was worked anyway lands in one bucket only: present (below), not also Libur.
            if ($schedule['is_day_off'] ?? false) {
                if (! ($attendance['checked_in'] ?? false)) {
                    $row['off_days']++;
                }
            } elseif ($schedule) {
                $row['scheduled_days']++;
                $row['scheduled_minutes'] += $this->scheduledMinutesOf($schedule['shift_id'], $worker, $shiftMinutes);
            }

            if ($attendance['checked_in'] ?? false) {
                $row['present_days']++;

                // Still waiting for the approver: present for now, but not final for pay.
                if ($attendance['pending']) {
                    $row['pending_days']++;
                }

                // A split shift counts as one late day however many segments were late.
                if ($attendance['late_minutes'] > 0) {
                    $row['late_days']++;
                    $row['late_minutes'] += $attendance['late_minutes'];
                }

                // Leaving before the scheduled end ("pulang cepat"), one day however many segments.
                if ($attendance['early_leave_minutes'] > 0) {
                    $row['early_leave_days']++;
                    $row['early_leave_minutes'] += $attendance['early_leave_minutes'];
                }

                $row['worked_minutes'] += $attendance['worked_minutes'];
            } elseif ($schedule && $calendar->isExpected($day['date'], $schedule['is_day_off'], $worker['company_id'], $worker['employed_from'], $worker['employed_until'])) {
                if ($day['date'] <= $lastCountableDay && ! isset($leaveDays["{$day['worker']}:{$day['date']}"])) {
                    $row['absent_days']++;
                }
            }

            unset($row);
        }

        return collect($rows)
            ->map(function (array $row): array {
                $row['scheduled_hours'] = round($row['scheduled_minutes'] / 60, 2);
                $row['worked_hours'] = round($row['worked_minutes'] / 60, 2);
                $overtimeCount = $row['overtime_count'];
                $overtimeHours = round($row['overtime_minutes'] / 60, 2);
                unset($row['scheduled_minutes'], $row['worked_minutes'], $row['overtime_count'], $row['overtime_minutes']);
                // Overtime last, next to the hours worked.
                $row['overtime_count'] = $overtimeCount;
                $row['overtime_hours'] = $overtimeHours;
                unset($row['worker']['company_id'], $row['worker']['employed_from'], $row['worker']['employed_until'], $row['worker']['default_shift_id'], $row['worker']['policy_minutes']);

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
        $sheet->setColumnWidthForRange(13, 7, 19);

        $writer->addRow(Row::fromValues([
            'Badan Usaha', 'Divisi / Brand', 'Outlet', 'ID Pekerja', 'Nama', 'Jenis',
            'Hari Terjadwal', 'Hari Masuk', 'Tidak Hadir', 'Libur', 'Terlambat (kali)', 'Total Telat (menit)',
            'Pulang Cepat (kali)', 'Total Pulang Cepat (menit)', 'Jam Terjadwal', 'Jam Kerja',
            'Lembur (kali)', 'Total Lembur (jam)', 'Menunggu Persetujuan (hari)',
        ], $bold));

        $totals = array_fill_keys(['scheduled_days', 'present_days', 'absent_days', 'off_days', 'late_days', 'late_minutes', 'early_leave_days', 'early_leave_minutes', 'scheduled_hours', 'worked_hours', 'overtime_count', 'overtime_hours', 'pending_days'], 0);

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
                $row['early_leave_days'],
                $row['early_leave_minutes'],
                $row['scheduled_hours'],
                $row['worked_hours'],
                $row['overtime_count'],
                $row['overtime_hours'],
                $row['pending_days'],
            ]));

            foreach (array_keys($totals) as $key) {
                $totals[$key] += $row[$key];
            }
        }

        $writer->addRow(Row::fromValues([
            'Total', '', '', '', count($rows).' baris', '',
            $totals['scheduled_days'], $totals['present_days'], $totals['absent_days'], $totals['off_days'],
            $totals['late_days'], $totals['late_minutes'], $totals['early_leave_days'], $totals['early_leave_minutes'], round($totals['scheduled_hours'], 2), round($totals['worked_hours'], 2),
            $totals['overtime_count'], round($totals['overtime_hours'], 2), $totals['pending_days'],
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
     * Scheduled minutes of a rostered working day, the same hours the attendance engine judges the day by
     * (WorkScheduleService::resolve): the scheduled shift (both segments of a split shift), otherwise the
     * worker's default shift, otherwise the company policy hours. A shift used by a schedule or attendance
     * cannot be deleted and its hours are locked once used on a past day (ShiftUsageService), so a closed
     * month keeps its hours; a schedule that lost its shift anyway falls back instead of dropping to 0.
     *
     * @param  array{default_shift_id: int|null, policy_minutes: int}  $worker
     * @param  array<int, int>  $shiftMinutes
     */
    private function scheduledMinutesOf(?int $shiftId, array $worker, array $shiftMinutes): int
    {
        foreach ([$shiftId, $worker['default_shift_id']] as $id) {
            if ($id !== null && isset($shiftMinutes[$id])) {
                return $shiftMinutes[$id];
            }
        }

        return $worker['policy_minutes'];
    }

    /**
     * Minutes between the policy start and end of work (08:00 - 17:00 without a policy), past midnight when
     * the end is not after the start.
     */
    private function policyMinutes(?CompanyPolicy $policy): int
    {
        $start = Carbon::parse((string) ($policy?->work_start_time ?? '08:00:00'));
        $end = Carbon::parse((string) ($policy?->work_end_time ?? '17:00:00'));

        if ($end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        return (int) $start->diffInMinutes($end);
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
     * Approved leave per worker-day in the month, keyed "type:id:Y-m-d": of the workers in the user's data
     * access, and of the workers in the recap (one moved away still has their leave next to their old days).
     *
     * @param  list<string>  $workerKeys
     * @return array<string, true>
     */
    private function approvedLeaveDays(User $user, Carbon $start, Carbon $end, array $workerKeys = []): array
    {
        $days = [];
        $idsByType = [];

        foreach ($workerKeys as $workerKey) {
            [$type, $id] = explode(':', $workerKey);
            $idsByType[$type][] = (int) $id;
        }

        LeaveRequest::query()
            ->where('status', 'approved')
            ->where('start_date', '<', $end->copy()->addDay()->toDateString())
            ->where('end_date', '>=', $start->toDateString())
            ->when(DataAccessService::workerFilter($user) !== null, fn ($query) => $query->where(function ($visible) use ($user, $idsByType): void {
                $visible->where(fn ($inScope) => $inScope->visibleTo($user));

                foreach ($idsByType as $type => $ids) {
                    $visible->orWhereIn("{$type}_id", $ids);
                }
            }))
            ->toBase()
            ->get(['employee_id', 'intern_id', 'freelancer_id', 'start_date', 'end_date'])
            ->each(function (object $leave) use ($start, $end, &$days): void {
                if (! ($worker = $this->workerKey($leave)) || blank($leave->start_date) || blank($leave->end_date)) {
                    return;
                }

                $cursor = Carbon::parse($leave->start_date)->startOfDay()->max($start->copy()->startOfDay());
                $until = Carbon::parse($leave->end_date)->startOfDay()->min($end->copy()->startOfDay());

                for (; $cursor->lte($until); $cursor->addDay()) {
                    $days["{$worker}:{$cursor->toDateString()}"] = true;
                }
            });

        return $days;
    }

    /**
     * The latest approved last working day per employee id.
     *
     * @param  array<int, int>  $employeeIds
     * @return array<int, string>
     */
    private function lastWorkingDays(array $employeeIds): array
    {
        return Resignation::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('status', 'approved')
            ->whereNotNull('last_working_day')
            ->toBase()
            ->get(['employee_id', 'last_working_day'])
            ->groupBy('employee_id')
            ->map(fn ($rows): string => (string) $this->dateString($rows->max('last_working_day')))
            ->mapWithKeys(fn (string $date, int|string $employeeId): array => [(int) $employeeId => $date])
            ->all();
    }

    private function dateString(mixed $value): ?string
    {
        return blank($value) ? null : Carbon::parse($value)->toDateString();
    }

    /**
     * Worker details per worker key, with only the columns the recap shows.
     *
     * @param  array<int, string>  $workerKeys
     * @return array<string, array{type: string, id: int, number: string, name: string, company: string|null, division: string|null, company_id: int|null, employed_from: string|null, employed_until: string|null, default_shift_id: int|null, policy_minutes: int}>
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
                $lastWorkingDays = $definition['until'] === null ? $this->lastWorkingDays($chunk) : [];

                $definition['model']::query()
                    ->whereIn('id', $chunk)
                    ->with(['company:id,name', 'company.policy', 'division:id,name'])
                    ->get(array_values(array_filter(['id', 'company_id', 'division_id', 'shift_id', 'full_name', 'status', $definition['number'], $definition['from'], $definition['until']])))
                    ->each(function (Model $worker) use ($type, $definition, $lastWorkingDays, &$workers): void {
                        $until = $definition['until'] !== null
                            ? $this->dateString($worker->getRawOriginal($definition['until']))
                            : $lastWorkingDays[(int) $worker->getKey()] ?? null;

                        // A rehired employee's old resignation does not end their current employment.
                        if ($definition['until'] === null && $until !== null && $until < today()->toDateString()
                            && in_array((string) $worker->getRawOriginal('status'), self::WORKING_STATUSES, true)) {
                            $until = null;
                        }

                        $workers["{$type}:{$worker->getKey()}"] = [
                            'type' => $type,
                            'id' => (int) $worker->getKey(),
                            'number' => (string) $worker->{$definition['number']},
                            'name' => (string) $worker->full_name,
                            'company' => $worker->company?->name,
                            'division' => $worker->division?->name,
                            'company_id' => $worker->company_id !== null ? (int) $worker->company_id : null,
                            'employed_from' => $this->dateString($worker->getRawOriginal($definition['from'])),
                            'employed_until' => $until,
                            'default_shift_id' => $worker->shift_id !== null ? (int) $worker->shift_id : null,
                            'policy_minutes' => $this->policyMinutes($worker->company?->policy),
                        ];
                    });
            }
        }

        return $workers;
    }
}
