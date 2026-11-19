<?php

namespace App\Services;

use App\Enums\SystemRole;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Rules shared by every way an overtime request is filed or changed (API, web panel):
 *
 * - The duration is always derived from the start and end time. An end time before the start time
 *   means the overtime runs past midnight; equal times are invalid; at most 12 hours.
 * - The date lies at most 31 days back or 31 days ahead.
 * - A worker never holds two pending or approved overtime requests whose hours overlap, also when one
 *   of them runs past midnight into the next day.
 * - Only a manager who may approve the worker's requests records the actual duration, never the
 *   requester, and only on approved overtime.
 * - No overtime on a date an approved leave of the worker is charged for.
 * - What a request counts for in the reports ({@see self::netMinutes()}) comes from the attendance ledger:
 *   from the company's netting date on, the part of a request inside the worker's shift is not counted twice.
 */
class OvertimeRequestService
{
    public const MAX_DURATION_MINUTES = 720;

    public const MAX_DAYS_BACK = 31;

    public const MAX_DAYS_AHEAD = 31;

    /**
     * Statuses that hold a worker's hours: a rejected request frees them again.
     */
    public const BLOCKING_STATUSES = ['pending', 'approved'];

    /**
     * Roles that may record the actual duration, always limited to workers inside their data access.
     */
    public const ACTUAL_DURATION_ROLES = ['Superadmin', 'Admin', 'Koordinator', 'Approver', 'BOD'];

    public const ON_LEAVE_MESSAGE = 'Tidak bisa lembur pada tanggal cuti/izin yang sudah disetujui.';

    /**
     * Counted minutes read for a list by {@see self::preloadNetMinutes()}, kept until the model is released.
     *
     * @var \WeakMap<OvertimeRequest, int|null>|null
     */
    private static ?\WeakMap $netMinutes = null;

    /**
     * The minutes the request counts for in the reports, or null while the worker's company does not net
     * requests against shift hours on that date (the request then counts in full). The figure is the
     * ledger's ({@see AttendanceLedger::netOvertimeMinutes()}), so it is the one the recaps add up.
     */
    public function netMinutes(OvertimeRequest $overtime): ?int
    {
        if (self::$netMinutes !== null && self::$netMinutes->offsetExists($overtime)) {
            return self::$netMinutes[$overtime];
        }

        return app(AttendanceLedger::class)->netOvertimeMinutes([$overtime])[(int) $overtime->getKey()] ?? null;
    }

    /**
     * Read the counted minutes of a whole list at once, so showing it does not ask per row.
     *
     * @param  iterable<mixed>  $overtimes
     */
    public static function preloadNetMinutes(iterable $overtimes): void
    {
        $rows = [];

        foreach ($overtimes as $overtime) {
            if ($overtime instanceof OvertimeRequest && $overtime->exists) {
                $rows[(int) $overtime->getKey()] = $overtime;
            }
        }

        if ($rows === []) {
            return;
        }

        self::$netMinutes ??= new \WeakMap;

        foreach (app(AttendanceLedger::class)->netOvertimeMinutes($rows) as $id => $minutes) {
            self::$netMinutes[$rows[$id]] = $minutes;
        }
    }

    /**
     * Error message when $date is charged by an approved leave of the worker, else null.
     */
    public static function leaveDateError(Employee|Intern|Freelancer $worker, mixed $date): ?string
    {
        if (! is_string($date) && ! $date instanceof \DateTimeInterface) {
            return null;
        }

        try {
            $day = Carbon::parse($date)->toDateString();
        } catch (\Throwable) {
            return null;
        }

        $onLeave = LeaveRequest::query()
            ->where(self::workerColumn($worker), $worker->getKey())
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $day)
            ->whereDate('end_date', '>=', $day)
            ->get()
            ->contains(fn (LeaveRequest $leave): bool => in_array($day, $leave->chargedDateStrings(), true));

        return $onLeave ? self::ON_LEAVE_MESSAGE : null;
    }

    /**
     * @throws ValidationException
     */
    public function assertNotOnLeave(Employee|Intern|Freelancer $worker, mixed $date, string $dateKey = 'date'): void
    {
        $error = self::leaveDateError($worker, $date);

        if ($error !== null) {
            throw ValidationException::withMessages([$dateKey => $error]);
        }
    }

    /**
     * Minutes between start and end, null when the times are unreadable or equal.
     */
    public static function durationMinutes(mixed $start, mixed $end): ?int
    {
        $startTime = self::parseTime($start);
        $endTime = self::parseTime($end);

        if ($startTime === null || $endTime === null || $startTime->equalTo($endTime)) {
            return null;
        }

        if ($endTime->lessThan($startTime)) {
            $endTime = $endTime->addDay();
        }

        return (int) $startTime->diffInMinutes($endTime);
    }

    /**
     * The duration of a valid time range, or a validation error under $timeKey.
     *
     * @throws ValidationException
     */
    public function validatedDuration(mixed $start, mixed $end, string $timeKey = 'end_time'): int
    {
        $minutes = self::durationMinutes($start, $end);

        if ($minutes === null) {
            throw ValidationException::withMessages([
                $timeKey => 'Jam selesai lembur tidak boleh sama dengan jam mulai.',
            ]);
        }

        if ($minutes > self::MAX_DURATION_MINUTES) {
            throw ValidationException::withMessages([
                $timeKey => 'Durasi lembur maksimal 12 jam.',
            ]);
        }

        return $minutes;
    }

    /**
     * Error message when the overtime date lies outside today minus/plus 31 days, else null.
     */
    public static function dateWindowError(mixed $date): ?string
    {
        if (! is_string($date) && ! $date instanceof \DateTimeInterface) {
            return null;
        }

        try {
            $day = Carbon::parse($date)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        if ($day->lessThan(today()->subDays(self::MAX_DAYS_BACK))) {
            return 'Tanggal lembur paling lama '.self::MAX_DAYS_BACK.' hari ke belakang.';
        }

        if ($day->greaterThan(today()->addDays(self::MAX_DAYS_AHEAD))) {
            return 'Tanggal lembur paling jauh '.self::MAX_DAYS_AHEAD.' hari ke depan.';
        }

        return null;
    }

    /**
     * @throws ValidationException
     */
    public function assertDateWithinWindow(mixed $date, string $dateKey = 'date'): void
    {
        $error = self::dateWindowError($date);

        if ($error !== null) {
            throw ValidationException::withMessages([$dateKey => $error]);
        }
    }

    /**
     * Error message when the worker may not hold overtime on $date, else null. Overtime is work, so it
     * only falls inside the contract: a worker whose status ended gets nothing, and an intern or freelancer
     * may not have overtime on a day after the contract's end date (check-in refuses that day as well).
     * Without a readable $date only the status is checked. $addressesWorker words the message to the
     * worker themself (mobile), otherwise to the manager on the web panel.
     */
    public static function contractError(Employee|Intern|Freelancer $worker, mixed $date = null, bool $addressesWorker = false): ?string
    {
        if (in_array($worker->status, WorkerAccountService::ENDED_STATUSES, true)) {
            return $addressesWorker
                ? WorkerAccountService::INACTIVE_MESSAGE
                : 'Status kerja pekerja ini tidak aktif, lemburnya tidak bisa diubah.';
        }

        if ($worker instanceof Employee || $worker->end_date === null) {
            return null;
        }

        if (! is_string($date) && ! $date instanceof \DateTimeInterface) {
            return null;
        }

        try {
            $day = Carbon::parse($date)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        if ($day->greaterThan($worker->end_date)) {
            return 'Tanggal lembur melewati akhir kontrak'.($addressesWorker ? ' Anda' : ' pekerja').' ('.$worker->end_date->format('d/m/Y').').';
        }

        return null;
    }

    /**
     * @throws ValidationException
     */
    public function assertWithinContract(Employee|Intern|Freelancer $worker, mixed $date = null, string $dateKey = 'date', bool $addressesWorker = false): void
    {
        $error = self::contractError($worker, $date, $addressesWorker);

        if ($error !== null) {
            throw ValidationException::withMessages([$dateKey => $error]);
        }
    }

    /**
     * Lock the worker's profile row for the rest of the transaction, so two submissions of the same worker
     * at the same time check for overlaps one after the other instead of both passing. Returns the freshly
     * read row (its current status and contract end), or $worker itself when the row is gone.
     */
    public function lockWorker(Employee|Intern|Freelancer $worker): Employee|Intern|Freelancer
    {
        return $worker->newQuery()->whereKey($worker->getKey())->lockForUpdate()->first() ?? $worker;
    }

    /**
     * The worker's pending or approved overtime whose hours overlap $date $start-$end, if any.
     */
    public function findOverlap(Employee|Intern|Freelancer $worker, mixed $date, mixed $start, mixed $end, ?int $ignoreId = null): ?OvertimeRequest
    {
        $range = self::range($date, $start, $end);

        if ($range === null) {
            return null;
        }

        [$from, $until] = $range;

        // An overtime of the day before may run past midnight into this one, and this one into the next day.
        return OvertimeRequest::query()
            ->where(self::workerColumn($worker), $worker->getKey())
            ->whereIn('status', self::BLOCKING_STATUSES)
            ->whereDate('date', '>=', $from->subDay()->toDateString())
            ->whereDate('date', '<=', $from->addDay()->toDateString())
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->orderBy('date')
            ->orderBy('start_time')
            ->get()
            ->first(function (OvertimeRequest $existing) use ($from, $until): bool {
                $other = self::range($existing->date, $existing->start_time, $existing->end_time);

                return $other !== null && $other[0]->lessThan($until) && $from->lessThan($other[1]);
            });
    }

    /**
     * @throws ValidationException
     */
    public function assertNoOverlap(Employee|Intern|Freelancer $worker, mixed $date, mixed $start, mixed $end, ?int $ignoreId = null, string $timeKey = 'start_time'): void
    {
        $overlap = $this->findOverlap($worker, $date, $start, $end, $ignoreId);

        if ($overlap === null) {
            return;
        }

        $status = $overlap->status === 'approved' ? 'sudah disetujui' : 'menunggu persetujuan';

        throw ValidationException::withMessages([
            $timeKey => sprintf(
                'Jam lembur bentrok dengan pengajuan lembur lain pada %s pukul %s–%s (%s).',
                $overlap->date->format('d/m/Y'),
                substr((string) $overlap->start_time, 0, 5),
                substr((string) $overlap->end_time, 0, 5),
                $status,
            ),
        ]);
    }

    /**
     * Whether $user may record the actual duration of this overtime: approved overtime only, never the
     * requester, and only a manager or approver whose data access covers the worker.
     */
    public static function canFillActualDuration(OvertimeRequest $overtime, ?User $user): bool
    {
        if ($user === null || $overtime->status !== 'approved' || $user->is_active === false) {
            return false;
        }

        $worker = $overtime->getWorkerProfile();

        if ($worker === null || (int) $worker->user_id === (int) $user->getKey()) {
            return false;
        }

        if ($user->hasRole(SystemRole::Superadmin->value)) {
            return true;
        }

        if (! $user->hasAnyRole(self::ACTUAL_DURATION_ROLES)) {
            return false;
        }

        if ($user->tenant_id !== null && $worker->company?->tenant_id !== null && (int) $worker->company->tenant_id !== (int) $user->tenant_id) {
            return false;
        }

        return DataAccessService::canAccessPlacement($user, $worker->company_id, $worker->division_id);
    }

    /**
     * Record the actual duration (in minutes) of an approved overtime.
     *
     * @throws ValidationException
     */
    public function fillActualDuration(OvertimeRequest $overtime, User $user, mixed $minutes, string $key = 'actual_duration_minutes'): void
    {
        if (! self::canFillActualDuration($overtime, $user)) {
            throw ValidationException::withMessages([
                $key => 'Anda tidak berhak mengisi durasi aktual lembur ini.',
            ]);
        }

        if (! is_numeric($minutes) || (int) $minutes != $minutes || (int) $minutes <= 0 || (int) $minutes > self::MAX_DURATION_MINUTES) {
            throw ValidationException::withMessages([
                $key => 'Durasi aktual harus antara 1 dan '.self::MAX_DURATION_MINUTES.' menit (maksimal 12 jam).',
            ]);
        }

        $overtime->forceFill(['actual_duration_minutes' => (int) $minutes])->save();
    }

    /**
     * The foreign key column of the worker type on overtime_requests.
     */
    public static function workerColumn(Model $worker): string
    {
        return match (true) {
            $worker instanceof Employee => 'employee_id',
            $worker instanceof Intern => 'intern_id',
            default => 'freelancer_id',
        };
    }

    /**
     * Absolute start and end moments of an overtime, the end moved to the next day when it runs past midnight.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private static function range(mixed $date, mixed $start, mixed $end): ?array
    {
        $startTime = self::parseTime($start);
        $endTime = self::parseTime($end);

        if ($startTime === null || $endTime === null || $startTime->equalTo($endTime)) {
            return null;
        }

        try {
            $day = CarbonImmutable::parse($date instanceof \DateTimeInterface ? $date->format('Y-m-d') : (string) $date)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        $from = $day->setTime($startTime->hour, $startTime->minute);
        $until = $day->setTime($endTime->hour, $endTime->minute);

        if ($until->lessThanOrEqualTo($from)) {
            $until = $until->addDay();
        }

        return [$from, $until];
    }

    private static function parseTime(mixed $time): ?CarbonImmutable
    {
        if ($time instanceof \DateTimeInterface) {
            $time = $time->format('H:i');
        }

        if (! is_string($time) || preg_match('/^([01]\d|2[0-3]):([0-5]\d)/', $time, $matches) !== 1) {
            return null;
        }

        return CarbonImmutable::today()->setTime((int) $matches[1], (int) $matches[2]);
    }
}
