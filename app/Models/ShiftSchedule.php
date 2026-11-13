<?php

namespace App\Models;

use App\Services\DataAccessService;
use App\Traits\ScopesToWorkerVisibility;
use Illuminate\Database\Eloquent\Builder;
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
        'company_location_id',
        'is_day_off',
        'section',
        'notes',
        'created_by',
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
