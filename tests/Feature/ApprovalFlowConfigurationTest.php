<?php

namespace Tests\Feature;

use App\Filament\Pages\ApprovalSaya;
use App\Filament\Resources\ApprovalFlows\Pages\EditApprovalFlow;
use App\Filament\Resources\LeaveRequests\Pages\CreateLeaveRequest;
use App\Filament\Resources\ResignationResource\Pages\CreateResignation;
use App\Models\ApprovalFlow;
use App\Models\ApprovalRequestStep;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\Resignation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ApprovalFlowConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Division $division;

    private User $superadmin;

    private User $employeeUser;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Superadmin', 'Approver', 'BOD', 'Employee'] as $role) {
            Role::create(['name' => $role]);
        }

        $this->company = Company::create(['name' => 'PT Alur', 'code' => 'ALR', 'is_active' => true]);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Keuangan', 'is_active' => true]);

        $this->superadmin = User::factory()->create();
        $this->superadmin->assignRole('Superadmin');

        $this->employeeUser = User::factory()->create();
        $this->employeeUser->assignRole('Employee');

        $this->employee = Employee::create([
            'user_id' => $this->employeeUser->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'job_level_id' => JobLevel::create(['name' => 'Staff', 'level_order' => 1])->id,
            'job_title_id' => JobTitle::create(['name' => 'Staff Keuangan'])->id,
            'nip' => 'ALR-001',
            'full_name' => 'Karyawan Alur',
            'status' => 'active',
        ]);
    }

    public function test_approval_flow_can_be_saved_with_resignation_and_geofence_steps(): void
    {
        foreach (['leave', 'overtime', 'correction'] as $requestType) {
            $this->createFlowStep($requestType);
        }

        $step = fn (string $name): array => ['name' => $name, 'approver_type' => 'role', 'approver_role' => 'Superadmin', 'user_id' => null];

        $this->actingAs($this->superadmin);

        Livewire::test(EditApprovalFlow::class, ['record' => $this->company->id])
            ->fillForm([
                'resignation_steps' => [$step('Persetujuan Resign')],
                'geofence_steps' => [$step('Persetujuan Geofence')],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('approval_flows', ['company_id' => $this->company->id, 'request_type' => 'resignation', 'name' => 'Persetujuan Resign']);
        $this->assertDatabaseHas('approval_flows', ['company_id' => $this->company->id, 'request_type' => 'geofence', 'name' => 'Persetujuan Geofence']);
        $this->assertDatabaseHas('approval_flows', ['company_id' => $this->company->id, 'request_type' => 'leave']);
    }

    public function test_resignation_is_not_saved_when_no_approval_flow_is_configured(): void
    {
        $this->actingAs($this->employeeUser);

        Livewire::test(CreateResignation::class)
            ->fillForm([
                'resignation_date' => '2026-11-04',
                'last_working_day' => '2026-12-04',
                'reason' => 'Melanjutkan studi',
            ])
            ->call('create')
            ->assertNotified('Pengajuan belum dapat dikirim');

        $this->assertDatabaseCount('resignations', 0);
        $this->assertDatabaseCount('approval_request_steps', 0);
    }

    public function test_leave_request_is_not_saved_when_no_approval_flow_is_configured(): void
    {
        $this->actingAs($this->employeeUser);

        Livewire::test(CreateLeaveRequest::class)
            ->fillForm([
                'leave_type' => 'permission',
                'start_date' => '2026-11-09',
                'end_date' => '2026-11-09',
                'reason' => 'Urusan keluarga',
            ])
            ->call('create')
            ->assertNotified('Pengajuan belum dapat dikirim');

        $this->assertSame(0, LeaveRequest::count());
    }

    public function test_approval_inbox_lists_pending_resignations(): void
    {
        $this->createFlowStep('resignation');

        $approverUser = User::factory()->create();
        $approverUser->assignRole('Approver');
        $this->grantDataAccessTo($approverUser, $this->company->id, $this->division->id);

        $this->actingAs($this->employeeUser);

        Livewire::test(CreateResignation::class)
            ->fillForm([
                'resignation_date' => '2026-11-04',
                'last_working_day' => '2026-12-04',
                'reason' => 'Pindah kota',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $resignation = Resignation::firstOrFail();
        $this->assertSame(1, ApprovalRequestStep::where('approvable_type', $resignation->getMorphClass())->where('approvable_id', $resignation->id)->count());

        $this->actingAs($approverUser);

        $inbox = Livewire::test(ApprovalSaya::class)->assertSuccessful();

        $this->assertSame([$resignation->id], $inbox->instance()->pendingResignations->pluck('id')->values()->all());
    }

    public function test_employee_cannot_see_approval_inbox(): void
    {
        $this->actingAs($this->employeeUser);

        $this->assertFalse(ApprovalSaya::canAccess());
    }

    private function createFlowStep(string $requestType): ApprovalFlow
    {
        return ApprovalFlow::create([
            'company_id' => $this->company->id,
            'request_type' => $requestType,
            'step_number' => 1,
            'step_order' => 1,
            'name' => "Persetujuan Approver {$requestType}",
            'approver_type' => 'role',
            'approver_role' => 'Approver',
        ]);
    }
}
