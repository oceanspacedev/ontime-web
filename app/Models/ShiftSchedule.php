<?php

namespace App\Models;

use App\Traits\ScopesToWorkerVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The shift (or a day off) assigned to one worker on one date. Overrides the worker's default shift.
 */
class ShiftSchedule extends Model
{
    use ScopesToWorkerVisibility;

    protected $fillable = [
        'date',
        'employee_id',
        'intern_id',
        'freelancer_id',
        'shift_id',
        'is_day_off',
        'notes',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::saving(function (ShiftSchedule $schedule): void {
            if ($schedule->is_day_off) {
                $schedule->shift_id = null;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'date' => 'date',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getWorkerProfile(): Employee|Intern|Freelancer|null
    {
        return $this->employee ?? $this->intern ?? $this->freelancer;
    }
}
