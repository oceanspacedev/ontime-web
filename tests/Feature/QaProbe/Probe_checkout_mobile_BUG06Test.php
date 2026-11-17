<?php

namespace Tests\Feature\QaProbe;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * BUG-06 (mobile cache): the backend half. On D+1 /attendance/today must answer D+1 with no check-in,
 * so a home card still showing D's "Tepat Waktu" can only come from the app's React Query cache.
 */
class Probe_checkout_mobile_BUG06Test extends TestCase
{
    use RefreshDatabase;

    public function test_today_endpoint_rolls_over_to_the_next_day(): void
    {
        Role::create(['name' => 'Employee']);
        $company = Company::create(['name' => 'PT P', 'code' => 'PP', 'is_active' => true]);
        $division = Division::create(['company_id' => $company->id, 'name' => 'Ops', 'is_active' => true]);
        $shift = Shift::create(['company_id' => $company->id, 'name' => 'Pagi', 'code' => 'PG', 'start_time' => '08:00', 'end_time' => '17:00', 'late_tolerance_minutes' => 0]);
        $user = User::factory()->create();
        $user->assignRole('Employee');
        $employee = Employee::create(['user_id' => $user->id, 'company_id' => $company->id, 'division_id' => $division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Staff'])->id,
            'nip' => 'N'.$user->id, 'full_name' => 'E'.$user->id, 'status' => 'active', 'shift_id' => $shift->id]);

        Attendance::create(['employee_id' => $employee->id, 'date' => '2026-11-16',
            'check_in' => '2026-11-16 07:55:00', 'check_out' => '2026-11-16 17:05:00', 'status' => AttendanceStatus::OnTime]);

        Sanctum::actingAs($user);

        $this->travelTo(Carbon::parse('2026-11-16 18:00'));
        $this->getJson('/api/v1/attendance/today')->assertOk()
            ->assertJsonPath('date', '2026-11-16')
            ->assertJsonPath('has_checked_in', true);

        $this->travelTo(Carbon::parse('2026-11-17 07:50'));
        $this->getJson('/api/v1/attendance/today')->assertOk()
            ->assertJsonPath('date', '2026-11-17')
            ->assertJsonPath('has_checked_in', false)
            ->assertJsonPath('attendance', null);
    }
}
