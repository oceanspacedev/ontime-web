<?php

namespace App\Models;

use App\Traits\ScopesToWorkerVisibility;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class OvertimeRequest extends Model
{
    use HasFactory, ScopesToWorkerVisibility;

    protected $fillable = [
        'employee_id',
        'intern_id',
        'freelancer_id',
        'date',
        'start_time',
        'end_time',
        'duration_minutes',
        'actual_duration_minutes',
        'reason',
        'status',
        'current_step',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'duration_minutes' => 'integer',
            'actual_duration_minutes' => 'integer',
            'current_step' => 'integer',
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
}
