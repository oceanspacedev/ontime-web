<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Models\ApprovalFlow;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Support\StorageUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The mobile API follows the same rules as the panel: data access, account status, leave types,
 * and approval steps.
 */
class ApiConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Division $sales;

    private Division $finance;

    private Employee $salesWorker;

    private Employee $financeWorker;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Superadmin', 'Admin', 'Approver', 'BOD', 'Employee', 'Freelancer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->company = Company::create(['name' => 'CV Top', 'code' => 'TOP', 'is_active' => true]);
        $this->company->policy()->update(['require_photo' => false, 'require_gps' => false, 'annual_leave_quota' => 3]);
        $this->sales = Division::create(['company_id' => $this->company->id, 'name' => 'SALES', 'is_active' => true]);
        $this->finance = Division::create(['company_id' => $this->company->id, 'name' => 'FINANCE', 'is_active' => true]);

        foreach (['leave', 'overtime'] as $requestType) {
            ApprovalFlow::create(['company_id' => $this->company->id, 'request_type' => $requestType, 'step_number' => 1, 'step_order' => 1, 'name' => 'Approver', 'approver_type' => 'role', 'approver_role' => 'Approver']);
            ApprovalFlow::create(['company_id' => $this->company->id, 'request_type' => $requestType, 'step_number' => 2, 'step_order' => 2, 'name' => 'Superadmin', 'approver_type' => 'role', 'approver_role' => 'Superadmin']);
        }

        $this->salesWorker = $this->makeEmployee($this->sales);
        $this->financeWorker = $this->makeEmployee($this->finance);
    }

    public function test_attendance_and_report_api_follow_data_access(): void
    {
        $salesAttendance = Attendance::create(['employee_id' => $this->salesWorker->id, 'date' => '2026-11-02', 'check_in' => '08:00:00', 'status' => 'on_time']);
        $financeAttendance = Attendance::create(['employee_id' => $this->financeWorker->id, 'date' => '2026-11-02', 'check_in' => '08:00:00', 'status' => 'on_time']);

        $approver = User::factory()->create();
        $approver->assignRole('Approver');
        $approver->grantDataAccess(DataScope::Divisions, divisionIds: [$this->sales->id]);
        Sanctum::actingAs($approver);

        $this->getJson('/api/v1/attendances?start_date=2026-11-01&end_date=2026-11-30')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $salesAttendance->id)
            ->assertJsonPath('data.0.date', '2026-11-02');

        $this->getJson("/api/v1/attendances/{$salesAttendance->id}")->assertOk();
        $this->getJson("/api/v1/attendances/{$financeAttendance->id}")->assertForbidden();

        $report = $this->getJson('/api/v1/laporan-absensi?start_date=2026-11-01&end_date=2026-11-30')->assertOk()->json();
        $this->assertStringNotContainsString($this->financeWorker->full_name, json_encode($report));
        $this->assertStringContainsString($this->salesWorker->full_name, json_encode($report));
    }

    public function test_workers_only_see_their_own_attendance(): void
    {
        Attendance::create(['employee_id' => $this->financeWorker->id, 'date' => '2026-11-02', 'check_in' => '08:00:00', 'status' => 'on_time']);
        $own = Attendance::create(['employee_id' => $this->salesWorker->id, 'date' => '2026-11-02', 'check_in' => '08:00:00', 'status' => 'on_time']);

        Sanctum::actingAs($this->salesWorker->user);

        $this->getJson('/api/v1/attendances')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
    }

    public function test_photo_links_open_on_a_private_local_disk(): void
    {
        config(['filesystems.default' => 'local']);
        Storage::disk('local')->put('attendance/photos/uat-selfie.png', 'png-bytes');

        try {
            $url = StorageUrl::for('attendance/photos/uat-selfie.png');

            $this->assertStringContainsString('signature=', (string) $url);
            $this->get((string) $url)->assertOk();
            $this->assertNull(StorageUrl::for(null));
            $this->assertSame('https://cdn.contoh.id/a.png', StorageUrl::for('https://cdn.contoh.id/a.png'));
        } finally {
            Storage::disk('local')->delete('attendance/photos/uat-selfie.png');
        }
    }

    public function test_inactive_account_cannot_log_in(): void
    {
        $user = User::factory()->create(['email' => 'nonaktif@contoh.id', 'password' => Hash::make('rahasia123'), 'is_active' => false]);

        $this->postJson('/api/v1/auth/login', ['email' => 'nonaktif@contoh.id', 'password' => 'rahasia123'])
            ->assertForbidden()
            ->assertJsonMissingPath('token');

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_token_stops_working_after_the_account_is_deactivated(): void
    {
        $user = $this->salesWorker->user;
        $token = $user->createToken('auth-token')->plainTextToken;

        $user->update(['is_active' => false]);

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_leave_api_uses_the_panel_leave_types_and_quota(): void
    {
        LeaveRequest::create(['employee_id' => $this->salesWorker->id, 'leave_type' => LeaveRequest::TYPE_ANNUAL, 'start_date' => now()->startOfYear()->toDateString(), 'end_date' => now()->startOfYear()->addDay()->toDateString(), 'days_count' => 2, 'reason' => 'Cuti dari panel', 'status' => 'approved']);
        Sanctum::actingAs($this->salesWorker->user);

        $this->getJson('/api/v1/leave-requests')->assertOk()->assertJsonPath('quota_summary.used_days', 2);

        $this->postJson('/api/v1/leave-requests', ['leave_type' => 'annual', 'start_date' => '2026-12-01', 'end_date' => '2026-12-02', 'reason' => 'Liburan'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('leave_type');

        $this->postJson('/api/v1/leave-requests', ['leave_type' => 'permitted', 'start_date' => '2026-12-01', 'end_date' => '2026-12-01', 'reason' => 'Urusan keluarga'])
            ->assertCreated()
            ->assertJsonPath('data.leave_type', 'permission')
            ->assertJsonPath('data.leave_type_label', 'Izin')
            ->assertJsonPath('data.start_date', '2026-12-01')
            ->assertJsonPath('data.total_steps', 2);
    }

    public function test_leave_update_cannot_bypass_the_quota(): void
    {
        Sanctum::actingAs($this->salesWorker->user);
        $leaveId = $this->postJson('/api/v1/leave-requests', ['leave_type' => 'annual_leave', 'start_date' => '2026-12-01', 'end_date' => '2026-12-01', 'reason' => 'Cuti'])->assertCreated()->json('data.id');

        $this->putJson("/api/v1/leave-requests/{$leaveId}", ['end_date' => '2026-12-10'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('leave_type');

        $this->assertSame(1, LeaveRequest::findOrFail($leaveId)->days_count);
    }

    public function test_freelancer_cannot_file_annual_leave_through_the_api(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Freelancer');
        Freelancer::create(['user_id' => $user->id, 'company_id' => $this->company->id, 'division_id' => $this->sales->id, 'freelancer_number' => 'FL-01', 'full_name' => 'Pekerja Lepas', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'active']);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/leave-requests', ['leave_type' => 'annual', 'start_date' => '2026-12-01', 'end_date' => '2026-12-01', 'reason' => 'Cuti'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('leave_type');
    }

    public function test_rejection_reason_is_returned(): void
    {
        $leave = LeaveRequest::create(['employee_id' => $this->salesWorker->id, 'leave_type' => 'sick', 'start_date' => '2026-11-09', 'end_date' => '2026-11-09', 'days_count' => 1, 'reason' => 'Sakit', 'status' => 'rejected', 'rejection_reason' => 'Surat dokter belum ada']);
        Sanctum::actingAs($this->salesWorker->user);

        $this->getJson("/api/v1/leave-requests/{$leave->id}")
            ->assertOk()
            ->assertJsonPath('data.rejection_reason', 'Surat dokter belum ada')
            ->assertJsonPath('data.rejection_note', 'Surat dokter belum ada')
            ->assertJsonPath('data.end_date', '2026-11-09');
    }

    public function test_overtime_past_midnight_is_accepted(): void
    {
        Sanctum::actingAs($this->salesWorker->user);

        $this->postJson('/api/v1/overtime-requests', ['date' => '2026-11-04', 'start_time' => '22:00', 'end_time' => '01:00', 'reason' => 'Stock opname'])
            ->assertCreated()
            ->assertJsonPath('data.duration_minutes', 180)
            ->assertJsonPath('data.date', '2026-11-04');

        $this->postJson('/api/v1/overtime-requests', ['date' => '2026-11-04', 'start_time' => '22:00', 'end_time' => '22:00', 'reason' => 'Salah isi'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end_time');
    }

    public function test_request_without_an_approval_flow_leaves_nothing_behind(): void
    {
        ApprovalFlow::query()->where('request_type', 'overtime')->delete();
        Sanctum::actingAs($this->salesWorker->user);

        $this->postJson('/api/v1/overtime-requests', ['date' => '2026-11-04', 'start_time' => '18:00', 'end_time' => '20:00', 'reason' => 'Lembur'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('approval_flow');

        $this->assertSame(0, OvertimeRequest::count());
    }

    private function makeEmployee(Division $division): Employee
    {
        $user = User::factory()->create();
        $user->assignRole('Employee');

        return Employee::create([
            'user_id' => $user->id,
            'company_id' => $division->company_id,
            'division_id' => $division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Staff'])->id,
            'nip' => 'NIP-'.$user->id,
            'full_name' => "Pekerja {$division->name} {$user->id}",
            'join_date' => '2025-01-06',
            'has_annual_leave' => true,
            'status' => 'active',
        ])->load('user');
    }
}
