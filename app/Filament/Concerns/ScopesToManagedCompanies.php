<?php

namespace App\Filament\Concerns;

use App\Enums\DataScope;
use App\Enums\SystemRole;
use App\Models\Company;
use App\Models\Division;
use App\Models\User;
use App\Services\DataAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;
use Spatie\Permission\Models\Role;
use WeakMap;

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
    /**
     * accountRolesBeyond() per signed-in user object, so an account list does not query the roles per row.
     *
     * @var WeakMap<User, array{signature: string, roles: list<string>}>|null
     */
    private static ?WeakMap $accountRolesBeyondCache = null;

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
    /**
     * A Koordinator: sees only the menus for running outlets (freelancers, shifts, schedules, attendance, approvals).
     */
    public static function isCoordinator(): bool
    {
        $user = auth()->user();

        return $user !== null && DataAccessService::isCoordinator($user);
    }

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
     * Roles the current user may hand out (an allow-list, see grantableRoleNames()).
     *
     * @return array<string, string>
     */
    public static function assignableRoleOptions(): array
    {
        $grantable = static::grantableRoleNames(auth()->user());

        return Role::query()
            ->when($grantable !== null, fn (Builder $query) => $query->whereIn('name', $grantable))
            ->orderBy('name')
            ->pluck('name', 'name')
            ->all();
    }

    /**
     * Roles $user may hand out; null means every role, custom ones included (Superadmin only). The PIC
     * of a Klien also grants Admin, for the HR staff of their client; an Admin (HR) also grants
     * Koordinator, for the store coordinators of their brands; nobody else grants a manager role.
     *
     * @return list<string>|null
     */
    protected static function grantableRoleNames(?User $user): ?array
    {
        $baseRoles = [SystemRole::Bod->value, SystemRole::Approver->value, SystemRole::Employee->value, SystemRole::Intern->value, SystemRole::Freelancer->value];

        return match (true) {
            (bool) $user?->hasRole(SystemRole::Superadmin->value) => null,
            DataAccessService::isTenantOwner($user) => [SystemRole::Admin->value, SystemRole::Koordinator->value, ...$baseRoles],
            (bool) $user?->hasRole(SystemRole::Admin->value) => [SystemRole::Koordinator->value, ...$baseRoles],
            default => $baseRoles,
        };
    }

    /**
     * Roles whose accounts $user may not manage: every role $user cannot grant, custom roles included.
     *
     * @return list<string>
     */
    protected static function accountRolesBeyond(User $user): array
    {
        $grantable = static::grantableRoleNames($user);

        if ($grantable === null) {
            return [];
        }

        // Asked once per listed account: kept per signed-in user object, and again when their roles change.
        self::$accountRolesBeyondCache ??= new WeakMap;
        $signature = implode('|', $grantable);
        $cached = self::$accountRolesBeyondCache[$user] ?? null;

        if ($cached !== null && $cached['signature'] === $signature) {
            return $cached['roles'];
        }

        $roles = Role::query()
            ->whereNotIn('name', $grantable)
            ->pluck('name')
            ->merge([SystemRole::Superadmin->value, SystemRole::Pic->value])
            ->unique()
            ->values()
            ->all();

        self::$accountRolesBeyondCache[$user] = ['signature' => $signature, 'roles' => $roles];

        return $roles;
    }

    /**
     * Whether $user may manage login accounts without a worker profile: the PIC, or a manager whose own
     * data access is "Semua". A division- or company-scoped Admin cannot tell whose an unlinked account is.
     */
    protected static function canReachUnlinkedAccounts(User $user): bool
    {
        return DataAccessService::isTenantOwner($user) || DataAccessService::canGrantAccess($user, DataScope::All);
    }

    /**
     * Whether the current user may edit, delete or take over this login account. Nobody removes their
     * own account here, and an Admin only touches accounts of their own Klien holding roles they may
     * grant that belong to a worker inside their data access, or are unlinked when their own data
     * access is "Semua".
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

        if (! $user->hasAnyRole([SystemRole::Admin->value, SystemRole::Koordinator->value]) || $account->hasAnyRole(static::accountRolesBeyond($user))) {
            return false;
        }

        // Another client's account is never in reach, whatever its data access says.
        if (! DataAccessService::sameTenant($user, $account)) {
            return false;
        }

        // The PIC manages every account of their Klien, including the ones without a worker profile.
        if (DataAccessService::isTenantOwner($user)) {
            return true;
        }

        if (! $user->canManageWorkers()) {
            return false;
        }

        // Accounts usually come from a table with these relations already loaded; loadMissing keeps
        // a single account check from lazy loading.
        if (! DataAccessService::accountReachWithin($user, $account)) {
            return false;
        }

        $account->loadMissing(['employee', 'intern', 'freelancer']);
        $workers = array_filter([$account->employee, $account->intern, $account->freelancer]);

        if ($workers === []) {
            return static::canReachUnlinkedAccounts($user);
        }

        return collect($workers)->contains(fn (Model $worker): bool => $user->canManageWorker($worker));
    }

    /**
     * Limit a users query to the accounts the current user may manage (see canManageAccount()).
     * Unlinked accounts are included for linking pickers (only for the PIC or a manager with data access
     * "Semua") and left out of account lists.
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

        if (! $user->hasAnyRole([SystemRole::Admin->value, SystemRole::Koordinator->value])) {
            return $query->whereRaw('1 = 0');
        }

        // Another client's accounts are never in reach, linked or not.
        $query->when(
            $user->tenant_id === null,
            fn (Builder $tenant) => $tenant->whereNull($tenant->qualifyColumn('tenant_id')),
            fn (Builder $tenant) => $tenant->where($tenant->qualifyColumn('tenant_id'), $user->tenant_id),
        );

        // The PIC manages every account of their Klien, linked or not (a BOD or another Admin has no worker profile).
        if (DataAccessService::isTenantOwner($user)) {
            return $query
                ->whereKeyNot($user->getKey())
                ->whereDoesntHave('roles', fn (Builder $roles) => $roles->whereIn('name', static::accountRolesBeyond($user)));
        }

        if (! $user->canManageWorkers()) {
            return $query->whereRaw('1 = 0');
        }

        // Accounts with data access wider than the user's own (a BOD or Approver of another company) are out of reach.
        $outOfReach = User::query()
            ->when(
                $user->tenant_id === null,
                fn (Builder $tenant) => $tenant->whereNull('tenant_id'),
                fn (Builder $tenant) => $tenant->where('tenant_id', $user->tenant_id),
            )
            ->where('data_scope', '!=', DataScope::Own->value)
            ->with(['roles', 'accessibleCompanies', 'accessibleDivisions'])
            ->get(['id', 'tenant_id', 'data_scope'])
            ->reject(fn (User $account): bool => DataAccessService::accountReachWithin($user, $account))
            ->modelKeys();

        return $query
            ->whereKeyNot($user->getKey())
            ->whereNotIn($query->getModel()->getQualifiedKeyName(), $outOfReach)
            ->whereDoesntHave('roles', fn (Builder $roles) => $roles->whereIn('name', static::accountRolesBeyond($user)))
            ->where(fn (Builder $scope) => $scope
                ->when($includeUnlinked && static::canReachUnlinkedAccounts($user), fn (Builder $linkable) => $linkable->orWhere(fn (Builder $unlinked) => $unlinked->whereDoesntHave('employee')->whereDoesntHave('intern')->whereDoesntHave('freelancer')))
                ->orWhereHas('employee', fn (Builder $worker) => DataAccessService::scopeWorkers($worker, $user, includeOwn: false))
                ->orWhereHas('intern', fn (Builder $worker) => DataAccessService::scopeWorkers($worker, $user, includeOwn: false))
                ->orWhereHas('freelancer', fn (Builder $worker) => DataAccessService::scopeWorkers($worker, $user, includeOwn: false)));
    }

    /**
     * Accounts the current user may link to a worker: within reach (so within the user's Klien) and not
     * linked to any employee, intern or freelancer yet, since one account checks in as one worker. With
     * a company, only accounts of that company's Klien (this matters for a Superadmin, who reaches all).
     *
     * @return array<int, string>
     */
    public static function assignableUserOptions(int|string|null $companyId = null): array
    {
        $tenantId = filled($companyId) ? Company::query()->whereKey($companyId)->value('tenant_id') : null;

        return static::scopeToManageableAccounts(User::query())
            ->whereDoesntHave('employee')
            ->whereDoesntHave('intern')
            ->whereDoesntHave('freelancer')
            ->when($tenantId !== null, fn (Builder $query) => $query->where('tenant_id', $tenantId))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Keep a worker number (NIP, NIS, freelancer number) unique inside one Klien: the Klien of the
     * chosen company, else the current user's. Numbers of other clients never block it, and the
     * error never reveals them.
     */
    public static function uniqueWithinTenant(Unique $rule, int|string|null $companyId): Unique
    {
        $tenantId = filled($companyId)
            ? Company::query()->whereKey($companyId)->value('tenant_id')
            : auth()->user()?->tenant_id;

        $companyIds = Company::query()
            ->when($tenantId === null, fn (Builder $query) => $query->whereNull('tenant_id'), fn (Builder $query) => $query->where('tenant_id', $tenantId))
            ->pluck('id')
            ->all();

        return $rule->whereIn('company_id', $companyIds === [] ? [0] : $companyIds);
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
