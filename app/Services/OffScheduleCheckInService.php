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
use App\Services\Attendance\AttendanceRuleException;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Masuk di luar jadwal": an emergency backup check-in on a day the worker is not rostered (a dated day
 * off, a freelancer without any shift, or a day without a schedule in a company that uses shift rosters). The check-in is accepted but waits for approval through the
 * geofence approval flow. It may name the shift it covers (lateness is judged against it) and the
 * scheduled colleague it replaces.
 *
 * Once approved, the worker's roster of that date becomes the covered shift (so reports read a worked,
 * scheduled day instead of Libur) and the replaced colleague, when not checked in that day, gets a day off
 * ("Digantikan oleh ..."), so they are neither absent nor paid. A rejection changes no roster.
 */
class OffScheduleCheckInService
{
    public const REASON = 'masuk di luar jadwal';

    /** A worker has at most this many schedule rows on one date. */
    private const MAX_SCHEDULES_PER_DAY = 3;

    /** @var array<string, class-string<Employee|Intern|Freelancer>> */
    private const WORKER_TYPES = [
        'employee' => Employee::class,
        'intern' => Intern::class,
        'freelancer' => Freelancer::class,
    ];

    public function __construct(private readonly WorkScheduleService $workSchedule) {}

    /**
     * The active shift of the worker's company the check-in covers. Every off-schedule check-in names one, so
     * its lateness, early leave and the approved roster always have real hours (never a shift-less day).
     *
     * @throws AttendanceRuleException When no shift was picked, or it is unknown, inactive or of another company.
     */
    public function coverShift(Employee|Intern|Freelancer $worker, ?int $shiftId): Shift
    {
        if ($shiftId === null) {
            $hasShifts = Shift::query()->active()->where('company_id', $worker->company_id)->exists();

            throw new AttendanceRuleException(
                $hasShifts
                    ? 'Pilih shift yang Anda gantikan untuk masuk di luar jadwal.'
                    : 'Belum ada shift aktif di badan usaha Anda, jadi masuk di luar jadwal belum bisa dicatat. Hubungi koordinator atau PIC.',
                422,
                'cover_shift_id',
            );
        }

        $shift = Shift::query()->active()->whereKey($shiftId)->where('company_id', $worker->company_id)->first();

        if (! $shift) {
            throw new AttendanceRuleException('Shift yang dipilih tidak tersedia untuk badan usaha Anda.', 422, 'cover_shift_id');
        }

        return $shift;
    }

    /**
     * The outlet a cover check-in names ("cover_location_id"): an active outlet of the worker's company, or
     * of another company of the same client (a worker lent to a sister brand). Null when none is named.
     *
     * @throws AttendanceRuleException When the outlet is unknown, inactive or outside the client.
     */
    public function coverLocation(Employee|Intern|Freelancer $worker, ?int $locationId): ?CompanyLocation
    {
        if ($locationId === null) {
            return null;
        }

        $outlet = CompanyLocation::query()->with('company')->where('is_active', true)->find($locationId);
        $sameCompany = $outlet && (int) $outlet->company_id === (int) $worker->company_id;
        $sameClient = $outlet && $worker->company?->tenant_id !== null && (int) $outlet->company?->tenant_id === (int) $worker->company->tenant_id;

        if (! $sameCompany && ! $sameClient) {
            throw new AttendanceRuleException('Outlet yang dipilih tidak tersedia untuk Anda.', 422, 'cover_location_id');
        }

        return $outlet;
    }

    /**
     * The colleague named as replaced ("employee:12", or "employee:12#45" naming their schedule row 45), with
     * their schedule of the date: someone else of the same company who is rostered to work that date. With a
     * cover shift, the colleague must be rostered on that very shift: approving a backup who covers Pagi must
     * never give the colleague's Sore shift the day off (cover options loaded before a swap still name the
     * colleague on the old shift). Without a named row, a row of that shift no other backup replaces yet is
     * taken first (D1: two rows on the same shift at two outlets are replaced one by one).
     *
     * @return array{worker: Employee|Intern|Freelancer, schedule: ShiftSchedule}|null
     *
     * @throws AttendanceRuleException When the key does not name such a colleague, or the colleague works another shift.
     */
    public function replacedWorker(Employee|Intern|Freelancer $worker, ?string $key, CarbonInterface $date, ?Shift $coverShift = null): ?array
    {
        if (blank($key)) {
            return null;
        }

        $colleague = $this->workerFromKey((string) $key);
        $invalid = new AttendanceRuleException('Rekan yang digantikan harus rekan satu badan usaha yang terjadwal masuk hari ini.', 422, 'replaces_worker');

        if (! $colleague || (int) $colleague->company_id !== (int) $worker->company_id
            || ($colleague::class === $worker::class && (int) $colleague->getKey() === (int) $worker->getKey())) {
            throw $invalid;
        }

        $scheduleId = $this->scheduleIdFromKey((string) $key);
        $schedule = $scheduleId !== null
            ? $this->workingSchedules($colleague, $date)->first(fn (ShiftSchedule $row): bool => (int) $row->getKey() === $scheduleId)
            : $this->unclaimedSchedule($colleague, $date, $worker, $coverShift?->getKey());

        if (! $schedule) {
            throw $invalid;
        }

        if ($coverShift && $schedule->shift_id && (int) $schedule->shift_id !== (int) $coverShift->getKey()) {
            throw new AttendanceRuleException('Shift yang dipilih bukan shift rekan yang digantikan. Muat ulang pilihan lalu pilih lagi.', 422, 'replaces_worker');
        }

        $this->ensureNotReplacedYet($colleague, $date, $worker, $schedule);

        return ['worker' => $colleague, 'schedule' => $schedule];
    }

    /**
     * The colleague's working row of the date on the shift (else their first working row) that no other
     * backup's cover replaces yet; the first such row when every one is taken.
     */
    private function unclaimedSchedule(Employee|Intern|Freelancer $colleague, CarbonInterface $date, Employee|Intern|Freelancer $backup, ?int $shiftId): ?ShiftSchedule
    {
        $rows = $this->workingSchedules($colleague, $date);
        $onShift = $shiftId !== null ? $rows->filter(fn (ShiftSchedule $row): bool => (int) $row->shift_id === $shiftId)->values() : collect();

        if ($onShift->count() > 1) {
            return $onShift->first(fn (ShiftSchedule $row): bool => ! $this->replacementClaims($colleague, $date, $backup, $row)->exists()) ?? $onShift->first();
        }

        return $onShift->first() ?? $rows->first();
    }

    /**
     * One schedule is replaced by one backup: refuse when another worker's check-in of the date (pending or
     * approved, not rejected) already names this colleague as replaced on the same schedule row. A colleague
     * with several schedules (D1) can be replaced on each by a different backup, also two rows on the same
     * shift at two outlets. A cover saved before the row was recorded counts for every row of its shift. Run
     * again under the colleague's roster lock when the check-in is saved, so two backups racing for the same
     * schedule cannot both land.
     *
     * @param  ShiftSchedule|null  $schedule  the covered row; null refuses any replacement of the colleague that date
     *
     * @throws AttendanceRuleException
     */
    public function ensureNotReplacedYet(Employee|Intern|Freelancer $colleague, CarbonInterface $date, Employee|Intern|Freelancer $backup, ?ShiftSchedule $schedule = null): void
    {
        if ($this->replacementClaims($colleague, $date, $backup, $schedule)->exists()) {
            throw new AttendanceRuleException("{$colleague->full_name} sudah digantikan rekan lain hari ini. Pilih rekan lain atau kosongkan.", 422, 'replaces_worker');
        }
    }

    /**
     * Other backups' check-ins of the date (not rejected) that replace the colleague on the schedule row: by
     * the row they recorded, or, for a cover without one, by its shift.
     *
     * @return Builder<Attendance>
     */
    private function replacementClaims(Employee|Intern|Freelancer $colleague, CarbonInterface $date, Employee|Intern|Freelancer $backup, ?ShiftSchedule $schedule): Builder
    {
        $backupColumn = $this->workSchedule->workerColumn($backup);

        return Attendance::query()
            ->whereDate('date', $date->toDateString())
            ->where('replaced_worker_type', $this->typeOf($colleague))
            ->where('replaced_worker_id', $colleague->getKey())
            ->when($schedule !== null, fn (Builder $sameRow) => $sameRow->where(fn (Builder $row) => $row
                ->where('replaced_schedule_id', $schedule->getKey())
                ->orWhere(fn (Builder $older) => $older->whereNull('replaced_schedule_id')
                    ->when($schedule->shift_id !== null, fn (Builder $sameShift) => $sameShift->where('shift_id', $schedule->shift_id)))))
            ->where('status', '!=', AttendanceStatus::Rejected)
            ->where(fn (Builder $others) => $others->whereNull($backupColumn)->orWhere($backupColumn, '!=', $backup->getKey()));
    }

    /**
     * Lock the replaced colleague's roster row of the date (the lock their own check-in and an approved
     * replacement take), then check again that no other backup claimed them meanwhile.
     *
     * @throws AttendanceRuleException
     */
    public function lockReplacement(string $replacedType, int $replacedId, CarbonInterface $date, Employee|Intern|Freelancer $backup, ?int $shiftId = null, ?int $scheduleId = null): void
    {
        $colleague = $this->workerFromKey($replacedType.':'.$replacedId);

        if (! $colleague) {
            return;
        }

        $rows = $this->workingSchedules($colleague, $date, lock: true);
        $schedule = ($scheduleId !== null ? $rows->first(fn (ShiftSchedule $row): bool => (int) $row->getKey() === $scheduleId) : null)
            ?? ($shiftId !== null ? $rows->first(fn (ShiftSchedule $row): bool => (int) $row->shift_id === $shiftId) : null)
            ?? $rows->first();
        $this->ensureNotReplacedYet($colleague, $date, $backup, $schedule);
    }

    /**
     * The reason shown to the worker and the approver, e.g. "masuk di luar jadwal (menggantikan Budi)".
     */
    public function reason(Employee|Intern|Freelancer|null $replaced): string
    {
        return self::REASON.($replaced ? " (menggantikan {$replaced->full_name})" : '');
    }

    /**
     * What the app offers for an off-schedule check-in on the date: whether a check-in now would be off
     * schedule and why ("day_off": the date's roster gives a day off; "no_schedule": no schedule and no
     * standing hours, e.g. a blank day in a company that uses shift rosters), the active shifts of the
     * company with the worker's default shift pre-selected when it is one of them, and the colleagues
     * rostered that date.
     *
     * The outlets are those a cover can name ({@see self::coverLocation()}): the active outlets of the
     * worker's company and of the other companies of the same client.
     *
     * @return array{date: string, is_off_schedule: bool, off_schedule_reason: string|null, shifts: list<array<string, mixed>>, default_shift_id: int|null, colleagues: list<array<string, mixed>>, outlets: list<array{id: int, name: string}>}
     */
    public function options(Employee|Intern|Freelancer $worker, CarbonInterface $date): array
    {
        $rows = ShiftSchedule::query()
            ->with(['shift', 'location'])
            ->where($this->workSchedule->workerColumn($worker), $worker->getKey())
            ->whereDate('date', $date->toDateString())
            ->get();
        // Off schedule = no session of the date: a day off, or no schedule and no standing hours.
        $isOff = app(SessionResolver::class)->sessionsFor($worker, $date, $rows) === [];

        $shifts = Shift::query()->active()->where('company_id', $worker->company_id)->orderBy('start_time')->get()
            ->map(fn (Shift $shift): array => [
                'id' => (int) $shift->id,
                'name' => $shift->name,
                'code' => $shift->code,
                'start_time' => substr((string) $shift->start_time, 0, 5),
                'end_time' => substr((string) $shift->end_time, 0, 5),
                'segments' => $shift->segments(),
                'crosses_midnight' => $shift->crossesMidnight(),
            ])->values()->all();
        $defaultShiftId = $worker->shift_id !== null && collect($shifts)->contains('id', (int) $worker->shift_id) ? (int) $worker->shift_id : null;

        return [
            'date' => $date->toDateString(),
            'is_off_schedule' => $isOff,
            'off_schedule_reason' => match (true) {
                ! $isOff => null,
                $rows->contains(fn (ShiftSchedule $row): bool => (bool) $row->is_day_off) => 'day_off',
                default => 'no_schedule',
            },
            'shifts' => $shifts,
            'default_shift_id' => $defaultShiftId,
            'colleagues' => $this->colleagues($worker, $date),
            'outlets' => $this->coverOutlets($worker),
        ];
    }

    /**
     * The outlets a cover check-in may name, with the same scope {@see self::coverLocation()} accepts:
     * active outlets of the worker's company and of the other companies of the same client.
     *
     * @return list<array{id: int, name: string}>
     */
    private function coverOutlets(Employee|Intern|Freelancer $worker): array
    {
        $tenantId = $worker->loadMissing('company')->company?->tenant_id;

        return CompanyLocation::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query
                ->where('company_id', $worker->company_id)
                ->when($tenantId !== null, fn ($sameClient) => $sameClient->orWhereHas('company', fn ($company) => $company->where('tenant_id', $tenantId))))
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn (CompanyLocation $outlet): array => ['id' => (int) $outlet->id, 'name' => (string) $outlet->name])
            ->values()
            ->all();
    }

    /**
     * Colleagues of the same company rostered to work the date, with whether they already checked in.
     *
     * @return list<array{key: string, name: string, type: string, shift: array<string, mixed>|null, outlet: array{id: int, name: string}|null, has_checked_in: bool}>
     */
    private function colleagues(Employee|Intern|Freelancer $worker, CarbonInterface $date): array
    {
        $schedules = ShiftSchedule::query()
            ->with(['shift', 'location', 'employee', 'intern', 'freelancer'])
            ->whereDate('date', $date->toDateString())
            ->where('is_day_off', false)
            ->whereNotNull('shift_id')
            ->whereWorkerInCompanies([(int) $worker->company_id])
            ->where(fn ($others) => $others->whereNull($this->workSchedule->workerColumn($worker))->orWhere($this->workSchedule->workerColumn($worker), '!=', $worker->getKey()))
            ->get()
            ->filter(function (ShiftSchedule $schedule) use ($worker): bool {
                $colleague = $schedule->getWorkerProfile();

                return $colleague !== null && (int) $colleague->company_id === (int) $worker->company_id;
            });

        $checkedIn = $this->checkedInKeys($schedules, $date);
        $beingReplaced = $this->beingReplacedKeys($date, $worker);

        return $schedules->map(function (ShiftSchedule $schedule) use ($checkedIn, $beingReplaced): array {
            $colleague = $schedule->getWorkerProfile();
            $key = $this->keyOf($colleague);
            // Each schedule of a colleague is its own entry (D1): checked in and replaced per schedule.
            $sequenceKey = $key.'#'.(int) ($schedule->sequence ?: 1);

            return [
                'key' => $key,
                // The key naming this very schedule row, so a colleague's two rows on one shift are told apart.
                'schedule_key' => $key.'#'.(int) $schedule->getKey(),
                'schedule_id' => (int) $schedule->getKey(),
                'name' => (string) $colleague->full_name,
                'type' => $this->typeOf($colleague),
                'shift' => $schedule->shift ? [
                    'id' => (int) $schedule->shift->id,
                    'name' => $schedule->shift->name,
                    'start_time' => substr((string) $schedule->shift->start_time, 0, 5),
                    'end_time' => substr((string) $schedule->shift->end_time, 0, 5),
                ] : null,
                'outlet' => $schedule->location ? ['id' => (int) $schedule->location->id, 'name' => $schedule->location->name] : null,
                'has_checked_in' => isset($checkedIn[$sequenceKey]),
                'being_replaced' => isset($beingReplaced[$key.'@'.(int) $schedule->getKey()]) || isset($beingReplaced[$key.'|'.(int) $schedule->shift_id]),
            ];
        })->sortBy('name')->values()->all();
    }

    /**
     * Keys of the schedules another backup's check-in of the date (not rejected) already replaces:
     * "employee:7@45" (colleague and schedule row), or "employee:7|11" (colleague and covered shift) for a
     * cover saved before the row was recorded.
     *
     * @return array<string, true>
     */
    private function beingReplacedKeys(CarbonInterface $date, Employee|Intern|Freelancer $backup): array
    {
        $backupColumn = $this->workSchedule->workerColumn($backup);

        return Attendance::query()
            ->whereDate('date', $date->toDateString())
            ->whereNotNull('replaced_worker_type')
            ->whereNotNull('replaced_worker_id')
            ->where('status', '!=', AttendanceStatus::Rejected)
            ->where(fn ($others) => $others->whereNull($backupColumn)->orWhere($backupColumn, '!=', $backup->getKey()))
            ->get(['replaced_worker_type', 'replaced_worker_id', 'replaced_schedule_id', 'shift_id'])
            ->mapWithKeys(fn (Attendance $row): array => [$row->replaced_worker_type.':'.$row->replaced_worker_id.($row->replaced_schedule_id !== null ? '@'.(int) $row->replaced_schedule_id : '|'.(int) $row->shift_id) => true])
            ->all();
    }

    /**
     * Keys ("employee:7#2": colleague and schedule number) of the schedules already checked in.
     *
     * @param  Collection<int, ShiftSchedule>  $schedules
     * @return array<string, true>
     */
    private function checkedInKeys(Collection $schedules, CarbonInterface $date): array
    {
        $keys = [];
        $ids = ['employee_id' => [], 'intern_id' => [], 'freelancer_id' => []];

        foreach ($schedules as $schedule) {
            foreach (array_keys($ids) as $column) {
                if ($schedule->{$column}) {
                    $ids[$column][] = (int) $schedule->{$column};
                }
            }
        }

        foreach ($ids as $column => $workerIds) {
            if ($workerIds === []) {
                continue;
            }

            Attendance::query()->whereDate('date', $date->toDateString())->whereIn($column, $workerIds)->whereNotNull('check_in')
                ->get([$column, 'schedule_sequence'])
                ->each(function (Attendance $row) use (&$keys, $column): void {
                    $keys[str_replace('_id', '', $column).':'.$row->{$column}.'#'.(int) ($row->schedule_sequence ?: 1)] = true;
                });
        }

        return $keys;
    }

    /**
     * Apply an approved off-schedule check-in to the rosters of its date. Runs again harmlessly on a later
     * approval round of the same row (for example a check-out outside the geofence), and leaves alone a
     * roster the PIC already changed meanwhile. A legacy row without a covered shift changes no roster of
     * its own, so no shift-less working day is ever written.
     *
     * The cover's own roster row is always defined: a row of the date on the covered shift (one written by an
     * earlier part of the cover, by the PIC, or added meanwhile without attendance) is linked; else the day
     * off or shift-less row of the date becomes the covered shift; else a row is created at the attendance's
     * schedule number, or at the lowest free one, which the attendance then takes. A row that would overlap
     * another working schedule of the date, or a fourth schedule, is not written (the attendance stays unlinked).
     */
    public function applyApproval(Attendance $attendance): void
    {
        $worker = $attendance->getWorkerProfile();

        if (! $attendance->is_off_schedule || ! $worker || ! $attendance->date) {
            return;
        }

        $date = $attendance->date;
        $column = $this->workSchedule->workerColumn($worker);

        RosterChangeContext::run('cover', null, fn () => DB::transaction(function () use ($attendance, $worker, $date, $column): void {
            if ($attendance->shift_id && ! $attendance->shift_schedule_id && $attendance->shift) {
                // Looked at first, then decided again on the locked rows: a roster the PIC gave meanwhile is kept.
                ShiftSchedule::query()->where($column, $worker->getKey())->whereDate('date', $date->toDateString())->get();
                $this->placeCover($attendance, $worker, $date, $column);
            }

            $this->giveReplacedWorkerTheDayOff($attendance, $worker, $date);
        }));
    }

    /**
     * Find or write the cover's own roster row (see {@see self::applyApproval()}) and link the attendance to it.
     */
    private function placeCover(Attendance $attendance, Employee|Intern|Freelancer $worker, CarbonInterface $date, string $column): void
    {
        $rows = ShiftSchedule::query()
            ->with('shift')
            ->where($column, $worker->getKey())
            ->whereDate('date', $date->toDateString())
            ->orderBy('sequence')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $sequence = (int) ($attendance->schedule_sequence ?: 1);
        $shiftId = (int) $attendance->shift_id;

        $sameShift = $rows->first(fn (ShiftSchedule $row): bool => ! $row->is_day_off && (int) $row->shift_id === $shiftId && ((int) $row->sequence === $sequence || ! $row->isLocked()));

        if ($sameShift) {
            $this->linkToSchedule($attendance, $sameShift);

            return;
        }

        $working = $rows->filter(fn (ShiftSchedule $row): bool => ! $row->is_day_off && $row->shift_id !== null);
        $convertible = $rows->first(fn (ShiftSchedule $row): bool => (int) $row->sequence === $sequence && ($row->is_day_off || ($row->shift_id === null && $this->isOffScheduleRow($worker, $row))))
            ?? ($working->isEmpty() ? $rows->first(fn (ShiftSchedule $row): bool => (bool) $row->is_day_off) : null);

        if ($working->count() >= self::MAX_SCHEDULES_PER_DAY || $this->overlapsWorkingRows($attendance, $working)) {
            return;
        }

        if ($convertible) {
            $convertible->fill([
                'shift_id' => $attendance->shift_id,
                'company_location_id' => $attendance->company_location_id,
                'is_day_off' => false,
                'notes' => 'Masuk di luar jadwal (disetujui)',
            ])->save();

            $this->linkToSchedule($attendance, $convertible);

            return;
        }

        $taken = $rows->pluck('sequence')
            ->merge(Attendance::query()->where($column, $worker->getKey())->whereDate('date', $date->toDateString())->whereNotNull('check_in')->where('schedule_sequence', '!=', $sequence)->pluck('schedule_sequence'))
            ->map(fn (mixed $value): int => (int) ($value ?: 1))
            ->all();
        $target = $sequence;

        while (in_array($target, $taken, true)) {
            $target++;
        }

        $schedule = ShiftSchedule::query()->create([
            $column => $worker->getKey(),
            'date' => $date->toDateString(),
            'sequence' => $target,
            'shift_id' => $attendance->shift_id,
            'company_location_id' => $attendance->company_location_id,
            'is_day_off' => false,
            'notes' => 'Masuk di luar jadwal (disetujui)',
        ]);

        $this->linkToSchedule($attendance, $schedule);
    }

    /**
     * Whether the covered shift, on the attendance's date, overlaps one of the working rows of that date.
     *
     * @param  Collection<int, ShiftSchedule>  $working
     */
    private function overlapsWorkingRows(Attendance $attendance, Collection $working): bool
    {
        $windows = fn (Shift $shift): array => array_map(
            fn (array $part): array => AttendanceFigures::window($attendance->date->toDateString(), $part['start'], $part['end']),
            $shift->segments(),
        );
        $cover = $windows($attendance->shift);

        foreach ($working as $row) {
            foreach ($windows($row->shift) as [$start, $end]) {
                foreach ($cover as [$coverStart, $coverEnd]) {
                    if ($coverStart < $end && $start < $coverEnd) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Tie the approved cover to its roster row (the link and the row's number only; the hours kept on the
     * attendance stay those of the check-in). Every part of the same cover moves with it, so the attendance
     * keeps the schedule number of its row. A row that names another shift is not the schedule it belongs to.
     */
    private function linkToSchedule(Attendance $attendance, ?ShiftSchedule $schedule): void
    {
        if (! $schedule || $schedule->is_day_off || (int) $schedule->shift_id !== (int) $attendance->shift_id) {
            return;
        }

        if ((int) $attendance->shift_schedule_id === (int) $schedule->getKey()) {
            return;
        }

        $worker = $attendance->getWorkerProfile();
        $sequence = (int) ($attendance->schedule_sequence ?: 1);
        $parts = $worker
            ? Attendance::query()
                ->where($this->workSchedule->workerColumn($worker), $worker->getKey())
                ->whereDate('date', $attendance->date->toDateString())
                ->where('schedule_sequence', $sequence)
                ->where('is_off_schedule', true)
                ->where('shift_id', $attendance->shift_id)
                ->whereNull('shift_schedule_id')
                ->get()
            : collect([$attendance]);

        foreach ($parts->isEmpty() ? collect([$attendance]) : $parts as $part) {
            $part->forceFill([
                'shift_schedule_id' => $schedule->getKey(),
                'schedule_sequence' => (int) ($schedule->sequence ?: 1),
            ])->saveQuietly();
        }

        $attendance->forceFill([
            'shift_schedule_id' => $schedule->getKey(),
            'schedule_sequence' => (int) ($schedule->sequence ?: 1),
        ])->syncOriginalAttributes(['shift_schedule_id', 'schedule_sequence']);

        // A row the cover wrote itself is now its own row, which does not put the worker on a roster (ShiftSchedule::whereRosterRow()).
        if ($worker && app()->resolved(SessionResolver::class)) {
            app(SessionResolver::class)->forgetRosteredSince($this->workSchedule->workerColumn($worker), (int) $worker->getKey());
        }
    }

    /**
     * Whether the (locked) roster row leaves the worker off schedule: a day off, or a row without a shift in a
     * company that uses shift rosters (a blank day). Elsewhere a row without a shift is a legacy placement
     * that plans the standing hours, for a freelancer without a default shift too.
     */
    private function isOffScheduleRow(Employee|Intern|Freelancer $worker, ShiftSchedule $schedule): bool
    {
        if (! $schedule->is_day_off && ! $schedule->shift_id && $worker->loadMissing('company.policy')->company?->policy?->uses_shift_roster) {
            return true;
        }

        $shift = $schedule->shift ?? ($worker->shift_id ? $worker->shift : null);

        return $this->workSchedule->isOffSchedule($worker, [
            'is_day_off' => (bool) $schedule->is_day_off,
            'shift' => $shift,
            'source' => $shift ? 'schedule' : 'policy',
            // A row without a shift is a legacy placement here (a roster company returned above).
            'is_placement' => ! $schedule->is_day_off && ! $schedule->shift_id,
        ]);
    }

    /**
     * The replaced colleague's row of the covered shift is given up: turned into a day off when it is their
     * only schedule of the date, removed when they have others. Left alone when the colleague came after all
     * (checked in on it), is on leave, or was moved to another shift meanwhile.
     */
    private function giveReplacedWorkerTheDayOff(Attendance $attendance, Employee|Intern|Freelancer $worker, CarbonInterface $date): void
    {
        $class = self::WORKER_TYPES[$attendance->replaced_worker_type] ?? null;
        $replaced = $class && $attendance->replaced_worker_id ? $class::query()->find($attendance->replaced_worker_id) : null;

        if (! $replaced) {
            return;
        }

        $replacedColumn = $this->workSchedule->workerColumn($replaced);

        // The colleague's roster rows are locked first: their check-in reads them under the same lock
        // (AttendanceCheckService::saveCheckIn()), so either their check-in lands first and is seen below,
        // or this change lands first and their check-in is refused as a changed schedule.
        $rows = $this->workingSchedules($replaced, $date, lock: true);
        $schedule = $attendance->replaced_schedule_id !== null
            ? $rows->first(fn (ShiftSchedule $row): bool => (int) $row->getKey() === (int) $attendance->replaced_schedule_id)
            : ($attendance->shift_id ? $rows->first(fn (ShiftSchedule $row): bool => (int) $row->shift_id === (int) $attendance->shift_id) : null) ?? $rows->first();

        // Re-rostered meanwhile on another shift than the one covered (the PIC moved them, or a swap): the
        // backup did not replace that shift, so the colleague's roster stands.
        if (! $schedule || ($attendance->shift_id && (int) $schedule->shift_id !== (int) $attendance->shift_id)) {
            return;
        }

        $others = ShiftSchedule::query()
            ->where($replacedColumn, $replaced->getKey())
            ->whereDate('date', $date->toDateString())
            ->whereKeyNot($schedule->getKey())
            ->where('is_day_off', false)
            ->whereNotNull('shift_id')
            ->exists();

        // Came to work after all (on that schedule, or on the day when it is their only one), or on an approved
        // leave: their own attendance (or leave) stands and the roster is left as it is.
        $hasCheckedIn = Attendance::query()
            ->where($replacedColumn, $replaced->getKey())
            ->whereDate('date', $date->toDateString())
            ->whereNotNull('check_in')
            ->when($others, fn ($query) => $query->where('schedule_sequence', (int) ($schedule->sequence ?: 1)))
            ->lockForUpdate()
            ->exists();

        if ($hasCheckedIn || $schedule->isLocked() || $this->isOnApprovedLeave($replaced, $date)) {
            return;
        }

        if ($others) {
            $schedule->delete();

            return;
        }

        $schedule->fill([
            'is_day_off' => true,
            'notes' => 'Digantikan oleh '.$worker->full_name,
        ])->save();
    }

    /**
     * Whether the worker has a leave row of the date, or an approved leave request covering it.
     */
    private function isOnApprovedLeave(Employee|Intern|Freelancer $worker, CarbonInterface $date): bool
    {
        $column = $this->workSchedule->workerColumn($worker);
        $day = $date->toDateString();

        $hasLeaveRow = Attendance::query()->where($column, $worker->getKey())->whereDate('date', $day)->where('status', AttendanceStatus::Leave)->exists();

        return $hasLeaveRow || LeaveRequest::query()
            ->where($column, $worker->getKey())
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $day)
            ->whereDate('end_date', '>=', $day)
            ->exists();
    }

    /**
     * The worker's dated working schedules of the date (not a day off), by sequence; with $lock read under a lock.
     *
     * @return Collection<int, ShiftSchedule>
     */
    private function workingSchedules(Employee|Intern|Freelancer $worker, CarbonInterface $date, bool $lock = false): Collection
    {
        return ShiftSchedule::query()
            ->with('location')
            ->where($this->workSchedule->workerColumn($worker), $worker->getKey())
            ->whereDate('date', $date->toDateString())
            ->where('is_day_off', false)
            ->orderBy('sequence')
            ->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get();
    }

    /**
     * The outlet the replaced colleague was rostered at, where the backup works that day.
     *
     * @param  array{worker: Employee|Intern|Freelancer, schedule: ShiftSchedule}|null  $replaced
     */
    public function outletOf(?array $replaced): ?CompanyLocation
    {
        return $replaced['schedule']->location ?? null;
    }

    public function workerFromKey(string $key): Employee|Intern|Freelancer|null
    {
        [$type, $id] = array_pad(explode(':', explode('#', $key, 2)[0], 2), 2, null);
        $class = self::WORKER_TYPES[$type] ?? null;

        if (! $class || ! ctype_digit((string) $id)) {
            return null;
        }

        return $class::query()->find((int) $id);
    }

    /**
     * The schedule row a colleague key names ("employee:12#45" => 45), or null for a plain worker key.
     */
    public function scheduleIdFromKey(string $key): ?int
    {
        $row = explode('#', $key, 2)[1] ?? null;

        return $row !== null && ctype_digit($row) ? (int) $row : null;
    }

    public function typeOf(Employee|Intern|Freelancer $worker): string
    {
        return (string) array_search($worker::class, self::WORKER_TYPES, true);
    }

    public function keyOf(Employee|Intern|Freelancer $worker): string
    {
        return $this->typeOf($worker).':'.$worker->getKey();
    }
}
