<?php

namespace Tests\Feature;

use App\Filament\Resources\ResignationResource;
use App\Filament\Resources\ResignationResource\Pages\CreateResignation;
use App\Models\ApprovalFlow;
use App\Models\Approver;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\Resignation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ResignationAccessTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Division $division;

    private User $superadmin;

    private User $alice;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Superadmin', 'Approver', 'BOD', 'Employee', 'Freelancer'] as $role) {
            Role::create(['name' => $role]);
        }

        $this->company = Company::create(['name' => 'PT Resign', 'code' => 'RSG', 'is_active' => true]);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'HR', 'is_active' => true]);

        ApprovalFlow::create([
            'company_id' => $this->company->id,
            'request_type' => 'resignation',
            'step_number' => 1,
            'step_order' => 1,
            'name' => 'Persetujuan Approver',
            'approver_type' => 'role',
            'approver_role' => 'Approver',
        ]);

        $this->superadmin = User::factory()->create();
        $this->superadmin->assignRole('Superadmin');

        $this->alice = $this->makeEmployeeUser('RSG-001');
        $this->bob = $this->makeEmployeeUser('RSG-002');
    }

    public function test_employee_cannot_file_a_resignation_for_a_colleague(): void
    {
        $this->actingAs($this->alice);

        Livewire::test(CreateResignation::class)
            ->fillForm([
                'employee_id' => $this->bob->employee->id,
                'resignation_date' => '2026-11-04',
                'last_working_day' => '2026-12-04',
                'reason' => 'Mencoba mengajukan untuk rekan',
            ])
            ->call('create')
            ->assertHasFormErrors(['employee_id']);

        $this->assertDatabaseCount('resignations', 0);
    }

    public function test_employee_resignation_defaults_to_their_own_profile(): void
    {
        $this->actingAs($this->alice);

        Livewire::test(CreateResignation::class)
            ->assertSchemaStateSet(['employee_id' => $this->alice->employee->id])
            ->fillForm([
                'resignation_date' => '2026-11-04',
                'last_working_day' => '2026-12-04',
                'reason' => 'Melanjutkan studi',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('resignations', ['employee_id' => $this->alice->employee->id]);
        $this->assertDatabaseMissing('resignations', ['employee_id' => $this->bob->employee->id]);
    }

    public function test_superadmin_can_file_a_resignation_for_any_employee(): void
    {
        $this->actingAs($this->superadmin);

        Livewire::test(CreateResignation::class)
            ->fillForm([
                'employee_id' => $this->bob->employee->id,
                'resignation_date' => '2026-11-04',
                'last_working_day' => '2026-12-04',
                'reason' => 'Diajukan oleh HR',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('resignations', ['employee_id' => $this->bob->employee->id]);
    }

    public function test_employees_only_see_their_own_resignations(): void
    {
        $aliceResignation = $this->makeResignation($this->alice);
        $this->makeResignation($this->bob);

        $this->actingAs($this->alice);

        $this->assertSame([$aliceResignation->id], ResignationResource::getEloquentQuery()->pluck('id')->all());
        $this->assertFalse(ResignationResource::canEdit($aliceResignation));
        $this->assertFalse(ResignationResource::canDelete($aliceResignation));
    }

    public function test_mapped_approver_sees_resignations_in_scope(): void
    {
        $aliceResignation = $this->makeResignation($this->alice);

        $approver = User::factory()->create();
        $approver->assignRole('Approver');
        Approver::create(['user_id' => $approver->id, 'company_id' => $this->company->id, 'division_id' => $this->division->id, 'level' => 1]);

        $this->actingAs($approver);

        $this->assertTrue(ResignationResource::canViewAny());
        $this->assertSame([$aliceResignation->id], ResignationResource::getEloquentQuery()->pluck('id')->all());
    }

    public function test_users_without_employee_profile_cannot_open_resignations(): void
    {
        $freelancerUser = User::factory()->create();
        $freelancerUser->assignRole('Freelancer');
        Freelancer::create([
            'user_id' => $freelancerUser->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'freelancer_number' => 'FL-RSG-01',
            'full_name' => 'Freelancer Resign',
            'status' => 'active',
        ]);

        $this->actingAs($freelancerUser);
        $this->assertFalse(ResignationResource::canViewAny());
        $this->assertFalse(ResignationResource::canCreate());

        $this->actingAs(User::factory()->create());
        $this->assertFalse(ResignationResource::canViewAny());
        $this->assertFalse(ResignationResource::canCreate());
    }

    public function test_only_superadmin_can_edit_or_delete_resignations(): void
    {
        $resignation = $this->makeResignation($this->alice);

        $this->actingAs($this->superadmin);

        $this->assertTrue(ResignationResource::canEdit($resignation));
        $this->assertTrue(ResignationResource::canDelete($resignation));
        $this->assertTrue(ResignationResource::canDeleteAny());
    }

    public function test_resignation_api_detail_is_forbidden_for_other_employees(): void
    {
        $bobResignation = $this->makeResignation($this->bob);

        Sanctum::actingAs($this->alice);

        $this->getJson('/api/v1/resignations/'.$bobResignation->id)->assertForbidden();
        $this->assertSame(0, $this->getJson('/api/v1/resignations')->json('pagination.total'));
    }

    private function makeEmployeeUser(string $nip): User
    {
        $user = User::factory()->create();
        $user->assignRole('Employee');

        Employee::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Staff HR'])->id,
            'nip' => $nip,
            'full_name' => "Karyawan {$nip}",
            'status' => 'active',
        ]);

        return $user->fresh();
    }

    private function makeResignation(User $user): Resignation
    {
        return Resignation::create([
            'employee_id' => $user->employee->id,
            'resignation_date' => '2026-11-04',
            'last_working_day' => '2026-12-04',
            'reason' => 'Alasan pribadi',
            'status' => 'pending',
        ]);
    }
}
