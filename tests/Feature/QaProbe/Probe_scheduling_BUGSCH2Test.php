<?php

namespace Tests\Feature\QaProbe;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyPolicy;
use App\Models\Division;
use App\Models\Freelancer;
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
 * BUG-SCH-2: a check-in on a day the PIC scheduled as Libur must not be recorded as a plain
 * "Tepat Waktu" attendance (it should be refused or flagged for approval / marked as day-off work).
 */
class Probe_scheduling_BUGSCH2Test extends TestCase
{
    use RefreshDatabase;

    public function test_check_in_on_scheduled_day_off_is_not_plain_on_time(): void
    {
        $this->seed(RoleSeeder::class);
        Notification::fake();
        Queue::fake();

        $company = Company::create(['name' => 'PT SCH2', 'code' => 'SCH2', 'latitude' => -6.2, 'longitude' => 106.8, 'is_active' => true]);
        $division = Division::create(['company_id' => $company->id, 'name' => 'Ops', 'code' => 'OPS', 'is_active' => true]);
        $company->policy->update([
            ...CompanyPolicy::flagsForSelfieMode('none'),
            'require_gps' => false,
            'work_start_time' => '08:00:00',
            'work_end_time' => '17:00:00',
            'late_tolerance_minutes' => 15,
        ]);

        $user = User::factory()->create();
        $user->assignRole('Employee');
        $worker = Freelancer::create([
            'user_id' => $user->id, 'company_id' => $company->id, 'division_id' => $division->id,
            'full_name' => 'Freelance Libur', 'start_date' => '2026-10-01', 'end_date' => '2026-12-31', 'status' => 'active',
        ]);
        ShiftSchedule::create(['freelancer_id' => $worker->id, 'date' => '2026-11-18', 'is_day_off' => true]);

        Sanctum::actingAs($user);
        $this->travelTo(Carbon::parse('2026-11-18 15:00'));
        $response = $this->postJson('/api/v1/attendance/check-in', []);
        dump(['http' => $response->status(), 'message' => $response->json('message')]);

        $row = Attendance::query()->where('freelancer_id', $worker->id)->first();
        dump(['row' => $row ? [$row->status->value, $row->late_minutes, $row->shift_id] : null]);

        // Business expectation: either refused (no row) or not recorded as plain Tepat Waktu.
        $this->assertTrue(
            $row === null || $row->status->value !== 'on_time',
            'A 15:00 check-in on a scheduled Libur day was recorded as Tepat Waktu.'
        );
    }
}
