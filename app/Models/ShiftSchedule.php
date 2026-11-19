<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Services\DataAccessService;
use App\Services\RosterChangeContext;
use App\Services\SessionResolver;
use App\Services\ShiftAssignmentService;
use App\Traits\ScopesToWorkerVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The shift (or a day off) assigned to one worker on one date. Overrides the worker's default shift.
 * A worker may have several on one date, numbered by sequence.
 *
 * Once a linked attendance has a check-in the schedule is locked: it is changed or removed only by an
 * approved attendance correction ({@see RosterChangeContext}). Every create, update and delete writes one
 * entry of the change log ({@see ShiftScheduleChange}). Whether a past date may be edited is decided by the
 * roster writers ({@see ShiftAssignmentService}), never here.
 */
class ShiftSchedule extends Model
{
    use ScopesToWorkerVisibility;

    public const LOCKED_MESSAGE = 'Jadwal ini sudah ada absensinya. Ajukan koreksi absensi.';

    /** Columns whose change is a roster change: refused on a locked schedule, and logged. */
    public const ROSTER_COLUMNS = ['date', 'sequence', 'employee_id', 'intern_id', 'freelancer_id', 'shift_id', 'company_location_id', 'is_day_off', 'section'];

    protected $fillable = [
        'date',
        'sequence',
        'employee_id',
        'intern_id',
        'freelancer_id',
        'shift_id',
        'company_location_id',
        'is_day_off',
        'section',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected static function booted(): void
    {
        static::saving(function (ShiftSchedule $schedule): void {
            if ($schedule->is_day_off) {
                $schedule->shift_id = null;
                $schedule->company_location_id = null;
                $schedule->section = null;
            }
        });

        // D4: an attendance result is tied to the schedule it was checked in to. A schedule that already has
        // attendance is changed only through an approved correction; roster changes never re-judge attendance.
        static::updating(function (ShiftSchedule $schedule): void {
            if ($schedule->isDirty(self::ROSTER_COLUMNS) && ! RosterChangeContext::isCorrection() && static::hasCheckedInAttendance((int) $schedule->getKey())) {
                throw static::lockedException();
            }

            if (auth()->id() !== null) {
                $schedule->updated_by = auth()->id();
            }
        });

        static::deleting(function (ShiftSchedule $schedule): void {
            if (! RosterChangeContext::isCorrection() && static::hasCheckedInAttendance((int) $schedule->getKey())) {
                throw static::lockedException();
            }
        });

        // A first schedule row (or the removal of one) changes whether the worker is on a roster.
        static::saved(fn (ShiftSchedule $schedule) => $schedule->forgetRosterCache());
        static::deleted(fn (ShiftSchedule $schedule) => $schedule->forgetRosterCache());

        static::created(fn (ShiftSchedule $schedule) => $schedule->logChange(ShiftScheduleChange::ACTION_CREATED, null, $schedule->logState($schedule->getAttributes())));

        static::updated(function (ShiftSchedule $schedule): void {
            if ($schedule->wasChanged(self::ROSTER_COLUMNS)) {
                $schedule->logChange(ShiftScheduleChange::ACTION_UPDATED, $schedule->logState($schedule->getRawOriginal()), $schedule->logState($schedule->getAttributes()));
            }
        });

        static::deleted(fn (ShiftSchedule $schedule) => $schedule->logChange(ShiftScheduleChange::ACTION_DELETED, $schedule->logState($schedule->getAttributes()), null));
    }

    /**
     * The message every roster writer gives for a schedule that already has attendance.
     */
    public static function lockedException(): ValidationException
    {
        return ValidationException::withMessages(['date' => self::LOCKED_MESSAGE]);
    }

    /**
     * Whether the schedule has a linked attendance with a check-in that was not rejected (a locked schedule).
     * A rejected check-in was never a worked session, so it does not hold the schedule, the same way the
     * roster writers' unlinked-attendance guard ignores it.
     */
    public static function hasCheckedInAttendance(int $scheduleId): bool
    {
        return $scheduleId > 0 && static::lockingAttendances(Attendance::query()->where('shift_schedule_id', $scheduleId))->exists();
    }

    /**
     * Narrow an attendance query to the rows that lock their schedule: a check-in that was not rejected.
     *
     * @template TQuery of Builder|HasMany
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function lockingAttendances(Builder|HasMany $query): Builder|HasMany
    {
        return $query->whereNotNull('check_in')->where('status', '!=', AttendanceStatus::Rejected);
    }

    /**
     * Limit a schedule query to the rows that put a worker on a roster: every row except the own row of an
     * approved off-schedule cover, that is a row the cover approval itself wrote (its "created" entry in the
     * change log has the source "cover"; a cover row written before the change log existed got that entry
     * from a backfill migration) and that is linked to a cover check-in that was not rejected. A
     * cover alone never ends a worker's fixed hours. A row the PIC wrote keeps counting when a cover is
     * linked to it or converts it (a day off turned into the covered shift), so an approval never moves
     * the start of a roster to a later date. {@see SessionResolver}, the attendance ledger, the roster
     * preflight, schedule corrections and leave charging all read the start of the roster through this one
     * rule: from the date of the first such row.
     *
     * @template TQuery of Builder|QueryBuilder
     *
     * @param  TQuery  $query  a query on the shift_schedules table
     * @return TQuery
     */
    public static function whereRosterRow(Builder|QueryBuilder $query): Builder|QueryBuilder
    {
        return $query->whereNotExists(fn (QueryBuilder $cover) => $cover->selectRaw('1')
            ->from('attendances')
            ->whereColumn('attendances.shift_schedule_id', 'shift_schedules.id')
            ->where('attendances.is_off_schedule', true)
            ->where(fn (QueryBuilder $notRejected) => $notRejected->whereNull('attendances.status')->orWhere('attendances.status', '!=', AttendanceStatus::Rejected->value))
            ->whereExists(fn (QueryBuilder $writtenByCover) => $writtenByCover->selectRaw('1')
                ->from('shift_schedule_changes')
                ->whereColumn('shift_schedule_changes.shift_schedule_id', 'shift_schedules.id')
                ->where('shift_schedule_changes.action', ShiftScheduleChange::ACTION_CREATED)
                ->where('shift_schedule_changes.source', 'cover')));
    }

    /**
     * The state of the schedule as the change log keeps it.
     *
     * @param  array<string, mixed>  $attributes  Raw attributes of the schedule.
     * @return array{is_day_off: bool, shift_id: int|null, shift_code: string|null, company_location_id: int|null, outlet_name: string|null, section: string|null, sequence: int}
     */
    private function logState(array $attributes): array
    {
        $shiftId = isset($attributes['shift_id']) ? (int) $attributes['shift_id'] : null;
        $locationId = isset($attributes['company_location_id']) ? (int) $attributes['company_location_id'] : null;

        return [
            'is_day_off' => (bool) ($attributes['is_day_off'] ?? false),
            'shift_id' => $shiftId,
            'shift_code' => $shiftId ? (Shift::query()->whereKey($shiftId)->value('code') ?: null) : null,
            'company_location_id' => $locationId,
            'outlet_name' => $locationId ? CompanyLocation::query()->whereKey($locationId)->value('name') : null,
            'section' => $attributes['section'] ?? null,
            'sequence' => (int) ($attributes['sequence'] ?? 1),
        ];
    }

    /**
     * Write one entry of the change log, with the source and reason of the running roster change.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function logChange(string $action, ?array $before, ?array $after): void
    {
        ['source' => $source, 'reason' => $reason] = RosterChangeContext::current();

        ShiftScheduleChange::query()->create([
            'shift_schedule_id' => $this->getKey(),
            'employee_id' => $this->employee_id,
            'intern_id' => $this->intern_id,
            'freelancer_id' => $this->freelancer_id,
            'date' => $this->date?->toDateString(),
            'sequence' => (int) ($this->sequence ?: 1),
            'action' => $action,
            'source' => $source,
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'changed_by' => auth()->id(),
        ]);
    }

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'sequence' => 'integer',
            'is_day_off' => 'boolean',
        ];
    }

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

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * Outlet the worker works at that day.
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(CompanyLocation::class, 'company_location_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Attendance rows that belong to this schedule (one per working part).
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * Log of the changes made to this schedule.
     */
    public function changes(): HasMany
    {
        return $this->hasMany(ShiftScheduleChange::class);
    }

    /**
     * The rows of one worker-date in day order: working rows by shift start, then by sequence; a working row
     * without a shift after them; a day off last. The first one is the day's primary schedule, the one an
     * older app sees as the only cell of the day.
     *
     * @param  Collection<int, ShiftSchedule>  $rows  rows of one worker and date, with their shift loaded
     * @return Collection<int, ShiftSchedule>
     */
    public static function inDayOrder(Collection $rows): Collection
    {
        return $rows
            ->sortBy(fn (ShiftSchedule $row): string => sprintf(
                '%d|%s|%03d|%010d',
                $row->is_day_off ? 2 : ($row->shift ? 0 : 1),
                $row->shift ? substr((string) $row->shift->start_time, 0, 5) : '99:99',
                (int) ($row->sequence ?: 1),
                (int) $row->getKey(),
            ))
            ->values();
    }

    /**
     * Drop what the request's {@see SessionResolver} remembers of this worker's first schedule row.
     */
    private function forgetRosterCache(): void
    {
        if (! app()->resolved(SessionResolver::class)) {
            return;
        }

        foreach (['employee_id', 'intern_id', 'freelancer_id'] as $column) {
            if ($this->{$column} !== null) {
                app(SessionResolver::class)->forgetRosteredSince($column, (int) $this->{$column});
            }
        }
    }

    /**
     * A schedule the worker already checked in on: its attendance result is tied to it.
     */
    public function isLocked(): bool
    {
        return $this->exists && static::hasCheckedInAttendance((int) $this->getKey());
    }

    /**
     * A coordinator also sees the days other brands' workers are lent to their outlets, besides the schedules
     * of workers in their own access. Editing stays with the worker's own brand.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $brandIds = DataAccessService::coordinatedDivisionIds($user);

        return $query->where(function (Builder $visible) use ($user, $brandIds): void {
            $visible->where(fn (Builder $own) => DataAccessService::scopeWorkerRecords($own, $user, static::workerRelations()));

            if ($brandIds !== null) {
                $visible->orWhereHas('location', fn (Builder $outlet) => $outlet->whereIn('division_id', $brandIds));
            }
        });
    }

    /**
     * The worker's own brand when this day is spent at another brand's outlet, otherwise null.
     */
    public function borrowedFromBrand(): ?string
    {
        $worker = $this->getWorkerProfile();

        if (! $worker || ! $this->location?->division_id || ! $worker->division_id || (int) $this->location->division_id === (int) $worker->division_id) {
            return null;
        }

        return $worker->division?->name;
    }

    public function getWorkerProfile(): Employee|Intern|Freelancer|null
    {
        return $this->employee ?? $this->intern ?? $this->freelancer;
    }
}
