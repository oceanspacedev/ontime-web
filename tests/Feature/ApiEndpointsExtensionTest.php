<?php

namespace Tests\Feature;

use App\Models\ApprovalFlow;
use App\Models\Company;
use App\Models\CompanyPolicy;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\Resignation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ApiEndpointsExtensionTest extends TestCase
{
    use RefreshDatabase;

    protected User $employeeUser;

    protected Employee $employee;

    protected User $approverUser;

    protected Company $company;

    protected Division $division;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->seed(RoleSeeder::class);

        $approverRole = Role::findByName('Approver');
        $employeeRole = Role::findByName('Employee');

        $this->company = Company::create([
            'name' => 'PT Test Extension API',
            'code' => 'TESTEXTAPI',
            'is_active' => true,
        ]);

        CompanyPolicy::create([
            'company_id' => $this->company->id,
            'late_tolerance_minutes' => 15,
            'require_photo' => false,
            'require_gps' => false,
            'annual_leave_quota' => 12,
            'work_start_time' => '08:00:00',
            'work_end_time' => '17:00:00',
        ]);

        $this->division = Division::create([
            'company_id' => $this->company->id,
            'name' => 'Divisi IT',
            'code' => 'DIVIT',
            'is_active' => true,
        ]);

        $jobLevel = JobLevel::firstOrCreate(['name' => 'Staff', 'level_order' => 1]);
        $jobTitle = JobTitle::firstOrCreate(['name' => 'Programmer']);

        $this->employeeUser = User::create([
            'name' => 'Employee Extension Test',
            'email' => 'employee.ext@ontime.com',
            'password' => bcrypt('password'),
        ]);
        $this->employeeUser->assignRole($employeeRole);

        $this->employee = Employee::create([
            'user_id' => $this->employeeUser->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'job_level_id' => $jobLevel->id,
            'job_title_id' => $jobTitle->id,
            'nip' => 'EMP.EXT.001',
            'full_name' => 'Employee Extension Test',
            'join_date' => now()->toDateString(),
            'has_annual_leave' => true,
            'status' => 'permanent',
        ]);

        $this->approverUser = User::create([
            'name' => 'Approver Extension Test',
            'email' => 'approver.ext@ontime.com',
            'password' => bcrypt('password'),
        ]);
        $this->approverUser->assignRole($approverRole);
        $this->approverUser->refresh();

        $this->grantDataAccessTo($this->approverUser, $this->company->id, $this->division->id);

        foreach (['leave', 'overtime', 'correction', 'resignation'] as $type) {
            ApprovalFlow::create([
                'company_id' => $this->company->id,
                'request_type' => $type,
                'step_number' => 1,
                'step_order' => 1,
                'name' => "Approver {$type}",
                'approver_type' => 'role',
                'approver_role' => 'Approver',
            ]);
        }
    }

    public function test_get_today_attendance_summary(): void
    {
        Sanctum::actingAs($this->employeeUser);

        $response = $this->getJson('/api/v1/attendance/today');

        $response->assertStatus(200)
            ->assertJsonPath('has_checked_in', false)
            ->assertJsonPath('has_checked_out', false)
            ->assertJsonPath('date', today()->toDateString());
    }

    public function test_delete_master_face(): void
    {
        Sanctum::actingAs($this->employeeUser);

        $this->employee->update([
            'master_face_photo' => 'master-faces/test.jpg',
            'master_face_verified_at' => now(),
        ]);

        $response = $this->deleteJson('/api/v1/attendance/master-face');

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Foto Master Wajah berhasil dihapus.');

        $this->assertNull($this->employee->fresh()->master_face_photo);
    }

    public function test_delete_leave_request(): void
    {
        Sanctum::actingAs($this->employeeUser);

        $createResponse = $this->postJson('/api/v1/leave-requests', [
            'leave_type' => 'annual',
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date' => now()->addDays(11)->toDateString(),
            'reason' => 'Test delete cuti',
        ]);

        $createResponse->assertStatus(201);
        $leaveId = $createResponse->json('data.id');

        $deleteResponse = $this->deleteJson("/api/v1/leave-requests/{$leaveId}");

        $deleteResponse->assertStatus(200)
            ->assertJsonPath('message', 'Pengajuan cuti berhasil dibatalkan.');
    }

    public function test_delete_overtime_request(): void
    {
        Sanctum::actingAs($this->employeeUser);

        $createResponse = $this->postJson('/api/v1/overtime-requests', [
            'date' => now()->addDay()->toDateString(),
            'start_time' => '17:00',
            'end_time' => '19:00',
            'reason' => 'Test delete lembur',
        ]);

        $createResponse->assertStatus(201);
        $overtimeId = $createResponse->json('data.id');

        $deleteResponse = $this->deleteJson("/api/v1/overtime-requests/{$overtimeId}");

        $deleteResponse->assertStatus(200)
            ->assertJsonPath('message', 'Pengajuan lembur berhasil dibatalkan.');
    }

    public function test_delete_attendance_correction(): void
    {
        Sanctum::actingAs($this->employeeUser);

        $createResponse = $this->postJson('/api/v1/attendance-corrections', [
            'date' => now()->subDay()->toDateString(),
            'corrected_check_in' => '08:00',
            'corrected_check_out' => '17:00',
            'reason' => 'Test delete koreksi',
        ]);

        $createResponse->assertStatus(201);
        $correctionId = $createResponse->json('data.id');

        $deleteResponse = $this->deleteJson("/api/v1/attendance-corrections/{$correctionId}");

        $deleteResponse->assertStatus(200)
            ->assertJsonPath('message', 'Pengajuan koreksi absensi berhasil dibatalkan.');
    }

    public function test_update_and_delete_resignation(): void
    {
        Sanctum::actingAs($this->employeeUser);

        $createResponse = $this->postJson('/api/v1/resignations', [
            'resignation_date' => now()->toDateString(),
            'last_working_day' => now()->addMonth()->toDateString(),
            'reason' => 'Mencari tantangan baru',
        ]);

        $createResponse->assertStatus(201);
        $resignationId = $createResponse->json('data.id');

        $updateResponse = $this->putJson("/api/v1/resignations/{$resignationId}", [
            'reason' => 'Mencari tantangan baru di industri lain',
        ]);

        $updateResponse->assertStatus(200)
            ->assertJsonPath('data.reason', 'Mencari tantangan baru di industri lain');

        $deleteResponse = $this->deleteJson("/api/v1/resignations/{$resignationId}");

        $deleteResponse->assertStatus(200)
            ->assertJsonPath('message', 'Pengajuan pengunduran diri berhasil dibatalkan.');
    }

    public function test_process_resignation_approval_via_api(): void
    {
        Sanctum::actingAs($this->employeeUser);

        $createResponse = $this->postJson('/api/v1/resignations', [
            'resignation_date' => now()->toDateString(),
            'last_working_day' => now()->addMonth()->toDateString(),
            'reason' => 'Resign untuk studi lanjut',
        ]);

        $createResponse->assertStatus(201);
        $resignationId = $createResponse->json('data.id');

        Sanctum::actingAs($this->approverUser);

        $processResponse = $this->putJson("/api/v1/approvals/resignation/{$resignationId}/process", [
            'action' => 'approved',
            'rejection_note' => 'Disetujui, semoga sukses',
        ]);

        $processResponse->assertStatus(200)
            ->assertJsonPath('message', 'Pengajuan berhasil disetujui');

        $this->assertEquals('approved', Resignation::find($resignationId)->status);
    }
}
