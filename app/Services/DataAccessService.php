<?php

namespace App\Services;

use App\Enums\DataScope;
use App\Enums\SystemRole;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The one place that decides which records a user can see and manage (ERP-style data access).
 *
 * - The role decides what a user can do: Superadmin and Admin manage, Approver / BOD approve,
 *   workers submit requests.
 * - users.data_scope decides which workers that applies to:
 *   all (every company), companies (company_user), divisions (division_user, including
 *   sub-divisions) or own (only their own records). Superadmin is always "all".
 * - Everyone always sees their own records.
 */
final class DataAccessService
{
    /**
     * Resolved access per user object, so table rows and repeated checks do not re-query. Entries
     * disappear with the user object; flush() clears them when divisions or access picks change.
     *
     * @var \WeakMap<User, array{scope: DataScope, companies: list<int>, divisions: list<int>}>|null
     */
    private static ?\WeakMap $resolved = null;

    public static function flush(): void
    {
        self::$resolved = null;
    }

    public static function scopeOf(User $user): DataScope
    {
        return self::resolve($user)['scope'];
    }

    /**
     * Company and division filters that select the workers in scope. Null means every worker.
     * Divisions already include their sub-divisions.
     *
     * @return array{companies: list<int>, divisions: list<int>}|null
     */
    public static function workerFilter(User $user): ?array
    {
        $resolved = self::resolve($user);

        return $resolved['scope'] === DataScope::All
            ? null
            : ['companies' => $resolved['companies'], 'divisions' => $resolved['divisions']];
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

        $scope = $user->hasRole('Superadmin')
            ? DataScope::All
            : ($user->data_scope instanceof DataScope ? $user->data_scope : (DataScope::tryFrom((string) $user->data_scope) ?? DataScope::Own));

        $ids = fn ($relation): array => $relation->pluck($relation->getRelated()->getQualifiedKeyName())
            ->map(fn ($id): int => (int) $id)->unique()->values()->all();

        return self::$resolved[$user] = [
            'scope' => $scope,
            'companies' => $scope === DataScope::Companies ? $ids($user->accessibleCompanies()) : [],
            'divisions' => $scope === DataScope::Divisions ? self::withDescendants($ids($user->accessibleDivisions())) : [],
        ];
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

                if ($hasScope) {
                    $visible->orWhereHas($relation, fn (Builder $worker) => $worker->where(function (Builder $inScope) use ($filter): void {
                        if ($filter['companies'] !== []) {
                            $inScope->orWhereIn('company_id', $filter['companies']);
                        }

                        if ($filter['divisions'] !== []) {
                            $inScope->orWhereIn('division_id', $filter['divisions']);
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

        return $query->where(fn (Builder $visible) => $visible
            ->whereKey($user->getKey())
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
     * Superadmin, or an Admin whose data access is "Semua". Used for system-wide tools such as Horizon
     * and the Log Viewer, which show data of every company.
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
            DataScope::All => null,
            DataScope::Companies => self::selectedCompanyIds($user),
            default => [],
        };
    }

    public static function canManageCompanySettings(User $user): bool
    {
        return self::manageableCompanyIds($user) !== [];
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
