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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Masuk di luar jadwal": an emergency backup check-in on a day the worker is not rostered (a dated day
 * off, or a freelancer without any shift). The check-in is accepted but waits for approval through the
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
     * The colleague named as replaced ("employee:12"), with their schedule of the date: someone else of the
     * same company who is rostered to work that date. With a cover shift, the colleague must be rostered on
     * that very shift: approving a backup who covers Pagi must never give the colleague's Sore shift the day
     * off (cover options loaded before a swap still name the colleague on the old shift).
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

        $schedule = $this->workingSchedule($colleague, $date);

        if (! $schedule) {
            throw $invalid;
        }

        if ($coverShift && $schedule->shift_id && (int) $schedule->shift_id !== (int) $coverShift->getKey()) {
            throw new AttendanceRuleException('Shift yang dipilih bukan shift rekan yang digantikan. Muat ulang pilihan lalu pilih lagi.', 422, 'replaces_worker');
        }

        $this->ensureNotReplacedYet($colleague, $date, $worker);

        return ['worker' => $colleague, 'schedule' => $schedule];
    }

    /**
     * One shift is replaced by one backup: refuse when another worker's check-in of the date (pending or
     * approved, not rejected) already names this colleague as replaced. Run again under the colleague's
     * roster lock when the check-in is saved, so two backups racing for the same colleague cannot both land.
     *
     * @throws AttendanceRuleException
     */
    public function ensureNotReplacedYet(Employee|Intern|Freelancer $colleague, CarbonInterface $date, Employee|Intern|Freelancer $backup): void
    {
        $backupColumn = $this->workSchedule->workerColumn($backup);

        $claimed = Attendance::query()
            ->whereDate('date', $date->toDateString())
            ->where('replaced_worker_type', $this->typeOf($colleague))
            ->where('replaced_worker_id', $colleague->getKey())
            ->where('status', '!=', AttendanceStatus::Rejected)
            ->where(fn ($others) => $others->whereNull($backupColumn)->orWhere($backupColumn, '!=', $backup->getKey()))
            ->exists();

        if ($claimed) {
            throw new AttendanceRuleException("{$colleague->full_name} sudah digantikan rekan lain hari ini. Pilih rekan lain atau kosongkan.", 422, 'replaces_worker');
        }
    }

    /**
     * Lock the replaced colleague's roster row of the date (the lock their own check-in and an approved
     * replacement take), then check again that no other backup claimed them meanwhile.
     *
     * @throws AttendanceRuleException
     */
    public function lockReplacement(string $replacedType, int $replacedId, CarbonInterface $date, Employee|Intern|Freelancer $backup): void
    {
        $colleague = $this->workerFromKey($replacedType.':'.$replacedId);

        if (! $colleague) {
            return;
        }

        $this->workingSchedule($colleague, $date, lock: true);
        $this->ensureNotReplacedYet($colleague, $date, $backup);
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
     * schedule, the active shifts of the company, and the colleagues rostered that date.
     *
     * @return array{date: string, is_off_schedule: bool, off_schedule_reason: string|null, shifts: list<array<string, mixed>>, colleagues: list<array<string, mixed>>}
     */
    public function options(Employee|Intern|Freelancer $worker, CarbonInterface $date): array
    {
        $hours = $this->workSchedule->resolve($worker, $date);
        $isOff = $this->workSchedule->isOffSchedule($worker, $hours);

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

        return [
            'date' => $date->toDateString(),
            'is_off_schedule' => $isOff,
            'off_schedule_reason' => match (true) {
                ! $isOff => null,
                $hours['is_day_off'] => 'day_off',
                default => 'no_schedule',
            },
            'shifts' => $shifts,
            'colleagues' => $this->colleagues($worker, $date),
        ];
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

            return [
                'key' => $key,
                'name' => (string) $colleague->full_name,
                'type' => $this->typeOf($colleague),
                'shift' => $schedule->shift ? [
                    'id' => (int) $schedule->shift->id,
                    'name' => $schedule->shift->name,
                    'start_time' => substr((string) $schedule->shift->start_time, 0, 5),
                    'end_time' => substr((string) $schedule->shift->end_time, 0, 5),
                ] : null,
                'outlet' => $schedule->location ? ['id' => (int) $schedule->location->id, 'name' => $schedule->location->name] : null,
                'has_checked_in' => isset($checkedIn[$key]),
                'being_replaced' => isset($beingReplaced[$key]),
            ];
        })->sortBy('name')->values()->all();
    }

    /**
     * Keys of the colleagues another backup's check-in of the date (not rejected) already replaces.
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
            ->get(['replaced_worker_type', 'replaced_worker_id'])
            ->mapWithKeys(fn (Attendance $row): array => [$row->replaced_worker_type.':'.$row->replaced_worker_id => true])
            ->all();
    }

    /**
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
                ->pluck($column)
                ->each(function (int|string $id) use (&$keys, $column): void {
                    $keys[str_replace('_id', '', $column).':'.$id] = true;
                });
        }

        return $keys;
    }

    /**
     * Apply an approved off-schedule check-in to the rosters of its date. Runs again harmlessly on a later
     * approval round of the same row (for example a check-out outside the geofence), and leaves alone a
     * roster the PIC already changed meanwhile. A legacy row without a covered shift changes no roster of
     * its own, so no shift-less working day is ever written.
     */
    public function applyApproval(Attendance $attendance): void
    {
        $worker = $attendance->getWorkerProfile();

        if (! $attendance->is_off_schedule || ! $worker || ! $attendance->date) {
            return;
        }

        $date = $attendance->date;
        $column = $this->workSchedule->workerColumn($worker);

        DB::transaction(fn () => AttendanceLatenessService::withoutRosterRecalculation(function () use ($attendance, $worker, $date, $column): void {
            if ($attendance->shift_id && $this->workSchedule->isOffSchedule($worker, $this->workSchedule->resolve($worker, $date))) {
                // Decided again on the locked row: a roster the PIC gave meanwhile is kept, not overwritten.
                $schedule = ShiftSchedule::query()->where($column, $worker->getKey())->whereDate('date', $date->toDateString())->lockForUpdate()->first();

                if (! $schedule || $this->isOffScheduleRow($worker, $schedule)) {
                    ($schedule ?? new ShiftSchedule([$column => $worker->getKey(), 'date' => $date->toDateString()]))->fill([
                        'shift_id' => $attendance->shift_id,
                        'company_location_id' => $attendance->company_location_id,
                        'is_day_off' => false,
                        'notes' => 'Masuk di luar jadwal (disetujui)',
                    ])->save();
                }
            }

            $this->giveReplacedWorkerTheDayOff($attendance, $worker, $date);
        }));
    }

    /**
     * Whether the (locked) roster row leaves the worker off schedule: a day off, or a freelancer without a
     * shift on it nor a default shift.
     */
    private function isOffScheduleRow(Employee|Intern|Freelancer $worker, ShiftSchedule $schedule): bool
    {
        $shift = $schedule->shift ?? ($worker->shift_id ? $worker->shift : null);

        return $this->workSchedule->isOffSchedule($worker, [
            'is_day_off' => (bool) $schedule->is_day_off,
            'shift' => $shift,
            'source' => $shift ? 'schedule' : 'policy',
        ]);
    }

    private function giveReplacedWorkerTheDayOff(Attendance $attendance, Employee|Intern|Freelancer $worker, CarbonInterface $date): void
    {
        $class = self::WORKER_TYPES[$attendance->replaced_worker_type] ?? null;
        $replaced = $class && $attendance->replaced_worker_id ? $class::query()->find($attendance->replaced_worker_id) : null;

        if (! $replaced) {
            return;
        }

        $replacedColumn = $this->workSchedule->workerColumn($replaced);

        // The colleague's roster row is locked first: their check-in reads it under the same lock
        // (AttendanceCheckService::saveCheckIn()), so either their check-in lands first and is seen below,
        // or this day off lands first and their check-in is refused as a changed schedule.
        $schedule = $this->workingSchedule($replaced, $date, lock: true);

        // Re-rostered meanwhile on another shift than the one covered (the PIC moved them, or a swap): the
        // backup did not replace that shift, so the colleague's roster stands.
        if (! $schedule || ($attendance->shift_id && (int) $schedule->shift_id !== (int) $attendance->shift_id)) {
            return;
        }

        // Came to work after all, or on an approved leave: their own attendance (or leave) stands and the
        // roster is left as it is.
        $hasCheckedIn = Attendance::query()->where($replacedColumn, $replaced->getKey())->whereDate('date', $date->toDateString())->whereNotNull('check_in')->lockForUpdate()->exists();

        if ($hasCheckedIn || $this->isOnApprovedLeave($replaced, $date)) {
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
     * The worker's dated schedule of the date when it is a working one (not a day off).
     */
    private function workingSchedule(Employee|Intern|Freelancer $worker, CarbonInterface $date, bool $lock = false): ?ShiftSchedule
    {
        return ShiftSchedule::query()
            ->where($this->workSchedule->workerColumn($worker), $worker->getKey())
            ->whereDate('date', $date->toDateString())
            ->where('is_day_off', false)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
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
        [$type, $id] = array_pad(explode(':', $key, 2), 2, null);
        $class = self::WORKER_TYPES[$type] ?? null;

        if (! $class || ! ctype_digit((string) $id)) {
            return null;
        }

        return $class::query()->find((int) $id);
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
