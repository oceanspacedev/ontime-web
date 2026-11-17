<?php

namespace Tests\Feature\QaProbe;

use App\Enums\DataScope;
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
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * RPT-B5: mobile laporan-absensi counts absences for a rostered freelancer. A freelancer only rostered on
 * Sat 7 and Sun 8 Nov 2026 (no schedule Mon 2 - Fri 6) who never showed up has 2 absences, as Rekap Tim says.
 */
class Probe_payroll_report_RPTB5Test extends TestCase
{
    use RefreshDatabase;

    public function test_api_absences_follow_the_roster_like_team_recap(): void
    {
        $this->travelTo(Carbon::parse('2026-11-15 12:00'));

        foreach (['Koordinator', 'Freelancer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $company = Company::create(['name' => 'PT Probe', 'code' => 'PRB', 'is_active' => true]);
        $brand = Division::create(['company_id' => $company->id, 'name' => 'BRAND', 'is_active' => true]);
        $outlet = CompanyLocation::create(['company_id' => $company->id, 'division_id' => $brand->id, 'name' => 'Outlet A', 'latitude' => -6.2, 'longitude' => 106.7]);
        $morning = Shift::create(['company_id' => $company->id, 'division_id' => $brand->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '17:00']);

        $coordinator = User::factory()->create();
        $coordinator->assignRole('Koordinator');
        $coordinator->grantDataAccess(DataScope::Divisions, divisionIds: [$brand->id]);

        $bayu = Freelancer::create(['company_id' => $company->id, 'division_id' => $brand->id, 'full_name' => 'Bayu', 'status' => 'active', 'start_date' => '2026-11-01']);

        foreach (['2026-11-07', '2026-11-08'] as $date) {
            ShiftSchedule::create(['freelancer_id' => $bayu->id, 'date' => $date, 'shift_id' => $morning->id, 'company_location_id' => $outlet->id]);
        }

        $recapAbsent = collect(app(TeamRecapService::class)->recap($coordinator, Carbon::parse('2026-11-01')))
            ->sum('absent_days');

        Sanctum::actingAs($coordinator);
        $summary = $this->getJson('/api/v1/laporan-absensi?start_date=2026-11-02&end_date=2026-11-08')
            ->assertOk()
            ->json('summary');

        $this->assertSame(2, $recapAbsent, 'Rekap Tim absent_days for the two rostered no-shows');
        $this->assertSame(2, $summary['total_absent'], 'API total_absent should count only the 2 rostered no-shows, not unscheduled weekdays');
    }
}
