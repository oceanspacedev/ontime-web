<?php

namespace Tests\Feature\QaProbe;

use App\Enums\DataScope;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyPolicy;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\Holiday;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * BUG-SCH-3: a Holiday row (Hari Libur) is ignored by check-in status and the absence count.
 * Holiday rows are created directly here because the app has no UI, seeder or factory that creates them.
 */
class Probe_scheduling_BUGSCH3Test extends TestCase
{
    use RefreshDatabase;

    public function test_check_in_on_a_holiday_without_schedule_is_judged_against_policy_hours(): void
    {
        $this->seed(RoleSeeder::class);
        Notification::fake();
        Queue::fake();

        $company = Company::create(['name' => 'PT SCH3', 'code' => 'SCH3', 'latitude' => -6.2, 'longitude' => 106.8, 'is_active' => true]);
        $division = Division::create(['company_id' => $company->id, 'name' => 'Ops', 'code' => 'OPS', 'is_active' => true]);
        $company->policy->update([
            ...CompanyPolicy::flagsForSelfieMode('none'),
            'require_gps' => false,
            'work_start_time' => '08:00:00',
            'work_end_time' => '17:00:00',
            'late_tolerance_minutes' => 15,
        ]);
        Holiday::create(['company_id' => null, 'name' => 'Libur Nasional', 'date' => '2026-11-18', 'is_national' => true]);

        $user = User::factory()->create();
        $user->assignRole('Employee');
        $worker = Freelancer::create([
            'user_id' => $user->id, 'company_id' => $company->id, 'division_id' => $division->id,
            'full_name' => 'Freelance Libur', 'start_date' => '2026-10-01', 'end_date' => '2026-12-31', 'status' => 'active',
        ]);

        // Without a roster the check-in is "Masuk di luar jadwal" and names the shift it covers.
        $shift = Shift::create(['company_id' => $company->id, 'name' => 'Siang', 'start_time' => '10:00', 'end_time' => '18:00', 'late_tolerance_minutes' => 0, 'is_active' => true]);
        Sanctum::actingAs($user);
        $this->travelTo(Carbon::parse('2026-11-18 10:00'));
        $this->postJson('/api/v1/attendance/check-in', ['cover_shift_id' => $shift->id])->assertSuccessful();

        $row = Attendance::query()->where('freelancer_id', $worker->id)->firstOrFail();
        dump(['holiday_check_in' => [$row->status->value, $row->late_minutes]]);

        $this->assertSame(0, (int) $row->late_minutes, 'Working on a national holiday with no schedule should not be penalised as late.');
    }

    public function test_unattended_national_holiday_is_not_counted_as_absent(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 12:00'));
        Role::findOrCreate('Admin', 'web');

        $company = Company::create(['name' => 'PT SCH3B', 'code' => 'S3B', 'is_active' => true]);
        $division = Division::create(['company_id' => $company->id, 'name' => 'Ops', 'is_active' => true]);
        Freelancer::create(['company_id' => $company->id, 'division_id' => $division->id, 'full_name' => 'Di Rumah', 'status' => 'active']);
        Holiday::create(['company_id' => null, 'name' => 'Libur Nasional', 'date' => '2026-11-18', 'is_national' => true]);

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $admin->grantDataAccess(DataScope::All);
        Sanctum::actingAs($admin);

        $summary = $this->getJson('/api/v1/laporan-absensi?start_date=2026-11-18&end_date=2026-11-18')->assertOk()->json('summary');
        dump(['holiday_summary' => $summary]);

        $this->assertSame(0, $summary['total_absent'], 'A national holiday is not a working day, so staying home is not an absence.');
    }
}
