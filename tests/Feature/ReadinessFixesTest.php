<?php

namespace Tests\Feature;

use App\Models\ApprovalFlow;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\Resignation;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReadinessFixesTest extends TestCase
{
    use RefreshDatabase;

    private function createEmployee(): Employee
    {
        $company = Company::create(['name' => 'PT Ready', 'code' => 'PTRD', 'is_active' => true]);
        $division = Division::create(['company_id' => $company->id, 'name' => 'IT', 'code' => 'IT', 'is_active' => true]);

        return Employee::create([
            'user_id' => User::factory()->create()->id,
            'company_id' => $company->id,
            'division_id' => $division->id,
            'job_level_id' => JobLevel::create(['name' => 'Staff', 'level_number' => 1])->id,
            'job_title_id' => JobTitle::create(['name' => 'Developer'])->id,
            'nip' => 'EMP-READY',
            'full_name' => 'Ready Employee',
            'join_date' => now(),
            'status' => 'permanent',
        ]);
    }

    public function test_overnight_shift_can_check_out_after_midnight(): void
    {
        $employee = $this->createEmployee();

        $this->travelTo(now()->setTime(2, 0));
        $attendance = Attendance::create([
            'employee_id' => $employee->id,
            'date' => today()->subDay(),
            'check_in' => now()->subHours(4),
            'status' => 'on_time',
            'late_minutes' => 0,
        ]);

        $this->actingAs($employee->user)->postJson('/api/v1/attendance/check-out', [
            'latitude' => -6.1753924,
            'longitude' => 106.8271528,
        ])->assertOk();

        $this->assertNotNull($attendance->fresh()->check_out);
        $this->assertSame(1, Attendance::count());
    }

    public function test_check_out_without_open_check_in_is_still_rejected(): void
    {
        $employee = $this->createEmployee();

        $this->actingAs($employee->user)->postJson('/api/v1/attendance/check-out', [
            'latitude' => -6.1753924,
            'longitude' => 106.8271528,
        ])->assertStatus(422);
    }

    public function test_attendance_report_rejects_malformed_and_oversized_ranges(): void
    {
        $employee = $this->createEmployee();
        Sanctum::actingAs($employee->user);

        $this->getJson('/api/v1/laporan-absensi?start_date=abc')->assertStatus(422);
        $this->getJson('/api/v1/laporan-absensi?start_date=2020-01-01&end_date=2026-01-01')->assertStatus(422);
        $this->getJson('/api/v1/laporan-absensi?start_date=2026-02-01&end_date=2026-01-01')->assertStatus(422);
        $this->getJson('/api/v1/laporan-absensi?start_date=2026-01-01&end_date=2026-01-31')->assertOk();
    }

    public function test_leave_calendar_rejects_invalid_month(): void
    {
        $employee = $this->createEmployee();
        Sanctum::actingAs($employee->user);

        $this->getJson('/api/v1/kalender-cuti?month=13')->assertStatus(422);
        $this->getJson('/api/v1/kalender-cuti?year=99999')->assertStatus(422);
        $this->getJson('/api/v1/kalender-cuti?month=2&year=2026')->assertOk();
    }

    public function test_user_who_is_a_named_approver_cannot_be_deleted(): void
    {
        $company = Company::create(['name' => 'PT Flow', 'code' => 'PTFL', 'is_active' => true]);
        $approver = User::factory()->create();
        ApprovalFlow::create([
            'company_id' => $company->id,
            'request_type' => 'leave',
            'step_number' => 1,
            'step_order' => 1,
            'name' => 'Atasan',
            'approver_type' => 'user',
            'user_id' => $approver->id,
        ]);

        $this->assertFalse($approver->delete());
        $this->assertDatabaseHas('users', ['id' => $approver->id]);
        $this->assertDatabaseCount('approval_flows', 1);

        $plain = User::factory()->create();
        $this->assertTrue($plain->delete());
    }

    public function test_resignation_waits_for_the_last_working_day(): void
    {
        $employee = $this->createEmployee();
        $resignation = Resignation::create([
            'employee_id' => $employee->id,
            'resignation_date' => today(),
            'last_working_day' => today()->addDays(10),
            'reason' => 'Pindah',
            'status' => 'approved',
        ]);

        $resignation->applyResignation();
        $this->assertSame('permanent', $employee->fresh()->status);

        $this->artisan('employees:apply-resignations')->assertSuccessful();
        $this->assertSame('permanent', $employee->fresh()->status);

        $this->travelTo(today()->addDays(11)->setTime(1, 0));
        $this->artisan('employees:apply-resignations')->assertSuccessful();

        $this->assertSame('inactive', $employee->fresh()->status);
        $this->assertFalse((bool) $employee->user->fresh()->is_active);
    }

    public function test_database_seeder_refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->expectException(\RuntimeException::class);

        (new DatabaseSeeder)->run();
    }
}
