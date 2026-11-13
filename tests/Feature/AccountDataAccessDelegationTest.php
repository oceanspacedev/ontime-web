<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Filament\Concerns\ScopesToManagedCompanies;
use App\Filament\Resources\JobLevelResource;
use App\Filament\Resources\JobTitleResource;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\User;
use App\Services\DataAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * An Admin sets who an Approver or BOD account sees, but never wider than their own access, and cannot
 * touch accounts of another company's approvers.
 */
class AccountDataAccessDelegationTest extends TestCase
{
    use RefreshDatabase;

    private Company $companyA;

    private Company $companyB;

    private User $adminA;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Superadmin', 'Admin', 'Koordinator', 'BOD', 'Approver', 'Employee'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->companyA = Company::create(['name' => 'PT A', 'code' => 'A', 'is_active' => true]);
        $this->companyB = Company::create(['name' => 'PT B', 'code' => 'B', 'is_active' => true]);
        $this->adminA = $this->account('Admin', DataScope::Companies, [$this->companyA->id]);
    }

    public function test_nobody_but_a_global_manager_grants_more_than_they_hold(): void
    {
        $divisionA = Division::create(['company_id' => $this->companyA->id, 'name' => 'Toko', 'is_active' => true]);
        $divisionB = Division::create(['company_id' => $this->companyB->id, 'name' => 'Toko', 'is_active' => true]);

        $this->assertTrue(DataAccessService::canGrantAccess($this->adminA, DataScope::Own));
        $this->assertTrue(DataAccessService::canGrantAccess($this->adminA, DataScope::Companies, [$this->companyA->id]));
        $this->assertFalse(DataAccessService::canGrantAccess($this->adminA, DataScope::Companies, [$this->companyA->id, $this->companyB->id]));
        $this->assertFalse(DataAccessService::canGrantAccess($this->adminA, DataScope::All));
        $this->assertTrue(DataAccessService::canGrantAccess($this->adminA, DataScope::Divisions, divisionIds: [$divisionA->id]));
        $this->assertFalse(DataAccessService::canGrantAccess($this->adminA, DataScope::Divisions, divisionIds: [$divisionB->id]));

        $global = $this->account('Admin', DataScope::All);
        $this->assertTrue(DataAccessService::canGrantAccess($global, DataScope::All));

        $divisionAdmin = $this->account('Admin', DataScope::Divisions, divisionIds: [$divisionA->id]);
        $this->assertFalse(DataAccessService::canGrantAccess($divisionAdmin, DataScope::Companies, [$this->companyA->id]), 'A division is not the whole company.');
    }

    public function test_an_admin_cannot_manage_or_link_an_approver_account_reaching_another_company(): void
    {
        $inReach = $this->account('Approver', DataScope::Companies, [$this->companyA->id]);
        $broader = $this->account('BOD', DataScope::Companies, [$this->companyA->id, $this->companyB->id]);
        $everything = $this->account('BOD', DataScope::All);
        $otherCompany = $this->account('Approver', DataScope::Companies, [$this->companyB->id]);
        $this->actingAs($this->adminA);

        $this->assertTrue(ScopesToManagedCompanies::canManageAccount($inReach));
        $this->assertFalse(ScopesToManagedCompanies::canManageAccount($broader));
        $this->assertFalse(ScopesToManagedCompanies::canManageAccount($everything));
        $this->assertFalse(ScopesToManagedCompanies::canManageAccount($otherCompany));

        $linkable = ScopesToManagedCompanies::assignableUserOptions();
        $this->assertArrayHasKey($inReach->id, $linkable);
        $this->assertArrayNotHasKey($broader->id, $linkable);
        $this->assertArrayNotHasKey($everything->id, $linkable);
        $this->assertArrayNotHasKey($otherCompany->id, $linkable);
    }

    public function test_an_admin_gives_an_approver_access_to_their_company_but_not_beyond(): void
    {
        $approver = $this->workerAccount($this->companyA, 'Approver');
        $this->actingAs($this->adminA);

        Livewire::test(EditUser::class, ['record' => $approver->id])
            ->fillForm(['data_scope' => DataScope::Companies->value, 'accessibleCompanies' => [$this->companyA->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(DataScope::Companies, $approver->fresh()->dataScope());
        $this->assertSame([$this->companyA->id], $approver->accessibleCompanies()->pluck('companies.id')->all());

        Livewire::test(EditUser::class, ['record' => $approver->id])
            ->fillForm(['data_scope' => DataScope::All->value])
            ->call('save')
            ->assertHasFormErrors(['data_scope']);

        $this->assertSame(DataScope::Companies, $approver->fresh()->dataScope());
    }

    public function test_hr_makes_a_store_coordinator_without_a_superadmin(): void
    {
        $brand = Division::create(['company_id' => $this->companyA->id, 'name' => 'JCHICKEN', 'is_active' => true]);
        $other = Division::create(['company_id' => $this->companyB->id, 'name' => 'LUUCA', 'is_active' => true]);
        $crew = $this->workerAccount($this->companyA, 'Employee');
        $this->actingAs($this->adminA);

        $roles = ScopesToManagedCompanies::assignableRoleOptions();
        $this->assertArrayHasKey('Koordinator', $roles);
        $this->assertArrayNotHasKey('Admin', $roles);
        $this->assertArrayNotHasKey('Superadmin', $roles);

        Livewire::test(EditUser::class, ['record' => $crew->id])
            ->fillForm(['roles' => [Role::findByName('Koordinator', 'web')->id], 'data_scope' => DataScope::Divisions->value, 'accessibleDivisions' => [$brand->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $crew = $crew->fresh();
        $this->assertTrue($crew->hasRole('Koordinator'));
        $this->assertSame([$brand->id], $crew->accessibleDivisions()->pluck('divisions.id')->all());
        $this->assertTrue(ScopesToManagedCompanies::canManageAccount($crew), 'HR keeps managing the coordinator they made.');

        Livewire::test(EditUser::class, ['record' => $crew->id])
            ->fillForm(['data_scope' => DataScope::Divisions->value, 'accessibleDivisions' => [$other->id]])
            ->call('save')
            ->assertHasFormErrors(['data_scope']);
    }

    public function test_hr_manages_job_levels_and_titles_but_a_coordinator_does_not(): void
    {
        $this->actingAs($this->adminA);
        $this->assertTrue(JobTitleResource::canViewAny());
        $this->assertTrue(JobLevelResource::canViewAny());

        $this->actingAs($this->account('Koordinator', DataScope::Companies, [$this->companyA->id]));
        $this->assertFalse(JobTitleResource::canViewAny());

        $this->actingAs($this->account('Employee', DataScope::Own));
        $this->assertFalse(JobLevelResource::canViewAny());
    }

    /**
     * @param  list<int>  $companyIds
     * @param  list<int>  $divisionIds
     */
    private function account(string $role, DataScope $scope, array $companyIds = [], array $divisionIds = []): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);
        $user->grantDataAccess($scope, companyIds: $companyIds, divisionIds: $divisionIds);

        return $user;
    }

    private function workerAccount(Company $company, string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);
        $division = Division::firstOrCreate(['company_id' => $company->id, 'name' => 'Umum'], ['is_active' => true]);
        Employee::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'division_id' => $division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Kasir'])->id,
            'nip' => 'N-'.$user->id,
            'join_date' => '2025-01-01',
            'full_name' => "Pekerja {$user->id}",
            'status' => 'active',
        ]);

        return $user;
    }
}
