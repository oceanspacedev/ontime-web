<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Models\ApprovalFlow;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\User;
use App\Services\ApprovalFlowService;
use App\Services\GeofenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'Approver']);
        Role::create(['name' => 'BOD']);
        Role::create(['name' => 'Superadmin']);
    }

    private function createEmployee(Company $company): Employee
    {
        $user = User::factory()->create();
        $division = Division::create(['company_id' => $company->id, 'name' => 'IT', 'code' => 'IT', 'is_active' => true]);
        $jobLevel = JobLevel::create(['name' => 'Staff', 'level_number' => 1]);
        $jobTitle = JobTitle::create(['name' => 'Developer']);

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

    public function test_late_status_determination_based_on_shift_and_tolerance(): void
    {
        $company = Company::create([
            'name' => 'PT Monitored Office',
            'code' => 'PTMO',
            'is_active' => true,
        ]);

        $company->policy()->update([
            'work_start_time' => '08:00:00',
            'late_tolerance_minutes' => 15,
        ]);

        $employee = $this->createEmployee($company);

        $onTimeAttendance = Attendance::create([
            'employee_id' => $employee->id,
            'date' => today(),
            'check_in' => now()->setTime(8, 10, 0),
            'status' => AttendanceStatus::OnTime,
            'late_minutes' => 0,
        ]);

        $this->assertEquals(AttendanceStatus::OnTime, $onTimeAttendance->status);
        $this->assertEquals(0, $onTimeAttendance->late_minutes);

        $lateAttendance = Attendance::create([
            'employee_id' => $employee->id,
            'date' => today()->addDay(),
            'check_in' => now()->addDay()->setTime(8, 25, 0),
            'status' => AttendanceStatus::Late,
            'late_minutes' => 25,
        ]);

        $this->assertEquals(AttendanceStatus::Late, $lateAttendance->status);
        $this->assertEquals(25, $lateAttendance->late_minutes);
    }

    public function test_geofence_distance_calculation_and_validation(): void
    {
        $officeLat = -6.1753924;
        $officeLng = 106.8271528;
        $radius = 100;

        $insideLat = -6.1750000;
        $insideLng = 106.8271528;

        $outsideLat = -6.1700000;
        $outsideLng = 106.8271528;

        $distanceOutside = GeofenceService::calculateDistance($officeLat, $officeLng, $outsideLat, $outsideLng);

        $this->assertTrue(GeofenceService::isWithinGeofence($officeLat, $officeLng, $insideLat, $insideLng, $radius));
        $this->assertFalse(GeofenceService::isWithinGeofence($officeLat, $officeLng, $outsideLat, $outsideLng, $radius));
        $this->assertGreaterThan($radius, $distanceOutside);
    }

    public function test_multi_step_approval_flow_progression_and_rejection(): void
    {
        $company = Company::create(['name' => 'PT Multi Flow', 'code' => 'PTMF', 'is_active' => true]);
        $employee = $this->createEmployee($company);

        // Define 2-step approval flow for company
        ApprovalFlow::create([
            'company_id' => $company->id,
            'request_type' => 'correction',
            'step_number' => 1,
            'step_order' => 1,
            'name' => 'Direct Manager Review',
            'approver_type' => 'role',
            'approver_role' => 'Approver',
        ]);

        ApprovalFlow::create([
            'company_id' => $company->id,
            'request_type' => 'correction',
            'step_number' => 2,
            'step_order' => 2,
            'name' => 'BOD Review',
            'approver_type' => 'role',
            'approver_role' => 'BOD',
        ]);

        ApprovalFlow::create([
            'company_id' => $company->id,
            'request_type' => 'correction',
            'step_number' => 3,
            'step_order' => 3,
            'name' => 'HR Manager Final Approval',
            'approver_type' => 'role',
            'approver_role' => 'Superadmin',
        ]);

        // Approver 1: mapped to division
        $user1 = User::factory()->create();
        $user1->assignRole('Approver');
        $this->grantDataAccessTo($user1, $company->id, $employee->division_id);

        // BOD: mapped to company level
        $user2 = User::factory()->create();
        $user2->assignRole('BOD');
        $this->grantDataAccessTo($user2, $company->id);

        $user3 = User::factory()->create();
        $user3->assignRole('Superadmin');

        // Create correction request
        $correction = AttendanceCorrection::create([
            'employee_id' => $employee->id,
            'date' => today(),
            'corrected_check_in' => now()->setTime(8, 0, 0),
            'corrected_check_out' => now()->setTime(17, 0, 0),
            'reason' => 'Network error during check in',
            'status' => 'pending',
        ]);

        $service = new ApprovalFlowService;
        $service->generateSteps($correction, 'correction');

        $this->assertEquals(1, $correction->fresh()->current_step);
        $this->assertEquals('pending', $correction->fresh()->status);
        $this->assertTrue($service->isUserAuthorizedToApprove($correction, $user1));

        // Approver 1 approves Step 1
        $service->approveStep($correction, $user1);

        $correction = $correction->fresh();
        $this->assertEquals(2, $correction->current_step); // Advanced to Step 2
        $this->assertEquals('pending', $correction->status); // Status is STILL pending
        $this->assertDatabaseMissing('attendances', ['employee_id' => $employee->id]); // Attendance NOT updated yet!

        // BOD approves Step 2
        $this->assertFalse($service->isUserAuthorizedToApprove($correction, $user1));
        $this->assertTrue($service->isUserAuthorizedToApprove($correction, $user2));
        $service->approveStep($correction, $user2);

        $correction = $correction->fresh();
        $this->assertEquals(3, $correction->current_step);
        $this->assertFalse($service->isUserAuthorizedToApprove($correction, $user2));
        $this->assertTrue($service->isUserAuthorizedToApprove($correction, $user3));
        $service->approveStep($correction, $user3);

        $correction = $correction->fresh();
        $this->assertEquals('approved', $correction->status); // Status is NOW approved
        $this->assertDatabaseHas('attendances', [
            'employee_id' => $employee->id,
            'is_corrected' => 1,
        ]); // Attendance IS now updated!

        // Test Rejection Scenario
        $correction2 = AttendanceCorrection::create([
            'employee_id' => $employee->id,
            'date' => today()->addDay(),
            'corrected_check_in' => now()->addDay()->setTime(8, 0, 0),
            'reason' => 'Invalid request test',
            'status' => 'pending',
        ]);
        $service->generateSteps($correction2, 'correction');

        // Approver 1 rejects Step 1
        $service->rejectStep($correction2, $user1, 'Data tidak valid');
        $correction2 = $correction2->fresh();

        $this->assertEquals('rejected', $correction2->status);
        $this->assertEquals('Data tidak valid', $correction2->rejection_reason);
    }
}
