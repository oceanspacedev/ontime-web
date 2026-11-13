<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Services\WorkScheduleService;
use App\Traits\ScopesToWorkerVisibility;
use Carbon\Carbon;
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
        'notes',
        'is_corrected',
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
            'is_out_of_bounds' => 'boolean',
            'is_mock_location' => 'boolean',
            'is_face_verified' => 'boolean',
            'face_match_score' => 'integer',
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
     * Minutes between check-in and check-out, or null while either one is missing.
     */
    public function workedMinutes(): ?int
    {
        if (! $this->check_in || ! $this->check_out) {
            return null;
        }

        return (int) max(0, $this->check_in->diffInMinutes($this->check_out));
    }

    public function applyGeofenceApproval(): void
    {
        $profile = $this->getWorkerProfile();
        $lateMinutes = 0;

        if ($this->check_in && $profile) {
            $workSchedule = app(WorkScheduleService::class);
            $checkInTime = Carbon::parse($this->check_in);
            $lateMinutes = $workSchedule->lateMinutes($workSchedule->resolve($profile, $checkInTime, (int) ($this->segment ?? 1)), $checkInTime);
        }

        $this->update([
            'status' => $lateMinutes > 0 ? AttendanceStatus::Late : AttendanceStatus::OnTime,
            'late_minutes' => $lateMinutes,
        ]);
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
