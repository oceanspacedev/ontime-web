<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Services\AttendanceLatenessService;
use App\Services\OffScheduleCheckInService;
use App\Services\SessionResolver;
use App\Services\WorkScheduleService;
use App\Traits\ScopesToWorkerVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Attendance extends Model
{
    use HasFactory, ScopesToWorkerVisibility;

    /** pending_punch: the check-in waits for approval (a check-out tapped meanwhile waits with it). */
    public const PUNCH_CHECK_IN = 'check_in';

    /** pending_punch: only the check-out waits; the check-in was settled before. */
    public const PUNCH_CHECK_OUT = 'check_out';

    protected $fillable = [
        'employee_id',
        'intern_id',
        'freelancer_id',
        'shift_id',
        'shift_schedule_id',
        'company_location_id',
        'date',
        'schedule_sequence',
        'segment',
        'check_in',
        'check_out',
        'check_in_photo',
        'check_out_photo',
        'check_in_lat',
        'check_in_lng',
        'check_out_lat',
        'check_out_lng',
        'status',
        'late_minutes',
        'check_in_late_minutes',
        'early_leave_minutes',
        'is_off_schedule',
        'replaced_worker_type',
        'replaced_worker_id',
        'replaced_schedule_id',
        'notes',
        'is_corrected',
        'status_overridden',
        'is_out_of_bounds',
        'is_mock_location',
        'is_face_verified',
        'face_match_score',
        'face_verification_notes',
        'liveness_verified',
        'liveness_notes',
        'liveness_frames',
        'current_step',
        'rejection_reason',
        'sched_start_at',
        'sched_end_at',
        'sched_tolerance_minutes',
        'sched_scheduled_minutes',
        'sched_normal_minutes',
        'sched_shift_name',
        'sched_shift_code',
        'sched_outlet_name',
        'sched_section',
        'sched_source',
        'pending_punch',
        'status_before_check_out',
        'check_out_rejected_at',
        'checks_before_check_out',
        'state_before_leave',
    ];

    /**
     * The verification results a check-out may overwrite on a row ({@see self::$checks_before_check_out}).
     *
     * @var list<string>
     */
    public const CHECK_RESULT_COLUMNS = [
        'is_out_of_bounds',
        'is_mock_location',
        'is_face_verified',
        'face_match_score',
        'face_verification_notes',
        'liveness_verified',
        'liveness_notes',
        'liveness_frames',
    ];

    /**
     * What an admin edit or a correction may change on a row, kept on the correction as it was before
     * ({@see AttendanceCorrection::$before_values}).
     *
     * @var list<string>
     */
    public const AUDITED_COLUMNS = [
        'check_in',
        'check_out',
        'status',
        'late_minutes',
        'check_in_late_minutes',
        'early_leave_minutes',
        'shift_id',
        'company_location_id',
        'is_off_schedule',
        'rejection_reason',
        'notes',
    ];

    /**
     * The link to the roster row and the schedule that applied at check-in, written together by every
     * writer of a checked-in row ({@see self::fillSnapshot()}).
     *
     * @var list<string>
     */
    public const SNAPSHOT_COLUMNS = [
        'shift_schedule_id',
        'schedule_sequence',
        'sched_start_at',
        'sched_end_at',
        'sched_tolerance_minutes',
        'sched_scheduled_minutes',
        'sched_normal_minutes',
        'sched_shift_name',
        'sched_shift_code',
        'sched_outlet_name',
        'sched_section',
        'sched_source',
    ];

    protected static function booted(): void
    {
        // The lateness judged at check-in only holds for that check-in time; a correction or an
        // admin edit of the check-in leaves the approval to judge the new time again.
        static::updating(function (Attendance $attendance): void {
            if ($attendance->checkInMinuteChanged() && ! $attendance->isDirty('check_in_late_minutes')) {
                $attendance->check_in_late_minutes = null;
            }
        });

        // Leaving before the scheduled end ("pulang cepat") is judged whenever the check-out is set or
        // moved: by the worker's check-out, an approved correction or an admin edit.
        static::saving(function (Attendance $attendance): void {
            if ($attendance->isDirty('check_out') && ! $attendance->isDirty('early_leave_minutes')) {
                $attendance->early_leave_minutes = $attendance->check_out
                    ? app(WorkScheduleService::class)->earlyLeaveMinutes($attendance)
                    : null;
            }
        });
    }

    /**
     * Whether a pending update moves the check-in to another minute. Lateness is counted in whole
     * minutes and the admin form drops seconds, so saving it unchanged (08:30:27 → 08:30:00) keeps
     * the lateness judged at check-in.
     */
    public function checkInMinuteChanged(): bool
    {
        if (! $this->isDirty('check_in')) {
            return false;
        }

        $original = $this->getOriginal('check_in');
        $current = $this->check_in;

        if (! $original || ! $current) {
            return $original != $current;
        }

        return ! $original->copy()->startOfMinute()->equalTo($current->copy()->startOfMinute());
    }

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'check_in' => 'datetime',
            'check_out' => 'datetime',
            'check_in_lat' => 'decimal:7',
            'check_in_lng' => 'decimal:7',
            'check_out_lat' => 'decimal:7',
            'check_out_lng' => 'decimal:7',
            'is_corrected' => 'boolean',
            'status_overridden' => 'boolean',
            'is_out_of_bounds' => 'boolean',
            'is_mock_location' => 'boolean',
            'is_face_verified' => 'boolean',
            'face_match_score' => 'integer',
            'check_in_late_minutes' => 'integer',
            'early_leave_minutes' => 'integer',
            'is_off_schedule' => 'boolean',
            'replaced_worker_id' => 'integer',
            'replaced_schedule_id' => 'integer',
            'liveness_verified' => 'boolean',
            'liveness_frames' => 'array',
            'current_step' => 'integer',
            'status' => AttendanceStatus::class,
            'shift_schedule_id' => 'integer',
            'schedule_sequence' => 'integer',
            'sched_start_at' => 'datetime',
            'sched_end_at' => 'datetime',
            'sched_tolerance_minutes' => 'integer',
            'sched_scheduled_minutes' => 'integer',
            'sched_normal_minutes' => 'integer',
            'check_out_rejected_at' => 'datetime',
            'checks_before_check_out' => 'array',
            'state_before_leave' => 'array',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * Shift that applied when the worker checked in.
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * Roster row the attendance belongs to; null when it was judged against a default shift or the company hours.
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ShiftSchedule::class, 'shift_schedule_id');
    }

    /**
     * Whether the row keeps the schedule that applied at check-in (the sched_* columns).
     */
    public function hasSnapshot(): bool
    {
        return $this->sched_source !== null;
    }

    /**
     * Set the schedule snapshot ({@see SessionResolver::snapshotFor()}) on the row without saving it.
     * Keys that are not snapshot columns are ignored.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function fillSnapshot(array $snapshot): static
    {
        return $this->forceFill(array_intersect_key($snapshot, array_flip(self::SNAPSHOT_COLUMNS)));
    }

    /**
     * Outlet the worker was scheduled at when checking in.
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(CompanyLocation::class, 'company_location_id');
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

    public function corrections(): HasMany
    {
        return $this->hasMany(AttendanceCorrection::class);
    }

    public function approvalSteps(): MorphMany
    {
        return $this->morphMany(ApprovalRequestStep::class, 'approvable');
    }

    public function getWorkerProfile(): mixed
    {
        return $this->employee ?? $this->intern ?? $this->freelancer;
    }

    /**
     * Lateness for reports and pay. A check-in waiting for approval keeps late_minutes at 0 until it is
     * approved, so its lateness judged at check-in is reported instead of letting it read as on time.
     */
    public function reportedLateMinutes(): int
    {
        if ($this->status === AttendanceStatus::PendingApproval) {
            return (int) ($this->check_in_late_minutes ?? $this->late_minutes ?? 0);
        }

        return (int) ($this->late_minutes ?? 0);
    }

    /**
     * Minutes between check-in and check-out, or null while either one is missing.
     */
    public function workedMinutes(): ?int
    {
        if (! $this->check_in || ! $this->check_out) {
            return null;
        }

        return (int) max(0, $this->check_in->diffInMinutes($this->check_out));
    }

    /**
     * Lateness is the verdict judged at check-in; a roster change, a default shift or a policy edited
     * meanwhile does not change it (D4). Rows without that value (for example a corrected check-in) are judged against the
     * schedule kept in the row's snapshot, and only a row without a snapshot against the schedule that
     * applies now, on the row's own business date.
     *
     * An approved off-schedule check-in ("Masuk di luar jadwal") also updates the rosters of its date.
     */
    public function applyGeofenceApproval(): void
    {
        // Only the check-out waited: the check-in was settled before, so its verdict comes back as it was.
        if ($this->isCheckOutRound()) {
            $this->update([
                'status' => $this->freshStatusBeforeCheckOut(),
                'pending_punch' => null,
                'status_before_check_out' => null,
                'checks_before_check_out' => null,
            ]);

            return;
        }

        $lateMinutes = 0;
        // Read again: an admin edit committed since this model was loaded may have judged it anew.
        $snapshot = $this->exists ? static::query()->whereKey($this->getKey())->value('check_in_late_minutes') : $this->check_in_late_minutes;

        if ($this->check_in && $snapshot !== null) {
            $lateMinutes = (int) $snapshot;
        } elseif ($this->check_in) {
            // Read again for the same reason: the snapshot may have been written meanwhile.
            $stored = $this->exists ? (static::query()->whereKey($this->getKey())->first() ?? $this) : $this;

            $lateMinutes = $stored->hasSnapshot()
                ? app(WorkScheduleService::class)->snapshotLateMinutes($stored)
                : app(AttendanceLatenessService::class)->judge($this)['late_minutes'];
        }

        $this->update([
            'status' => $lateMinutes > 0 ? AttendanceStatus::Late : AttendanceStatus::OnTime,
            'late_minutes' => $lateMinutes,
            'pending_punch' => null,
            'status_before_check_out' => null,
        ]);

        if ($this->is_off_schedule) {
            app(OffScheduleCheckInService::class)->applyApproval($this);
        }
    }

    /**
     * The colleague an off-schedule check-in replaced, or null.
     */
    public function replacedWorker(): Employee|Intern|Freelancer|null
    {
        if (! $this->replaced_worker_type || ! $this->replaced_worker_id) {
            return null;
        }

        return app(OffScheduleCheckInService::class)->workerFromKey($this->replaced_worker_type.':'.$this->replaced_worker_id);
    }

    /**
     * Why an off-schedule check-in waits for approval, for approvers and history, e.g.
     * "Masuk di luar jadwal · menggantikan Budi · shift Closing". Null for a rostered check-in.
     */
    public function offScheduleLabel(): ?string
    {
        if (! $this->is_off_schedule) {
            return null;
        }

        $replaced = $this->replacedWorker();
        // Lists that did not load the shift (the approval inbox) read it directly instead of lazy loading.
        $shift = $this->shift_id
            ? ($this->relationLoaded('shift') ? $this->shift : Shift::query()->find($this->shift_id))
            : null;

        return implode(' · ', array_filter([
            'Masuk di luar jadwal',
            $replaced ? 'menggantikan '.$replaced->full_name : null,
            $shift ? 'shift '.$shift->name : null,
        ]));
    }

    /**
     * A new approval round is needed when no step is still waiting for an approver
     * (none generated yet, or the previous round was already approved or rejected).
     */
    public function needsNewApprovalRound(): bool
    {
        return $this->approvalSteps()->where('status', 'pending')->doesntExist();
    }

    /**
     * Reject the punch that waits for approval. When only the check-out waited (the check-in was already
     * settled), only the check-out is voided: its time, photo and coordinates are cleared, the check-in's
     * verdict comes back, and the row is left without a check-out, to be fixed by a correction. Otherwise
     * (the check-in waited, or a row pending from before rounds were told apart) the whole row is rejected.
     */
    public function applyGeofenceRejection(?string $reason = null): void
    {
        if ($this->isCheckOutRound()) {
            $this->update([
                'check_out' => null,
                'check_out_lat' => null,
                'check_out_lng' => null,
                'check_out_photo' => null,
                // The flags and verification results the voided check-out wrote go with it.
                ...$this->checkResultsBeforeCheckOut(),
                'status' => $this->freshStatusBeforeCheckOut(),
                'check_out_rejected_at' => now(),
                'rejection_reason' => $reason,
                'pending_punch' => null,
                'status_before_check_out' => null,
                'checks_before_check_out' => null,
            ]);

            return;
        }

        $this->update([
            'status' => AttendanceStatus::Rejected,
            'rejection_reason' => $reason,
            'pending_punch' => null,
            'status_before_check_out' => null,
        ]);
    }

    /**
     * The verification results of the check-in as they were before the check-out that waits for approval
     * ({@see self::CHECK_RESULT_COLUMNS}); empty for a row without them kept (a round from before they were
     * kept), which then keeps what it has.
     *
     * @return array<string, mixed>
     */
    private function checkResultsBeforeCheckOut(): array
    {
        $stored = $this->exists
            ? static::query()->whereKey($this->getKey())->first(['checks_before_check_out'])?->checks_before_check_out
            : $this->checks_before_check_out;

        return is_array($stored) ? array_intersect_key($stored, array_flip(self::CHECK_RESULT_COLUMNS)) : [];
    }

    /**
     * The audited columns of the row as they are now ({@see self::AUDITED_COLUMNS} and the schedule
     * snapshot), as plain values for a correction's before_values.
     *
     * @return array<string, mixed>
     */
    public function auditValues(): array
    {
        $values = [];

        foreach ([...self::AUDITED_COLUMNS, ...self::SNAPSHOT_COLUMNS] as $column) {
            $value = $this->getAttribute($column);
            $values[$column] = match (true) {
                $value instanceof \BackedEnum => $value->value,
                $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
                default => $value,
            };
        }

        return $values;
    }

    /**
     * Whether the approval round waiting on this row is about its check-out only.
     */
    public function isCheckOutRound(): bool
    {
        return $this->pending_punch === self::PUNCH_CHECK_OUT && filled($this->status_before_check_out);
    }

    /**
     * The status the row had before its check-out went to approval; on time when it cannot be read.
     * It is read again: an admin edit or a correction committed since this model was loaded may have
     * judged the check-in anew ({@see self::rejudgeSettledCheckIn()}).
     */
    private function freshStatusBeforeCheckOut(): AttendanceStatus
    {
        $stored = $this->exists
            ? static::query()->whereKey($this->getKey())->value('status_before_check_out')
            : $this->status_before_check_out;

        return AttendanceStatus::tryFrom((string) ($stored ?? $this->status_before_check_out)) ?? AttendanceStatus::OnTime;
    }

    /**
     * Apply a new lateness verdict of the check-in to a row whose check-out alone waits for approval,
     * without saving it. The check-in was settled, so its late minutes are reported at once and the
     * status the approval or rejection brings back follows the new verdict. A settled status other than
     * on time or late (set by hand) is kept.
     */
    public function rejudgeSettledCheckIn(int $lateMinutes): void
    {
        $this->late_minutes = $lateMinutes;

        $before = AttendanceStatus::tryFrom((string) $this->status_before_check_out);

        if (in_array($before, [AttendanceStatus::OnTime, AttendanceStatus::Late], true)) {
            $this->status_before_check_out = ($lateMinutes > 0 ? AttendanceStatus::Late : AttendanceStatus::OnTime)->value;
        }
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    /**
     * Scope to filter attendances belonging to the given User's linked worker profile.
     * Handles employee, intern, and freelancer profiles transparently.
     */
    public function scopeByWorker(Builder $query, User $user): Builder
    {
        if ($user->employee_id || $user->employee) {
            $employeeId = $user->employee_id ?? $user->employee?->id;

            return $query->where('employee_id', $employeeId);
        }

        if ($user->intern) {
            return $query->where('intern_id', $user->intern->id);
        }

        if ($user->freelancer) {
            return $query->where('freelancer_id', $user->freelancer->id);
        }

        // Return empty result if no profile linked
        return $query->whereRaw('1 = 0');
    }

    /**
     * Scope to filter attendances for today's date.
     */
    public function scopeToday(Builder $query): Builder
    {
        return $query->whereDate('date', today());
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Resolve the worker identifier array for creating/finding an attendance record.
     * Returns exactly one non-null FK mapping.
     *
     * @return array{employee_id?: int, intern_id?: int, freelancer_id?: int}
     */
    public static function workerKeys(User $user): array
    {
        if ($user->employee) {
            return ['employee_id' => $user->employee->id, 'intern_id' => null, 'freelancer_id' => null];
        }

        if ($user->intern) {
            return ['employee_id' => null, 'intern_id' => $user->intern->id, 'freelancer_id' => null];
        }

        if ($user->freelancer) {
            return ['employee_id' => null, 'intern_id' => null, 'freelancer_id' => $user->freelancer->id];
        }

        return [];
    }
}
