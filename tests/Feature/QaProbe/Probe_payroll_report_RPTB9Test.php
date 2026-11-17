<?php

namespace Tests\Feature\QaProbe;

use App\Enums\DataScope;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Services\TeamRecapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * RPT-B9: a past day with a check-in but no check-out in the team recap (rekap tim).
 */
class Probe_payroll_report_RPTB9Test extends TestCase
{
    use RefreshDatabase;

    public function test_missing_check_out_day_in_team_recap(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17 12:00'));
        Role::findOrCreate('Koordinator', 'web');

        $company = Company::create(['name' => 'PT Franchise', 'code' => 'PFC', 'is_active' => true]);
        $brand = Division::create(['company_id' => $company->id, 'name' => 'JCHICKEN', 'is_active' => true]);
        $outlet = CompanyLocation::create(['company_id' => $company->id, 'division_id' => $brand->id, 'name' => 'JChicken Ciledug', 'latitude' => -6.2, 'longitude' => 106.7]);
        $morning = Shift::create(['company_id' => $company->id, 'division_id' => $brand->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '17:00']);

        $coordinator = User::factory()->create();
        $coordinator->assignRole('Koordinator');
        $coordinator->grantDataAccess(DataScope::Divisions, divisionIds: [$brand->id]);

        $bayu = Freelancer::create(['company_id' => $company->id, 'division_id' => $brand->id, 'full_name' => 'Bayu', 'status' => 'active']);
        ShiftSchedule::create(['freelancer_id' => $bayu->id, 'date' => '2026-11-05', 'shift_id' => $morning->id, 'company_location_id' => $outlet->id]);
        Attendance::create(['freelancer_id' => $bayu->id, 'date' => '2026-11-05', 'check_in' => '2026-11-05 08:00:00', 'check_out' => null, 'status' => 'on_time', 'late_minutes' => 0, 'company_location_id' => $outlet->id]);

        $rows = app(TeamRecapService::class)->recap($coordinator, Carbon::parse('2026-11-01'));

        $this->assertCount(1, $rows);
        $row = $rows[0];

        // Observed behaviour: the day counts as present, with 0 worked hours.
        $this->assertSame(1, $row['scheduled_days']);
        $this->assertSame(1, $row['present_days']);
        $this->assertSame(0, $row['absent_days']);
        $this->assertEquals(9.0, $row['scheduled_hours']);
        $this->assertEquals(0.0, $row['worked_hours']);

        // No field in the recap row marks a day as missing its check-out.
        $flagKeys = array_filter(array_keys($row), fn (string $key): bool => (bool) preg_match('/check_?out|incomplete|missing/i', $key));
        $this->assertSame([], array_values($flagKeys));
    }
}
