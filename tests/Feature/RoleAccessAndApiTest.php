<?php

namespace Tests\Feature;

use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Resources\LeaveRequests\Pages\CreateLeaveRequest;
use App\Models\ApprovalFlow;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Intern;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Core\CoreUserSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleAccessAndApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Division $division;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Superadmin', 'Approver', 'BOD', 'Employee', 'Intern'] as $role) {
            Role::create(['name' => $role]);
        }

        $this->company = Company::create(['name' => 'PT Akses', 'code' => 'AKS', 'is_active' => true]);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Umum', 'is_active' => true]);
    }

    public function test_user_without_any_role_cannot_open_the_panel(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
    }

    public function test_user_with_a_role_can_open_the_panel(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Employee');

        $this->actingAs($user)->get('/admin')->assertOk();
    }

    public function test_approver_with_employee_profile_can_submit_leave(): void
    {
        ApprovalFlow::create(['company_id' => $this->company->id, 'request_type' => 'leave', 'step_number' => 1, 'step_order' => 1, 'name' => 'BOD', 'approver_type' => 'role', 'approver_role' => 'BOD']);
        $approver = $this->makeEmployeeUser('Approver');

        $this->actingAs($approver);

        Livewire::test(CreateLeaveRequest::class)
            ->fillForm(['leave_type' => 'permission', 'start_date' => '2026-11-09', 'end_date' => '2026-11-09', 'reason' => 'Urusan keluarga'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('leave_requests', ['employee_id' => $approver->employee->id]);
    }

    public function test_bod_without_worker_profile_does_not_get_a_leave_form(): void
    {
        $bod = User::factory()->create();
        $bod->assignRole('BOD');

        $this->actingAs($bod);

        $this->assertFalse(LeaveRequestResource::canCreate());
    }

    public function test_attendance_api_lists_many_rows_without_errors(): void
    {
        $superadmin = User::factory()->create();
        $superadmin->assignRole('Superadmin');

        $employee = $this->makeEmployeeUser('Employee')->employee;
        $intern = Intern::create(['company_id' => $this->company->id, 'division_id' => $this->division->id, 'nis' => 'MG-AKS', 'full_name' => 'Magang Akses', 'institution' => 'SMK', 'status' => 'active']);

        Attendance::create(['employee_id' => $employee->id, 'date' => today(), 'status' => 'on_time']);
        Attendance::create(['employee_id' => $employee->id, 'date' => today()->subDay(), 'status' => 'on_time']);
        Attendance::create(['intern_id' => $intern->id, 'date' => today(), 'status' => 'on_time']);

        Sanctum::actingAs($superadmin);

        $this->getJson('/api/v1/attendances')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/laporan-absensi?start_date='.today()->subDay()->toDateString().'&end_date='.today()->toDateString())->assertOk();
    }

    public function test_leave_api_lists_many_rows_without_errors(): void
    {
        $user = $this->makeEmployeeUser('Employee');

        foreach (['2026-11-09', '2026-11-10'] as $date) {
            LeaveRequest::create(['employee_id' => $user->employee->id, 'leave_type' => 'permission', 'start_date' => $date, 'end_date' => $date, 'days_count' => 1, 'reason' => 'Izin', 'status' => 'pending']);
        }

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/leave-requests')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_sso_redirect_without_configuration_returns_to_login(): void
    {
        config(['services.core.client_id' => null, 'services.core.client_secret' => null, 'services.core.redirect' => null]);

        $this->get('/auth/redirect')->assertRedirect('/admin/login');
    }

    public function test_sso_sync_links_an_unlinked_employee_and_grants_the_employee_role(): void
    {
        $employee = Employee::create([
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Staff Umum'])->id,
            'nip' => 'AKS-SSO',
            'full_name' => 'Karyawan SSO',
            'email' => 'sso@example.com',
            'status' => 'active',
        ]);

        $user = app(CoreUserSynchronizer::class)->sync(['email' => 'sso@example.com', 'name' => 'Karyawan SSO']);

        $this->assertSame($user->id, $employee->fresh()->user_id);
        $this->assertTrue($user->hasRole('Employee'));
    }

    private function makeEmployeeUser(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        Employee::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Staff Umum'])->id,
            'nip' => 'AKS-'.$user->id,
            'full_name' => "Pekerja {$user->id}",
            'status' => 'active',
        ]);

        return $user->fresh();
    }
}
