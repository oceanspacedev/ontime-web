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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * RPT-B10: a split shift day with both segments rejected must count as ONE absent worker-day (and zero
 * present) in the laporan-absensi API summary, not two absences.
 */
class Probe_payroll_report_RPTB10Test extends TestCase
{
    use RefreshDatabase;

    public function test_split_shift_with_both_segments_rejected_counts_one_absence(): void
    {
        $this->travelTo(Carbon::parse('2026-11-15 12:00'));
        Role::findOrCreate('Koordinator', 'web');

        $company = Company::create(['name' => 'PT Probe', 'code' => 'PRB', 'is_active' => true]);
        $brand = Division::create(['company_id' => $company->id, 'name' => 'BRAND', 'is_active' => true]);
        $outlet = CompanyLocation::create(['company_id' => $company->id, 'division_id' => $brand->id, 'name' => 'Outlet A', 'latitude' => -6.2, 'longitude' => 106.7]);
        $morning = Shift::create(['company_id' => $company->id, 'division_id' => $brand->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '12:00', 'second_start_time' => '17:00', 'second_end_time' => '21:00']);

        $coordinator = User::factory()->create();
        $coordinator->assignRole('Koordinator');
        $coordinator->grantDataAccess(DataScope::Divisions, divisionIds: [$brand->id]);

        $bayu = Freelancer::create(['company_id' => $company->id, 'division_id' => $brand->id, 'full_name' => 'Bayu', 'status' => 'active', 'start_date' => '2026-11-01']);

        ShiftSchedule::create(['freelancer_id' => $bayu->id, 'date' => '2026-11-03', 'shift_id' => $morning->id, 'company_location_id' => $outlet->id]);

        foreach ([[1, '08:00', '12:00'], [2, '17:00', '21:00']] as [$segment, $in, $out]) {
            $attendance = Attendance::create(['freelancer_id' => $bayu->id, 'date' => '2026-11-03', 'segment' => $segment, 'check_in' => "2026-11-03 {$in}:00", 'check_out' => "2026-11-03 {$out}:00", 'status' => 'pending_approval', 'late_minutes' => 0, 'company_location_id' => $outlet->id]);
            $attendance->applyGeofenceRejection('Di luar area');
        }

        $this->assertSame(2, Attendance::where('freelancer_id', $bayu->id)->where('status', 'rejected')->count());

        Sanctum::actingAs($coordinator);
        $summary = $this->getJson('/api/v1/laporan-absensi?start_date=2026-11-03&end_date=2026-11-03')->assertOk()->json('summary');

        $this->assertSame(0, $summary['total_present'], 'rejected split day counted as present');
        $this->assertSame(1, $summary['total_absent'], 'split day with two rejected segments counted as more than one absence');
    }
}
