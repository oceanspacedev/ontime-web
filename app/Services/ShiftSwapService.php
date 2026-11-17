<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\Resignation;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\ShiftSwapRequest;
use App\Models\User;
use App\Notifications\ShiftSwapUpdatedNotification;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tukar Shift: checks, the colleague's answer, cancelling, expiry and applying an approved swap.
 *
 * A swap trades the whole assignment of the two workers on every affected date (one date for a same-day swap
 * or a give away, two for "kamu ambil Selasa saya, saya ambil Kamis kamu"). The assignment of a worker on a
 * date (a cell) is what check-in would use: the dated schedule (a shift or a day off), else the worker's
 * default shift. Cells are snapshot when the request is made; the final approval only applies the swap when
 * every cell is still the same, nobody checked in on those dates and no date has passed.
 */
class ShiftSwapService
{
    public const STALE_MESSAGE = 'Jadwal salah satu pekerja sudah berubah sejak pengajuan tukar shift dibuat. Ajukan ulang dengan jadwal terbaru.';

    public const EXPIRED_MESSAGE = 'Tanggal tukar shift sudah lewat.';

    /** Same as AttendanceCheckService: the first check-in of a shift opens this many minutes before it starts. */
    public const CHECK_IN_OPENS_MINUTES_BEFORE = 60;

    /**
     * The worker profile of the account, as every other request resolves it.
     */
    public function requesterProfileOf(User $user): Employee|Intern|Freelancer|null
    {
        return $user->employee ?? $user->intern ?? $user->freelancer;
    }

    /**
     * Find a colleague by worker type and id.
     */
    public function findWorker(string $type, int $id): Employee|Intern|Freelancer|null
    {
        return match ($type) {
            'employee' => Employee::query()->find($id),
            'intern' => Intern::query()->find($id),
            'freelancer' => Freelancer::query()->find($id),
            default => null,
        };
    }

    /**
     * The worker type key used by the API ("employee", "intern", "freelancer").
     */
    public static function workerType(Employee|Intern|Freelancer $worker): string
    {
        return match (true) {
            $worker instanceof Employee => 'employee',
            $worker instanceof Intern => 'intern',
            default => 'freelancer',
        };
    }

    /**
     * Whether the worker may still work (on the date, when given): not ended, their account (when they have
     * one) active, and on that date neither past the contract end date nor past the last working day of an
     * approved resignation of the current employment.
     */
    public function isActiveWorker(Employee|Intern|Freelancer $worker, ?CarbonInterface $onDate = null): bool
    {
        if (in_array((string) $worker->status, WorkerAccountService::ENDED_STATUSES, true)) {
            return false;
        }

        if ($worker->user_id && $worker->user?->is_active === false) {
            return false;
        }

        if (! $onDate) {
            return true;
        }

        $endDate = $worker->getAttribute('end_date');

        if ($endDate && Carbon::parse($endDate)->lt($onDate->copy()->startOfDay())) {
            return false;
        }

        return ! ($worker instanceof Employee && $this->resignsBefore($worker, $onDate));
    }

    /**
     * Whether the employee has an approved resignation of the current employment whose last working day
     * falls before the date.
     */
    private function resignsBefore(Employee $employee, CarbonInterface $date): bool
    {
        $accounts = app(WorkerAccountService::class);

        return $employee->resignations()
            ->where('status', 'approved')
            ->whereNotNull('last_working_day')
            ->whereDate('last_working_day', '<', $date->toDateString())
            ->get()
            ->contains(fn (Resignation $resignation): bool => $accounts->resignationIsCurrent($resignation->setRelation('employee', $employee)));
    }

    /**
     * The assignment of a worker on a date: a day off, or a shift (with the outlet and section of that day).
     * The shift falls back to the worker's default shift when the date has no scheduled shift.
     *
     * Mirrors WorkScheduleService::isOffSchedule: a freelancer only works when rostered, so a freelancer with
     * no shift that day (no row, or a row without a shift, and no default shift) is off.
     *
     * @return array{off: bool, shift_id: int|null, company_location_id: int|null, section: string|null, schedule_id: int|null}
     */
    public function cellState(Employee|Intern|Freelancer $worker, ?ShiftSchedule $schedule): array
    {
        $shiftId = $schedule?->shift_id ?? $worker->shift_id;

        if ($schedule?->is_day_off || ($worker instanceof Freelancer && $shiftId === null)) {
            return ['off' => true, 'shift_id' => null, 'company_location_id' => null, 'section' => null, 'schedule_id' => $schedule ? (int) $schedule->id : null];
        }

        return [
            'off' => false,
            'shift_id' => $shiftId !== null ? (int) $shiftId : null,
            'company_location_id' => $schedule?->company_location_id !== null ? (int) $schedule->company_location_id : null,
            'section' => $schedule?->section,
            'schedule_id' => $schedule ? (int) $schedule->id : null,
        ];
    }

    /**
     * The schedule rows of the worker on the dates, keyed by date. Locked when $lock is true (inside a transaction).
     *
     * @param  list<string>  $dates
     * @return Collection<string, ShiftSchedule>
     */
    private function schedulesOf(Employee|Intern|Freelancer $worker, array $dates, bool $lock = false): Collection
    {
        $column = app(WorkScheduleService::class)->workerColumn($worker);

        return ShiftSchedule::query()
            ->where($column, $worker->getKey())
            ->where(fn ($query) => collect($dates)->each(fn (string $date) => $query->orWhereDate('date', $date)))
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get()
            ->keyBy(fn (ShiftSchedule $schedule): string => $schedule->date->toDateString());
    }

    /**
     * Every cell the swap touches: the assignment of both workers on every affected date.
     *
     * @param  list<string>  $dates
     * @return array<string, array{off: bool, shift_id: int|null, company_location_id: int|null, section: string|null, schedule_id: int|null}>
     */
    public function snapshot(Employee|Intern|Freelancer $requester, Employee|Intern|Freelancer $counterpart, array $dates, bool $lock = false): array
    {
        return $this->cellsFrom($requester, $counterpart, $dates, $this->schedulesOf($requester, $dates, $lock), $this->schedulesOf($counterpart, $dates, $lock));
    }

    /**
     * The cells built from schedule rows already read (the locked rows, at the final approval).
     *
     * @param  list<string>  $dates
     * @param  Collection<string, ShiftSchedule>  $requesterSchedules
     * @param  Collection<string, ShiftSchedule>  $counterpartSchedules
     * @return array<string, array{off: bool, shift_id: int|null, company_location_id: int|null, section: string|null, schedule_id: int|null}>
     */
    private function cellsFrom(Employee|Intern|Freelancer $requester, Employee|Intern|Freelancer $counterpart, array $dates, Collection $requesterSchedules, Collection $counterpartSchedules): array
    {
        $cells = [];

        foreach ($dates as $date) {
            $cells["requester|{$date}"] = $this->cellState($requester, $requesterSchedules->get($date));
            $cells["counterpart|{$date}"] = $this->cellState($counterpart, $counterpartSchedules->get($date));
        }

        return $cells;
    }

    /**
     * Why the swap may not be made (null when it may), checked when it is filed and again when the colleague
     * accepts.
     *
     * @param  list<string>  $dates
     */
    public function eligibilityError(
        Employee|Intern|Freelancer $requester,
        Employee|Intern|Freelancer $counterpart,
        string $type,
        CarbonInterface $requesterDate,
        ?CarbonInterface $counterpartDate,
        ?int $ignoreRequestId = null,
    ): ?string {
        $dates = $this->datesFor($type, $requesterDate, $counterpartDate);

        if (collect($dates)->contains(fn (string $date): bool => Carbon::parse($date)->lt(today()))) {
            return 'Tukar shift hanya bisa untuk hari ini atau tanggal yang akan datang.';
        }

        if ($requester->is($counterpart)) {
            return 'Pilih rekan kerja lain untuk bertukar shift.';
        }

        if (! $requester->company_id || (int) $requester->company_id !== (int) $counterpart->company_id) {
            return 'Tukar shift hanya bisa dengan rekan di badan usaha yang sama.';
        }

        foreach ($dates as $date) {
            if (! $this->isActiveWorker($requester, Carbon::parse($date))) {
                return 'Akun Anda sudah tidak aktif pada tanggal tukar shift.';
            }

            if (! $this->isActiveWorker($counterpart, Carbon::parse($date))) {
                return "{$counterpart->full_name} sudah tidak aktif pada tanggal tukar shift.";
            }
        }

        if (! $counterpart->user_id) {
            return "{$counterpart->full_name} belum punya akun aplikasi, jadi tidak bisa menyetujui tukar shift.";
        }

        $cells = $this->snapshot($requester, $counterpart, $dates);

        if ($error = $this->scheduleError($requester, $counterpart, $type, $requesterDate, $counterpartDate, $cells)) {
            return $error;
        }

        if ($error = $this->checkedInError($requester, $counterpart, $dates)) {
            return $error;
        }

        if ($error = $this->startedError($counterpart, $cells)) {
            return $error;
        }

        if ($error = $this->leaveError($requester, $counterpart, $dates)) {
            return $error;
        }

        if ($error = $this->overtimeError($requester, $counterpart, $dates, $cells)) {
            return $error;
        }

        $clash = ShiftSwapRequest::query()
            ->whereIn('status', ShiftSwapRequest::OPEN_STATUSES)
            ->when($ignoreRequestId, fn ($query) => $query->whereKeyNot($ignoreRequestId))
            ->where(fn ($query) => $this->whereInvolvesWorker($query, $requester)->orWhere(fn ($other) => $this->whereInvolvesWorker($other, $counterpart)))
            ->get()
            ->first(fn (ShiftSwapRequest $open): bool => array_intersect($open->affectedDates(), $dates) !== []);

        if ($clash) {
            return 'Sudah ada pengajuan tukar shift lain yang masih berjalan untuk tanggal tersebut. Batalkan atau tunggu pengajuan itu selesai.';
        }

        return null;
    }

    /**
     * The dates a swap touches, earliest first.
     *
     * @return list<string>
     */
    public function datesFor(string $type, CarbonInterface $requesterDate, ?CarbonInterface $counterpartDate): array
    {
        $dates = [$requesterDate->toDateString()];

        if ($type === ShiftSwapRequest::TYPE_SWAP) {
            $dates[] = ($counterpartDate ?? $requesterDate)->toDateString();
        }

        $dates = array_values(array_unique($dates));
        sort($dates);

        return $dates;
    }

    /**
     * Schedule rules: the requester works the date they give, the colleague works the date they give (a swap)
     * or is off that day (a give away), every moved assignment is a shift or a day off, and the swap changes
     * something.
     *
     * @param  array<string, array{off: bool, shift_id: int|null, company_location_id: int|null, section: string|null, schedule_id: int|null}>  $cells
     */
    private function scheduleError(
        Employee|Intern|Freelancer $requester,
        Employee|Intern|Freelancer $counterpart,
        string $type,
        CarbonInterface $requesterDate,
        ?CarbonInterface $counterpartDate,
        array $cells,
    ): ?string {
        $requesterDay = $requesterDate->toDateString();
        $own = $cells["requester|{$requesterDay}"];

        if ($own['off'] || $own['shift_id'] === null) {
            return 'Anda tidak punya jadwal shift pada '.$this->formatDate($requesterDay).'.';
        }

        if ($type === ShiftSwapRequest::TYPE_GIVE_AWAY) {
            if (! $cells["counterpart|{$requesterDay}"]['off']) {
                return "{$counterpart->full_name} tidak libur pada ".$this->formatDate($requesterDay).', jadi tidak bisa mengambil alih shift Anda. Pilih tukar shift.';
            }
        } else {
            $theirDay = ($counterpartDate ?? $requesterDate)->toDateString();
            $theirs = $cells["counterpart|{$theirDay}"];

            if ($theirs['off'] || $theirs['shift_id'] === null) {
                return "{$counterpart->full_name} tidak punya jadwal shift pada ".$this->formatDate($theirDay).'.';
            }
        }

        foreach ($cells as $key => $cell) {
            if (! $cell['off'] && $cell['shift_id'] === null) {
                $name = str_starts_with($key, 'requester') ? 'Anda' : $counterpart->full_name;

                return "Jadwal {$name} pada ".$this->formatDate(explode('|', $key)[1]).' belum diatur. Minta koordinator mengatur jadwalnya dulu.';
            }
        }

        $changesSomething = collect($cells)
            ->keys()
            ->filter(fn (string $key): bool => str_starts_with($key, 'requester|'))
            ->contains(fn (string $key): bool => ! $this->sameAssignment($cells[$key], $cells['counterpart|'.explode('|', $key)[1]]));

        if (! $changesSomething) {
            return "Jadwal Anda dan {$counterpart->full_name} pada tanggal tersebut sama, tidak ada yang perlu ditukar.";
        }

        return null;
    }

    /**
     * Whether two cells hold the same assignment (the row they came from does not matter).
     *
     * @param  array{off: bool, shift_id: int|null, company_location_id: int|null, section: string|null, schedule_id: int|null}  $first
     * @param  array{off: bool, shift_id: int|null, company_location_id: int|null, section: string|null, schedule_id: int|null}  $second
     */
    private function sameAssignment(array $first, array $second): bool
    {
        return $first['off'] === $second['off']
            && $first['shift_id'] === $second['shift_id']
            && $first['company_location_id'] === $second['company_location_id']
            && $first['section'] === $second['section'];
    }

    /**
     * Refused once either worker checked in (any segment, also one waiting for geofence approval) on a date.
     *
     * With $lock the read is a locking one, so inside the final approval it sees the latest committed rows
     * (not the transaction's older snapshot) and a check-in committed meanwhile is not missed.
     *
     * @param  list<string>  $dates
     */
    private function checkedInError(Employee|Intern|Freelancer $requester, Employee|Intern|Freelancer $counterpart, array $dates, bool $lock = false): ?string
    {
        foreach ([$requester, $counterpart] as $worker) {
            $column = app(WorkScheduleService::class)->workerColumn($worker);

            $checkedIn = Attendance::query()
                ->where($column, $worker->getKey())
                ->whereNotNull('check_in')
                ->where(fn ($query) => collect($dates)->each(fn (string $date) => $query->orWhereDate('date', $date)))
                ->when($lock, fn ($query) => $query->lockForUpdate())
                ->get(['date'])
                ->first()
                ?->date;

            if ($checkedIn) {
                $name = $worker->is($requester) ? 'Anda' : $worker->full_name;

                return "{$name} sudah presensi pada ".$this->formatDate(Carbon::parse($checkedIn)->toDateString()).', jadwalnya tidak bisa ditukar lagi.';
            }
        }

        return null;
    }

    /**
     * Refused once a shift that would move has started, or its check-in already opened
     * (CHECK_IN_OPENS_MINUTES_BEFORE). Otherwise a worker who missed today's shift could hand it, already
     * over, to a colleague and erase their own absence. The start is taken on the schedule's own date, so an
     * overnight shift counts from its business date.
     *
     * @param  array<string, array{off: bool, shift_id: int|null, company_location_id: int|null, section: string|null, schedule_id: int|null}>  $cells
     */
    private function startedError(Employee|Intern|Freelancer $counterpart, array $cells): ?string
    {
        $shifts = Shift::query()->whereIn('id', collect($cells)->pluck('shift_id')->filter()->unique()->values())->get()->keyBy('id');

        foreach ($cells as $key => $cell) {
            $shift = $cell['off'] || $cell['shift_id'] === null ? null : $shifts->get($cell['shift_id']);

            if (! $shift) {
                continue;
            }

            [$side, $date] = explode('|', $key);
            $opensAt = Carbon::parse($date.' '.substr((string) $shift->start_time, 0, 5))->subMinutes(self::CHECK_IN_OPENS_MINUTES_BEFORE);

            if (now()->greaterThanOrEqualTo($opensAt)) {
                $name = $side === 'requester' ? 'Anda' : $counterpart->full_name;

                return "Shift {$name} pada ".$this->formatDate($date).' sudah dimulai atau presensinya sudah dibuka, jadwalnya tidak bisa ditukar lagi.';
            }
        }

        return null;
    }

    /**
     * Refused when either worker has a pending or approved leave (cuti / izin) on an affected date: the swap
     * would hand a shift to someone who is away, or take the cover away from the day they are on leave.
     *
     * @param  list<string>  $dates
     */
    private function leaveError(Employee|Intern|Freelancer $requester, Employee|Intern|Freelancer $counterpart, array $dates, bool $lock = false): ?string
    {
        foreach ([$requester, $counterpart] as $worker) {
            $column = app(WorkScheduleService::class)->workerColumn($worker);

            $leaveDate = LeaveRequest::query()
                ->where($column, $worker->getKey())
                ->whereIn('status', ['pending', 'approved'])
                ->whereDate('start_date', '<=', max($dates))
                ->whereDate('end_date', '>=', min($dates))
                ->when($lock, fn ($query) => $query->lockForUpdate())
                ->get(['start_date', 'end_date'])
                ->flatMap(fn (LeaveRequest $leave): array => array_values(array_filter(
                    $dates,
                    fn (string $date): bool => $date >= $leave->start_date->toDateString() && $date <= $leave->end_date->toDateString(),
                )))
                ->sort()
                ->first();

            if ($leaveDate) {
                $name = $worker->is($requester) ? 'Anda' : $worker->full_name;

                return "{$name} punya pengajuan cuti/izin pada ".$this->formatDate($leaveDate).', jadwalnya tidak bisa ditukar.';
            }
        }

        return null;
    }

    /**
     * Why a worker who gives a working date away (and is off after the swap) still holds pending or approved
     * overtime on it: that overtime belongs to the shift and would be paid on a day off.
     *
     * @param  list<string>  $dates
     * @param  array<string, array<string, mixed>>  $cells
     */
    private function overtimeError(Employee|Intern|Freelancer $requester, Employee|Intern|Freelancer $counterpart, array $dates, array $cells, bool $lock = false): ?string
    {
        foreach (['requester' => $requester, 'counterpart' => $counterpart] as $side => $worker) {
            $otherSide = $side === 'requester' ? 'counterpart' : 'requester';
            $givenAway = array_values(array_filter(
                $dates,
                fn (string $date): bool => ! ($cells["{$side}|{$date}"]['off'] ?? true) && ($cells["{$otherSide}|{$date}"]['off'] ?? false),
            ));

            if ($givenAway === []) {
                continue;
            }

            $overtimeDate = OvertimeRequest::query()
                ->where(app(WorkScheduleService::class)->workerColumn($worker), $worker->getKey())
                ->whereIn('status', ['pending', 'approved'])
                ->where(function ($onDates) use ($givenAway): void {
                    foreach ($givenAway as $date) {
                        $onDates->orWhereDate('date', $date);
                    }
                })
                ->when($lock, fn ($query) => $query->lockForUpdate())
                ->get(['date'])
                ->map(fn (OvertimeRequest $overtime): string => $overtime->date->toDateString())
                ->sort()
                ->first();

            if ($overtimeDate) {
                $name = $worker->is($requester) ? 'Anda' : $worker->full_name;

                return "{$name} punya pengajuan lembur pada ".$this->formatDate($overtimeDate).', jadwalnya tidak bisa diberikan. Batalkan lemburnya dulu.';
            }
        }

        return null;
    }

    /**
     * @param  Builder<ShiftSwapRequest>  $query
     * @return Builder<ShiftSwapRequest>
     */
    private function whereInvolvesWorker($query, Employee|Intern|Freelancer $worker)
    {
        $column = app(WorkScheduleService::class)->workerColumn($worker);

        return $query->where(fn ($either) => $either->where($column, $worker->getKey())->orWhere("counterpart_{$column}", $worker->getKey()));
    }

    /**
     * File a request on behalf of the requester. The colleague is notified once it is saved.
     *
     * @throws ValidationException
     */
    public function create(Employee|Intern|Freelancer $requester, Employee|Intern|Freelancer $counterpart, string $type, CarbonInterface $requesterDate, ?CarbonInterface $counterpartDate, ?string $reason): ShiftSwapRequest
    {
        if ($type === ShiftSwapRequest::TYPE_GIVE_AWAY) {
            $counterpartDate = null;
        } else {
            $counterpartDate ??= $requesterDate;
        }

        $swap = DB::transaction(function () use ($requester, $counterpart, $type, $requesterDate, $counterpartDate, $reason): ShiftSwapRequest {
            // Both profiles locked, so two requests for the same worker are checked one after the other.
            $this->lockProfiles($requester, $counterpart);

            if ($error = $this->eligibilityError($requester, $counterpart, $type, $requesterDate, $counterpartDate)) {
                throw ValidationException::withMessages(['shift_swap' => $error]);
            }

            $requesterColumn = app(WorkScheduleService::class)->workerColumn($requester);
            $counterpartColumn = app(WorkScheduleService::class)->workerColumn($counterpart);

            return ShiftSwapRequest::create([
                'company_id' => $requester->company_id,
                $requesterColumn => $requester->getKey(),
                "counterpart_{$counterpartColumn}" => $counterpart->getKey(),
                'type' => $type,
                'requester_date' => $requesterDate->toDateString(),
                'counterpart_date' => $counterpartDate?->toDateString(),
                'reason' => $reason,
                'schedule_snapshot' => $this->snapshot($requester, $counterpart, $this->datesFor($type, $requesterDate, $counterpartDate)),
                'status' => ShiftSwapRequest::STATUS_AWAITING_COLLEAGUE,
            ]);
        });

        $this->notify($counterpart->user, $swap, 'requested', 'Permintaan tukar shift', "{$requester->full_name} mengajak Anda tukar shift. Buka aplikasi untuk menerima atau menolak.");

        return $swap;
    }

    /**
     * The colleague accepts: the request moves to the company's shift_swap approval flow. A request whose
     * schedules changed in the meantime fails instead.
     *
     * @throws ValidationException
     */
    public function accept(ShiftSwapRequest $swap, User $user): ShiftSwapRequest
    {
        $failure = null;

        $swap = DB::transaction(function () use ($swap, $user, &$failure): ShiftSwapRequest {
            $locked = $this->lockAwaiting($swap, $user);
            $requester = $locked->getWorkerProfile();
            $counterpart = $locked->counterpartProfile();

            if ($failure = $this->closingFailure($locked, $requester, $counterpart)) {
                return $locked;
            }

            if ($error = $this->eligibilityError($requester, $counterpart, $locked->type, $locked->requester_date, $locked->counterpart_date, $locked->id)) {
                $this->markFailed($locked, $error);
                $failure = $error;

                return $locked;
            }

            $locked->update(['counterpart_responded_at' => now()]);

            app(ApprovalFlowService::class)->generateSteps($locked, 'shift_swap');

            return $locked;
        });

        if ($failure !== null) {
            throw ValidationException::withMessages(['shift_swap' => $failure]);
        }

        $this->notify($swap->getWorkerProfile()?->user, $swap, 'accepted', 'Tukar shift diterima rekan', ($swap->counterpartProfile()?->full_name ?? 'Rekan Anda').' menerima ajakan tukar shift. Menunggu persetujuan atasan.');

        return $swap;
    }

    /**
     * The colleague declines: the request ends.
     *
     * @throws ValidationException
     */
    public function decline(ShiftSwapRequest $swap, User $user, ?string $reason = null): ShiftSwapRequest
    {
        $swap = DB::transaction(function () use ($swap, $user, $reason): ShiftSwapRequest {
            $locked = $this->lockAwaiting($swap, $user);

            $locked->update([
                'status' => ShiftSwapRequest::STATUS_DECLINED,
                'rejection_reason' => $reason,
                'counterpart_responded_at' => now(),
            ]);

            return $locked;
        });

        $body = ($swap->counterpartProfile()?->full_name ?? 'Rekan Anda').' menolak ajakan tukar shift'.(filled($reason) ? ": {$reason}" : '.');
        $this->notify($swap->getWorkerProfile()?->user, $swap, 'declined', 'Tukar shift ditolak rekan', $body);

        return $swap;
    }

    /**
     * The requester withdraws the request while it is still open (waiting for the colleague or an approver).
     *
     * @throws ValidationException
     */
    public function cancel(ShiftSwapRequest $swap, User $user): ShiftSwapRequest
    {
        $wasAccepted = false;

        $swap = DB::transaction(function () use ($swap, $user, &$wasAccepted): ShiftSwapRequest {
            $locked = ShiftSwapRequest::query()->lockForUpdate()->find($swap->getKey());

            if (! $locked || ! $locked->isRequestedBy($user)) {
                throw ValidationException::withMessages(['shift_swap' => 'Hanya pengaju yang bisa membatalkan tukar shift ini.']);
            }

            if (! $locked->isOpen()) {
                throw ValidationException::withMessages(['shift_swap' => 'Pengajuan tukar shift ini sudah selesai diproses dan tidak bisa dibatalkan.']);
            }

            if ($locked->isOverdue()) {
                throw ValidationException::withMessages(['shift_swap' => self::EXPIRED_MESSAGE]);
            }

            $wasAccepted = $locked->status === ShiftSwapRequest::STATUS_PENDING;

            $locked->update(['status' => ShiftSwapRequest::STATUS_CANCELLED, 'cancelled_at' => now()]);

            return $locked;
        });

        $this->notify($swap->counterpartProfile()?->user, $swap, 'cancelled', 'Tukar shift dibatalkan', ($swap->getWorkerProfile()?->full_name ?? 'Rekan Anda').' membatalkan pengajuan tukar shift'.($wasAccepted ? ' yang sudah Anda terima.' : '.'));

        return $swap;
    }

    /**
     * Called by ApprovalFlowService on the final approval, inside its transaction and with the request row
     * locked. Re-checks the schedules under row locks and writes the swap. Returns why the swap cannot be
     * applied (the caller marks the request failed or expired) or null once it is applied.
     *
     * @return array{status: string, message: string}|null
     */
    public function applyApproved(ShiftSwapRequest $swap, User $approver): ?array
    {
        $requester = $swap->getWorkerProfile();
        $counterpart = $swap->counterpartProfile();

        if ($failure = $this->closingFailure($swap, $requester, $counterpart, mark: false)) {
            return [
                'status' => $failure === self::EXPIRED_MESSAGE ? ShiftSwapRequest::STATUS_EXPIRED : ShiftSwapRequest::STATUS_FAILED,
                'message' => $failure,
            ];
        }

        $dates = $swap->affectedDates();

        // Everything below is judged on the locked rows (a locking read returns the latest committed version,
        // not the transaction's older snapshot), so a schedule change committed meanwhile is never overwritten.
        [$requester, $counterpart] = $this->lockProfiles($requester, $counterpart);

        if (! $requester || ! $counterpart) {
            return ['status' => ShiftSwapRequest::STATUS_FAILED, 'message' => 'Data pekerja pada pengajuan ini sudah tidak ada.'];
        }

        if ($error = $this->lockedWorkersError($swap, $requester, $counterpart, $dates)) {
            return ['status' => ShiftSwapRequest::STATUS_FAILED, 'message' => $error];
        }

        $requesterSchedules = $this->schedulesOf($requester, $dates, lock: true);
        $counterpartSchedules = $this->schedulesOf($counterpart, $dates, lock: true);
        $current = $this->cellsFrom($requester, $counterpart, $dates, $requesterSchedules, $counterpartSchedules);

        if ($current !== ($swap->schedule_snapshot ?? [])) {
            return ['status' => ShiftSwapRequest::STATUS_FAILED, 'message' => self::STALE_MESSAGE];
        }

        $error = $this->checkedInError($requester, $counterpart, $dates, lock: true)
            ?? $this->startedError($counterpart, $current)
            ?? $this->leaveError($requester, $counterpart, $dates, lock: true)
            ?? $this->overtimeError($requester, $counterpart, $dates, $current, lock: true);

        if ($error) {
            return ['status' => ShiftSwapRequest::STATUS_FAILED, 'message' => $error];
        }

        $snapshot = $swap->schedule_snapshot;

        try {
            // A savepoint: a schedule row inserted concurrently (unique worker + date) undoes every write of
            // this swap, and the request fails as stale instead of answering with a server error.
            DB::transaction(function () use ($dates, $requester, $counterpart, $requesterSchedules, $counterpartSchedules, $snapshot, $approver): void {
                foreach ($dates as $date) {
                    $this->writeCell($requester, $date, $requesterSchedules->get($date), $snapshot["counterpart|{$date}"], "Tukar shift dengan {$counterpart->full_name}", $approver);
                    $this->writeCell($counterpart, $date, $counterpartSchedules->get($date), $snapshot["requester|{$date}"], "Tukar shift dengan {$requester->full_name}", $approver);
                }
            });
        } catch (UniqueConstraintViolationException) {
            return ['status' => ShiftSwapRequest::STATUS_FAILED, 'message' => self::STALE_MESSAGE];
        }

        $swap->update(['applied_at' => now(), 'applied_by' => $approver->id]);

        $this->notify($counterpart->user, $swap, 'approved', 'Tukar shift disetujui', "Tukar shift dengan {$requester->full_name} disetujui. Jadwal Anda sudah diperbarui.");

        return null;
    }

    /**
     * Expire open requests whose earliest date has passed, optionally only within some companies. Run by the
     * shift-swaps:expire command; reads never write (they show effectiveStatus() instead). Returns how many
     * were expired.
     *
     * @param  list<int>|null  $companyIds
     */
    public function expireOverdue(?array $companyIds = null): int
    {
        $expired = 0;

        ShiftSwapRequest::query()
            ->overdue()
            ->when($companyIds !== null, fn ($query) => $query->whereIn('company_id', $companyIds))
            ->pluck('id')
            ->each(function (int $id) use (&$expired): void {
                DB::transaction(function () use ($id, &$expired): void {
                    $swap = ShiftSwapRequest::query()->lockForUpdate()->find($id);

                    if ($swap && $swap->isOpen()) {
                        $swap->update(['status' => ShiftSwapRequest::STATUS_EXPIRED, 'failure_reason' => self::EXPIRED_MESSAGE]);
                        $expired++;
                    }
                });
            });

        return $expired;
    }

    /**
     * Tell both workers a swap that reached its final approval could not be applied (failed or expired).
     * Must be called inside the approval transaction: the pushes go out once it commits.
     */
    public function notifyNotApplied(ShiftSwapRequest $swap, string $status, string $message): void
    {
        $event = $status === ShiftSwapRequest::STATUS_EXPIRED ? 'expired' : 'failed';
        $title = $event === 'expired' ? 'Tukar shift kedaluwarsa' : 'Tukar shift gagal diterapkan';
        $body = "Tukar shift tidak diterapkan: {$message}";

        $this->notify($swap->getWorkerProfile()?->user, $swap, $event, $title, $body);
        $this->notify($swap->counterpartProfile()?->user, $swap, $event, $title, $body);
    }

    /**
     * Tell the colleague an approver rejected the swap they accepted (the requester hears it through the
     * usual approval notification).
     */
    public function notifyRejectedByApprover(ShiftSwapRequest $swap, ?string $reason): void
    {
        $requesterName = $swap->getWorkerProfile()?->full_name ?? 'rekan Anda';
        $body = "Tukar shift dengan {$requesterName} ditolak atasan".(filled($reason) ? ": {$reason}" : '.').' Jadwal Anda tidak berubah.';

        $this->notify($swap->counterpartProfile()?->user, $swap, 'rejected', 'Tukar shift ditolak atasan', $body);
    }

    /**
     * Set the worker's schedule on the date to the given assignment, keeping the row when there is one so the
     * schedule hooks (lateness recompute) see an update.
     *
     * @param  array{off: bool, shift_id: int|null, company_location_id: int|null, section: string|null, schedule_id: int|null}  $state
     */
    private function writeCell(Employee|Intern|Freelancer $worker, string $date, ?ShiftSchedule $schedule, array $state, string $note, User $approver): void
    {
        $attributes = [
            'is_day_off' => $state['off'],
            'shift_id' => $state['off'] ? null : $state['shift_id'],
            'company_location_id' => $state['off'] ? null : $state['company_location_id'],
            'section' => $state['off'] ? null : $state['section'],
            'notes' => $note,
        ];

        if ($schedule) {
            $schedule->fill($attributes)->save();

            return;
        }

        ShiftSchedule::create([
            ...$attributes,
            'date' => $date,
            app(WorkScheduleService::class)->workerColumn($worker) => $worker->getKey(),
            'created_by' => $approver->id,
        ]);
    }

    /**
     * Re-read the request locked, and make sure the colleague is the one answering and it still waits for them.
     *
     * @throws ValidationException
     */
    private function lockAwaiting(ShiftSwapRequest $swap, User $user): ShiftSwapRequest
    {
        $locked = ShiftSwapRequest::query()->lockForUpdate()->find($swap->getKey());

        if (! $locked || ! $locked->isCounterpart($user)) {
            throw ValidationException::withMessages(['shift_swap' => 'Hanya rekan yang diajak yang bisa menjawab tukar shift ini.']);
        }

        if ($locked->status !== ShiftSwapRequest::STATUS_AWAITING_COLLEAGUE) {
            throw ValidationException::withMessages(['shift_swap' => 'Pengajuan tukar shift ini sudah tidak menunggu jawaban Anda.']);
        }

        return $locked;
    }

    /**
     * Why an open request can no longer go ahead: a date passed, or a worker left. Marks it expired or failed
     * unless $mark is false.
     */
    private function closingFailure(ShiftSwapRequest $swap, Employee|Intern|Freelancer|null $requester, Employee|Intern|Freelancer|null $counterpart, bool $mark = true): ?string
    {
        if ($swap->isOverdue()) {
            if ($mark) {
                $swap->update(['status' => ShiftSwapRequest::STATUS_EXPIRED, 'failure_reason' => self::EXPIRED_MESSAGE]);
            }

            return self::EXPIRED_MESSAGE;
        }

        if (! $requester || ! $counterpart) {
            $message = 'Data pekerja pada pengajuan ini sudah tidak ada.';
        } elseif (! $this->isActiveWorker($requester) || ! $this->isActiveWorker($counterpart)) {
            $message = 'Salah satu pekerja sudah tidak aktif, tukar shift tidak bisa diterapkan.';
        } else {
            return null;
        }

        if ($mark) {
            $this->markFailed($swap, $message);
        }

        return $message;
    }

    /**
     * Why the (locked) workers can no longer swap on the final approval: one of them moved to another company
     * than the request's, or may not work on one of its dates (a contract end date moved earlier, or a
     * resignation approved with an earlier last working day) since the request was filed and accepted.
     *
     * @param  list<string>  $dates
     */
    private function lockedWorkersError(ShiftSwapRequest $swap, Employee|Intern|Freelancer $requester, Employee|Intern|Freelancer $counterpart, array $dates): ?string
    {
        $companyId = (int) ($swap->company_id ?: $requester->company_id);

        if ((int) $requester->company_id !== $companyId || (int) $counterpart->company_id !== $companyId) {
            return 'Tukar shift hanya bisa dengan rekan di badan usaha yang sama.';
        }

        foreach ($dates as $date) {
            if (! $this->isActiveWorker($requester, Carbon::parse($date)) || ! $this->isActiveWorker($counterpart, Carbon::parse($date))) {
                return 'Salah satu pekerja sudah tidak aktif pada tanggal tukar shift, tukar shift tidak bisa diterapkan.';
            }
        }

        return null;
    }

    private function markFailed(ShiftSwapRequest $swap, string $message): void
    {
        $swap->update(['status' => ShiftSwapRequest::STATUS_FAILED, 'failure_reason' => $message]);
    }

    /**
     * Lock both worker profile rows, in a fixed order so two requests between the same pair never deadlock.
     * Returns the locked rows (requester first), freshly read, or null for a profile that no longer exists.
     *
     * @return array{0: Employee|Intern|Freelancer|null, 1: Employee|Intern|Freelancer|null}
     */
    private function lockProfiles(Employee|Intern|Freelancer $first, Employee|Intern|Freelancer $second): array
    {
        $locked = collect([$first, $second])
            ->sortBy(fn ($worker): string => $worker->getTable().':'.str_pad((string) $worker->getKey(), 12, '0', STR_PAD_LEFT))
            ->mapWithKeys(fn ($worker): array => [
                $worker->getTable().':'.$worker->getKey() => $worker->newQuery()->withoutGlobalScopes()->whereKey($worker->getKey())->lockForUpdate()->first(),
            ]);

        return [
            $locked->get($first->getTable().':'.$first->getKey()),
            $locked->get($second->getTable().':'.$second->getKey()),
        ];
    }

    private function formatDate(string $date): string
    {
        return Carbon::parse($date)->locale('id')->translatedFormat('l, d M Y');
    }

    /**
     * Push an update to a worker's account, once the change is committed.
     */
    public function notify(?User $user, ShiftSwapRequest $swap, string $event, string $title, string $body): void
    {
        if (! $user || $user->is_active === false) {
            return;
        }

        DB::afterCommit(fn () => $user->notify(new ShiftSwapUpdatedNotification($swap, $event, $title, $body)));
    }
}
