<?php

namespace App\Models;

use App\Services\DataAccessService;
use App\Traits\PreventsDeletionWhileInUse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobTitle extends Model
{
    use HasFactory, PreventsDeletionWhileInUse;

    protected $fillable = [
        'division_id',
        'name',
    ];

    /**
     * Titles a user may see: the shared ones (no division) and those of divisions in their Klien.
     * The Superadmin sees every title.
     *
     * @param  Builder<JobTitle>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->isPlatformAdmin()) {
            return;
        }

        $companyIds = DataAccessService::tenantCompanyIds($user);

        $query->where(fn (Builder $visible) => $visible
            ->whereNull('division_id')
            ->orWhereHas('division', fn (Builder $division) => $division->whereIn('company_id', $companyIds === [] ? [0] : $companyIds)));
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function deletionBlockedReason(): ?string
    {
        if ($this->employees()->exists()) {
            return "Posisi {$this->name} masih dipakai oleh karyawan. Pindahkan karyawan ke posisi lain sebelum menghapus.";
        }

        return null;
    }
}
