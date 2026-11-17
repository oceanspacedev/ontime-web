<?php

namespace Tests\Feature\QaProbe;

use App\Enums\AttendanceStatus;
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
 * BUG-03: a check-in rejected by the geofence approver keeps check_in/check_out
 * (Attendance::applyGeofenceRejection only changes status) and is then counted as present and paid.
 */
class Probe_lateness_BUG03Test extends TestCase
{
    use RefreshDatabase;

    private User $coordinator;

    private Attendance $rejected;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-15 12:00'));
        foreach (['Koordinator', 'Freelancer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $company = Company::create(['name' => 'PT Probe', 'code' => 'PRB3', 'is_active' => true]);
        $brand = Division::create(['company_id' => $company->id, 'name' => 'JCHICKEN', 'is_active' => true]);
        $outlet = CompanyLocation::create(['company_id' => $company->id, 'division_id' => $brand->id, 'name' => 'JChicken Ciledug', 'latitude' => -6.2, 'longitude' => 106.7]);
        $shift = Shift::create(['company_id' => $company->id, 'division_id' => $brand->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '17:00']);

        $this->coordinator = User::factory()->create();
        $this->coordinator->assignRole('Koordinator');
        $this->coordinator->grantDataAccess(DataScope::Divisions, divisionIds: [$brand->id]);

        $bayu = Freelancer::create(['company_id' => $company->id, 'division_id' => $brand->id, 'full_name' => 'Bayu Adillah', 'status' => 'active']);
        ShiftSchedule::create(['freelancer_id' => $bayu->id, 'date' => '2026-11-02', 'shift_id' => $shift->id, 'company_location_id' => $outlet->id]);

        // Checked in out of bounds (pending), then the approver rejected it via the real model method.
        $this->rejected = Attendance::create([
            'freelancer_id' => $bayu->id, 'date' => '2026-11-02',
            'check_in' => '2026-11-02 07:55:00', 'check_out' => '2026-11-02 17:05:00',
            'status' => AttendanceStatus::PendingApproval, 'is_out_of_bounds' => true, 'late_minutes' => 0,
            'company_location_id' => $outlet->id,
        ]);
        $this->rejected->applyGeofenceRejection('Lokasi tidak sesuai');
        $this->rejected->refresh();
    }

    public function test_rejection_keeps_times_and_sets_rejected_status(): void
    {
        $this->assertSame(AttendanceStatus::Rejected, $this->rejected->status);
        $this->assertNotNull($this->rejected->check_in, 'Precondition: rejection keeps check_in.');
    }

    public function test_team_recap_does_not_count_or_pay_a_rejected_check_in(): void
    {
        $rows = app(TeamRecapService::class)->recap($this->coordinator, Carbon::parse('2026-11-01'));
        $row = collect($rows)->firstWhere('outlet', 'JChicken Ciledug');

        $this->assertSame(
            ['present_days' => 0, 'absent_days' => 1, 'worked_hours' => 0.0],
            ['present_days' => $row['present_days'], 'absent_days' => $row['absent_days'], 'worked_hours' => (float) $row['worked_hours']],
            'A rejected check-in must not be paid as a present day with worked hours.',
        );
    }

    public function test_report_summary_does_not_count_a_rejected_row_as_present_and_absent(): void
    {
        Sanctum::actingAs($this->coordinator);

        $summary = $this->getJson('/api/v1/laporan-absensi?start_date=2026-11-02&end_date=2026-11-02')
            ->assertOk()
            ->json('summary');

        $this->assertSame(0, $summary['total_present'], 'Rejected row counted as present.');
        $this->assertSame(0, $summary['total_late']);
        $this->assertSame(1, $summary['total_absent'], 'A rejected rostered day is one absence, not present and absent.');
    }

    public function test_absen_hari_ini_badge_logic_does_not_render_tepat_waktu_for_rejected(): void
    {
        // Mirror of absen-hari-ini.blade.php:188-198 / presensi-widget.blade.php:76-85 branching.
        $view = file_get_contents(resource_path('views/filament/pages/absen-hari-ini.blade.php'));
        $this->assertTrue(str_contains($view, 'AttendanceStatus::Rejected'), 'Card has no branch for Rejected, so it falls into the green "Tepat Waktu" else-branch.');
    }
}
