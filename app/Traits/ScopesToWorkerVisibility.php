<?php

namespace App\Traits;

use App\Models\User;
use App\Services\DataAccessService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared visibility rules for records that belong to a worker (employee, intern or freelancer).
 * Everyone sees their own records plus the workers inside their data access (see DataAccessService).
 */
trait ScopesToWorkerVisibility
{
    /**
     * Worker relations available on the model. Each relation has a matching `{relation}_id` column.
     *
     * @return list<string>
     */
    public static function workerRelations(): array
    {
        return ['employee', 'intern', 'freelancer'];
    }

    /**
     * Relations to eager load before serialising worker details (name, company, division).
     *
     * @return list<string>
     */
    public static function workerEagerLoads(): array
    {
        return collect(static::workerRelations())
            ->flatMap(fn (string $relation): array => ["{$relation}.company", "{$relation}.division"])
            ->all();
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return DataAccessService::scopeWorkerRecords($query, $user, static::workerRelations());
    }

    /**
     * Calendar visibility: everything in the user's data access plus colleagues of their own company,
     * so workers can see who else is on leave.
     */
    public function scopeVisibleWithColleaguesTo(Builder $query, User $user): Builder
    {
        if (DataAccessService::workerFilter($user) === null) {
            return $query;
        }

        $ownCompanyIds = collect(DataAccessService::ownProfiles($user))->pluck('company_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all();

        return $query->where(function (Builder $visible) use ($user, $ownCompanyIds): void {
            $visible->where(fn (Builder $scoped) => $scoped->visibleTo($user));

            if ($ownCompanyIds !== []) {
                $visible->orWhere(fn (Builder $colleagues) => $colleagues->whereWorkerInCompanies($ownCompanyIds));
            }
        });
    }

    /**
     * Limit records to workers that belong to one of the given companies.
     *
     * @param  list<int>  $companyIds
     */
    public function scopeWhereWorkerInCompanies(Builder $query, array $companyIds): Builder
    {
        if ($companyIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $inCompanies) use ($companyIds): void {
            foreach (static::workerRelations() as $relation) {
                $inCompanies->orWhereHas($relation, fn (Builder $worker) => $worker->whereIn('company_id', $companyIds));
            }
        });
    }
}
