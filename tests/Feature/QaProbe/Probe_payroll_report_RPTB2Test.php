<?php

namespace Tests\Feature\QaProbe;

use App\Enums\DataScope;
use App\Filament\Pages\LaporanAbsensi;
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
 * RPT-B2: a check-in 40 minutes late that still waits for geofence approval must not be
 * reported to payroll as an on-time present day.
 */
class Probe_payroll_report_RPTB2Test extends TestCase
{
    use RefreshDatabase;

    private User $coordinator;

    private Attendance $pending;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-15 12:00'));

        foreach (['Koordinator', 'Freelancer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $company = Company::create(['name' => 'PT Probe', 'code' => 'PRB', 'is_active' => true]);
        $brand = Division::create(['company_id' => $company->id, 'name' => 'BRAND', 'is_active' => true]);
        $outlet = CompanyLocation::create(['company_id' => $company->id, 'division_id' => $brand->id, 'name' => 'Outlet A', 'latitude' => -6.2, 'longitude' => 106.7]);
        $morning = Shift::create(['company_id' => $company->id, 'division_id' => $brand->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '17:00']);

        $this->coordinator = User::factory()->create();
        $this->coordinator->assignRole('Koordinator');
        $this->coordinator->grantDataAccess(DataScope::Divisions, divisionIds: [$brand->id]);

        $bayu = Freelancer::create(['company_id' => $company->id, 'division_id' => $brand->id, 'full_name' => 'Bayu', 'status' => 'active', 'start_date' => '2026-11-01']);
        ShiftSchedule::create(['freelancer_id' => $bayu->id, 'date' => '2026-11-04', 'shift_id' => $morning->id, 'company_location_id' => $outlet->id]);

        // Exactly what AttendanceCheckService stores for a late check-in that needs approval.
        $this->pending = Attendance::create(['freelancer_id' => $bayu->id, 'date' => '2026-11-04', 'segment' => 1, 'check_in' => '2026-11-04 08:40:00', 'check_out' => '2026-11-04 17:00:00', 'status' => 'pending_approval', 'late_minutes' => 0, 'check_in_late_minutes' => 40, 'company_location_id' => $outlet->id]);
    }

    public function test_laporan_badge_shows_pending_not_hadir(): void
    {
        $label = app(LaporanAbsensi::class)->statusLabel($this->pending->fresh());
        $this->assertSame('Menunggu Persetujuan (telat 40m)', $label);
    }

    public function test_team_recap_does_not_count_pending_late_checkin_as_on_time(): void
    {
        $row = app(TeamRecapService::class)->recap($this->coordinator, Carbon::parse('2026-11-01'))[0];
        dump($row);

        $countedAsPresent = $row['present_days'] > 0;
        $flaggedLate = $row['late_days'] === 1 && $row['late_minutes'] === 40;

        $this->assertTrue(! $countedAsPresent || $flaggedLate, 'Pending 40-min-late day is paid as an on-time present day');
    }

    public function test_api_summary_does_not_count_pending_late_checkin_as_on_time(): void
    {
        Sanctum::actingAs($this->coordinator);
        $summary = $this->getJson('/api/v1/laporan-absensi?start_date=2026-11-04&end_date=2026-11-04')->json('summary');
        dump($summary);

        $this->assertTrue($summary['total_present'] === 0 || $summary['total_late'] === 1, 'Pending 40-min-late day counted present and not late');
    }
}
