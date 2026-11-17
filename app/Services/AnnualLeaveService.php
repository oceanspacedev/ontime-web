<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\LeaveRequest;
use Carbon\Carbon;
use Carbon\CarbonInterface;

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
 */
class AnnualLeaveService
{
    public const DEFAULT_QUOTA = 12;

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
            ->get(['id', 'start_date', 'end_date', 'days_count'])
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
     * Days of an approved request charged to the year. A request inside one year keeps its stored
     * day count; one crossing the new year is split by calendar days.
     */
    private function daysInYear(LeaveRequest $leave, int $year): int
    {
        if ($leave->start_date->year === $leave->end_date->year) {
            return $leave->start_date->year === $year ? (int) $leave->days_count : 0;
        }

        return $this->daysPerYear($leave->start_date, $leave->end_date)[$year] ?? 0;
    }

    /**
     * Why the period does not fit the remaining quota of each year it touches, or null. A period
     * inside one year is charged `$daysCount` when given (the stored count of an existing request).
     * Messages speak to the worker, or name the worker for an approver; the year is named only when
     * it is not the current one.
     */
    private function periodQuotaError(Employee $profile, CarbonInterface $startDate, CarbonInterface $endDate, ?int $exceptLeaveRequestId, bool $lockRows, bool $forApprover, ?int $daysCount = null): ?string
    {
        $perYear = $this->daysPerYear($startDate, $endDate);

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

        if ($error = $this->periodQuotaError($profile, $leave->start_date, $leave->end_date, $leave->getKey(), $lockRows, forApprover: true, daysCount: (int) $leave->days_count)) {
            return $error;
        }

        if ($overlap = $this->overlappingLeave($profile, $leave->start_date, $leave->end_date, $leave->getKey(), statuses: ['approved'])) {
            return "{$name} sudah memiliki pengajuan ".LeaveRequest::typeLabel($overlap->leave_type).' pada '.self::periodLabel($overlap).' yang bertabrakan dengan cuti ini. Tolak pengajuan ini.';
        }

        return null;
    }
}
