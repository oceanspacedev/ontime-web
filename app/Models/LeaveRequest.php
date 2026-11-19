<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Services\AnnualLeaveService;
use App\Services\OvertimeRequestService;
use App\Traits\ScopesToWorkerVisibility;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

class LeaveRequest extends Model
{
    use HasFactory, ScopesToWorkerVisibility;

    /** The only leave type that uses the yearly quota. */
    public const TYPE_ANNUAL = 'annual_leave';

    /**
     * Leave types shared by the panel and the API, with their labels.
     *
     * @var array<string, string>
     */
    public const TYPES = [
        self::TYPE_ANNUAL => 'Cuti Tahunan',
        'permission' => 'Izin',
        'sick' => 'Sakit',
        'maternity' => 'Cuti Melahirkan',
        'unpaid' => 'Cuti Tanpa Gaji',
    ];

    /**
     * Codes older versions of the mobile app send.
     *
     * @var array<string, string>
     */
    public const TYPE_ALIASES = [
        'annual' => self::TYPE_ANNUAL,
        'permitted' => 'permission',
    ];

    /** How many days back a leave may start, so a forgotten sick day can still be filed. */
    public const MAX_DAYS_BEFORE_TODAY = 31;

    /** How far ahead a leave may start. */
    public const MAX_DAYS_AFTER_TODAY = 365;

    /** The longest leave in one request (six months of maternity leave fits). */
    public const MAX_DAYS_PER_REQUEST = 180;

    /**
     * Why the period cannot be filed, keyed by the field to show it on, or an empty array: a start
     * more than {@see MAX_DAYS_BEFORE_TODAY} days back or {@see MAX_DAYS_AFTER_TODAY} days ahead, or
     * more than {@see MAX_DAYS_PER_REQUEST} days in one request. Approving a leave writes one
     * attendance row per day, so an unbounded period (year 1 to 9999) is refused here.
     *
     * @return array<string, string>
     */
    public static function periodErrors(CarbonInterface $startDate, CarbonInterface $endDate): array
    {
        $start = Carbon::parse($startDate->toDateString());
        $end = Carbon::parse($endDate->toDateString());

        if ($start->lessThan(today()->subDays(self::MAX_DAYS_BEFORE_TODAY))) {
            return ['start_date' => 'Tanggal mulai paling lama '.self::MAX_DAYS_BEFORE_TODAY.' hari sebelum hari ini.'];
        }

        if ($start->greaterThan(today()->addDays(self::MAX_DAYS_AFTER_TODAY))) {
            return ['start_date' => 'Tanggal mulai paling jauh '.self::MAX_DAYS_AFTER_TODAY.' hari setelah hari ini.'];
        }

        if ($end->greaterThanOrEqualTo($start) && (int) $start->diffInDays($end) + 1 > self::MAX_DAYS_PER_REQUEST) {
            return ['end_date' => 'Satu pengajuan paling lama '.self::MAX_DAYS_PER_REQUEST.' hari. Bagi menjadi beberapa pengajuan.'];
        }

        return [];
    }

    public static function normalizeType(mixed $type): mixed
    {
        return is_string($type) ? (self::TYPE_ALIASES[$type] ?? $type) : $type;
    }

    public static function typeLabel(?string $type): string
    {
        return self::TYPES[self::normalizeType($type)] ?? (string) $type;
    }

    protected $fillable = [
        'employee_id',
        'intern_id',
        'freelancer_id',
        'leave_type',
        'start_date',
        'end_date',
        'days_count',
        'reason',
        'attachment',
        'status',
        'current_step',
        'rejection_reason',
        'charged_dates',
    ];

    /**
     * Deleting an approved leave also undoes the leave rows its approval wrote (rows without a check-in),
     * so the days do not stay marked as leave ({@see self::undoLeaveRows()}).
     */
    protected static function booted(): void
    {
        static::deleted(function (LeaveRequest $leave): void {
            if ($leave->status === 'approved' && $leave->start_date && $leave->end_date) {
                $leave->undoLeaveRows($leave->chargedDateStrings());
            }
        });
    }

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'days_count' => 'integer',
            'current_step' => 'integer',
            'charged_dates' => 'array',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function intern(): BelongsTo
    {
        return $this->belongsTo(Intern::class);
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class);
    }

    public function approvalSteps(): MorphMany
    {
        return $this->morphMany(ApprovalRequestStep::class, 'approvable');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Returns the linked worker profile regardless of type.
     */
    public function getWorkerProfile(): Employee|Intern|Freelancer|null
    {
        return $this->employee ?? $this->intern ?? $this->freelancer;
    }

    /**
     * Returns whether this leave request was submitted by a freelancer
     * (freelancers do not consume quota from company policy).
     */
    public function isFromFreelancer(): bool
    {
        return $this->freelancer_id !== null;
    }

    /**
     * The dates the leave is charged for, as Y-m-d: the list stored at approval, or every date of the
     * range for a request from before that list was stored.
     *
     * @return list<string>
     */
    public function chargedDateStrings(): array
    {
        if (is_array($this->charged_dates)) {
            return array_values(array_map(fn (mixed $date): string => substr((string) $date, 0, 10), $this->charged_dates));
        }

        if (! $this->start_date || ! $this->end_date) {
            return [];
        }

        $dates = [];

        for ($date = $this->start_date->copy()->startOfDay(); $date->lte($this->end_date); $date->addDay()) {
            $dates[] = $date->toDateString();
        }

        return $dates;
    }

    /**
     * The dates charged by approved leave of these workers inside the period, per worker id: the stored
     * list of each request, or every date of its range for a request from before that list was stored.
     * The one reader of approved leave days for the planning views and the roster writers.
     *
     * @param  'employee_id'|'intern_id'|'freelancer_id'  $column
     * @param  array<int, int|string>  $workerIds
     * @return array<int|string, array<string, true>>
     */
    public static function chargedDatesByWorker(string $column, array $workerIds, CarbonInterface $from, CarbonInterface $until): array
    {
        $charged = [];
        $first = $from->toDateString();
        $last = $until->toDateString();

        if ($workerIds === []) {
            return $charged;
        }

        static::query()
            ->whereIn($column, array_values($workerIds))
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $last)
            ->whereDate('end_date', '>=', $first)
            ->get([$column, 'start_date', 'end_date', 'charged_dates'])
            ->each(function (LeaveRequest $leave) use (&$charged, $column, $first, $last): void {
                foreach ($leave->chargedDateStrings() as $date) {
                    if ($date >= $first && $date <= $last) {
                        $charged[$leave->{$column}][$date] = true;
                    }
                }
            });

        return $charged;
    }

    // ── Actions ──────────────────────────────────────────────────────────────

    /**
     * The worker's attendance rows on the leave days. A split-shift day has one row per segment.
     *
     * @return Builder<Attendance>
     */
    private function attendanceOnLeaveDays(): Builder
    {
        return Attendance::query()
            ->where(array_filter([
                'employee_id' => $this->employee_id,
                'intern_id' => $this->intern_id,
                'freelancer_id' => $this->freelancer_id,
            ]))
            ->whereDate('date', '>=', $this->start_date->toDateString())
            ->whereDate('date', '<=', $this->end_date->toDateString());
    }

    /**
     * The worker's leave rows (status leave, no check-in) on these dates.
     *
     * @param  list<string>  $dates
     * @return Builder<Attendance>
     */
    private function leaveRowsOn(array $dates): Builder
    {
        return Attendance::query()
            ->where(array_filter([
                'employee_id' => $this->employee_id,
                'intern_id' => $this->intern_id,
                'freelancer_id' => $this->freelancer_id,
            ]))
            ->where(fn (Builder $query) => $this->whereDateIn($query, $dates))
            ->where('status', AttendanceStatus::Leave->value)
            ->whereNull('check_in');
    }

    /**
     * Undo the leave on these dates: a leave row the approval created is removed; a row that existed before
     * and was only turned into a leave row (a placeholder, an absent row) gets its status and notes from
     * before back ({@see Attendance::$state_before_leave}). A date another approved leave of the worker still
     * charges stays a leave day.
     *
     * @param  list<string>  $dates
     */
    private function undoLeaveRows(array $dates): void
    {
        $dates = array_values(array_diff($dates, $this->datesChargedByOtherLeave($dates)));

        $this->leaveRowsOn($dates)->whereNull('state_before_leave')->delete();

        $this->leaveRowsOn($dates)->whereNotNull('state_before_leave')->get()->each(function (Attendance $row): void {
            $before = (array) $row->state_before_leave;

            $row->forceFill([
                'status' => $before['status'] ?? AttendanceStatus::Absent->value,
                'notes' => $before['notes'] ?? null,
                'state_before_leave' => null,
            ] + array_intersect_key($before, array_flip(['check_in', 'check_out', 'check_in_late_minutes', 'early_leave_minutes', 'is_off_schedule', 'replaced_worker_type', 'replaced_worker_id', 'replaced_schedule_id'])))->save();
        });
    }

    /**
     * Which of these dates another approved leave of the same worker charges.
     *
     * @param  list<string>  $dates
     * @return list<string>
     */
    private function datesChargedByOtherLeave(array $dates): array
    {
        if ($dates === []) {
            return [];
        }

        $column = collect(['employee_id', 'intern_id', 'freelancer_id'])->first(fn (string $column): bool => $this->{$column} !== null);

        if ($column === null) {
            return [];
        }

        $wanted = array_flip($dates);

        return static::query()
            ->where($column, $this->{$column})
            ->where('status', 'approved')
            ->when($this->exists, fn (Builder $others) => $others->whereKeyNot($this->getKey()))
            ->whereDate('start_date', '<=', max($dates))
            ->whereDate('end_date', '>=', min($dates))
            ->get()
            ->flatMap(fn (LeaveRequest $other): array => $other->chargedDateStrings())
            ->filter(fn (string $date): bool => isset($wanted[$date]))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Limit the query to rows on one of the dates (none when the list is empty).
     *
     * @param  Builder<Attendance>  $query
     * @param  list<string>  $dates
     */
    private function whereDateIn(Builder $query, array $dates): void
    {
        if ($dates === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        foreach ($dates as $date) {
            $query->orWhereDate('date', $date);
        }
    }

    /**
     * Charged leave days on which the worker already checked in (any segment, including a check-in
     * still waiting for geofence approval). Approving the leave would bury that attendance, so the
     * final approval is refused for these days. A day the leave does not charge (a rostered day off)
     * does not count. `$lockRows` reads them with a locking read, so the approval sees a check-in
     * committed after its transaction started.
     *
     * @return list<string> dates as Y-m-d, in order
     */
    public function checkedInDates(bool $lockRows = false): array
    {
        $charged = array_flip($this->chargedDateStrings());

        return $this->attendanceOnLeaveDays()
            ->whereNotNull('check_in')
            // A rejected check-in is no presence: it does not stand in the way of a leave.
            ->where(fn (Builder $notRejected) => $notRejected->whereNull('status')->orWhere('status', '!=', AttendanceStatus::Rejected->value))
            ->when($lockRows, fn (Builder $query) => $query->lockForUpdate())
            ->pluck('date')
            ->map(fn ($date): string => Carbon::parse($date)->toDateString())
            ->filter(fn (string $date): bool => isset($charged[$date]))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Marks every charged leave day as leave ({@see self::chargedDateStrings()}). Every existing row of
     * such a day (each segment of a split-shift day) is updated, and a charged day without any row gets
     * one. A day the leave does not charge (a rostered day off) is left as it is.
     *
     * The rows are read with a locking read, and a row holding a check-in (one committed after the
     * approval's own check, see ApprovalFlowService::assertLeaveCanBeApproved()) refuses the whole
     * leave instead of burying that attendance; so does a row created by a parallel check-in while
     * the missing days are written. Called inside the approval transaction, which then rolls back.
     *
     * @throws ValidationException when a leave day holds a check-in
     */
    public function applyLeave(): void
    {
        $notes = 'Cuti / Izin Disetujui: '.$this->reason;
        $existingDates = [];

        $charged = $this->chargedDateStrings();
        $chargedSet = array_flip($charged);

        $attendances = $this->attendanceOnLeaveDays()->lockForUpdate()->get()
            ->filter(fn (Attendance $attendance): bool => isset($chargedSet[Carbon::parse($attendance->getRawOriginal('date'))->toDateString()]));
        $checkedIn = $attendances->filter(fn (Attendance $attendance): bool => $attendance->check_in !== null && $attendance->status !== AttendanceStatus::Rejected);

        if ($checkedIn->isNotEmpty()) {
            $this->refuseCheckedInDays($checkedIn->map(fn (Attendance $attendance): string => Carbon::parse($attendance->getRawOriginal('date'))->toDateString())->all());
        }

        $this->refuseApprovedOvertimeOn($charged);

        $attendances->each(function (Attendance $attendance) use ($notes, &$existingDates): void {
            // A row that was not a leave row yet keeps what it was, so undoing the leave puts it back.
            $isLeaveAlready = $attendance->status === AttendanceStatus::Leave;
            // A rejected check-in becomes a leave row too; its punches are kept aside and come back on undo.
            $rejectedPunches = $attendance->status === AttendanceStatus::Rejected && $attendance->check_in !== null
                ? [
                    'check_in' => $attendance->getRawOriginal('check_in'),
                    'check_out' => $attendance->getRawOriginal('check_out'),
                    'check_in_late_minutes' => $attendance->check_in_late_minutes,
                    'early_leave_minutes' => $attendance->early_leave_minutes,
                ]
                : [];
            // A rejected cover keeps no claim on the colleague it named: a leave row replaces nobody. The
            // cover fields are kept aside with the punches and come back on undo.
            $rejectedCover = $attendance->status === AttendanceStatus::Rejected && ($attendance->is_off_schedule || $attendance->replaced_worker_id !== null)
                ? [
                    'is_off_schedule' => (bool) $attendance->is_off_schedule,
                    'replaced_worker_type' => $attendance->replaced_worker_type,
                    'replaced_worker_id' => $attendance->replaced_worker_id,
                    'replaced_schedule_id' => $attendance->replaced_schedule_id,
                ]
                : [];

            $attendance->update([
                'status' => AttendanceStatus::Leave->value,
                'notes' => $notes,
                'state_before_leave' => $isLeaveAlready ? $attendance->state_before_leave : [
                    'status' => $attendance->status?->value,
                    'notes' => $attendance->notes,
                ] + $rejectedPunches + $rejectedCover,
            ] + ($rejectedPunches !== [] ? ['check_in' => null, 'check_out' => null, 'check_in_late_minutes' => null, 'early_leave_minutes' => null] : [])
                + ($rejectedCover !== [] ? ['is_off_schedule' => false, 'replaced_worker_type' => null, 'replaced_worker_id' => null, 'replaced_schedule_id' => null] : []));

            $existingDates[Carbon::parse($attendance->getRawOriginal('date'))->toDateString()] = true;
        });

        $workerCondition = array_filter([
            'employee_id' => $this->employee_id,
            'intern_id' => $this->intern_id,
            'freelancer_id' => $this->freelancer_id,
        ]);

        foreach ($charged as $date) {
            if (isset($existingDates[$date])) {
                continue;
            }

            try {
                // One leave row per date, on the first schedule's first part; any check-in of the date refused the approval above.
                Attendance::create(array_merge($workerCondition, [
                    'date' => $date,
                    'schedule_sequence' => 1,
                    'segment' => 1,
                    'status' => AttendanceStatus::Leave->value,
                    'notes' => $notes,
                ]));
            } catch (UniqueConstraintViolationException) {
                $this->refuseCheckedInDays([$date]);
            }
        }
    }

    /**
     * Keeps the leave rows of an approved leave in step with its (new) period, after the period was
     * changed: the charged dates are recomputed for the new range and stored, the leave rows without a
     * check-in on a previously charged date that is no longer charged are undone ({@see self::undoLeaveRows()}:
     * removed when the leave created them, put back when they existed before), and the newly
     * charged dates are marked as leave ({@see self::applyLeave()}, which refuses when one of them
     * already holds a check-in). Call inside a transaction.
     *
     * @param  list<string>  $previousDates  the dates charged before the change
     *
     * @throws ValidationException when the new period holds no working day or a new date holds a check-in
     */
    public function syncAttendanceRows(array $previousDates): void
    {
        $charged = app(AnnualLeaveService::class)->chargedDates($this->getWorkerProfile(), $this->start_date, $this->end_date);

        if ($charged === []) {
            throw ValidationException::withMessages(['start_date' => AnnualLeaveService::NO_WORKDAY_MESSAGE.'.']);
        }

        $this->forceFill(['charged_dates' => $charged, 'days_count' => count($charged)])->save();

        $this->undoLeaveRows(array_values(array_diff($previousDates, $charged)));

        if ($this->status === 'approved') {
            $this->applyLeave();
        }
    }

    /**
     * Overtime and leave never count on the same day: an overtime approved first holds its date. Every path
     * that charges leave dates (the final approval, and moving the dates of an approved leave) passes here.
     * The overtime rows are read with a locking read. Call inside a transaction.
     *
     * @param  list<string>|null  $dates  the dates to charge (Y-m-d); null = the stored charged dates
     *
     * @throws ValidationException when one of the dates holds approved overtime
     */
    public function refuseApprovedOvertimeOn(?array $dates = null): void
    {
        $dates ??= $this->chargedDateStrings();
        $profile = $this->getWorkerProfile();

        if (! $profile || $dates === []) {
            return;
        }

        $overtimeDates = OvertimeRequest::query()
            ->where(OvertimeRequestService::workerColumn($profile), $profile->getKey())
            ->where('status', 'approved')
            ->where(fn (Builder $onDates) => collect($dates)->each(fn (string $date) => $onDates->orWhereDate('date', $date)))
            ->lockForUpdate()
            ->pluck('date')
            ->map(fn ($date): string => Carbon::parse($date)->toDateString())
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($overtimeDates === []) {
            return;
        }

        $labels = collect($overtimeDates)->map(fn (string $date): string => Carbon::parse($date)->format('d/m/Y'))->implode(', ');

        throw ValidationException::withMessages([
            'approve' => "{$profile->full_name} sudah memiliki lembur yang disetujui pada tanggal {$labels}. Tolak pengajuan ini, atau batalkan lemburnya terlebih dahulu.",
        ]);
    }

    /**
     * Refuses the approval because the worker checked in on these leave days.
     *
     * @param  list<string>  $dates  dates as Y-m-d
     *
     * @throws ValidationException
     */
    private function refuseCheckedInDays(array $dates): never
    {
        $name = $this->getWorkerProfile()?->full_name ?? 'Pekerja ini';
        $labels = collect($dates)->unique()->sort()->map(fn (string $date): string => Carbon::parse($date)->format('d/m/Y'))->implode(', ');

        throw ValidationException::withMessages([
            'approve' => "{$name} sudah melakukan presensi pada tanggal {$labels}. Tolak pengajuan ini, atau minta pekerja mengubah tanggal cuti/izinnya.",
        ]);
    }
}
