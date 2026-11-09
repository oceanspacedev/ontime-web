<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\EmployeeResource;
use App\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\UserResource;
use App\Models\ApprovalFlow;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\ApprovalFlowService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyAdminAccessTest extends TestCase
{
    use RefreshDatabase;

    private Company $top;

    private Company $cs;

    private User $admin;

    private User $superadmin;

    private Employee $employeeTop;

    private Employee $employeeCs;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Superadmin', 'Admin', 'Approver', 'BOD', 'Employee'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->top = Company::create(['name' => 'CV Top', 'code' => 'TOP', 'is_active' => true]);
        $this->cs = Company::create(['name' => 'CV CS', 'code' => 'CS', 'is_active' => true]);

        $this->employeeTop = $this->makeEmployee($this->top, 'TOP-001');
        $this->employeeCs = $this->makeEmployee($this->cs, 'CS-001');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        $this->grantDataAccessTo($this->admin, $this->top->id);

        $this->superadmin = User::factory()->create();
        $this->superadmin->assignRole('Superadmin');
    }

    public function test_company_admin_only_sees_employees_of_their_companies(): void
    {
        $this->actingAs($this->admin);

        $this->assertTrue(EmployeeResource::canViewAny());
        $this->assertSame([$this->employeeTop->id], EmployeeResource::getEloquentQuery()->pluck('id')->all());

        $this->get(EmployeeResource::getUrl('edit', ['record' => $this->employeeTop]))->assertOk();
        $this->get(EmployeeResource::getUrl('edit', ['record' => $this->employeeCs]))->assertNotFound();
    }

    public function test_company_admin_manages_requests_of_their_companies(): void
    {
        $leaveTop = $this->makeLeave($this->employeeTop);
        $leaveCs = $this->makeLeave($this->employeeCs);

        $this->actingAs($this->admin);

        $this->assertSame([$leaveTop->id], LeaveRequestResource::getEloquentQuery()->pluck('id')->all());
        $this->assertTrue(LeaveRequestResource::canEdit($leaveTop->load('employee')));
        $this->assertFalse(LeaveRequestResource::canEdit($leaveCs->load('employee')));

        $service = app(ApprovalFlowService::class);
        $this->assertTrue($service->isUserAuthorizedToApprove($leaveTop, $this->admin));
        $this->assertFalse($service->isUserAuthorizedToApprove($leaveCs, $this->admin));

        $service->approveStep($leaveTop, $this->admin);
        $this->assertSame('approved', $leaveTop->fresh()->status, 'The default flow has a single stage.');
    }

    public function test_company_admin_cannot_hand_out_superadmin_or_admin_roles(): void
    {
        $this->actingAs($this->admin);

        $roles = EmployeeResource::assignableRoleOptions();

        $this->assertArrayHasKey('Employee', $roles);
        $this->assertArrayNotHasKey('Superadmin', $roles);
        $this->assertArrayNotHasKey('Admin', $roles);

        Livewire::test(ListEmployees::class)
            ->callAction(TestAction::make('createUser')->table($this->employeeTop), data: ['role' => 'Superadmin'])
            ->assertHasFormErrors(['role']);

        $this->assertNull($this->employeeTop->fresh()->user_id);
    }

    public function test_company_admin_never_sees_or_deletes_superadmin_accounts(): void
    {
        $superadminEmployee = $this->makeEmployee($this->top, 'TOP-SA');
        $superadminEmployee->update(['user_id' => $this->superadmin->id]);

        $staffUser = User::factory()->create();
        $staffUser->assignRole('Employee');
        $this->employeeTop->update(['user_id' => $staffUser->id]);

        $this->actingAs($this->admin);

        $this->assertSame([$staffUser->id], UserResource::getEloquentQuery()->pluck('id')->all());
        $this->assertFalse(EmployeeResource::canManageAccount($this->superadmin));
        $this->assertTrue(EmployeeResource::canManageAccount($staffUser));
        $this->assertFalse(EmployeeResource::canManageAccount($this->admin));
    }

    public function test_only_superadmin_creates_or_deletes_companies(): void
    {
        $this->actingAs($this->admin);

        $this->assertTrue(CompanyResource::canViewAny());
        $this->assertFalse(CompanyResource::canCreate());
        $this->assertFalse(CompanyResource::canDelete($this->top));
        $this->assertSame([$this->top->id], CompanyResource::getEloquentQuery()->pluck('id')->all());
    }

    public function test_admin_without_companies_gets_no_access(): void
    {
        $emptyAdmin = User::factory()->create();
        $emptyAdmin->assignRole('Admin');

        $this->actingAs($emptyAdmin);

        $this->assertFalse(EmployeeResource::canViewAny());
        $this->assertFalse(CompanyResource::canViewAny());
        $this->assertSame([], $emptyAdmin->manageableCompanyIds());
    }

    public function test_superadmin_grants_company_access_to_an_admin(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Admin');

        $this->actingAs($this->superadmin);

        Livewire::test(EditUser::class, ['record' => $user->id])
            ->fillForm(['data_scope' => DataScope::Companies->value, 'accessibleCompanies' => [$this->top->id, $this->cs->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $user = $user->fresh();
        $this->assertSame(DataScope::Companies, $user->dataScope());
        $this->assertEqualsCanonicalizing([$this->top->id, $this->cs->id], $user->manageableCompanyIds());
    }

    private function makeEmployee(Company $company, string $nip): Employee
    {
        $division = Division::firstOrCreate(['company_id' => $company->id, 'name' => 'Umum'], ['is_active' => true]);

        return Employee::create([
            'company_id' => $company->id,
            'division_id' => $division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Staff'])->id,
            'nip' => $nip,
            'full_name' => "Karyawan {$nip}",
            'join_date' => '2025-01-06',
            'status' => 'active',
        ]);
    }

    private function makeLeave(Employee $employee): LeaveRequest
    {
        if (! ApprovalFlow::where('company_id', $employee->company_id)->exists()) {
            app(ApprovalFlowService::class)->createDefaultFlows($employee->company);
        }

        $leave = LeaveRequest::create(['employee_id' => $employee->id, 'leave_type' => 'permission', 'start_date' => '2026-11-09', 'end_date' => '2026-11-09', 'days_count' => 1, 'reason' => 'Izin', 'status' => 'pending']);
        app(ApprovalFlowService::class)->generateSteps($leave, 'leave');

        return $leave->fresh();
    }
}
