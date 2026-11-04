<?php

namespace App\Models;

use App\Traits\PreventsDeletionWhileInUse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    use PreventsDeletionWhileInUse;

    protected $fillable = [
        'company_id',
        'name',
        'code',
        'start_time',
        'end_time',
        'late_tolerance_minutes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'late_tolerance_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function interns(): HasMany
    {
        return $this->hasMany(Intern::class);
    }

    public function freelancers(): HasMany
    {
        return $this->hasMany(Freelancer::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(ShiftSchedule::class);
    }

    public function deletionBlockedReason(): ?string
    {
        if ($this->employees()->exists() || $this->interns()->exists() || $this->freelancers()->exists()) {
            return "Shift {$this->name} masih menjadi shift default pekerja. Ganti shift mereka atau nonaktifkan shift ini.";
        }

        if ($this->schedules()->whereDate('date', '>=', today())->exists()) {
            return "Shift {$this->name} masih dipakai di jadwal mendatang. Ubah jadwalnya atau nonaktifkan shift ini.";
        }

        return null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Shift ends on the next day (for example 22:00 - 06:00).
     */
    public function crossesMidnight(): bool
    {
        return substr((string) $this->end_time, 0, 5) <= substr((string) $this->start_time, 0, 5);
    }

    /**
     * "Pagi (08:00 - 17:00)".
     */
    public function label(): string
    {
        return sprintf('%s (%s - %s)', $this->name, substr((string) $this->start_time, 0, 5), substr((string) $this->end_time, 0, 5));
    }
}
