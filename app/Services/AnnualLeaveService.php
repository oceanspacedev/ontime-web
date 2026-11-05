<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\LeaveRequest;

/**
 * Who may take annual leave, and how much is left. The panel, the API and the dashboard all ask
 * this class, so the rule lives in one place:
 *
 * - Interns and freelancers never have annual leave.
 * - An employee has it only when HR turned it on (`employees.has_annual_leave`).
 * - The yearly quota is the employee's own quota, or the company policy quota when it is empty.
 * - Used days are approved annual leave that starts in the current year.
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
     * Approved annual leave days starting in the year. `$exceptLeaveRequestId` leaves out the
     * request being edited or approved, so it is never counted against itself.
     */
    public function usedDays(Employee|Intern|Freelancer|null $profile, ?int $year = null, ?int $exceptLeaveRequestId = null): int
    {
        if (! $profile instanceof Employee) {
            return 0;
        }

        return (int) LeaveRequest::query()
            ->where('employee_id', $profile->getKey())
            ->where('leave_type', LeaveRequest::TYPE_ANNUAL)
            ->where('status', 'approved')
            ->whereYear('start_date', $year ?? now()->year)
            ->when($exceptLeaveRequestId, fn ($query) => $query->whereKeyNot($exceptLeaveRequestId))
            ->sum('days_count');
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
     * Messages speak to the worker filing it.
     */
    public function validationError(Employee|Intern|Freelancer|null $profile, ?string $leaveType, int $daysCount, ?int $exceptLeaveRequestId = null): ?string
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

        $remaining = $this->quota($profile) - $this->usedDays($profile, exceptLeaveRequestId: $exceptLeaveRequestId);

        return $daysCount > $remaining
            ? 'Kuota cuti tahunan Anda tidak mencukupi (Sisa: '.max(0, $remaining)." hari, Pengajuan: {$daysCount} hari)."
            : null;
    }

    /**
     * The same rule for an edit of an existing request. A request that already was annual leave and
     * does not grow keeps its approval: editing only the reason or attachment of an approved or old
     * request never fails, even when the worker no longer has annual leave.
     */
    public function updateError(LeaveRequest $leave, ?string $newLeaveType, int $newDaysCount): ?string
    {
        $wasAnnual = LeaveRequest::normalizeType($leave->leave_type) === LeaveRequest::TYPE_ANNUAL;

        if ($wasAnnual && $newDaysCount <= (int) $leave->days_count && LeaveRequest::normalizeType($newLeaveType ?? $leave->leave_type) === LeaveRequest::TYPE_ANNUAL) {
            return null;
        }

        return $this->validationError($leave->getWorkerProfile(), $newLeaveType ?? $leave->leave_type, $newDaysCount, $leave->getKey());
    }

    /**
     * Why an approver cannot give the final approval to this request, or null. Requests filed before
     * annual leave became per employee, or before HR turned it off, are caught here.
     */
    public function approvalError(LeaveRequest $leave): ?string
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

        $remaining = $this->quota($profile) - $this->usedDays($profile, exceptLeaveRequestId: $leave->getKey());
        $days = (int) $leave->days_count;

        return $days > $remaining
            ? "Sisa kuota cuti tahunan {$name} tidak mencukupi (Sisa: ".max(0, $remaining)." hari, Pengajuan: {$days} hari)."
            : null;
    }
}
