<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\CompanyLocation;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\LeaveRequest;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Change one worker's schedule for one day: a shift (with outlet and section), a day off, or nothing.
 * Every roster writer of the panel and the API goes through here, so they refuse the same things.
 */
final class ShiftAssignmentService
{
    public const MODE_SHIFT = 'shift';

    public const MODE_DAY_OFF = 'off';

    public const MODE_CLEAR = 'clear';

    /** How far ahead of today a day may be scheduled. */
    public const MAX_DAYS_AHEAD = 366;

    public const PAST_REASON_MESSAGE = 'Isi alasan perubahan untuk tanggal yang sudah lewat.';

    public const UNLINKED_ATTENDANCE_MESSAGE = 'Sudah ada absensi pada jam ini. Ajukan koreksi absensi.';

    /** Most schedules one worker may have on one date. */
    public const MAX_SCHEDULES_PER_DAY = 3;

    /** The released app clears a day or makes it a day off without naming the schedule. */
    public const MULTI_SCHEDULE_MESSAGE = 'Hari ini punya lebih dari satu jadwal. Perbarui aplikasi atau ubah dari panel.';

    /** The same refusal in the panel, whose week grid does not name the schedule yet. */
    public const PANEL_MULTI_SCHEDULE_MESSAGE = 'Tanggal ini punya lebih dari satu jadwal. Ubah atau hapus per jadwal dari menu Jadwal Shift.';

    public const MAX_SCHEDULES_MESSAGE = 'Satu pekerja paling banyak punya 3 jadwal dalam sehari.';

    public const ADD_ON_DAY_OFF_MESSAGE = 'Hari ini libur. Ubah dulu menjadi shift sebelum menambah jadwal.';

    public const DAY_OFF_WITH_OTHERS_MESSAGE = 'Hari ini masih punya jadwal lain. Hapus jadwal lainnya dulu sebelum menjadikannya libur.';

    public const DUPLICATE_SCHEDULE_MESSAGE = 'Shift ini di outlet yang sama sudah ada pada tanggal tersebut.';

    public const OVERLAP_MESSAGE = 'Jam shift ini bertabrakan dengan jadwal lain pada tanggal tersebut.';

    /** A shift past midnight of the day before, or a shift of the next day, runs into the hours of this one. */
    public const ADJACENT_OVERLAP_MESSAGE = 'Jam shift ini bertabrakan dengan jadwal di hari sebelum atau sesudahnya.';

    /** A working schedule on a date an approved leave of the worker is charged for; a day off or clearing stays allowed. */
    public const ON_LEAVE_MESSAGE = 'Sedang cuti pada tanggal ini. Jadikan libur atau kosongkan, atau ubah dulu cutinya.';

    public function __construct(
        private readonly SchedulingOptions $options,
        private readonly SessionResolver $sessions,
    ) {}

    /**
     * The single writer of a worker's day: runs in a transaction that locks the worker row, so two identical
     * requests at once write one schedule, and logs the change with the given source and reason.
     *
     * A worker may have up to {@see self::MAX_SCHEDULES_PER_DAY} schedules on a date. $scheduleId changes or
     * clears exactly that row; $add creates a further one with the lowest free number (refused above the
     * maximum, on a day off, for the same shift at the same outlet again, or when its hours overlap another
     * schedule of the day). Without either (the released app) a day with one row works as it always did; on a
     * day with several a shift changes the primary row and a day off or clearing is refused.
     *
     * A schedule that already has attendance (locked) is not changed; a working schedule whose hours overlap
     * a check-in of that day that has no schedule (default shift, company hours, a cover waiting for
     * approval) is refused. With $requirePastReason (the panel) a date before today needs a reason.
     *
     * @param  string|null  $notes  null keeps the notes of an existing schedule; an empty string clears them.
     * @return ShiftSchedule|null The saved schedule, or null when it was cleared.
     *
     * @throws AuthorizationException When the worker is outside the user's data access.
     * @throws ValidationException When the shift or outlet is not one the user may pick, the worker
     *                             cannot be scheduled on the date, or the day's attendance stands in the way.
     */
    public function assignDay(
        User $user,
        Employee|Intern|Freelancer $worker,
        string $date,
        string $mode,
        ?int $shiftId = null,
        ?int $locationId = null,
        ?string $section = null,
        ?string $reason = null,
        string $source = 'api',
        bool $requirePastReason = false,
        ?string $notes = null,
        ?int $scheduleId = null,
        bool $add = false,
    ): ?ShiftSchedule {
        if (! $user->canManageWorker($worker)) {
            throw new AuthorizationException('Pekerja ini di luar akses Anda.');
        }

        $column = $this->workerColumn($worker);

        if ($add && $scheduleId !== null) {
            throw ValidationException::withMessages(['schedule_id' => 'Pilih salah satu: ubah jadwal yang ada atau tambah jadwal baru.']);
        }

        if ($add && $mode !== self::MODE_SHIFT) {
            throw ValidationException::withMessages(['mode' => 'Jadwal tambahan harus berupa shift.']);
        }

        return RosterChangeContext::run($source, $reason, fn (): ?ShiftSchedule => DB::transaction(function () use ($user, $worker, $column, $date, $mode, $shiftId, $locationId, $section, $reason, $requirePastReason, $notes, $scheduleId, $add, $source): ?ShiftSchedule {
            $this->lockWorker($worker);
            $rows = ShiftSchedule::query()->with('shift')->where($column, $worker->getKey())->whereDate('date', $date)->orderBy('sequence')->orderBy('id')->lockForUpdate()->get();
            $schedule = $this->targetRow($rows, $mode, $scheduleId, $add, $source);

            if ($mode === self::MODE_CLEAR) {
                if ($schedule) {
                    $this->guardPastDate($date, $reason, $requirePastReason);
                    $this->guardLocked($schedule);
                    $schedule->delete();
                }

                return null;
            }

            if (($dateError = $this->dateError($worker, Carbon::parse($date))) !== null) {
                throw ValidationException::withMessages(['date' => $dateError]);
            }

            $dayOff = $mode === self::MODE_DAY_OFF;

            if (! $dayOff) {
                if (! $this->options->shifts($user, $worker->company_id, borrowing: true)->contains('id', $shiftId)) {
                    throw ValidationException::withMessages(['shift_id' => 'Shift ini tidak bisa dipilih untuk pekerja tersebut.']);
                }

                if ($locationId !== null && ! $this->options->locations($user, $worker->company_id, borrowing: true)->contains('id', $locationId)) {
                    throw ValidationException::withMessages(['company_location_id' => 'Outlet ini tidak bisa dipilih.']);
                }
            }

            $others = $rows->reject(fn (ShiftSchedule $row): bool => $schedule !== null && $row->is($schedule))->values();

            if ($dayOff && $others->isNotEmpty()) {
                throw ValidationException::withMessages(['mode' => self::DAY_OFF_WITH_OTHERS_MESSAGE]);
            }

            if ($schedule === null) {
                $schedule = new ShiftSchedule([$column => $worker->getKey(), 'date' => $date, 'sequence' => $this->newScheduleSequence($worker, $date, $rows), 'created_by' => $user->id]);
            }

            $schedule->fill([
                'shift_id' => $dayOff ? null : $shiftId,
                'company_location_id' => $dayOff ? null : $locationId,
                'is_day_off' => $dayOff,
                'section' => $dayOff ? null : (trim((string) $section) ?: null),
            ]);

            if ($notes !== null) {
                $schedule->notes = trim($notes) ?: null;
            }

            if ($schedule->exists && ! $schedule->isDirty()) {
                return $schedule;
            }

            if (! $schedule->exists || $schedule->isDirty(ShiftSchedule::ROSTER_COLUMNS)) {
                $this->guardPastDate($date, $reason, $requirePastReason);
                $this->guardLocked($schedule);

                if (! $dayOff) {
                    $this->guardOnLeave($worker, $date);
                    $this->guardDayShape($worker, $others, $date, (int) $shiftId, $locationId, ! $schedule->exists || $schedule->isDirty(['shift_id', 'is_day_off', 'date']));
                }

                $this->guardUnlinkedAttendance($worker, $schedule);
            }

            $schedule->save();

            return $schedule;
        }));
    }

    /**
     * Every schedule row of the worker on the date, in day order ({@see ShiftSchedule::inDayOrder()}).
     *
     * @return Collection<int, ShiftSchedule>
     */
    public function rowsOn(Employee|Intern|Freelancer $worker, string $date): Collection
    {
        return ShiftSchedule::inDayOrder(ShiftSchedule::query()->with('shift')->where($this->workerColumn($worker), $worker->getKey())->whereDate('date', $date)->get());
    }

    /**
     * The row a change is made to: the named schedule, none for a new one ($add), or, for a request without
     * either (the released app), the day's only row, or on a day with several the primary row for a shift.
     * Clearing a day or making it a day off without naming the schedule is refused on a day with several,
     * pointing a panel user to the schedule list and an app user to an update.
     *
     * @param  Collection<int, ShiftSchedule>  $rows
     *
     * @throws ValidationException
     */
    private function targetRow(Collection $rows, string $mode, ?int $scheduleId, bool $add, string $source = 'api'): ?ShiftSchedule
    {
        if ($scheduleId !== null) {
            $row = $rows->first(fn (ShiftSchedule $row): bool => (int) $row->getKey() === $scheduleId);

            if (! $row) {
                throw ValidationException::withMessages(['schedule_id' => 'Jadwal ini tidak ada pada tanggal tersebut. Muat ulang jadwalnya.']);
            }

            return $row;
        }

        if ($add) {
            if ($rows->contains(fn (ShiftSchedule $row): bool => (bool) $row->is_day_off)) {
                throw ValidationException::withMessages(['date' => self::ADD_ON_DAY_OFF_MESSAGE]);
            }

            if ($rows->count() >= self::MAX_SCHEDULES_PER_DAY) {
                throw ValidationException::withMessages(['date' => self::MAX_SCHEDULES_MESSAGE]);
            }

            return null;
        }

        if ($rows->count() <= 1) {
            return $rows->first();
        }

        if ($mode !== self::MODE_SHIFT) {
            throw ValidationException::withMessages(['date' => $source === 'panel' ? self::PANEL_MULTI_SCHEDULE_MESSAGE : self::MULTI_SCHEDULE_MESSAGE]);
        }

        return ShiftSchedule::inDayOrder($rows)->first();
    }

    /**
     * A working schedule fits the other rows of its date: not the same shift at the same outlet again, and
     * no overlap with the hours of another working row (absolute windows, both parts of a split shift, a
     * shift past midnight included); nor with the working rows of the day before that run past midnight
     * or of the next day that start before this one ends ({@see self::adjacentOverlapError()}). The
     * neighbouring days are only asked when the row's hours change ($windowChanged): an edit of the outlet,
     * section or notes of a row saved before that rule existed is not refused for an overlap it already had.
     *
     * @param  Collection<int, ShiftSchedule>  $others
     *
     * @throws ValidationException
     */
    private function guardDayShape(Employee|Intern|Freelancer $worker, Collection $others, string $date, int $shiftId, ?int $locationId, bool $windowChanged = true): void
    {
        if (($error = $this->dayShapeError($others, $date, $shiftId, $locationId) ?? ($windowChanged ? $this->adjacentOverlapError($worker, $date, $shiftId) : null)) !== null) {
            throw ValidationException::withMessages(['shift_id' => $error]);
        }
    }

    /**
     * Why the shift on the date would overlap a working schedule of the worker on the day before (a shift
     * running past midnight) or on the next day (starting before this one ends), or null. Absolute windows,
     * both parts of a split shift included.
     */
    public function adjacentOverlapError(Employee|Intern|Freelancer $worker, string $date, int $shiftId): ?string
    {
        $date = substr($date, 0, 10);
        $shift = Shift::query()->find($shiftId);

        if (! $shift) {
            return null;
        }

        $neighbours = ShiftSchedule::query()
            ->with('shift')
            ->where($this->workerColumn($worker), $worker->getKey())
            ->where(fn ($dates) => $dates
                ->whereDate('date', Carbon::parse($date)->subDay()->toDateString())
                ->orWhereDate('date', Carbon::parse($date)->addDay()->toDateString()))
            ->where('is_day_off', false)
            ->whereNotNull('shift_id')
            ->get()
            ->filter(fn (ShiftSchedule $row): bool => $row->shift !== null)
            ->map(fn (ShiftSchedule $row): array => ['shift' => $row->shift, 'date' => $row->date->toDateString()]);

        return $this->overlapsAdjacentDays($shift, $date, $neighbours) ? self::ADJACENT_OVERLAP_MESSAGE : null;
    }

    /**
     * Whether the shift worked on the date overlaps one of the given working schedules of the day before or
     * the next day (absolute windows, both parts of a split shift included; ends that only touch do not
     * count). Entries of other dates are ignored. Copy week and the Excel import ask here with the state they
     * plan to leave behind, instead of what the database holds while they write.
     *
     * @param  iterable<array{shift: Shift, date: string}>  $neighbours
     */
    public function overlapsAdjacentDays(Shift $shift, string $date, iterable $neighbours): bool
    {
        $date = substr($date, 0, 10);
        $adjacent = [Carbon::parse($date)->subDay()->toDateString(), Carbon::parse($date)->addDay()->toDateString()];
        $windows = $this->shiftWindows($shift, $date);

        foreach ($neighbours as $neighbour) {
            $neighbourDate = substr($neighbour['date'], 0, 10);

            if (in_array($neighbourDate, $adjacent, true) && $this->overlaps($windows, $this->shiftWindows($neighbour['shift'], $neighbourDate))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Why a working schedule (shift at an outlet) does not fit next to the other rows of its date, or null:
     * the same shift at the same outlet again, or hours overlapping another working row. The Excel import
     * asks here too, so it refuses what the panel and the app refuse.
     *
     * @param  Collection<int, ShiftSchedule>  $others  The other rows of the date (shift loaded).
     */
    public function dayShapeError(Collection $others, string $date, int $shiftId, ?int $locationId): ?string
    {
        $working = $others->filter(fn (ShiftSchedule $row): bool => ! $row->is_day_off && $row->shift_id !== null);

        if ($working->isEmpty()) {
            return null;
        }

        if ($working->contains(fn (ShiftSchedule $row): bool => (int) $row->shift_id === $shiftId && ($row->company_location_id === null ? null : (int) $row->company_location_id) === $locationId)) {
            return self::DUPLICATE_SCHEDULE_MESSAGE;
        }

        $shift = Shift::query()->find($shiftId);

        if (! $shift) {
            return null;
        }

        foreach ($working as $row) {
            if ($row->shift && $this->shiftsOverlap($shift, $row->shift, $date)) {
                return self::OVERLAP_MESSAGE;
            }
        }

        return null;
    }

    /**
     * Whether two shifts worked on the same date overlap in time (both parts of a split shift, a shift past
     * midnight included).
     */
    public function shiftsOverlap(Shift $first, Shift $second, string $date): bool
    {
        return $this->overlaps($this->shiftWindows($first, $date), $this->shiftWindows($second, $date));
    }

    /**
     * The lowest schedule number that no row of the date and no checked-in attendance row of the date holds.
     * A new schedule never takes the number of a check-in without a schedule (a cover waiting for approval,
     * a row whose schedule was deleted, a rejected check-in): sessions are paired with their attendance by
     * number and part, so it would inherit that row. How many schedules a day may have is checked apart
     * ({@see self::targetRow()}), so the number itself may run past {@see self::MAX_SCHEDULES_PER_DAY}.
     *
     * @param  Collection<int, ShiftSchedule>  $rows
     * @param  list<int>  $heldByAttendance
     */
    private function freeSequence(Collection $rows, array $heldByAttendance = []): int
    {
        $used = [...$rows->map(fn (ShiftSchedule $row): int => (int) ($row->sequence ?: 1))->all(), ...$heldByAttendance];
        $sequence = 1;

        while (in_array($sequence, $used, true)) {
            $sequence++;
        }

        return $sequence;
    }

    /**
     * The number a new schedule of the worker on the date gets, next to the date's rows ({@see self::freeSequence()}).
     *
     * @param  Collection<int, ShiftSchedule>  $rows
     */
    public function newScheduleSequence(Employee|Intern|Freelancer $worker, string $date, Collection $rows): int
    {
        return $this->freeSequence($rows, $this->attendanceSequencesOn($worker, $date));
    }

    /**
     * The schedule numbers of the date held by a checked-in attendance row of the worker, a rejected one
     * included: each keeps its slot (worker, date, number, part) in the attendance table.
     *
     * @return list<int>
     */
    public function attendanceSequencesOn(Employee|Intern|Freelancer $worker, string $date): array
    {
        return Attendance::query()
            ->where($this->workerColumn($worker), $worker->getKey())
            ->whereDate('date', substr($date, 0, 10))
            ->whereNotNull('check_in')
            ->pluck('schedule_sequence')
            ->map(fn (mixed $sequence): int => (int) ($sequence ?: 1))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Absolute windows (timestamps) of each part of the shift on the date.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function shiftWindows(Shift $shift, string $date): array
    {
        $date = substr($date, 0, 10);
        $windows = [AttendanceFigures::window($date, (string) $shift->start_time, (string) $shift->end_time)];

        if ($shift->hasSecondSegment()) {
            $windows[] = AttendanceFigures::window($date, (string) $shift->second_start_time, (string) $shift->second_end_time);
        }

        return $windows;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $first
     * @param  list<array{0: int, 1: int}>  $second
     */
    private function overlaps(array $first, array $second): bool
    {
        foreach ($first as [$firstStart, $firstEnd]) {
            foreach ($second as [$secondStart, $secondEnd]) {
                if ($firstStart < $secondEnd && $secondStart < $firstEnd) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Change one existing schedule (the panel's edit form): same guards as {@see self::assignDay()}.
     *
     * @param  array{is_day_off?: bool, shift_id?: int|null, company_location_id?: int|null, section?: string|null, notes?: string|null}  $attributes
     *
     * @throws AuthorizationException|ValidationException
     */
    public function updateSchedule(User $user, ShiftSchedule $schedule, array $attributes, ?string $reason = null, string $source = 'panel', bool $requirePastReason = true): ShiftSchedule
    {
        $worker = $schedule->getWorkerProfile();

        if (! $worker || ! $user->canManageWorker($worker)) {
            throw new AuthorizationException('Pekerja ini di luar akses Anda.');
        }

        return RosterChangeContext::run($source, $reason, fn (): ShiftSchedule => DB::transaction(function () use ($user, $worker, $schedule, $attributes, $reason, $requirePastReason): ShiftSchedule {
            $this->lockWorker($worker);
            $row = ShiftSchedule::query()->whereKey($schedule->getKey())->lockForUpdate()->firstOrFail();
            $row->fill(array_intersect_key($attributes, array_flip(['is_day_off', 'shift_id', 'company_location_id', 'section', 'notes'])));

            if ($row->is_day_off) {
                $row->forceFill(['shift_id' => null, 'company_location_id' => null, 'section' => null]);
            } elseif ($row->isDirty('shift_id') && ! $this->options->shifts($user, $worker->company_id, borrowing: true)->contains('id', (int) $row->shift_id)) {
                throw ValidationException::withMessages(['shift_id' => 'Shift ini tidak bisa dipilih untuk pekerja tersebut.']);
            } elseif ($row->isDirty('company_location_id') && $row->company_location_id !== null && ! $this->options->locations($user, $worker->company_id, borrowing: true)->contains('id', (int) $row->company_location_id)) {
                throw ValidationException::withMessages(['company_location_id' => 'Outlet ini tidak bisa dipilih.']);
            }

            if ($row->isDirty(ShiftSchedule::ROSTER_COLUMNS)) {
                $date = $row->date->toDateString();
                $this->guardPastDate($date, $reason, $requirePastReason);
                $this->guardLocked($row);

                if (($dateError = $this->dateError($worker, Carbon::parse($date))) !== null) {
                    throw ValidationException::withMessages(['date' => $dateError]);
                }

                // The same day shape as assignDay(): no day off beside other schedules, no duplicate or overlap.
                $others = ShiftSchedule::query()->with('shift')->where($this->workerColumn($worker), $worker->getKey())->whereDate('date', $date)->whereKeyNot($row->getKey())->lockForUpdate()->get();

                if ($row->is_day_off && $others->isNotEmpty()) {
                    throw ValidationException::withMessages(['is_day_off' => self::DAY_OFF_WITH_OTHERS_MESSAGE]);
                }

                if (! $row->is_day_off) {
                    $this->guardOnLeave($worker, $date, 'is_day_off');
                }

                if (! $row->is_day_off && $row->shift_id !== null) {
                    $this->guardDayShape($worker, $others, $date, (int) $row->shift_id, $row->company_location_id === null ? null : (int) $row->company_location_id, $row->isDirty(['shift_id', 'is_day_off', 'date']));
                }

                $this->guardUnlinkedAttendance($worker, $row);
            }

            $row->save();
            $schedule->setRawAttributes($row->getAttributes(), true);

            return $schedule;
        }));
    }

    /**
     * Remove one schedule (the panel's delete action): refused when it has attendance, and a date before
     * today needs a reason with $requirePastReason.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function deleteSchedule(User $user, ShiftSchedule $schedule, ?string $reason = null, string $source = 'panel', bool $requirePastReason = true): void
    {
        $worker = $schedule->getWorkerProfile();

        if ($worker && ! $user->canManageWorker($worker)) {
            throw new AuthorizationException('Pekerja ini di luar akses Anda.');
        }

        RosterChangeContext::run($source, $reason, fn () => DB::transaction(function () use ($worker, $schedule, $reason, $requirePastReason): void {
            if ($worker) {
                $this->lockWorker($worker);
            }

            $row = ShiftSchedule::query()->whereKey($schedule->getKey())->lockForUpdate()->first();

            if (! $row) {
                return;
            }

            $this->guardPastDate($row->date->toDateString(), $reason, $requirePastReason);
            $this->guardLocked($row);
            $row->delete();
        }));
    }

    /**
     * Apply the shift and outlet of an approved schedule correction: the only change a schedule that already
     * has attendance (a locked schedule) accepts. It runs under the roster context "correction" (logged with
     * that source and the correction's reason) in a transaction that locks the worker row. Without a schedule
     * (the day had none: the worker worked standing hours) a working schedule is created with the given number
     * when it is free, else the lowest free one. The day keeps its shape: no duplicate and no overlap with
     * another working schedule of the date.
     *
     * Not checked: who may pick the shift or outlet (the correction was approved) and the past date (a
     * correction is about the past).
     *
     * @throws ValidationException When the corrected schedule would not fit next to the other schedules of the date.
     */
    public function correctSchedule(Employee|Intern|Freelancer $worker, string $date, ?ShiftSchedule $schedule, int $sequence, int $shiftId, ?int $locationId, ?string $reason = null): ShiftSchedule
    {
        $date = substr($date, 0, 10);
        $column = $this->workerColumn($worker);

        return RosterChangeContext::run('correction', $reason, fn (): ShiftSchedule => DB::transaction(function () use ($worker, $date, $column, $schedule, $sequence, $shiftId, $locationId): ShiftSchedule {
            $this->lockWorker($worker);
            $rows = ShiftSchedule::query()->with('shift')->where($column, $worker->getKey())->whereDate('date', $date)->lockForUpdate()->get();
            $row = $schedule ? $rows->first(fn (ShiftSchedule $candidate): bool => $candidate->is($schedule)) : null;

            if ($schedule && ! $row) {
                throw ValidationException::withMessages(['approve' => 'Jadwal yang dikoreksi sudah tidak ada pada tanggal tersebut. Tolak pengajuan ini dan minta pekerja mengajukan ulang.']);
            }

            $others = $rows->reject(fn (ShiftSchedule $candidate): bool => $row !== null && $candidate->is($row))->values();

            if ($others->contains(fn (ShiftSchedule $candidate): bool => (bool) $candidate->is_day_off)) {
                throw ValidationException::withMessages(['approve' => self::DAY_OFF_WITH_OTHERS_MESSAGE]);
            }

            if (($error = $this->dayShapeError($others, $date, $shiftId, $locationId)) !== null) {
                throw ValidationException::withMessages(['approve' => $error]);
            }

            if (! $row) {
                $taken = $rows->map(fn (ShiftSchedule $candidate): int => (int) ($candidate->sequence ?: 1))->all();
                $row = new ShiftSchedule([
                    $column => $worker->getKey(),
                    'date' => $date,
                    'sequence' => in_array($sequence, $taken, true) ? $this->freeSequence($rows) : $sequence,
                    'created_by' => auth()->id(),
                ]);
            }

            $row->fill(['shift_id' => $shiftId, 'company_location_id' => $locationId, 'is_day_off' => false]);

            if (! $row->exists || $row->isDirty()) {
                $row->save();
            }

            return $row;
        }));
    }

    /**
     * Remove the worker's schedules after the given day, one by one so each removal is logged (source
     * "lifecycle"): a resignation or an ended contract. A schedule that already has attendance stays.
     *
     * @return int The number of schedules removed.
     */
    public function deleteFutureFor(Employee|Intern|Freelancer $worker, CarbonInterface $after): int
    {
        return RosterChangeContext::run('lifecycle', null, function () use ($worker, $after): int {
            $removed = 0;

            ShiftSchedule::query()
                ->where($this->workerColumn($worker), $worker->getKey())
                ->whereDate('date', '>', $after->toDateString())
                ->orderBy('date')
                ->get()
                ->each(function (ShiftSchedule $schedule) use (&$removed): void {
                    if (! $schedule->isLocked()) {
                        $schedule->delete();
                        $removed++;
                    }
                });

            return $removed;
        });
    }

    /**
     * Why a working schedule cannot be given for the date because a check-in without a schedule (default
     * shift, company hours, a cover waiting for approval) falls in its hours, or null. The check-ins of the
     * date itself and of the day before (a night shift running past midnight) are looked at; a rejected
     * check-in never stands in the way.
     */
    public function unlinkedAttendanceError(Employee|Intern|Freelancer $worker, ShiftSchedule $schedule): ?string
    {
        $shift = $schedule->is_day_off || ! $schedule->shift_id ? null : Shift::query()->find($schedule->shift_id);

        if (! $shift) {
            return null;
        }

        $date = $schedule->date->toDateString();
        $windows = $this->shiftWindows($shift, $date);

        $attendances = Attendance::query()
            ->with(['employee', 'intern', 'freelancer', 'shift'])
            ->where($this->workerColumn($worker), $worker->getKey())
            ->whereDate('date', '>=', Carbon::parse($date)->subDay()->toDateString())
            ->whereDate('date', '<=', $date)
            ->whereNotNull('check_in')
            ->whereNull('shift_schedule_id')
            ->where('status', '!=', AttendanceStatus::Rejected)
            ->get();

        foreach ($attendances as $attendance) {
            $window = $this->sessions->windowOf($attendance);
            $start = $window ? $window['start']->getTimestamp() : $attendance->check_in->getTimestamp();
            $end = $window ? $window['end']->getTimestamp() : ($attendance->check_out ?? $attendance->check_in)->getTimestamp() + 1;

            foreach ($windows as [$partStart, $partEnd]) {
                if ($start < $partEnd && $partStart < $end) {
                    return self::UNLINKED_ATTENDANCE_MESSAGE;
                }
            }
        }

        return null;
    }

    private function guardPastDate(string $date, ?string $reason, bool $requirePastReason): void
    {
        if ($requirePastReason && substr($date, 0, 10) < today()->toDateString() && blank($reason)) {
            throw ValidationException::withMessages(['reason' => self::PAST_REASON_MESSAGE]);
        }
    }

    /**
     * Refuses a working schedule on a date an approved leave of the worker is charged for.
     *
     * @throws ValidationException
     */
    private function guardOnLeave(Employee|Intern|Freelancer $worker, string $date, string $field = 'date'): void
    {
        if (($error = $this->leaveDateError($worker, Carbon::parse($date))) !== null) {
            throw ValidationException::withMessages([$field => $error]);
        }
    }

    /**
     * Why the worker cannot be given a working schedule on the date because an approved leave is charged
     * for it ({@see LeaveRequest::chargedDatesByWorker()}), or null. A day off or an empty day is always allowed.
     */
    public function leaveDateError(Employee|Intern|Freelancer $worker, CarbonInterface $date): ?string
    {
        $charged = LeaveRequest::chargedDatesByWorker($this->workerColumn($worker), [$worker->getKey()], $date, $date);

        return isset($charged[$worker->getKey()][$date->toDateString()]) ? self::ON_LEAVE_MESSAGE : null;
    }

    private function guardLocked(ShiftSchedule $schedule): void
    {
        if ($schedule->exists && ! RosterChangeContext::isCorrection() && $schedule->isLocked()) {
            throw ShiftSchedule::lockedException();
        }
    }

    private function guardUnlinkedAttendance(Employee|Intern|Freelancer $worker, ShiftSchedule $schedule): void
    {
        if (($error = $this->unlinkedAttendanceError($worker, $schedule)) !== null) {
            throw ValidationException::withMessages(['date' => $error]);
        }
    }

    /**
     * Serialise roster writes of one worker: a second request waits here until the first has committed.
     */
    private function lockWorker(Employee|Intern|Freelancer $worker): void
    {
        $worker->newQuery()->withoutGlobalScopes()->whereKey($worker->getKey())->lockForUpdate()->first(['id']);
    }

    private function workerColumn(Employee|Intern|Freelancer $worker): string
    {
        return match (true) {
            $worker instanceof Employee => 'employee_id',
            $worker instanceof Intern => 'intern_id',
            default => 'freelancer_id',
        };
    }

    /**
     * Why this worker cannot be given this shift at this outlet on the date, or null when they can. Every
     * roster writer (one day, "Atur Jadwal", copy week, Excel import) asks here, so they all refuse the same
     * things: a shift or outlet switched off, and a date the worker cannot work (see {@see self::dateError()}).
     */
    public function assignmentError(Employee|Intern|Freelancer $worker, CarbonInterface $date, ?Shift $shift = null, ?CompanyLocation $outlet = null): ?string
    {
        if ($shift !== null && ! $shift->is_active) {
            return "Shift {$shift->name} sudah nonaktif.";
        }

        if ($outlet !== null && ! $outlet->is_active) {
            return "Outlet {$outlet->name} sudah nonaktif.";
        }

        return $this->dateError($worker, $date);
    }

    /**
     * Why the worker cannot be scheduled on the date, or null when they can: an ended contract, a day after
     * the contract's end date, a day before the contract starts or before the employee joined, or a date more
     * than a year ahead (a mistyped year).
     */
    public function dateError(Employee|Intern|Freelancer $worker, CarbonInterface $date): ?string
    {
        $day = $date->toDateString();
        $endDate = $worker instanceof Employee ? null : $worker->end_date;
        $startDate = $worker instanceof Employee ? null : $worker->start_date;

        if ($endDate !== null && $day > $endDate->toDateString()) {
            return 'Kontrak pekerja ini berakhir '.$endDate->locale('id')->translatedFormat('d F Y').'. Hari setelahnya tidak bisa dijadwalkan.';
        }

        if ($endDate === null && in_array($worker->status, WorkerAccountService::ENDED_STATUSES, true)) {
            return 'Kontrak pekerja ini sudah berakhir.';
        }

        if ($startDate !== null && $day < $startDate->toDateString()) {
            return 'Kontrak pekerja ini baru mulai '.$startDate->locale('id')->translatedFormat('d F Y').'. Hari sebelumnya tidak bisa dijadwalkan.';
        }

        if ($worker instanceof Employee && $worker->join_date !== null && $day < $worker->join_date->toDateString()) {
            return 'Pekerja ini baru bergabung '.$worker->join_date->locale('id')->translatedFormat('d F Y').'. Hari sebelumnya tidak bisa dijadwalkan.';
        }

        if ($day > today()->addDays(self::MAX_DAYS_AHEAD)->toDateString()) {
            return 'Jadwal hanya bisa diatur sampai satu tahun dari hari ini.';
        }

        return null;
    }
}
