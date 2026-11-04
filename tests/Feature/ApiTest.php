<?php

namespace Tests\Feature;

use App\Models\ApprovalFlow;
use App\Models\Company;
use App\Models\CompanyPolicy;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ApiTest extends TestCase
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
            'name' => 'PT Test API',
            'code' => 'TESTAPI',
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
            'name' => 'Employee Test',
            'email' => 'employee.api@ontime.com',
            'password' => bcrypt('password'),
        ]);
        $this->employeeUser->assignRole($employeeRole);

        $this->employee = Employee::create([
            'user_id' => $this->employeeUser->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'job_level_id' => $jobLevel->id,
            'job_title_id' => $jobTitle->id,
            'nip' => 'EMP.API.001',
            'full_name' => 'Employee Test',
            'join_date' => now()->toDateString(),
            'status' => 'permanent',
        ]);

        $this->approverUser = User::create([
            'name' => 'Approver Test',
            'email' => 'approver.api@ontime.com',
            'password' => bcrypt('password'),
        ]);
        $this->approverUser->assignRole($approverRole);
        $this->approverUser->refresh();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->grantDataAccessTo($this->approverUser, $this->company->id, $this->division->id);

        // Standard 3-step flows
        foreach (['leave', 'overtime', 'correction'] as $type) {
            ApprovalFlow::create([
                'company_id' => $this->company->id,
                'request_type' => $type,
                'step_number' => 1,
                'step_order' => 1,
                'name' => "Approver {$type}",
                'approver_type' => 'role',
                'approver_role' => 'Approver',
            ]);
            ApprovalFlow::create([
                'company_id' => $this->company->id,
                'request_type' => $type,
                'step_number' => 2,
                'step_order' => 2,
                'name' => "BOD {$type}",
                'approver_type' => 'role',
                'approver_role' => 'BOD',
            ]);
            ApprovalFlow::create([
                'company_id' => $this->company->id,
                'request_type' => $type,
                'step_number' => 3,
                'step_order' => 3,
                'name' => "Superadmin {$type}",
                'approver_type' => 'role',
                'approver_role' => 'Superadmin',
            ]);
        }
    }

    public function test_user_can_login_via_api_and_get_token(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'employee.api@ontime.com',
            'password' => 'password',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['token', 'token_type', 'user']);
    }

    public function test_employee_can_check_in_via_api(): void
    {
        $token = $this->employeeUser->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/attendance/check-in', []);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Check in berhasil');
    }

    public function test_employee_can_create_and_update_leave_request_via_api(): void
    {
        $token = $this->employeeUser->createToken('test')->plainTextToken;

        // POST Create
        $createResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/leave-requests', [
                'leave_type' => 'annual',
                'start_date' => now()->addDays(5)->toDateString(),
                'end_date' => now()->addDays(6)->toDateString(),
                'reason' => 'Acara keluarga',
            ]);

        $createResponse->assertStatus(201)
            ->assertJsonPath('message', 'Pengajuan cuti berhasil dibuat');

        $leaveId = $createResponse->json('data.id');

        // PUT Update
        $updateResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/v1/leave-requests/{$leaveId}", [
                'reason' => 'Acara keluarga penting sekali',
            ]);

        $updateResponse->assertStatus(200)
            ->assertJsonPath('data.reason', 'Acara keluarga penting sekali');
    }

    public function test_employee_can_create_and_update_overtime_request_via_api(): void
    {
        $token = $this->employeeUser->createToken('test')->plainTextToken;

        // POST Create
        $createResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/overtime-requests', [
                'date' => now()->addDay()->toDateString(),
                'start_time' => '17:00',
                'end_time' => '20:00',
                'reason' => 'Lembur project sprint',
            ]);

        $createResponse->assertStatus(201)
            ->assertJsonPath('message', 'Pengajuan lembur berhasil dibuat');

        $overtimeId = $createResponse->json('data.id');

        // PUT Update
        $updateResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/v1/overtime-requests/{$overtimeId}", [
                'reason' => 'Lembur project sprint revisi',
            ]);

        $updateResponse->assertStatus(200)
            ->assertJsonPath('data.reason', 'Lembur project sprint revisi');
    }

    public function test_employee_can_create_and_update_attendance_correction_via_api(): void
    {
        $token = $this->employeeUser->createToken('test')->plainTextToken;

        // POST Create
        $createResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/attendance-corrections', [
                'date' => now()->subDay()->toDateString(),
                'corrected_check_in' => '08:00',
                'corrected_check_out' => '17:00',
                'reason' => 'Lupa tap in',
            ]);

        $createResponse->assertStatus(201)
            ->assertJsonPath('message', 'Pengajuan koreksi absensi berhasil dibuat');

        $correctionId = $createResponse->json('data.id');

        // PUT Update
        $updateResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/v1/attendance-corrections/{$correctionId}", [
                'reason' => 'Lupa tap in mesin error',
            ]);

        $updateResponse->assertStatus(200)
            ->assertJsonPath('data.reason', 'Lupa tap in mesin error');
    }

    public function test_approver_can_process_approval_via_api(): void
    {
        Sanctum::actingAs($this->employeeUser);

        // Employee creates leave request
        $createResponse = $this->postJson('/api/v1/leave-requests', [
            'leave_type' => 'sick',
            'start_date' => now()->addDays(2)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'reason' => 'Sakit demam',
        ]);
        $leaveId = $createResponse->json('data.id');

        // Approver approves step 1 via PUT
        Sanctum::actingAs($this->approverUser);

        $processResponse = $this->putJson("/api/v1/approvals/leave/{$leaveId}/process", [
            'action' => 'approved',
        ]);

        $processResponse->assertStatus(200)
            ->assertJsonPath('message', 'Pengajuan berhasil disetujui')
            ->assertJsonPath('current_step', 2);
    }
}
