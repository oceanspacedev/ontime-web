<?php

namespace App\Services;

use App\Enums\DataScope;
use App\Enums\SystemRole;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * The one place that decides which records a user can see and manage (ERP-style data access).
 *
 * - The role decides what a user can do: Superadmin and Admin manage, Approver / BOD approve,
 *   workers submit requests.
 * - users.data_scope decides which workers that applies to:
 *   all (every company of the user's Klien), companies (company_user), divisions (division_user,
 *   including sub-divisions) or own (only their own records).
 * - Nothing ever reaches past the user's Klien (users.tenant_id = companies.tenant_id, a missing
 *   Klien only matching companies without one). Only the platform Superadmin is unbounded.
 * - Everyone always sees their own records.
 */
final class DataAccessService
{
    /**
     * Resolved access per user object, so table rows and repeated checks do not re-query. Entries
     * disappear with the user object; flush() clears them when divisions or access picks change.
     *
     * @var \WeakMap<User, array{scope: DataScope, unbounded: bool, companies: list<int>, divisions: list<int>}>|null
     */
    private static ?\WeakMap $resolved = null;

    public static function flush(): void
    {
        self::$resolved = null;
    }

    public static function scopeOf(User $user): DataScope
    {
        // Only the level is needed here: resolving the picks would cost queries per account in a list.
        if (self::$resolved !== null && isset(self::$resolved[$user])) {
            return self::$resolved[$user]['scope'];
        }

        return self::scopeLevel($user);
    }

    private static function scopeLevel(User $user): DataScope
    {
        if ($user->hasRole(SystemRole::Superadmin->value)) {
            return DataScope::All;
        }

        return $user->data_scope instanceof DataScope ? $user->data_scope : (DataScope::tryFrom((string) $user->data_scope) ?? DataScope::Own);
    }

    /**
     * Company and division filters that select the workers in scope. Null means every worker on the
     * platform, which only the Superadmin gets: "Semua" for anyone else is every company of their
     * Klien. Divisions already include their sub-divisions.
     *
     * @return array{companies: list<int>, divisions: list<int>}|null
     */
    public static function workerFilter(User $user): ?array
    {
        $resolved = self::resolve($user);

        return $resolved['unbounded']
            ? null
            : ['companies' => $resolved['companies'], 'divisions' => $resolved['divisions']];
    }

    /**
     * Ids of the companies in the user's Klien. A user without a Klien only matches companies
     * without one, so a missing value never opens another client's data.
     *
     * @return list<int>
     */
    public static function tenantCompanyIds(User $user): array
    {
        return self::tenantCompanies($user)->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * Companies of the user's Klien.
     *
     * @return Builder<Company>
     */
    public static function tenantCompanies(User $user): Builder
    {
        return Company::query()->when(
            $user->tenant_id === null,
            fn (Builder $query) => $query->whereNull('tenant_id'),
            fn (Builder $query) => $query->where('tenant_id', $user->tenant_id),
        );
    }

    /**
     * Whether two accounts belong to the same Klien (both without one counts as the same).
     */
    public static function sameTenant(User $user, User $other): bool
    {
        return (int) $user->tenant_id === (int) $other->tenant_id;
    }

    /**
     * Companies picked for data access "Badan Usaha".
     *
     * @return list<int>
     */
    public static function selectedCompanyIds(User $user): array
    {
        return self::resolve($user)['companies'];
    }

    /**
     * The given divisions plus every division below them.
     *
     * @param  list<int>  $divisionIds
     * @return list<int>
     */
    public static function withDescendants(array $divisionIds): array
    {
        $all = array_values(array_unique(array_map('intval', $divisionIds)));
        $frontier = $all;

        while ($frontier !== []) {
            $children = Division::query()->whereIn('parent_id', $frontier)->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->reject(fn (int $id): bool => in_array($id, $all, true))
                ->values()
                ->all();

            $all = [...$all, ...$children];
            $frontier = $children;
        }

        return $all;
    }

    /**
     * @return array{scope: DataScope, companies: list<int>, divisions: list<int>}
     */
    private static function resolve(User $user): array
    {
        self::$resolved ??= new \WeakMap;

        if (isset(self::$resolved[$user])) {
            return self::$resolved[$user];
        }

        $unbounded = $user->hasRole(SystemRole::Superadmin->value);
        $scope = self::scopeLevel($user);

        if ($unbounded) {
            return self::$resolved[$user] = ['scope' => $scope, 'unbounded' => true, 'companies' => [], 'divisions' => []];
        }

        $tenantCompanyIds = in_array($scope, [DataScope::All, DataScope::Companies, DataScope::Divisions], true) ? self::tenantCompanyIds($user) : [];

        // Picks are kept inside the Klien even if one was saved for another client's company.
        $companies = match ($scope) {
            DataScope::All => $tenantCompanyIds,
            DataScope::Companies => array_values(array_intersect(self::ids($user->accessibleCompanies()), $tenantCompanyIds)),
            default => [],
        };

        $divisions = $scope === DataScope::Divisions
            ? self::withDescendants(Division::query()
                ->whereIn('id', self::ids($user->accessibleDivisions()))
                ->whereIn('company_id', $tenantCompanyIds)
                ->pluck('id')->map(fn ($id): int => (int) $id)->all())
            : [];

        return self::$resolved[$user] = [
            'scope' => $scope,
            'unbounded' => false,
            'companies' => $companies,
            'divisions' => $divisions,
        ];
    }

    /**
     * @return list<int>
     */
    private static function ids(BelongsToMany $relation): array
    {
        return $relation->pluck($relation->getRelated()->getQualifiedKeyName())
            ->map(fn ($id): int => (int) $id)->unique()->values()->all();
    }

    /**
     * Limit a workers table (employees, interns, freelancers) to the user's scope. Their own profile
     * is kept unless $includeOwn is false (management lists follow placement only).
     */
    public static function scopeWorkers(Builder $query, User $user, bool $includeOwn = true): Builder
    {
        $filter = self::workerFilter($user);

        if ($filter === null) {
            return $query;
        }

        $ownId = $includeOwn ? self::ownProfileFor($user, $query->getModel())?->getKey() : null;

        return $query->where(function (Builder $scoped) use ($filter, $ownId): void {
            $scoped->whereRaw('1 = 0');

            if ($filter['companies'] !== []) {
                $scoped->orWhereIn($scoped->qualifyColumn('company_id'), $filter['companies']);
            }

            if ($filter['divisions'] !== []) {
                $scoped->orWhereIn($scoped->qualifyColumn('division_id'), $filter['divisions']);
            }

            if ($ownId) {
                $scoped->orWhere($scoped->qualifyColumn('id'), $ownId);
            }
        });
    }

    /**
     * Limit records that belong to a worker (requests, attendance, schedules) through the given
     * relations, always keeping the user's own records.
     *
     * @param  list<string>  $relations  e.g. ['employee', 'intern', 'freelancer'], each with a {relation}_id column
     */
    public static function scopeWorkerRecords(Builder $query, User $user, array $relations): Builder
    {
        $filter = self::workerFilter($user);

        if ($filter === null) {
            return $query;
        }

        $hasScope = $filter['companies'] !== [] || $filter['divisions'] !== [];

        return $query->where(function (Builder $visible) use ($user, $filter, $relations, $hasScope): void {
            $visible->whereRaw('1 = 0');

            foreach ($relations as $relation) {
                if ($profile = $user->{$relation}) {
                    $visible->orWhere($visible->qualifyColumn("{$relation}_id"), $profile->getKey());
                }

                // "worker_id IN (workers in scope)" instead of a correlated EXISTS per row: the database
                // resolves the workers once, which keeps month-long reports of a large Klien fast.
                if ($hasScope) {
                    $workers = $visible->getModel()->{$relation}()->getRelated()->newQuery();

                    $visible->orWhereIn($visible->qualifyColumn("{$relation}_id"), $workers
                        ->select($workers->qualifyColumn('id'))
                        ->where(function (Builder $inScope) use ($filter): void {
                            if ($filter['companies'] !== []) {
                                $inScope->orWhereIn($inScope->qualifyColumn('company_id'), $filter['companies']);
                            }

                            if ($filter['divisions'] !== []) {
                                $inScope->orWhereIn($inScope->qualifyColumn('division_id'), $filter['divisions']);
                            }
                        }));
                }
            }
        });
    }

    public static function canAccessWorker(User $user, ?Model $worker): bool
    {
        if (! $worker) {
            return false;
        }

        if ((int) $worker->getAttribute('user_id') === (int) $user->getKey()) {
            return true;
        }

        return self::canAccessPlacement($user, $worker->getAttribute('company_id'), $worker->getAttribute('division_id'));
    }

    /**
     * Whether $actor may give an account this data access: never wider than their own. The Superadmin
     * may give any. A manager with "Semua" may give "Semua" (which stays inside the Klien), and anyone
     * else only whole companies or divisions they hold themselves, or "own".
     *
     * @param  list<int|string>  $companyIds
     * @param  list<int|string>  $divisionIds
     * @param  iterable<Division>|null  $divisions  the picked divisions when already loaded, instead of $divisionIds
     */
    public static function canGrantAccess(User $actor, DataScope $scope, array $companyIds = [], array $divisionIds = [], ?iterable $divisions = null): bool
    {
        $filter = self::workerFilter($actor);

        if ($actor->hasRole(SystemRole::Superadmin->value)) {
            return true;
        }

        if ($filter === null) {
            return false;
        }

        return match ($scope) {
            DataScope::Own => true,
            DataScope::All => self::isManager($actor) && self::scopeOf($actor) === DataScope::All,
            DataScope::Companies => collect($companyIds)->every(fn ($id): bool => in_array((int) $id, $filter['companies'], true)),
            DataScope::Divisions => collect($divisions ?? Division::query()->whereIn('id', $divisionIds)->get(['id', 'company_id']))->every(
                fn (Division $division): bool => in_array((int) $division->id, $filter['divisions'], true) || in_array((int) $division->company_id, $filter['companies'], true),
            ),
        };
    }

    /**
     * Whether an account's data access stays inside what $actor holds, so $actor may manage it.
     */
    public static function accountReachWithin(User $actor, User $account): bool
    {
        $scope = self::scopeOf($account);

        // Account lists eager load the picks; a single account check reads them.
        $divisionsLoaded = $scope === DataScope::Divisions && $account->relationLoaded('accessibleDivisions');

        return self::canGrantAccess(
            $actor,
            $scope,
            $scope === DataScope::Companies
                ? ($account->relationLoaded('accessibleCompanies') ? $account->accessibleCompanies->modelKeys() : $account->accessibleCompanies()->pluck('companies.id')->all())
                : [],
            $scope === DataScope::Divisions && ! $divisionsLoaded ? $account->accessibleDivisions()->pluck('divisions.id')->all() : [],
            $divisionsLoaded ? $account->accessibleDivisions : null,
        );
    }

    /**
     * Whether a company / division placement is inside the user's scope (own records excluded).
     */
    public static function canAccessPlacement(User $user, int|string|null $companyId, int|string|null $divisionId): bool
    {
        $filter = self::workerFilter($user);

        if ($filter === null) {
            return true;
        }

        return ($companyId !== null && in_array((int) $companyId, $filter['companies'], true))
            || ($divisionId !== null && in_array((int) $divisionId, $filter['divisions'], true));
    }

    /**
     * Companies the user may pick when placing a worker. Null means any company.
     *
     * @return list<int>|null
     */
    public static function placeableCompanyIds(User $user): ?array
    {
        $filter = self::workerFilter($user);

        if ($filter === null) {
            return null;
        }

        if ($filter['divisions'] === []) {
            return $filter['companies'];
        }

        return Division::query()->whereIn('id', $filter['divisions'])->distinct()->pluck('company_id')
            ->map(fn ($id): int => (int) $id)->merge($filter['companies'])->unique()->values()->all();
    }

    /**
     * Divisions the user may pick when placing a worker. Null means any division of the chosen company.
     *
     * @return list<int>|null
     */
    public static function placeableDivisionIds(User $user): ?array
    {
        $filter = self::workerFilter($user);

        return ($filter === null || $filter['divisions'] === []) ? null : $filter['divisions'];
    }

    /**
     * Companies with at least one worker the user can see, their own company included. Null means
     * every company. Used for filter options and company reference data.
     *
     * @return list<int>|null
     */
    public static function visibleCompanyIds(User $user): ?array
    {
        $placeable = self::placeableCompanyIds($user);

        if ($placeable === null) {
            return null;
        }

        return collect(self::ownProfiles($user))->pluck('company_id')->filter()
            ->map(fn ($id): int => (int) $id)->merge($placeable)->unique()->values()->all();
    }

    /**
     * Divisions the user can see, their own division included. Null means every division of the
     * visible companies (data access "Semua" or "Badan Usaha").
     *
     * @return list<int>|null
     */
    public static function visibleDivisionIds(User $user): ?array
    {
        $filter = self::workerFilter($user);

        if ($filter === null || $filter['companies'] !== []) {
            return null;
        }

        return collect(self::ownProfiles($user))->pluck('division_id')->filter()
            ->map(fn ($id): int => (int) $id)->merge($filter['divisions'])->unique()->values()->all();
    }

    /**
     * Limit a company-owned query (companies, locations, policies, divisions) to the visible companies.
     */
    public static function scopeCompanies(Builder $query, User $user, ?string $column = null): Builder
    {
        $companyIds = self::visibleCompanyIds($user);

        if ($companyIds === null) {
            return $query;
        }

        return $companyIds === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn($column ?? $query->qualifyColumn('company_id'), $companyIds);
    }

    /**
     * Limit a users query to the user themself plus accounts of workers inside their data access.
     */
    public static function scopeUsers(Builder $query, User $user): Builder
    {
        if (self::workerFilter($user) === null) {
            return $query;
        }

        // A manager with "Semua" also sees the accounts of their Klien that have no worker profile (a BOD, another Admin).
        $seesWholeTenant = self::isManager($user) && self::scopeOf($user) === DataScope::All;

        return $query->where(fn (Builder $visible) => $visible
            ->whereKey($user->getKey())
            ->when($seesWholeTenant, fn (Builder $tenant) => $tenant->orWhere(fn (Builder $sameTenant) => $user->tenant_id === null
                ? $sameTenant->whereNull('tenant_id')->whereDoesntHave('roles', fn (Builder $roles) => $roles->where('name', SystemRole::Superadmin->value))
                : $sameTenant->where('tenant_id', $user->tenant_id)))
            ->orWhereHas('employee', fn (Builder $worker) => self::scopeWorkers($worker, $user, includeOwn: false))
            ->orWhereHas('intern', fn (Builder $worker) => self::scopeWorkers($worker, $user, includeOwn: false))
            ->orWhereHas('freelancer', fn (Builder $worker) => self::scopeWorkers($worker, $user, includeOwn: false)));
    }

    /**
     * @return list<Model>
     */
    public static function ownProfiles(User $user): array
    {
        return array_values(array_filter([$user->employee, $user->intern, $user->freelancer]));
    }

    // ── Management (role + scope) ──────────────────────────────────────────

    public static function isManager(User $user): bool
    {
        return $user->hasAnyRole(SystemRole::managerNames());
    }

    /**
     * A Koordinator who is not also an Admin: manages the workers, shifts and schedules of their
     * brands but no company settings.
     */
    public static function isCoordinator(?User $user): bool
    {
        return $user !== null && $user->hasRole(SystemRole::Koordinator->value) && ! $user->hasAnyRole([SystemRole::Superadmin->value, SystemRole::Admin->value]);
    }

    /**
     * Brand divisions (with their sub-divisions) a coordinator works with. Null when the user is not
     * limited to brands: not a coordinator, or a coordinator whose data access is whole companies.
     *
     * @return list<int>|null
     */
    public static function coordinatedDivisionIds(User $user): ?array
    {
        if (! self::isCoordinator($user)) {
            return null;
        }

        $filter = self::workerFilter($user);

        return $filter === null || $filter['divisions'] === [] ? null : $filter['divisions'];
    }

    /**
     * The Superadmin only. Used for system-wide tools such as Horizon and the Log Viewer, which show
     * data of every client.
     */
    public static function hasGlobalAccess(User $user): bool
    {
        return self::isManager($user) && self::workerFilter($user) === null;
    }

    /**
     * Superadmin, or an Admin whose data access covers at least one company or division.
     */
    public static function canManageWorkers(User $user): bool
    {
        if (! self::isManager($user)) {
            return false;
        }

        $filter = self::workerFilter($user);

        return $filter === null || $filter['companies'] !== [] || $filter['divisions'] !== [];
    }

    /**
     * Managers manage workers by placement only: being the worker yourself grants nothing extra.
     * Records without a worker can only be managed with data access "Semua".
     */
    public static function canManageWorker(User $user, ?Model $worker): bool
    {
        if (! self::isManager($user)) {
            return false;
        }

        return $worker === null
            ? self::workerFilter($user) === null
            : self::canAccessPlacement($user, $worker->getAttribute('company_id'), $worker->getAttribute('division_id'));
    }

    public static function canManagePlacement(User $user, int|string|null $companyId, int|string|null $divisionId): bool
    {
        return self::isManager($user) && self::canAccessPlacement($user, $companyId, $divisionId);
    }

    /**
     * Companies whose settings (locations, policy, divisions, shifts, approval flows) the user manages.
     * Null means every company. Division-level admins manage workers, not company settings.
     *
     * @return list<int>|null
     */
    public static function manageableCompanyIds(User $user): ?array
    {
        if (! self::isManager($user) || self::isCoordinator($user)) {
            return [];
        }

        return match (self::scopeOf($user)) {
            DataScope::All => self::workerFilter($user)['companies'] ?? null,
            DataScope::Companies => self::selectedCompanyIds($user),
            default => [],
        };
    }

    /**
     * Company settings menus: a manager with whole companies in their data access, or the PIC of a
     * Klien, who needs them to add the first Badan Usaha.
     */
    public static function canManageCompanySettings(User $user): bool
    {
        return self::manageableCompanyIds($user) !== [] || self::isTenantOwner($user);
    }

    /**
     * The PIC of a Klien (never the Superadmin, never a Koordinator-only account).
     */
    public static function isTenantOwner(?User $user): bool
    {
        return $user !== null && $user->isTenantOwner() && self::isManager($user) && ! self::isCoordinator($user);
    }

    /**
     * Whether $user may add a Badan Usaha: the Superadmin, or the PIC of a Klien (to their own Klien).
     */
    public static function canCreateCompany(User $user): bool
    {
        return $user->hasRole(SystemRole::Superadmin->value) || (self::isTenantOwner($user) && $user->tenant_id !== null);
    }

    /**
     * Akun & akses: a manager (Superadmin, Admin, PIC) who manages workers. A Koordinator-only account
     * runs outlets and never manages login accounts.
     */
    public static function canManageAccounts(?User $user): bool
    {
        return $user !== null && self::canManageWorkers($user) && ! self::isCoordinator($user);
    }

    public static function canManageCompany(User $user, int|string|null $companyId): bool
    {
        $manageable = self::manageableCompanyIds($user);

        return $manageable === null || ($companyId !== null && in_array((int) $companyId, $manageable, true));
    }

    private static function ownProfileFor(User $user, Model $model): ?Model
    {
        return match (true) {
            $model instanceof Employee => $user->employee,
            $model instanceof Intern => $user->intern,
            $model instanceof Freelancer => $user->freelancer,
            default => null,
        };
    }
}
