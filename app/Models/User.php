<?php

namespace App\Models;

use App\Enums\DataScope;
use App\Services\DataAccessService;
use App\Traits\HasNormalizedPhone;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'phone', 'password', 'is_active', 'data_scope'])]
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
            'data_scope' => DataScope::class,
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
     * Companies in this user's data access (used when data_scope is "companies").
     */
    public function accessibleCompanies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class)->withTimestamps();
    }

    /**
     * Divisions in this user's data access (used when data_scope is "divisions").
     * Sub-divisions are included automatically.
     */
    public function accessibleDivisions(): BelongsToMany
    {
        return $this->belongsToMany(Division::class)->withTimestamps();
    }

    public function dataScope(): DataScope
    {
        return DataAccessService::scopeOf($this);
    }

    /**
     * Set the data access level and its companies or divisions in one call.
     *
     * @param  list<int>  $companyIds
     * @param  list<int>  $divisionIds
     */
    public function grantDataAccess(DataScope $scope, array $companyIds = [], array $divisionIds = []): static
    {
        $this->forceFill(['data_scope' => $scope])->save();
        $this->accessibleCompanies()->sync($scope === DataScope::Companies ? $companyIds : []);
        $this->accessibleDivisions()->sync($scope === DataScope::Divisions ? $divisionIds : []);
        $this->unsetRelation('accessibleCompanies')->unsetRelation('accessibleDivisions');
        DataAccessService::flush();

        return $this;
    }

    /**
     * Drop company or division picks that the current data_scope does not use.
     */
    public function clearUnusedDataAccess(): void
    {
        $scope = $this->data_scope instanceof DataScope ? $this->data_scope : DataScope::tryFrom((string) $this->data_scope);

        if ($scope !== DataScope::Companies) {
            $this->accessibleCompanies()->detach();
        }

        if ($scope !== DataScope::Divisions) {
            $this->accessibleDivisions()->detach();
        }

        DataAccessService::flush();
    }

    public function canAccessWorker(?Model $worker): bool
    {
        return DataAccessService::canAccessWorker($this, $worker);
    }

    /**
     * Superadmin, or an Admin whose data access covers at least one company or division.
     */
    public function canManageWorkers(): bool
    {
        return DataAccessService::canManageWorkers($this);
    }

    public function canManageWorker(?Model $worker): bool
    {
        return DataAccessService::canManageWorker($this, $worker);
    }

    public function canManagePlacement(int|string|null $companyId, int|string|null $divisionId): bool
    {
        return DataAccessService::canManagePlacement($this, $companyId, $divisionId);
    }

    /**
     * Companies whose settings this user manages. Null means every company.
     *
     * @return list<int>|null
     */
    public function manageableCompanyIds(): ?array
    {
        return DataAccessService::manageableCompanyIds($this);
    }

    public function canManageCompanySettings(): bool
    {
        return DataAccessService::canManageCompanySettings($this);
    }

    public function canManageCompany(int|string|null $companyId): bool
    {
        return DataAccessService::canManageCompany($this, $companyId);
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
