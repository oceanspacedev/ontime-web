<?php

namespace Tests\Feature\QaProbe;

use App\Models\Company;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Services\ScheduleCopyService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * BUG-SCH-5: copying a week (Mon-Sun) must copy the Sunday schedule too.
 */
class Probe_scheduling_BUGSCH5Test extends TestCase
{
    use RefreshDatabase;

    public function test_copy_week_copies_the_sunday_schedule(): void
    {
        $this->seed(RoleSeeder::class);

        $company = Company::create(['name' => 'PT SCH5', 'code' => 'SCH5', 'latitude' => -6.2, 'longitude' => 106.8, 'is_active' => true]);
        $division = Division::create(['company_id' => $company->id, 'name' => 'Ops', 'code' => 'OPS', 'is_active' => true]);
        $pagi = Shift::create([
            'company_id' => $company->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '16:00',
            'late_tolerance_minutes' => 15, 'is_active' => true,
        ]);

        $user = User::factory()->create();
        $user->assignRole('Employee');
        $worker = Freelancer::create([
            'user_id' => $user->id, 'company_id' => $company->id, 'division_id' => $division->id,
            'full_name' => 'Freelance Copy', 'start_date' => '2026-10-01', 'end_date' => '2026-12-31', 'status' => 'active',
        ]);
        ShiftSchedule::create(['freelancer_id' => $worker->id, 'date' => '2026-11-16', 'shift_id' => $pagi->id, 'is_day_off' => false]);
        ShiftSchedule::create(['freelancer_id' => $worker->id, 'date' => '2026-11-22', 'shift_id' => $pagi->id, 'is_day_off' => false]);

        $admin = User::factory()->create();
        $admin->assignRole('Superadmin');

        $result = app(ScheduleCopyService::class)->copyWeek($admin, Carbon::parse('2026-11-16'), Carbon::parse('2026-11-23'));

        $this->assertTrue(ShiftSchedule::query()->where('freelancer_id', $worker->id)->whereDate('date', '2026-11-23')->exists(), 'Monday copied');
        $this->assertTrue(ShiftSchedule::query()->where('freelancer_id', $worker->id)->whereDate('date', '2026-11-29')->exists(), 'Sunday 22 Nov must be copied to Sunday 29 Nov');
        $this->assertSame(2, $result['copied']);
    }
}
