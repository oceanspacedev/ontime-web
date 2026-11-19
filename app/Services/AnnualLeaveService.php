<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\LeaveRequest;
use App\Models\ShiftSchedule;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may take annual leave, and how much is left. The panel, the API and the dashboard all ask
 * this class, so the rule lives in one place:
 *
 * - Interns and freelancers never have annual leave.
 * - An employee has it only when HR turned it on (`employees.has_annual_leave`).
 * - The yearly quota is the employee's own quota, or the company policy quota when it is empty.
 * - The quota is per calendar year of the leave days: a request that crosses the new year charges
 *   each year with its own days, and used days of a year are the approved annual leave days that
 *   fall inside it.
 * - Annual leave may not overlap another pending or approved leave of the same worker.
 * - A leave is charged only for the days the worker would have worked ({@see self::chargedDates()}):
 *   in a company that plans with shift rosters a rostered day off, and a blank day of a week that has
 *   a roster, cost nothing. The list is frozen at the final approval and never recounted afterwards.
 */
class AnnualLeaveService
{
    public const DEFAULT_QUOTA = 12;

    /** Refusal for a period in which the worker would not have worked a single day. */
    public const NO_WORKDAY_MESSAGE = 'Tidak ada hari kerja pada rentang ini';

    /**
     * The dates of the period the leave is charged for, as Y-m-d in order.
     *
     * In a company that plans with shift rosters (`company_policies.uses_shift_roster`) a date is not
     * charged when every schedule row of it is a day off, nor when it has no row while the worker is on a
     * roster on it (from the date of their first roster row on, {@see ShiftSchedule::whereRosterRow()}) and
     * has at least one roster row in that ISO week (a published week: blank means not scheduled). A blank
     * date before the first roster row (fixed hours, the weekdays before a first row in mid-week included)
     * or of a week whose only rows are an approved cover's own rows is charged, as is a blank date of a
     * week without any row: the attendance ledger expects those days too. In any other company every
     * calendar date is charged.
     *
     * @return list<string>
     */
    public function chargedDates(Employee|Intern|Freelancer|null $worker, CarbonInterface $startDate, CarbonInterface $endDate): array
    {
        $start = CarbonImmutable::parse($startDate->toDateString());
        $end = CarbonImmutable::parse($endDate->toDateString());
        $dates = [];

        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            $dates[] = $date->toDateString();
        }

        if (! $worker || $dates === [] || ! $worker->loadMissing('company.policy')->company?->policy?->uses_shift_roster) {
            return $dates;
        }

        $column = match (true) {
            $worker instanceof Employee => 'employee_id',
            $worker instanceof Intern => 'intern_id',
            default => 'freelancer_id',
        };
        $weekStart = $start->startOfWeek(CarbonInterface::MONDAY);
        $weekEnd = $end->endOfWeek(CarbonInterface::SUNDAY);

        /** @var array<string, bool> $working whether a date has at least one row that is not a day off */
        $working = [];
        /** @var array<string, true> $publishedWeeks Monday (Y-m-d) of every week with at least one roster row */
        $publishedWeeks = [];
        $inRange = fn (): Builder => ShiftSchedule::query()
            ->where($column, $worker->getKey())
            // A half-open range: the last day still matches where dates are stored with a time (SQLite).
            ->where('date', '>=', $weekStart->toDateString())
            ->where('date', '<', $weekEnd->addDay()->toDateString());

        $inRange()
            ->get(['date', 'is_day_off'])
            ->each(function (ShiftSchedule $row) use (&$working): void {
                $date = $row->date->toDateString();
                $working[$date] = ($working[$date] ?? false) || ! $row->is_day_off;
            });

        ShiftSchedule::whereRosterRow($inRange())
            ->pluck('date')
            ->each(function (mixed $date) use (&$publishedWeeks): void {
                $publishedWeeks[CarbonImmutable::parse(substr((string) $date, 0, 10))->startOfWeek(CarbonInterface::MONDAY)->toDateString()] = true;
            });

        $first = ShiftSchedule::whereRosterRow(ShiftSchedule::query())->where($column, $worker->getKey())->min('date');
        $rosteredSince = $first === null ? null : substr((string) $first, 0, 10);

        return array_values(array_filter($dates, function (string $date) use ($working, $publishedWeeks, $rosteredSince): bool {
            if (array_key_exists($date, $working)) {
                return $working[$date];
            }

            if ($rosteredSince === null || $date < $rosteredSince) {
                return true;
            }

            return ! isset($publishedWeeks[CarbonImmutable::parse($date)->startOfWeek(CarbonInterface::MONDAY)->toDateString()]);
        }));
    }

    /**
     * Charged dates per calendar year, for example [2026 => 3, 2027 => 2].
     *
     * @param  list<string>  $dates
     * @return array<int, int>
     */
    public function datesPerYear(array $dates): array
    {
        $perYear = [];

        foreach ($dates as $date) {
            $year = (int) substr($date, 0, 4);
            $perYear[$year] = ($perYear[$year] ?? 0) + 1;
        }

        ksort($perYear);

        return $perYear;
    }

    public function isEligible(Employee|Intern|Freelancer|null $profile): bool
    {
        return $profile instanceof Employee && $profile->has_annual_leave;
    }

    /**
     * Yearly quota in days, or 0 for anyone without annual leave.
     */
    public function quota(Employee|Intern|Freelancer|null $profile): int
    {
        if (! $profile instanceof Employee || ! $this->isEligible($profile)) {
            return 0;
        }

        return $profile->annual_leave_quota ?? $profile->company?->policy?->annual_leave_quota ?? self::DEFAULT_QUOTA;
    }

    /**
     * Approved annual leave days that fall inside the year (the current year when null). A request
     * that crosses the new year counts only its days of that year. `$exceptLeaveRequestId` leaves out
     * the request being edited or approved, so it is never counted against itself. `$lockRows` reads
     * the rows with a locking read, so an approval waiting on the worker lock sees the leave another
     * approval just committed instead of the transaction's older snapshot.
     */
    public function usedDays(Employee|Intern|Freelancer|null $profile, ?int $year = null, ?int $exceptLeaveRequestId = null, bool $lockRows = false): int
    {
        if (! $profile instanceof Employee) {
            return 0;
        }

        $year ??= now()->year;

        return (int) LeaveRequest::query()
            ->where('employee_id', $profile->getKey())
            ->where('leave_type', LeaveRequest::TYPE_ANNUAL)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', "{$year}-12-31")
            ->whereDate('end_date', '>=', "{$year}-01-01")
            ->when($exceptLeaveRequestId, fn ($query) => $query->whereKeyNot($exceptLeaveRequestId))
            ->when($lockRows, fn ($query) => $query->lockForUpdate())
            ->get(['id', 'start_date', 'end_date', 'days_count', 'charged_dates'])
            ->sum(fn (LeaveRequest $leave): int => $this->daysInYear($leave, $year));
    }

    /**
     * Days of the period per calendar year, for example [2026 => 4, 2027 => 3] for 28 Dec - 3 Jan.
     *
     * @return array<int, int>
     */
    public function daysPerYear(CarbonInterface $startDate, CarbonInterface $endDate): array
    {
        $days = [];
        $start = Carbon::parse($startDate->toDateString());
        $end = Carbon::parse($endDate->toDateString());

        for ($year = $start->year; $year <= $end->year; $year++) {
            $from = $start->max(Carbon::create($year, 1, 1));
            $to = $end->min(Carbon::create($year, 12, 31));
            $days[$year] = (int) $from->diffInDays($to) + 1;
        }

        return $days;
    }

    /**
     * Days of an approved request charged to the year. A request with a stored list of charged dates
     * is split by that list. An older one inside one year keeps its stored day count; one crossing the
     * new year is split by calendar days.
     */
    private function daysInYear(LeaveRequest $leave, int $year): int
    {
        if (is_array($leave->charged_dates)) {
            return $this->datesPerYear($leave->chargedDateStrings())[$year] ?? 0;
        }

        if ($leave->start_date->year === $leave->end_date->year) {
            return $leave->start_date->year === $year ? (int) $leave->days_count : 0;
        }

        return $this->daysPerYear($leave->start_date, $leave->end_date)[$year] ?? 0;
    }

    /**
     * Why the period does not fit the remaining quota of each year it touches, or null. A period
     * inside one year is charged `$daysCount` when given (the stored count of an existing request).
     * Messages speak to the worker, or name the worker for an approver; the year is named only when
     * it is not the current one. A period crossing the new year is split by `$chargedDates` (computed
     * from the roster when not given).
     *
     * @param  list<string>|null  $chargedDates
     */
    private function periodQuotaError(Employee $profile, CarbonInterface $startDate, CarbonInterface $endDate, ?int $exceptLeaveRequestId, bool $lockRows, bool $forApprover, ?int $daysCount = null, ?array $chargedDates = null): ?string
    {
        $perYear = $startDate->year === $endDate->year
            ? $this->daysPerYear($startDate, $endDate)
            : $this->datesPerYear($chargedDates ?? $this->chargedDates($profile, $startDate, $endDate));

        if (count($perYear) === 1 && $daysCount !== null) {
            $perYear = [array_key_first($perYear) => $daysCount];
        }

        $quota = $this->quota($profile);

        foreach ($perYear as $year => $days) {
            $remaining = $quota - $this->usedDays($profile, $year, $exceptLeaveRequestId, $lockRows);

            if ($days <= $remaining) {
                continue;
            }

            $label = $year === now()->year ? '' : " {$year}";
            $detail = '(Sisa: '.max(0, $remaining)." hari, Pengajuan: {$days} hari).";

            return $forApprover
                ? "Sisa kuota cuti tahunan{$label} {$profile->full_name} tidak mencukupi {$detail}"
                : "Kuota cuti tahunan{$label} Anda tidak mencukupi {$detail}";
        }

        return null;
    }

    /**
     * The other leave of the same worker in one of `$statuses` that shares a day with the period, if any.
     *
     * @param  list<string>  $statuses
     */
    public function overlappingLeave(Employee|Intern|Freelancer $profile, CarbonInterface $startDate, CarbonInterface $endDate, ?int $exceptLeaveRequestId = null, array $statuses = ['pending', 'approved']): ?LeaveRequest
    {
        $column = match (true) {
            $profile instanceof Employee => 'employee_id',
            $profile instanceof Intern => 'intern_id',
            default => 'freelancer_id',
        };

        return LeaveRequest::query()
            ->where($column, $profile->getKey())
            ->whereIn('status', $statuses)
            ->whereDate('start_date', '<=', $endDate->toDateString())
            ->whereDate('end_date', '>=', $startDate->toDateString())
            ->when($exceptLeaveRequestId, fn ($query) => $query->whereKeyNot($exceptLeaveRequestId))
            ->orderBy('start_date')
            ->first();
    }

    /**
     * Why the period cannot be filed because it shares a day with another pending or approved leave
     * of the worker (any type), or null. Messages speak to the worker filing it. This also refuses the
     * same request sent again after its answer was lost.
     */
    public function overlapError(Employee|Intern|Freelancer $profile, CarbonInterface $startDate, CarbonInterface $endDate, ?int $exceptLeaveRequestId = null): ?string
    {
        $overlap = $this->overlappingLeave($profile, $startDate, $endDate, $exceptLeaveRequestId);

        return $overlap
            ? 'Tanggal cuti bertabrakan dengan pengajuan '.LeaveRequest::typeLabel($overlap->leave_type).' Anda pada '.self::periodLabel($overlap).'. Pilih tanggal lain.'
            : null;
    }

    private static function periodLabel(LeaveRequest $leave): string
    {
        $start = $leave->start_date->format('d/m/Y');
        $end = $leave->end_date->format('d/m/Y');

        return $start === $end ? $start : "{$start} - {$end}";
    }

    /**
     * @return array{eligible: bool, annual_leave_quota: int, used_days: int, remaining_days: int}
     */
    public function summary(Employee|Intern|Freelancer|null $profile): array
    {
        $quota = $this->quota($profile);
        $used = $this->usedDays($profile);

        return [
            'eligible' => $this->isEligible($profile),
            'annual_leave_quota' => $quota,
            'used_days' => $used,
            'remaining_days' => max(0, $quota - $used),
        ];
    }

    /**
     * Why this request cannot be filed as annual leave, or null when it can (or is another type).
     * Messages speak to the worker filing it. With the dates, the quota is checked per calendar year
     * of the leave days and the period may not overlap another pending or approved leave; without
     * them (older callers) the whole request is checked against the current year.
     */
    public function validationError(Employee|Intern|Freelancer|null $profile, ?string $leaveType, int $daysCount, ?int $exceptLeaveRequestId = null, ?CarbonInterface $startDate = null, ?CarbonInterface $endDate = null): ?string
    {
        if (LeaveRequest::normalizeType($leaveType) !== LeaveRequest::TYPE_ANNUAL) {
            return null;
        }

        if ($profile instanceof Freelancer) {
            return 'Freelancer tidak memiliki jatah cuti tahunan. Gunakan jenis Izin.';
        }

        if ($profile instanceof Intern) {
            return 'Peserta magang tidak memiliki jatah cuti tahunan. Gunakan jenis Izin.';
        }

        if (! $this->isEligible($profile)) {
            return 'Anda belum memiliki jatah cuti tahunan. Hubungi HR atau gunakan jenis Izin.';
        }

        if ($startDate && $endDate) {
            if ($error = $this->overlapError($profile, $startDate, $endDate, $exceptLeaveRequestId)) {
                return $error;
            }

            return $this->periodQuotaError($profile, $startDate, $endDate, $exceptLeaveRequestId, lockRows: false, forApprover: false, daysCount: $daysCount);
        }

        $remaining = $this->quota($profile) - $this->usedDays($profile, exceptLeaveRequestId: $exceptLeaveRequestId);

        return $daysCount > $remaining
            ? 'Kuota cuti tahunan Anda tidak mencukupi (Sisa: '.max(0, $remaining)." hari, Pengajuan: {$daysCount} hari)."
            : null;
    }

    /**
     * The same rule for an edit of an existing request. A request that already was annual leave and
     * does not grow keeps its approval: editing only the reason or attachment of an approved or old
     * request never fails, even when the worker no longer has annual leave. New dates are checked
     * like a new request (per year, no overlap), since they may move the leave into another year.
     */
    public function updateError(LeaveRequest $leave, ?string $newLeaveType, int $newDaysCount, ?CarbonInterface $newStartDate = null, ?CarbonInterface $newEndDate = null): ?string
    {
        $wasAnnual = LeaveRequest::normalizeType($leave->leave_type) === LeaveRequest::TYPE_ANNUAL;
        $datesChanged = ($newStartDate && $newStartDate->toDateString() !== $leave->start_date?->toDateString())
            || ($newEndDate && $newEndDate->toDateString() !== $leave->end_date?->toDateString());

        if ($wasAnnual && ! $datesChanged && $newDaysCount <= (int) $leave->days_count && LeaveRequest::normalizeType($newLeaveType ?? $leave->leave_type) === LeaveRequest::TYPE_ANNUAL) {
            return null;
        }

        return $this->validationError($leave->getWorkerProfile(), $newLeaveType ?? $leave->leave_type, $newDaysCount, $leave->getKey(), $newStartDate, $newEndDate);
    }

    /**
     * Why an approver cannot give the final approval to this request, or null. Requests filed before
     * annual leave became per employee, or before HR turned it off, are caught here, and so are
     * requests that no longer fit the quota of a year they touch or overlap an already approved leave
     * of the worker (overlapping pending requests filed before the overlap rule are settled by
     * whichever is approved first). `$lockRows` makes the quota read see the latest committed approvals;
     * the caller holds the worker lock (see ApprovalFlowService::approveStep).
     */
    public function approvalError(LeaveRequest $leave, bool $lockRows = false): ?string
    {
        if (LeaveRequest::normalizeType($leave->leave_type) !== LeaveRequest::TYPE_ANNUAL) {
            return null;
        }

        $profile = $leave->getWorkerProfile();
        $name = $profile?->full_name ?? 'Pekerja ini';

        if ($profile instanceof Freelancer || $profile instanceof Intern) {
            $kind = $profile instanceof Intern ? 'peserta magang' : 'freelancer';

            return "{$name} adalah {$kind} dan tidak memiliki jatah cuti tahunan. Tolak pengajuan ini dan minta diajukan ulang sebagai Izin.";
        }

        if (! $this->isEligible($profile)) {
            return "{$name} tidak memiliki jatah cuti tahunan. Tolak pengajuan ini, atau aktifkan cuti tahunan di data karyawannya terlebih dahulu.";
        }

        $chargedDates = is_array($leave->charged_dates) ? $leave->chargedDateStrings() : null;

        if ($error = $this->periodQuotaError($profile, $leave->start_date, $leave->end_date, $leave->getKey(), $lockRows, forApprover: true, daysCount: (int) $leave->days_count, chargedDates: $chargedDates)) {
            return $error;
        }

        if ($overlap = $this->overlappingLeave($profile, $leave->start_date, $leave->end_date, $leave->getKey(), statuses: ['approved'])) {
            return "{$name} sudah memiliki pengajuan ".LeaveRequest::typeLabel($overlap->leave_type).' pada '.self::periodLabel($overlap).' yang bertabrakan dengan cuti ini. Tolak pengajuan ini.';
        }

        return null;
    }
}
