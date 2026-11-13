<?php

namespace App\Models;

use App\Traits\PreventsDeletionWhileInUse;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    use PreventsDeletionWhileInUse;

    protected $fillable = [
        'company_id',
        'division_id',
        'name',
        'code',
        'talenta_code',
        'start_time',
        'end_time',
        'second_start_time',
        'second_end_time',
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

    /**
     * Brand the shift belongs to; null for a shift every brand of the company uses.
     */
    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
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

    public function hasSecondSegment(): bool
    {
        return filled($this->second_start_time) && filled($this->second_end_time);
    }

    /**
     * Working segments of the day as "HH:MM" pairs: one for a normal shift, two for a split shift.
     *
     * @return list<array{start: string, end: string}>
     */
    public function segments(): array
    {
        $segments = [['start' => substr((string) $this->start_time, 0, 5), 'end' => substr((string) $this->end_time, 0, 5)]];

        if ($this->hasSecondSegment()) {
            $segments[] = ['start' => substr((string) $this->second_start_time, 0, 5), 'end' => substr((string) $this->second_end_time, 0, 5)];
        }

        return $segments;
    }

    /**
     * "08:00 - 17:00", or "07:45 - 13:00 & 17:45 - 22:00" for a split shift.
     */
    public function hoursText(): string
    {
        return collect($this->segments())->map(fn (array $segment): string => "{$segment['start']} - {$segment['end']}")->implode(' & ');
    }

    /**
     * Total scheduled minutes of the day, without the break between split segments.
     */
    public function scheduledMinutes(): int
    {
        return (int) collect($this->segments())->sum(function (array $segment): int {
            $start = Carbon::parse($segment['start']);
            $end = Carbon::parse($segment['end']);

            if ($end->lessThanOrEqualTo($start)) {
                $end->addDay();
            }

            return $start->diffInMinutes($end);
        });
    }

    /**
     * "Pagi", or "JCHICKEN · Pagi" for a brand shift. Written this way in exports and accepted by imports.
     */
    public function qualifiedName(): string
    {
        if (! $this->division_id) {
            return $this->name;
        }

        // Uses the eager-loaded brand when present and a plain query otherwise (never lazy loading).
        $brand = $this->relationLoaded('division') ? $this->division : $this->division()->first();

        return $brand ? "{$brand->name} · {$this->name}" : $this->name;
    }

    /**
     * "Pagi (08:00 - 17:00)", or "JCHICKEN · Pagi (08:00 - 17:00)" for a brand shift.
     */
    public function label(): string
    {
        return sprintf('%s (%s)', $this->qualifiedName(), $this->hoursText());
    }
}
