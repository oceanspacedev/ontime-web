<?php

namespace App\Traits;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared visibility rules for records that belong to a worker (employee, intern or freelancer).
 *
 * - Superadmin sees everything.
 * - Admin Badan Usaha sees every worker of the companies they manage.
 * - Approver / BOD see their own records plus workers inside their `approvers` mapping.
 * - Everyone else sees only their own records.
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
        if ($user->hasRole('Superadmin')) {
            return $query;
        }

        return $query->where(function (Builder $visible) use ($user): void {
            $visible->whereRaw('1 = 0');

            foreach (static::workerRelations() as $relation) {
                $profile = $user->{$relation};

                if ($profile) {
                    $visible->orWhere($this->qualifyColumn("{$relation}_id"), $profile->getKey());
                }
            }

            // Admin Badan Usaha: every worker of the companies they manage.
            $managedCompanyIds = $user->managedCompanyIds() ?? [];

            if ($managedCompanyIds !== []) {
                foreach (static::workerRelations() as $relation) {
                    $visible->orWhereHas($relation, fn (Builder $worker) => $worker->whereIn('company_id', $managedCompanyIds));
                }
            }

            if (! $user->hasAnyRole(['Approver', 'BOD'])) {
                return;
            }

            ['division_ids' => $divisionIds, 'company_ids' => $companyIds] = $user->approverScope();

            if ($divisionIds === [] && $companyIds === []) {
                return;
            }

            foreach (static::workerRelations() as $relation) {
                $visible->orWhereHas($relation, function (Builder $worker) use ($divisionIds, $companyIds): void {
                    $worker->where(function (Builder $scope) use ($divisionIds, $companyIds): void {
                        if ($divisionIds !== []) {
                            $scope->orWhereIn('division_id', $divisionIds);
                        }

                        if ($companyIds !== []) {
                            $scope->orWhereIn('company_id', $companyIds);
                        }
                    });
                });
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
