<?php

namespace Tests\Feature\QaProbe;

use App\Enums\DataScope;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Services\TeamRecapService;
use App\Services\WorkScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * RPT-B7: a check-in on a scheduled day off is counted both as Libur and as Hari Masuk.
 */
class Probe_payroll_report_RPTB7Test extends TestCase
{
    use RefreshDatabase;

    public function test_worked_day_off_is_not_counted_as_both_off_and_present(): void
    {
        $this->travelTo(Carbon::parse('2026-11-15 12:00'));
        Role::findOrCreate('Koordinator', 'web');

        $company = Company::create(['name' => 'PT Franchise', 'code' => 'PFC', 'is_active' => true]);
        $brand = Division::create(['company_id' => $company->id, 'name' => 'JCHICKEN', 'is_active' => true]);
        $outlet = CompanyLocation::create(['company_id' => $company->id, 'division_id' => $brand->id, 'name' => 'JChicken Ciledug', 'latitude' => -6.2, 'longitude' => 106.7]);

        $pic = User::factory()->create();
        $pic->assignRole('Koordinator');
        $pic->grantDataAccess(DataScope::Divisions, divisionIds: [$brand->id]);

        $worker = Freelancer::create(['company_id' => $company->id, 'division_id' => $brand->id, 'full_name' => 'Libur Masuk', 'status' => 'active']);

        ShiftSchedule::create(['freelancer_id' => $worker->id, 'date' => '2026-11-06', 'is_day_off' => true, 'company_location_id' => $outlet->id]);
        Attendance::create(['freelancer_id' => $worker->id, 'date' => '2026-11-06', 'check_in' => '2026-11-06 08:00:00', 'check_out' => '2026-11-06 17:00:00', 'status' => 'on_time', 'late_minutes' => 0, 'company_location_id' => $outlet->id]);

        // Code path the check-in uses: a day off makes lateness 0, so the status is always on_time.
        $hours = app(WorkScheduleService::class)->resolve($worker, Carbon::parse('2026-11-06'));
        $this->assertTrue($hours['is_day_off']);
        $this->assertSame(0, app(WorkScheduleService::class)->lateMinutes($hours, Carbon::parse('2026-11-06 13:00')));

        $rows = app(TeamRecapService::class)->recap($pic, Carbon::parse('2026-11-01'));
        $this->assertCount(1, $rows);
        $row = $rows[0];

        // One calendar day must land in exactly one bucket: either it was a day off, or it was worked.
        $this->assertSame(
            1,
            $row['off_days'] + $row['present_days'],
            'One worked day off is counted twice (Libur + Hari Masuk). Row: '.json_encode(array_diff_key($row, ['worker' => 1])),
        );
    }
}
