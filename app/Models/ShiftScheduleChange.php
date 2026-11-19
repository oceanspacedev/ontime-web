<?php

namespace App\Models;

use Database\Factories\ShiftScheduleChangeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry of the roster change log: a schedule of a worker was created, updated or deleted.
 * The schedule and worker ids are kept without a foreign key, so the entry outlives both.
 */
class ShiftScheduleChange extends Model
{
    /** @use HasFactory<ShiftScheduleChangeFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public const ACTION_CREATED = 'created';

    public const ACTION_UPDATED = 'updated';

    public const ACTION_DELETED = 'deleted';

    /**
     * Channels a roster change can come from.
     */
    public const SOURCES = ['panel', 'api', 'import', 'copy', 'swap', 'cover', 'correction', 'lifecycle', 'system'];

    protected $fillable = [
        'shift_schedule_id',
        'employee_id',
        'intern_id',
        'freelancer_id',
        'date',
        'sequence',
        'action',
        'source',
        'before',
        'after',
        'reason',
        'changed_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'sequence' => 'integer',
            'before' => 'array',
            'after' => 'array',
        ];
    }

    /**
     * The schedule the entry is about; null once that schedule was deleted.
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ShiftSchedule::class, 'shift_schedule_id');
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

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
