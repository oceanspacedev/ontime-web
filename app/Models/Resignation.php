<?php

namespace App\Models;

use App\Traits\ScopesToWorkerVisibility;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Resignation extends Model
{
    use HasFactory, ScopesToWorkerVisibility;

    /**
     * Resignations are only filed by employees.
     *
     * @return list<string>
     */
    public static function workerRelations(): array
    {
        return ['employee'];
    }

    protected $fillable = [
        'employee_id',
        'resignation_date',
        'last_working_day',
        'reason',
        'handover_notes',
        'status',
        'current_step',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'resignation_date' => 'date',
            'last_working_day' => 'date',
            'current_step' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approvalSteps(): MorphMany
    {
        return $this->morphMany(ApprovalRequestStep::class, 'approvable');
    }

    public function applyResignation(): void
    {
        $employee = $this->employee;

        if (! $employee) {
            return;
        }

        // The employee keeps working until the last working day; the daily
        // employees:apply-resignations command deactivates the account afterwards.
        if ($this->last_working_day?->gte(today())) {
            return;
        }

        $employee->update(['status' => 'inactive']);

        if ($employee->user) {
            $employee->user->update(['is_active' => false]);
        }
    }
}
