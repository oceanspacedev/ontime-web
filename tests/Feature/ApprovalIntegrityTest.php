<?php

namespace Tests\Feature;

use App\Filament\Resources\AttendanceCorrections\Pages\CreateAttendanceCorrection;
use App\Models\ApprovalFlow;
use App\Models\Approver;
use App\Models\AttendanceCorrection;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Services\ApprovalFlowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ApprovalIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Division $division;

    private ApprovalFlowService $service;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Superadmin', 'Approver', 'Employee'] as $role) {
            Role::create(['name' => $role]);
        }

        $this->company = Company::create(['name' => 'PT Integritas', 'code' => 'ITG', 'is_active' => true]);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Admin', 'is_active' => true]);
        $this->service = app(ApprovalFlowService::class);

        foreach (['leave', 'correction'] as $requestType) {
            ApprovalFlow::create(['company_id' => $this->company->id, 'request_type' => $requestType, 'step_number' => 1, 'step_order' => 1, 'name' => 'Approver', 'approver_type' => 'role', 'approver_role' => 'Approver']);
            ApprovalFlow::create(['company_id' => $this->company->id, 'request_type' => $requestType, 'step_number' => 2, 'step_order' => 2, 'name' => 'Superadmin', 'approver_type' => 'role', 'approver_role' => 'Superadmin']);
        }
    }

    public function test_corrected_times_are_stored_on_the_corrected_date(): void
    {
        $user = $this->makeWorker('Employee');
        $this->actingAs($user);

        Livewire::test(CreateAttendanceCorrection::class)
            ->fillForm(['date' => '2026-10-20', 'corrected_check_in' => '08:05', 'corrected_check_out' => '17:10', 'reason' => 'Lupa absen'])
            ->call('create')
            ->assertHasNoFormErrors();

        $correction = AttendanceCorrection::firstOrFail();

        $this->assertSame('2026-10-20 08:05:00', $correction->corrected_check_in->toDateTimeString());
        $this->assertSame('2026-10-20 17:10:00', $correction->corrected_check_out->toDateTimeString());
    }

    public function test_approved_correction_writes_the_corrected_date_into_attendance(): void
    {
        $worker = $this->makeWorker('Employee');
        $correction = AttendanceCorrection::create(['employee_id' => $worker->employee->id, 'date' => '2026-10-20', 'corrected_check_in' => '08:05', 'reason' => 'Lupa absen', 'status' => 'pending']);

        $correction->applyCorrection();

        $this->assertDatabaseHas('attendances', ['employee_id' => $worker->employee->id, 'check_in' => '2026-10-20 08:05:00']);
    }

    public function test_superadmin_cannot_approve_their_own_request_at_any_stage(): void
    {
        $superadminWorker = $this->makeWorker('Superadmin');
        $otherSuperadmin = User::factory()->create();
        $otherSuperadmin->assignRole('Superadmin');

        $leave = $this->makeLeave($superadminWorker);

        // Stage 1 is an Approver stage: the Superadmin override does not cover their own request.
        $this->assertFalse($this->service->isUserAuthorizedToApprove($leave, $superadminWorker));
        $this->assertTrue($this->service->isUserAuthorizedToApprove($leave, $otherSuperadmin));

        // Final stage.
        $leave->update(['current_step' => 2]);
        $this->assertFalse($this->service->isUserAuthorizedToApprove($leave->fresh(), $superadminWorker));
        $this->assertTrue($this->service->isUserAuthorizedToApprove($leave->fresh(), $otherSuperadmin));
    }

    public function test_requester_cannot_give_the_final_approval_on_a_single_stage_flow(): void
    {
        ApprovalFlow::create(['company_id' => $this->company->id, 'request_type' => 'overtime', 'step_number' => 1, 'step_order' => 1, 'name' => 'Approver', 'approver_type' => 'role', 'approver_role' => 'Approver']);

        $approver = $this->makeWorker('Approver');
        Approver::create(['user_id' => $approver->id, 'company_id' => $this->company->id, 'division_id' => $this->division->id, 'level' => 1]);
        $colleague = $this->makeWorker('Employee');

        $this->assertFalse($this->service->isUserAuthorizedToApprove($this->makeOvertime($approver), $approver));
        $this->assertTrue($this->service->isUserAuthorizedToApprove($this->makeOvertime($colleague), $approver));
    }

    public function test_requester_may_pass_an_intermediate_stage_matching_their_role(): void
    {
        $approver = $this->makeWorker('Approver');
        Approver::create(['user_id' => $approver->id, 'company_id' => $this->company->id, 'division_id' => $this->division->id, 'level' => 1]);

        $leave = $this->makeLeave($approver);

        $this->assertTrue($this->service->isUserAuthorizedToApprove($leave, $approver));

        $this->service->approveStep($leave, $approver);
        $this->assertFalse($this->service->isUserAuthorizedToApprove($leave->fresh(), $approver));
    }

    public function test_command_generates_steps_for_stuck_requests(): void
    {
        $worker = $this->makeWorker('Employee');
        $stuck = LeaveRequest::create(['employee_id' => $worker->employee->id, 'leave_type' => 'permission', 'start_date' => '2026-11-09', 'end_date' => '2026-11-09', 'days_count' => 1, 'reason' => 'Izin', 'status' => 'pending']);

        $this->artisan('approvals:generate-missing-steps', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, $stuck->approvalSteps()->count());

        $this->artisan('approvals:generate-missing-steps')->assertSuccessful();
        $this->assertSame(2, $stuck->approvalSteps()->count());

        $this->artisan('approvals:generate-missing-steps')
            ->expectsOutput('Tidak ada pengajuan pending tanpa tahap approval.')
            ->assertSuccessful();
    }

    private function makeWorker(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        Employee::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Staff Admin'])->id,
            'nip' => 'ITG-'.$user->id,
            'full_name' => "Pekerja {$user->id}",
            'status' => 'active',
        ]);

        return $user->fresh();
    }

    private function makeLeave(User $worker): LeaveRequest
    {
        $leave = LeaveRequest::create(['employee_id' => $worker->employee->id, 'leave_type' => 'permission', 'start_date' => '2026-11-09', 'end_date' => '2026-11-09', 'days_count' => 1, 'reason' => 'Izin', 'status' => 'pending']);
        $this->service->generateSteps($leave, 'leave');

        return $leave->fresh();
    }

    private function makeOvertime(User $worker): OvertimeRequest
    {
        $overtime = OvertimeRequest::create(['employee_id' => $worker->employee->id, 'date' => '2026-11-04', 'start_time' => '18:00', 'end_time' => '20:00', 'duration_minutes' => 120, 'reason' => 'Lembur', 'status' => 'pending']);
        $this->service->generateSteps($overtime, 'overtime');

        return $overtime->fresh();
    }
}
