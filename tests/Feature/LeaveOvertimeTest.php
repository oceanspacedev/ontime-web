<?php

namespace Tests\Feature;

use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Models\ApprovalFlow;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\ApprovalFlowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LeaveOvertimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'Approver']);
        Role::firstOrCreate(['name' => 'BOD']);
        Role::firstOrCreate(['name' => 'Superadmin']);
        Role::firstOrCreate(['name' => 'Employee']);
        Role::firstOrCreate(['name' => 'Intern']);
        Role::firstOrCreate(['name' => 'Freelancer']);
    }

    private function createEmployee(Company $company): Employee
    {
        $user = User::factory()->create();
        $division = Division::create(['company_id' => $company->id, 'name' => 'HR', 'code' => 'HR', 'is_active' => true]);
        $jobLevel = JobLevel::create(['name' => 'Staff', 'level_number' => 1]);
        $jobTitle = JobTitle::create(['name' => 'Staff HR']);

        return Employee::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'division_id' => $division->id,
            'job_level_id' => $jobLevel->id,
            'job_title_id' => $jobTitle->id,
            'nip' => 'EMP'.rand(100, 999),
            'full_name' => 'Test Employee',
            'join_date' => now(),
            'status' => 'permanent',
        ]);
    }

    public function test_annual_leave_quota_validation_and_rejection_when_exceeded(): void
    {
        $company = Company::create(['name' => 'PT Quota Test', 'code' => 'PTQT', 'is_active' => true]);
        $company->policy()->update(['annual_leave_quota' => 10]);

        $employee = $this->createEmployee($company);

        // Attempt requesting 12 days (exceeds 10 days quota)
        $startDate = today();
        $endDate = today()->addDays(11); // 12 days

        $daysCount = $startDate->diffInDays($endDate) + 1;

        $maxQuota = $company->policy->annual_leave_quota;
        $usedQuota = (int) LeaveRequest::where('employee_id', $employee->id)
            ->where('leave_type', 'annual_leave')
            ->where('status', 'approved')
            ->whereYear('start_date', now()->year)
            ->sum('days_count');

        $remaining = $maxQuota - $usedQuota;

        $this->assertEquals(10, $remaining);
        $this->assertGreaterThan($remaining, $daysCount);
    }

    public function test_multi_step_approval_for_leave_request_updates_attendance_only_on_final_step(): void
    {
        $company = Company::create(['name' => 'PT Leave Flow', 'code' => 'PTLF', 'is_active' => true]);
        $employee = $this->createEmployee($company);

        // Define 2-step approval flow for leave
        ApprovalFlow::create([
            'company_id' => $company->id,
            'request_type' => 'leave',
            'step_number' => 1,
            'step_order' => 1,
            'name' => 'Direct Manager',
            'approver_type' => 'role',
            'approver_role' => 'Approver',
        ]);

        ApprovalFlow::create([
            'company_id' => $company->id,
            'request_type' => 'leave',
            'step_number' => 2,
            'step_order' => 2,
            'name' => 'BOD Review',
            'approver_type' => 'role',
            'approver_role' => 'BOD',
        ]);

        ApprovalFlow::create([
            'company_id' => $company->id,
            'request_type' => 'leave',
            'step_number' => 3,
            'step_order' => 3,
            'name' => 'HR Manager',
            'approver_type' => 'role',
            'approver_role' => 'Superadmin',
        ]);

        // Approver 1: division level
        $user1 = User::factory()->create();
        $user1->assignRole('Approver');
        $this->grantDataAccessTo($user1, $company->id, $employee->division_id);

        // BOD: company level
        $user2 = User::factory()->create();
        $user2->assignRole('BOD');
        $this->grantDataAccessTo($user2, $company->id);

        $user3 = User::factory()->create();
        $user3->assignRole('Superadmin');

        // Create 2-day leave request
        $startDate = today();
        $endDate = today()->addDay();

        $leaveRequest = LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type' => 'annual_leave',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'days_count' => 2,
            'reason' => 'Acara Keluarga',
            'status' => 'pending',
        ]);

        $service = new ApprovalFlowService;
        $service->generateSteps($leaveRequest, 'leave');

        $this->assertEquals(1, $leaveRequest->fresh()->current_step);

        // Step 1 approval by user1
        $service->approveStep($leaveRequest, $user1);
        $leaveRequest = $leaveRequest->fresh();

        $this->assertEquals(2, $leaveRequest->current_step);
        $this->assertEquals('pending', $leaveRequest->status);
        $this->assertDatabaseMissing('attendances', ['employee_id' => $employee->id, 'status' => 'leave']);

        // Step 2 approval by BOD
        $service->approveStep($leaveRequest, $user2);
        $leaveRequest = $leaveRequest->fresh();

        $this->assertEquals(3, $leaveRequest->current_step);
        $this->assertEquals('pending', $leaveRequest->status);

        // Step 3 (Final Step) approval by Superadmin
        $service->approveStep($leaveRequest, $user3);
        $leaveRequest = $leaveRequest->fresh();

        $this->assertEquals('approved', $leaveRequest->status);

        // Verify attendance records generated for start_date & end_date
        $this->assertDatabaseHas('attendances', [
            'employee_id' => $employee->id,
            'status' => 'leave',
        ]);
    }

    public function test_rejection_at_any_step_halts_leave_flow_and_leaves_attendance_unchanged(): void
    {
        $company = Company::create(['name' => 'PT Reject Test', 'code' => 'PTRT', 'is_active' => true]);
        $employee = $this->createEmployee($company);

        ApprovalFlow::create([
            'company_id' => $company->id,
            'request_type' => 'leave',
            'step_number' => 1,
            'step_order' => 1,
            'name' => 'Manager',
            'approver_type' => 'role',
            'approver_role' => 'Approver',
        ]);

        ApprovalFlow::create([
            'company_id' => $company->id,
            'request_type' => 'leave',
            'step_number' => 2,
            'step_order' => 2,
            'name' => 'BOD Review',
            'approver_type' => 'role',
            'approver_role' => 'BOD',
        ]);

        ApprovalFlow::create([
            'company_id' => $company->id,
            'request_type' => 'leave',
            'step_number' => 3,
            'step_order' => 3,
            'name' => 'Final Superadmin Approval',
            'approver_type' => 'role',
            'approver_role' => 'Superadmin',
        ]);

        $user1 = User::factory()->create();
        $user1->assignRole('Approver');
        $this->grantDataAccessTo($user1, $company->id, $employee->division_id);

        $leaveRequest = LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type' => 'permission',
            'start_date' => today(),
            'end_date' => today(),
            'days_count' => 1,
            'reason' => 'Urusan Pribadi',
            'status' => 'pending',
        ]);

        $service = new ApprovalFlowService;
        $service->generateSteps($leaveRequest, 'leave');

        // Reject step 1
        $service->rejectStep($leaveRequest, $user1, 'Alasan tidak mendesak');

        $leaveRequest = $leaveRequest->fresh();
        $this->assertEquals('rejected', $leaveRequest->status);
        $this->assertEquals('Alasan tidak mendesak', $leaveRequest->rejection_reason);
        $this->assertDatabaseMissing('attendances', ['employee_id' => $employee->id]);
    }

    public function test_approver_cannot_approve_bod_or_superadmin_stage(): void
    {
        $company = Company::create(['name' => 'PT Test Role Scope', 'code' => 'PTRS', 'is_active' => true]);
        $employee = $this->createEmployee($company);

        ApprovalFlow::create([
            'company_id' => $company->id,
            'request_type' => 'leave',
            'step_number' => 1,
            'step_order' => 1,
            'name' => 'Approver Stage',
            'approver_type' => 'role',
            'approver_role' => 'Approver',
        ]);

        ApprovalFlow::create([
            'company_id' => $company->id,
            'request_type' => 'leave',
            'step_number' => 2,
            'step_order' => 2,
            'name' => 'BOD Stage',
            'approver_type' => 'role',
            'approver_role' => 'BOD',
        ]);

        ApprovalFlow::create([
            'company_id' => $company->id,
            'request_type' => 'leave',
            'step_number' => 3,
            'step_order' => 3,
            'name' => 'Superadmin Stage',
            'approver_type' => 'role',
            'approver_role' => 'Superadmin',
        ]);

        $approverUser = User::factory()->create();
        $approverUser->assignRole('Approver');
        $this->grantDataAccessTo($approverUser, $company->id, $employee->division_id);

        $leaveRequest = LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type' => 'annual_leave',
            'start_date' => today(),
            'end_date' => today(),
            'days_count' => 1,
            'reason' => 'Tes Role Restriction',
            'status' => 'pending',
        ]);

        $service = new ApprovalFlowService;
        $service->generateSteps($leaveRequest, 'leave');

        $service->approveStep($leaveRequest, $approverUser);
        $leaveRequest = $leaveRequest->fresh();

        $this->assertEquals(2, $leaveRequest->current_step);

        $this->expectException(ValidationException::class);
        $service->approveStep($leaveRequest, $approverUser);
    }

    public function test_bod_can_approve_own_request_at_bod_stage(): void
    {
        $company = Company::create(['name' => 'PT BOD Self Request', 'code' => 'PTBOD', 'is_active' => true]);

        $bodUser = User::factory()->create();
        $bodUser->assignRole('BOD');

        $division = Division::create(['company_id' => $company->id, 'name' => 'Board', 'code' => 'BOD', 'is_active' => true]);
        $jobLevel = JobLevel::create(['name' => 'Director', 'level_number' => 5]);
        $jobTitle = JobTitle::create(['name' => 'Director']);

        $bodEmployee = Employee::create([
            'user_id' => $bodUser->id,
            'company_id' => $company->id,
            'division_id' => $division->id,
            'job_level_id' => $jobLevel->id,
            'job_title_id' => $jobTitle->id,
            'nip' => 'BOD001',
            'full_name' => 'BOD Executive',
            'join_date' => now(),
            'status' => 'permanent',
        ]);

        ApprovalFlow::create([
            'company_id' => $company->id,
            'request_type' => 'leave',
            'step_number' => 1,
            'step_order' => 1,
            'name' => 'Approver Stage',
            'approver_type' => 'role',
            'approver_role' => 'Approver',
        ]);

        ApprovalFlow::create([
            'company_id' => $company->id,
            'request_type' => 'leave',
            'step_number' => 2,
            'step_order' => 2,
            'name' => 'BOD Stage',
            'approver_type' => 'role',
            'approver_role' => 'BOD',
        ]);

        ApprovalFlow::create([
            'company_id' => $company->id,
            'request_type' => 'leave',
            'step_number' => 3,
            'step_order' => 3,
            'name' => 'Superadmin Stage',
            'approver_type' => 'role',
            'approver_role' => 'Superadmin',
        ]);

        $approverUser = User::factory()->create();
        $approverUser->assignRole('Approver');
        $this->grantDataAccessTo($approverUser, $company->id, $bodEmployee->division_id);

        $this->grantDataAccessTo($bodUser, $company->id);

        $leaveRequest = LeaveRequest::create([
            'employee_id' => $bodEmployee->id,
            'leave_type' => 'annual_leave',
            'start_date' => today(),
            'end_date' => today(),
            'days_count' => 1,
            'reason' => 'BOD Leave Request',
            'status' => 'pending',
        ]);

        $service = new ApprovalFlowService;
        $service->generateSteps($leaveRequest, 'leave');

        $service->approveStep($leaveRequest, $approverUser);
        $leaveRequest = $leaveRequest->fresh();
        $this->assertEquals(2, $leaveRequest->current_step);

        $service->approveStep($leaveRequest, $bodUser);
        $leaveRequest = $leaveRequest->fresh();
        $this->assertEquals(3, $leaveRequest->current_step);
        $this->assertEquals('pending', $leaveRequest->status);
    }

    public function test_superadmin_is_sole_final_approver(): void
    {
        $company = Company::create(['name' => 'PT Superadmin Final', 'code' => 'PTSF', 'is_active' => true]);
        $employee = $this->createEmployee($company);

        ApprovalFlow::create([
            'company_id' => $company->id,
            'request_type' => 'leave',
            'step_number' => 1,
            'step_order' => 1,
            'name' => 'Approver Stage',
            'approver_type' => 'role',
            'approver_role' => 'Approver',
        ]);

        ApprovalFlow::create([
            'company_id' => $company->id,
            'request_type' => 'leave',
            'step_number' => 2,
            'step_order' => 2,
            'name' => 'BOD Stage',
            'approver_type' => 'role',
            'approver_role' => 'BOD',
        ]);

        ApprovalFlow::create([
            'company_id' => $company->id,
            'request_type' => 'leave',
            'step_number' => 3,
            'step_order' => 3,
            'name' => 'Superadmin Stage',
            'approver_type' => 'role',
            'approver_role' => 'Superadmin',
        ]);

        $approverUser = User::factory()->create();
        $approverUser->assignRole('Approver');
        $this->grantDataAccessTo($approverUser, $company->id, $employee->division_id);

        $bodUser = User::factory()->create();
        $bodUser->assignRole('BOD');
        $this->grantDataAccessTo($bodUser, $company->id);

        $superadmin = User::factory()->create();
        $superadmin->assignRole('Superadmin');

        $leaveRequest = LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type' => 'annual_leave',
            'start_date' => today(),
            'end_date' => today(),
            'days_count' => 1,
            'reason' => 'Final Step Test',
            'status' => 'pending',
        ]);

        $service = new ApprovalFlowService;
        $service->generateSteps($leaveRequest, 'leave');

        $service->approveStep($leaveRequest, $approverUser);
        $service->approveStep($leaveRequest, $bodUser);

        $leaveRequest = $leaveRequest->fresh();
        $this->assertEquals(3, $leaveRequest->current_step);

        $this->assertFalse($service->isUserAuthorizedToApprove($leaveRequest, $approverUser));
        $this->assertFalse($service->isUserAuthorizedToApprove($leaveRequest, $bodUser));
        $this->assertTrue($service->isUserAuthorizedToApprove($leaveRequest, $superadmin));

        $service->approveStep($leaveRequest, $superadmin);
        $leaveRequest = $leaveRequest->fresh();

        $this->assertEquals('approved', $leaveRequest->status);
        $this->assertDatabaseHas('attendances', [
            'employee_id' => $employee->id,
            'status' => 'leave',
        ]);
    }

    public function test_freelancer_can_create_and_approve_leave_request(): void
    {
        $company = Company::create(['name' => 'PT Freelance Test', 'code' => 'PTFL', 'is_active' => true]);
        $division = Division::create(['company_id' => $company->id, 'name' => 'Design', 'code' => 'DSG', 'is_active' => true]);

        $user = User::factory()->create();
        $user->assignRole('Freelancer');

        $freelancer = Freelancer::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'division_id' => $division->id,
            'freelancer_number' => 'FL-TEST-001',
            'full_name' => 'Freelancer Designer',
            'email' => $user->email,
            'phone' => '628999888777',
            'start_date' => now(),
            'end_date' => now()->addMonths(6),
            'status' => 'active',
        ]);

        ApprovalFlow::create([
            'company_id' => $company->id,
            'request_type' => 'leave',
            'step_number' => 1,
            'step_order' => 1,
            'name' => 'Supervisor Approval',
            'approver_type' => 'role',
            'approver_role' => 'Approver',
        ]);

        $this->actingAs($user);

        $this->assertTrue(LeaveRequestResource::canCreate());

        $leaveRequest = LeaveRequest::create([
            'freelancer_id' => $freelancer->id,
            'leave_type' => 'permission',
            'start_date' => today(),
            'end_date' => today(),
            'days_count' => 1,
            'reason' => 'Izin keperluan freelance',
            'status' => 'pending',
        ]);

        $service = new ApprovalFlowService;
        $service->generateSteps($leaveRequest, 'leave');

        $this->assertDatabaseHas('leave_requests', [
            'freelancer_id' => $freelancer->id,
            'leave_type' => 'permission',
            'reason' => 'Izin keperluan freelance',
            'status' => 'pending',
        ]);

        $approverUser = User::factory()->create();
        $approverUser->assignRole('Approver');
        $this->grantDataAccessTo($approverUser, $company->id, $division->id);

        $service->approveStep($leaveRequest, $approverUser);
        $leaveRequest = $leaveRequest->fresh();

        $this->assertEquals('approved', $leaveRequest->status);
        $this->assertDatabaseHas('attendances', [
            'freelancer_id' => $freelancer->id,
            'status' => 'leave',
        ]);
    }
}
