<?php

namespace App\Models;

use App\Traits\PreventsDeletionWhileInUse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Division extends Model
{
    use HasFactory, PreventsDeletionWhileInUse;

    protected $fillable = [
        'company_id',
        'parent_id',
        'name',
        'code',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Division $division) {
            if ($division->exists && $division->parent_id) {
                if ((int) $division->parent_id === (int) $division->id) {
                    throw ValidationException::withMessages([
                        'parent_id' => 'Divisi tidak boleh menjadi parent bagi dirinya sendiri.',
                    ]);
                }

                if ($division->isDescendantOf($division->parent_id)) {
                    throw ValidationException::withMessages([
                        'parent_id' => 'Terjadi circular reference! Parent divisi tidak boleh diambil dari sub-divisinya sendiri.',
                    ]);
                }
            }
        });
    }

    /**
     * Number of ancestors above this division. Uses one cached id map per request instead of
     * walking the `parent` relation, which would lazy load once per level and per row.
     */
    public function getDepth(): int
    {
        $parentIdsById = once(fn (): array => static::query()->pluck('parent_id', 'id')->all());

        $depth = 0;
        $visited = [];
        $current = $parentIdsById[$this->id] ?? null;

        while ($current !== null && ! isset($visited[$current])) {
            $visited[$current] = true;
            $depth++;
            $current = $parentIdsById[$current] ?? null;
        }

        return $depth;
    }

    /**
     * Full path from the top level, e.g. "DISTRIBUTION SALES > DEPO BANDUNG". Uses eager-loaded
     * parents when present and plain queries otherwise (never lazy loading).
     */
    public function pathName(): string
    {
        $names = [];
        $node = $this;
        $visited = [];

        while ($node && ! isset($visited[$node->id])) {
            $visited[$node->id] = true;
            array_unshift($names, $node->name);

            $node = match (true) {
                $node->relationLoaded('parent') => $node->parent,
                $node->parent_id !== null => static::query()->find($node->parent_id),
                default => null,
            };
        }

        return implode(' > ', $names);
    }

    public function isDescendantOf(int $targetId): bool
    {
        return in_array($targetId, $this->getAllChildrenIds(), true);
    }

    /**
     * Ids of every division below this one, resolved level by level with one query per level.
     *
     * @return list<int>
     */
    public function getAllChildrenIds(): array
    {
        $ids = [];
        $frontier = [(int) $this->id];

        while ($frontier !== []) {
            $children = static::query()
                ->whereIn('parent_id', $frontier)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->reject(fn (int $id): bool => $id === (int) $this->id || in_array($id, $ids, true))
                ->values()
                ->all();

            $ids = array_merge($ids, $children);
            $frontier = $children;
        }

        return $ids;
    }

    public function descendantsAndSelf(): Collection
    {
        $ids = array_merge([$this->id], $this->getAllChildrenIds());

        return static::whereIn('id', $ids)->get();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Division::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Division::class, 'parent_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function approvers(): HasMany
    {
        return $this->hasMany(Approver::class);
    }

    public function jobTitles(): HasMany
    {
        return $this->hasMany(JobTitle::class);
    }

    public function interns(): HasMany
    {
        return $this->hasMany(Intern::class);
    }

    public function freelancers(): HasMany
    {
        return $this->hasMany(Freelancer::class);
    }

    public function deletionBlockedReason(): ?string
    {
        if ($this->employees()->exists() || $this->interns()->exists() || $this->freelancers()->exists()) {
            return "Divisi {$this->name} masih memiliki karyawan, magang, atau freelancer. Pindahkan mereka ke divisi lain sebelum menghapus.";
        }

        return null;
    }
}
