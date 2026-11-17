<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Services\AttendanceLatenessService;
use App\Services\OffScheduleCheckInService;
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

    protected $fillable = [
        'employee_id',
        'intern_id',
        'freelancer_id',
        'shift_id',
        'company_location_id',
        'date',
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
            'liveness_verified' => 'boolean',
            'liveness_frames' => 'array',
            'current_step' => 'integer',
            'status' => AttendanceStatus::class,
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
     * Lateness is the snapshot judged at check-in, which a roster change on that date keeps up to date
     * ({@see AttendanceLatenessService::recalculate()}); a default shift or policy edited meanwhile does
     * not change it. Rows without that value (for example a corrected check-in) are judged against the
     * schedule that applies now, on the row's own business date.
     *
     * An approved off-schedule check-in ("Masuk di luar jadwal") also updates the rosters of its date.
     */
    public function applyGeofenceApproval(): void
    {
        $lateMinutes = 0;
        // Read again: a roster change committed since this model was loaded may have judged it anew.
        $snapshot = $this->exists ? static::query()->whereKey($this->getKey())->value('check_in_late_minutes') : $this->check_in_late_minutes;

        if ($this->check_in && $snapshot !== null) {
            $lateMinutes = (int) $snapshot;
        } elseif ($this->check_in) {
            $lateMinutes = app(AttendanceLatenessService::class)->judge($this)['late_minutes'];
        }

        $this->update([
            'status' => $lateMinutes > 0 ? AttendanceStatus::Late : AttendanceStatus::OnTime,
            'late_minutes' => $lateMinutes,
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

    public function applyGeofenceRejection(?string $reason = null): void
    {
        $this->update([
            'status' => AttendanceStatus::Rejected,
            'rejection_reason' => $reason,
        ]);
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
