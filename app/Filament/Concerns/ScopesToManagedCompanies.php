<?php

namespace App\Filament\Concerns;

use App\Enums\SystemRole;
use App\Models\Company;
use App\Models\Division;
use App\Models\User;
use App\Services\DataAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

/**
 * Shared rules for resources managed by Superadmin and Admin. The role says who manages, the user's
 * data access (DataAccessService) says which companies and workers:
 *
 * - Company settings (companies, divisions, locations, shifts, approval flows): data access "Semua"
 *   or "Badan Usaha".
 * - Workers and their records: every data access level except "Pribadi", following placement.
 */
trait ScopesToManagedCompanies
{
    // ── Company settings ───────────────────────────────────────────────────

    /**
     * Superadmin, or an Admin whose data access covers whole companies.
     */
    public static function canManageCompanyData(): bool
    {
        return (bool) auth()->user()?->canManageCompanySettings();
    }

    /**
     * Limit a query to the companies whose settings the current user manages.
     */
    public static function scopeToManagedCompanies(Builder $query, ?string $column = null): Builder
    {
        $user = auth()->user();

        return static::whereCompanyIn($query, $user ? $user->manageableCompanyIds() : [], $column);
    }

    /**
     * Company select options for company settings.
     *
     * @return array<int, string>
     */
    public static function managedCompanyOptions(bool $activeOnly = true): array
    {
        return static::companyOptions(static::scopeToManagedCompanies(Company::query(), 'id'), $activeOnly);
    }

    // ── Workers ────────────────────────────────────────────────────────────

    /**
     * Superadmin, or an Admin whose data access covers at least one company or division.
     */
    public static function canManageWorkerData(): bool
    {
        return (bool) auth()->user()?->canManageWorkers();
    }

    /**
     * Limit a workers table (employees, interns, freelancers) to the workers the current user manages.
     */
    public static function scopeToManagedWorkers(Builder $query): Builder
    {
        $user = auth()->user();

        return $user && $user->canManageWorkers()
            ? DataAccessService::scopeWorkers($query, $user, includeOwn: false)
            : $query->whereRaw('1 = 0');
    }

    /**
     * Limit a company-owned query (e.g. shifts) to the companies the current user may place workers in.
     */
    public static function scopeToPlaceableCompanies(Builder $query, ?string $column = null): Builder
    {
        $user = auth()->user();

        if (! $user || ! $user->canManageWorkers()) {
            return $query->whereRaw('1 = 0');
        }

        return static::whereCompanyIn($query, DataAccessService::placeableCompanyIds($user), $column);
    }

    /**
     * Company select options for placing workers.
     *
     * @return array<int, string>
     */
    public static function placeableCompanyOptions(bool $activeOnly = true): array
    {
        return static::companyOptions(static::scopeToPlaceableCompanies(Company::query(), 'id'), $activeOnly);
    }

    public static function canPlaceInCompany(int|string|null $companyId): bool
    {
        $user = auth()->user();

        if (! $companyId || ! $user || ! $user->canManageWorkers()) {
            return false;
        }

        $companyIds = DataAccessService::placeableCompanyIds($user);

        return $companyIds === null || in_array((int) $companyId, $companyIds, true);
    }

    /**
     * Limit a divisions query to the divisions the current user may place workers in.
     */
    public static function scopeToPlaceableDivisions(Builder $query): Builder
    {
        $user = auth()->user();

        if (! $user || ! $user->canManageWorkers()) {
            return $query->whereRaw('1 = 0');
        }

        $divisionIds = DataAccessService::placeableDivisionIds($user);

        return $divisionIds !== null
            ? $query->whereIn($query->qualifyColumn('id'), $divisionIds)
            : static::whereCompanyIn($query, DataAccessService::placeableCompanyIds($user), $query->qualifyColumn('company_id'));
    }

    /**
     * Division select options of a company, limited to the divisions the current user may place workers in.
     *
     * @return array<int, string>
     */
    public static function placeableDivisionOptions(int|string|null $companyId, bool $activeOnly = true): array
    {
        if (! $companyId) {
            return [];
        }

        return static::scopeToPlaceableDivisions(Division::query())
            ->where('company_id', $companyId)
            ->when($activeOnly, fn (Builder $query) => $query->where('is_active', true))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Whether the current user manages the worker this record belongs to.
     */
    public static function canManageWorkerRecord(Model $record): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        $worker = $record->employee ?? $record->intern ?? $record->freelancer;

        return $user->canManageWorker($worker);
    }

    // ── Accounts ───────────────────────────────────────────────────────────

    /**
     * Roles the current user may hand out. Only Superadmin can grant Superadmin or Admin.
     *
     * @return array<string, string>
     */
    public static function assignableRoleOptions(): array
    {
        return Role::query()
            ->when(! auth()->user()?->hasRole('Superadmin'), fn (Builder $query) => $query->whereNotIn('name', [SystemRole::Superadmin->value, SystemRole::Admin->value]))
            ->orderBy('name')
            ->pluck('name', 'name')
            ->all();
    }

    /**
     * Whether the current user may edit, delete or take over this login account. Nobody removes their
     * own account here, and an Admin only touches non-admin accounts that are unlinked or belong to a
     * worker inside their data access.
     */
    public static function canManageAccount(?User $account): bool
    {
        $user = auth()->user();

        if (! $user || ! $account || $account->is($user)) {
            return false;
        }

        if ($user->hasRole(SystemRole::Superadmin->value)) {
            return true;
        }

        if (! $user->hasRole(SystemRole::Admin->value) || ! $user->canManageWorkers() || $account->hasAnyRole([SystemRole::Superadmin->value, SystemRole::Admin->value])) {
            return false;
        }

        $workers = array_filter([$account->employee, $account->intern, $account->freelancer]);

        return $workers === [] || collect($workers)->contains(fn (Model $worker): bool => $user->canManageWorker($worker));
    }

    /**
     * Limit a users query to the accounts the current user may manage (see canManageAccount()).
     * Unlinked accounts are included for linking pickers and left out of account lists.
     */
    public static function scopeToManageableAccounts(Builder $query, bool $includeUnlinked = true): Builder
    {
        $user = auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasRole(SystemRole::Superadmin->value)) {
            return $query;
        }

        if (! $user->hasRole(SystemRole::Admin->value) || ! $user->canManageWorkers()) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->whereKeyNot($user->getKey())
            ->whereDoesntHave('roles', fn (Builder $roles) => $roles->whereIn('name', [SystemRole::Superadmin->value, SystemRole::Admin->value]))
            ->where(fn (Builder $scope) => $scope
                ->when($includeUnlinked, fn (Builder $linkable) => $linkable->orWhere(fn (Builder $unlinked) => $unlinked->whereDoesntHave('employee')->whereDoesntHave('intern')->whereDoesntHave('freelancer')))
                ->orWhereHas('employee', fn (Builder $worker) => DataAccessService::scopeWorkers($worker, $user, includeOwn: false))
                ->orWhereHas('intern', fn (Builder $worker) => DataAccessService::scopeWorkers($worker, $user, includeOwn: false))
                ->orWhereHas('freelancer', fn (Builder $worker) => DataAccessService::scopeWorkers($worker, $user, includeOwn: false)));
    }

    /**
     * Accounts the current user may link to a worker.
     *
     * @return array<int, string>
     */
    public static function assignableUserOptions(): array
    {
        return static::scopeToManageableAccounts(User::query())
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @param  list<int>|null  $companyIds  null means every company
     */
    private static function whereCompanyIn(Builder $query, ?array $companyIds, ?string $column): Builder
    {
        if ($companyIds === null) {
            return $query;
        }

        return $companyIds === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn($column ?? $query->qualifyColumn('company_id'), $companyIds);
    }

    /**
     * @return array<int, string>
     */
    private static function companyOptions(Builder $query, bool $activeOnly): array
    {
        return $query
            ->when($activeOnly, fn (Builder $active) => $active->where('is_active', true))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
