<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Models\ApprovalFlow;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\User;
use App\Services\ApprovalFlowService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceGeofenceApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function createEmployee(Company $company): Employee
    {
        $user = User::factory()->create();
        $user->assignRole('Employee');
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

    public function test_check_in_outside_geofence_creates_pending_approval_attendance(): void
    {
        $company = Company::create([
            'name' => 'PT Monitored Office',
            'code' => 'PTMO',
            'latitude' => -6.1753924,
            'longitude' => 106.8271528,
            'is_active' => true,
        ]);

        $company->policy()->update([
            'require_gps' => true,
            'geofence_radius_meters' => 100,
            'work_start_time' => '08:00:00',
            'late_tolerance_minutes' => 15,
        ]);

        $employee = $this->createEmployee($company);

        // Position outside geofence (far away)
        $outsideLat = -6.2000000;
        $outsideLng = 106.8500000;

        $response = $this->actingAs($employee->user)->postJson('/api/v1/attendance/check-in', [
            'latitude' => $outsideLat,
            'longitude' => $outsideLng,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('attendance.is_out_of_bounds', true);
        $response->assertJsonPath('attendance.status', AttendanceStatus::PendingApproval->value);

        $attendance = Attendance::where('employee_id', $employee->id)->first();
        $this->assertNotNull($attendance);
        $this->assertTrue($attendance->is_out_of_bounds);
        $this->assertEquals(AttendanceStatus::PendingApproval, $attendance->status);
        $this->assertCount(1, $attendance->approvalSteps);
    }

    public function test_approver_approving_geofence_attendance_updates_status_to_ontime(): void
    {
        $company = Company::create(['name' => 'PT Test Geo', 'code' => 'PTTG', 'is_active' => true]);
        $employee = $this->createEmployee($company);

        ApprovalFlow::create([
            'company_id' => $company->id,
            'request_type' => 'geofence',
            'step_number' => 1,
            'step_order' => 1,
            'name' => 'Review Manager Direct',
            'approver_type' => 'role',
            'approver_role' => 'Approver',
        ]);

        $approverUser = User::factory()->create();
        $approverUser->assignRole('Approver');
        $this->grantDataAccessTo($approverUser, $company->id, $employee->division_id);

        $attendance = Attendance::create([
            'employee_id' => $employee->id,
            'date' => today(),
            'check_in' => now()->setTime(8, 5, 0),
            'status' => AttendanceStatus::PendingApproval,
            'is_out_of_bounds' => true,
        ]);

        $service = new ApprovalFlowService;
        $service->generateSteps($attendance, 'geofence');

        $this->assertEquals(AttendanceStatus::PendingApproval, $attendance->fresh()->status);
        $this->assertTrue($service->isUserAuthorizedToApprove($attendance, $approverUser));

        $service->approveStep($attendance, $approverUser);

        $attendance = $attendance->fresh();
        $this->assertEquals(AttendanceStatus::OnTime, $attendance->status);
    }

    public function test_approver_rejecting_geofence_attendance_updates_status_to_rejected(): void
    {
        $company = Company::create(['name' => 'PT Test Reject', 'code' => 'PTRJ', 'is_active' => true]);
        $employee = $this->createEmployee($company);

        $approverUser = User::factory()->create();
        $approverUser->assignRole('Approver');
        $this->grantDataAccessTo($approverUser, $company->id, $employee->division_id);

        $attendance = Attendance::create([
            'employee_id' => $employee->id,
            'date' => today(),
            'check_in' => now()->setTime(8, 5, 0),
            'status' => AttendanceStatus::PendingApproval,
            'is_out_of_bounds' => true,
        ]);

        $service = new ApprovalFlowService;
        $service->generateSteps($attendance, 'geofence');

        $service->rejectStep($attendance, $approverUser, 'Lokasi tidak sesuai alasan tugas luar');

        $attendance = $attendance->fresh();
        $this->assertEquals(AttendanceStatus::Rejected, $attendance->status);
        $this->assertEquals('Lokasi tidak sesuai alasan tugas luar', $attendance->rejection_reason);
    }

    public function test_check_out_outside_geofence_triggers_approval_flow(): void
    {
        $company = Company::create([
            'name' => 'PT Check Out Geo',
            'code' => 'PTCO',
            'latitude' => -6.1753924,
            'longitude' => 106.8271528,
            'is_active' => true,
        ]);

        $company->policy()->update([
            'require_gps' => true,
            'geofence_radius_meters' => 100,
        ]);

        $employee = $this->createEmployee($company);

        // Check in inside geofence
        $insideLat = -6.1753924;
        $insideLng = 106.8271528;

        $this->actingAs($employee->user)->postJson('/api/v1/attendance/check-in', [
            'latitude' => $insideLat,
            'longitude' => $insideLng,
        ])->assertStatus(200);

        // Check out outside geofence
        $outsideLat = -6.2000000;
        $outsideLng = 106.8500000;

        $response = $this->actingAs($employee->user)->postJson('/api/v1/attendance/check-out', [
            'latitude' => $outsideLat,
            'longitude' => $outsideLng,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('attendance.is_out_of_bounds', true);
        $response->assertJsonPath('attendance.status', AttendanceStatus::PendingApproval->value);

        $attendance = Attendance::where('employee_id', $employee->id)->first();
        $this->assertTrue($attendance->is_out_of_bounds);
        $this->assertEquals(AttendanceStatus::PendingApproval, $attendance->status);
        $this->assertCount(1, $attendance->approvalSteps);
    }
}
