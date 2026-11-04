<?php

namespace App\Models;

use App\Traits\HasNormalizedPhone;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'phone', 'password', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasNormalizedPhone, HasRoles, Notifiable;

    public static function findByPhone(string $phone): ?self
    {
        $digits = preg_replace('/\D/', '', $phone);
        if (empty($digits)) {
            return null;
        }

        $variants = array_unique(array_filter([
            $digits,
            str_starts_with($digits, '0') ? '62'.substr($digits, 1) : null,
            str_starts_with($digits, '62') ? '0'.substr($digits, 2) : null,
        ]));

        $user = static::whereIn('phone', $variants)->first();
        if ($user) {
            return $user;
        }

        $employee = Employee::whereIn('phone', $variants)->whereNotNull('user_id')->first();
        if ($employee?->user) {
            return $employee->user;
        }

        $intern = Intern::whereIn('phone', $variants)->whereNotNull('user_id')->first();
        if ($intern?->user) {
            return $intern->user;
        }

        $freelancer = Freelancer::whereIn('phone', $variants)->whereNotNull('user_id')->first();
        if ($freelancer?->user) {
            return $freelancer->user;
        }

        return null;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    public function intern(): HasOne
    {
        return $this->hasOne(Intern::class);
    }

    public function freelancer(): HasOne
    {
        return $this->hasOne(Freelancer::class);
    }

    public function approvers(): HasMany
    {
        return $this->hasMany(Approver::class);
    }

    /**
     * Companies an Admin Badan Usaha may manage.
     */
    public function managedCompanies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class)->withTimestamps();
    }

    /**
     * Admin Badan Usaha: the Admin role without Superadmin.
     */
    public function isCompanyAdmin(): bool
    {
        return $this->hasRole('Admin') && ! $this->hasRole('Superadmin');
    }

    /**
     * Companies whose data this user manages in full.
     * Null means every company (Superadmin); an empty list means none.
     *
     * @return list<int>|null
     */
    public function managedCompanyIds(): ?array
    {
        if ($this->hasRole('Superadmin')) {
            return null;
        }

        if (! $this->isCompanyAdmin()) {
            return [];
        }

        return $this->managedCompanies()->pluck('companies.id')->map(fn ($id): int => (int) $id)->values()->all();
    }

    /**
     * Superadmin, or an Admin Badan Usaha with at least one company.
     */
    public function canManageCompanies(): bool
    {
        return $this->managedCompanyIds() !== [];
    }

    public function canManageCompany(int|string|null $companyId): bool
    {
        $managed = $this->managedCompanyIds();

        return $managed === null || ($companyId !== null && in_array((int) $companyId, $managed, true));
    }

    /**
     * Divisions and companies this Approver / BOD is mapped to in the `approvers` table.
     * A mapping without a division covers the whole company.
     *
     * @return array{division_ids: list<int>, company_ids: list<int>}
     */
    public function approverScope(): array
    {
        $mappings = $this->approvers()->get(['company_id', 'division_id']);

        return [
            'division_ids' => $mappings->whereNotNull('division_id')->pluck('division_id')->map(fn ($id): int => (int) $id)->unique()->values()->all(),
            'company_ids' => $mappings->whereNull('division_id')->pluck('company_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all(),
        ];
    }

    /**
     * Companies whose team data (such as the leave calendar) this user may see.
     * Null means every company.
     *
     * @return list<int>|null
     */
    public function visibleCompanyIds(): ?array
    {
        if ($this->hasRole('Superadmin')) {
            return null;
        }

        $profile = $this->employee ?? $this->intern ?? $this->freelancer;
        $mappedCompanyIds = $this->hasAnyRole(['Approver', 'BOD']) ? $this->approvers()->pluck('company_id') : collect();

        return collect([$profile?->company_id])
            ->merge($mappedCompanyIds)
            ->merge($this->managedCompanyIds())
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function canTrackApprovalProgressFor(?Employee $employee = null): bool
    {
        if (! $employee) {
            return false;
        }

        return $this->hasRole('Employee') && $this->employee?->is($employee);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        // Require the user to be active (if you use that field)
        if (isset($this->is_active) && ! $this->is_active) {
            return false;
        }

        // Every page decides access by role, so an account without any role has nothing to do here.
        return $this->roles()->exists();
    }
}
