<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What a manager may pick when scheduling: companies, shifts and outlets inside their data access,
 * narrowed to their brands for a Koordinator. Shared by the panel and the mobile API.
 */
final class SchedulingOptions
{
    public function canPlaceInCompany(User $user, int|string|null $companyId): bool
    {
        if (! $companyId || ! $user->canManageWorkers()) {
            return false;
        }

        $companyIds = DataAccessService::placeableCompanyIds($user);

        return $companyIds === null || in_array((int) $companyId, $companyIds, true);
    }

    /**
     * @return Collection<int, Company>
     */
    public function companies(User $user): Collection
    {
        if (! $user->canManageWorkers()) {
            return collect();
        }

        $companyIds = DataAccessService::placeableCompanyIds($user);

        return Company::query()
            ->where('is_active', true)
            ->when($companyIds !== null, fn (Builder $companies) => $companies->whereIn('id', $companyIds))
            ->orderBy('name')
            ->get();
    }

    /**
     * Active shifts, ordered by brand then start time. A Koordinator gets their brands' shifts and the company-wide ones.
     *
     * @return Collection<int, Shift>
     */
    public function shifts(User $user, int|string|null $companyId): Collection
    {
        if (! $this->canPlaceInCompany($user, $companyId)) {
            return collect();
        }

        $brandIds = DataAccessService::coordinatedDivisionIds($user);

        return Shift::query()
            ->with('division')
            ->active()
            ->where('company_id', $companyId)
            ->when($brandIds !== null, fn (Builder $shifts) => $shifts->where(fn (Builder $own) => $own->whereIn('division_id', $brandIds)->orWhereNull('division_id')))
            ->orderBy('start_time')
            ->get()
            ->sortBy(fn (Shift $shift): string => ($shift->division?->name ?? '').'|'.$shift->start_time)
            ->values();
    }

    /**
     * Active outlets. A Koordinator gets only the outlets of their brands.
     *
     * @return Collection<int, CompanyLocation>
     */
    public function locations(User $user, int|string|null $companyId): Collection
    {
        if (! $this->canPlaceInCompany($user, $companyId)) {
            return collect();
        }

        $brandIds = DataAccessService::coordinatedDivisionIds($user);

        return CompanyLocation::query()
            ->with('division')
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->when($brandIds !== null, fn (Builder $outlets) => $outlets->whereIn('division_id', $brandIds))
            ->orderBy('name')
            ->get();
    }

    /**
     * Sections already used in the company's schedules ("Kasir", "Bar", ...).
     *
     * @return list<string>
     */
    public function sections(User $user, int|string|null $companyId): array
    {
        if (! $this->canPlaceInCompany($user, $companyId)) {
            return [];
        }

        return ShiftSchedule::query()
            ->whereNotNull('section')
            ->whereWorkerInCompanies([(int) $companyId])
            ->distinct()
            ->orderBy('section')
            ->limit(50)
            ->pluck('section')
            ->all();
    }
}
