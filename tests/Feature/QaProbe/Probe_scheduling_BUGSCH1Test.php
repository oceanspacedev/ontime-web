<?php

namespace Tests\Feature\QaProbe;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyPolicy;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * BUG-SCH-1: a late check-in after midnight for last night's overnight shift (22:00-06:00) must be
 * judged against that shift (late), not against tonight's 22:00 (on time).
 */
class Probe_scheduling_BUGSCH1Test extends TestCase
{
    use RefreshDatabase;

    public function test_late_overnight_check_in_after_midnight_is_late_and_does_not_block_tonight(): void
    {
        $this->seed(RoleSeeder::class);
        Notification::fake();
        Queue::fake();

        $company = Company::create(['name' => 'PT SCH1', 'code' => 'SCH1', 'latitude' => -6.2, 'longitude' => 106.8, 'is_active' => true]);
        $division = Division::create(['company_id' => $company->id, 'name' => 'Ops', 'code' => 'OPS', 'is_active' => true]);
        $company->policy->update([
            ...CompanyPolicy::flagsForSelfieMode('none'),
            'require_gps' => false,
            'work_start_time' => '08:00:00',
            'work_end_time' => '17:00:00',
            'late_tolerance_minutes' => 15,
        ]);

        $malam = Shift::create([
            'company_id' => $company->id, 'name' => 'Malam', 'start_time' => '22:00', 'end_time' => '06:00',
            'late_tolerance_minutes' => 15, 'is_active' => true,
        ]);

        $user = User::factory()->create();
        $user->assignRole('Employee');
        $worker = Freelancer::create([
            'user_id' => $user->id, 'company_id' => $company->id, 'division_id' => $division->id,
            'full_name' => 'Freelance Malam', 'start_date' => '2026-10-01', 'end_date' => '2026-12-31', 'status' => 'active',
        ]);
        ShiftSchedule::create(['freelancer_id' => $worker->id, 'date' => '2026-11-18', 'shift_id' => $malam->id, 'is_day_off' => false]);
        ShiftSchedule::create(['freelancer_id' => $worker->id, 'date' => '2026-11-19', 'shift_id' => $malam->id, 'is_day_off' => false]);

        Sanctum::actingAs($user);

        // Arrives 2h10m late for the 18 Nov night shift.
        $this->travelTo(Carbon::parse('2026-11-19 00:10'));
        $this->postJson('/api/v1/attendance/check-in', [])->assertSuccessful();

        $row = Attendance::query()->where('freelancer_id', $worker->id)->firstOrFail();
        dump(['first_row' => [$row->date->toDateString(), $row->status->value, $row->late_minutes]]);

        // Tonight's shift must stay available regardless of the status (checked before the status asserts).
        $this->travelTo(Carbon::parse('2026-11-19 06:00'));
        $this->postJson('/api/v1/attendance/check-out', [])->assertSuccessful();
        $this->travelTo(Carbon::parse('2026-11-19 21:55'));
        $tonight = $this->postJson('/api/v1/attendance/check-in', []);
        dump(['tonight' => [$tonight->status(), $tonight->json('message')]]);
        $tonightBlocked = $tonight->status() === 409;

        $this->assertNotSame('on_time', $row->status->value, 'A 00:10 arrival for a 22:00 shift must not be Tepat Waktu.');
        $this->assertSame('late', $row->status->value);
        $this->assertSame(130, $row->late_minutes);
        $this->assertSame('2026-11-18', $row->date->toDateString());
        $this->assertFalse($tonightBlocked, 'The 19 Nov night shift must not be refused.');
    }
}
