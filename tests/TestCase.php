<?php

namespace Tests;

use App\Enums\DataScope;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Give a user data access for one placement: a division (with its sub-divisions) or, without a
     * division, the whole company. Adds to companies or divisions granted earlier.
     */
    protected function grantDataAccessTo(User $user, int $companyId, ?int $divisionId = null): User
    {
        $companyIds = $user->accessibleCompanies()->pluck('companies.id')->map(fn ($id): int => (int) $id)->all();
        $divisionIds = $user->accessibleDivisions()->pluck('divisions.id')->map(fn ($id): int => (int) $id)->all();

        return $divisionId
            ? $user->grantDataAccess(DataScope::Divisions, divisionIds: [...$divisionIds, $divisionId])
            : $user->grantDataAccess(DataScope::Companies, companyIds: [...$companyIds, $companyId]);
    }
}
