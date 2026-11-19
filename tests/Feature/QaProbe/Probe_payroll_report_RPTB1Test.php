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
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * RPT-B1: a geofence-rejected attendance (status rejected, check_in/check_out kept) must not count as a
 * present, paid day in Rekap Tim nor in the laporan-absensi API summary.
 */
class Probe_payroll_report_RPTB1Test extends TestCase
{
    use RefreshDatabase;

    private User $coordinator;

    private Freelancer $bayu;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-15 12:00'));
        Role::findOrCreate('Koordinator', 'web');

        $company = Company::create(['name' => 'PT Probe', 'code' => 'PRB', 'is_active' => true]);
        $brand = Division::create(['company_id' => $company->id, 'name' => 'BRAND', 'is_active' => true]);
        $outlet = CompanyLocation::create(['company_id' => $company->id, 'division_id' => $brand->id, 'name' => 'Outlet A', 'latitude' => -6.2, 'longitude' => 106.7]);
        $morning = Shift::create(['company_id' => $company->id, 'division_id' => $brand->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '17:00']);

        $this->coordinator = User::factory()->create();
        $this->coordinator->assignRole('Koordinator');
        $this->coordinator->grantDataAccess(DataScope::Divisions, divisionIds: [$brand->id]);

        $this->bayu = Freelancer::create(['company_id' => $company->id, 'division_id' => $brand->id, 'full_name' => 'Bayu', 'status' => 'active', 'start_date' => '2026-11-01']);

        // On the roster from Sunday 1 November (a day off), the first day of the contract.
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-01', 'is_day_off' => true]);
        // Tue 2026-11-03: scheduled, checked in/out outside the geofence, rejected by the approver.
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-03', 'shift_id' => $morning->id, 'company_location_id' => $outlet->id]);
        $attendance = Attendance::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-03', 'segment' => 1, 'check_in' => '2026-11-03 08:00:00', 'check_out' => '2026-11-03 17:00:00', 'status' => 'pending_approval', 'late_minutes' => 0, 'company_location_id' => $outlet->id]);
        $attendance->applyGeofenceRejection('Di luar area');
    }

    public function test_team_recap_does_not_pay_a_rejected_day(): void
    {
        $rows = app(TeamRecapService::class)->recap($this->coordinator, Carbon::parse('2026-11-01'));
        $row = collect($rows)->first();

        $this->assertNotNull($row);
        $this->assertSame(1, $row['scheduled_days']);
        $this->assertSame(0, $row['present_days'], 'rejected day counted as present');
        $this->assertEquals(0, $row['worked_hours'], 'rejected day hours counted as worked (paid)');
        $this->assertSame(1, $row['absent_days'], 'rejected scheduled day not counted as absent');
    }

    public function test_api_summary_does_not_count_rejected_day_as_both_present_and_absent(): void
    {
        Sanctum::actingAs($this->coordinator);
        $summary = $this->getJson('/api/v1/laporan-absensi?start_date=2026-11-03&end_date=2026-11-03')->assertOk()->json('summary');

        $this->assertSame(0, $summary['total_present'], 'rejected day counted as present in API');
        $this->assertSame(1, $summary['total_absent']);
    }
}
