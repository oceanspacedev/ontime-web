<?php

namespace Tests\Feature\QaProbe;

use App\Enums\DataScope;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\OvertimeRequest;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Services\TeamRecapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * RPT-B4: the payroll recap must separate approved overtime from unapproved extra time.
 */
class Probe_payroll_report_RPTB4Test extends TestCase
{
    use RefreshDatabase;

    public function test_recap_distinguishes_approved_from_rejected_overtime(): void
    {
        $this->travelTo(Carbon::parse('2026-11-15 12:00'));
        Role::findOrCreate('Koordinator', 'web');

        $company = Company::create(['name' => 'PT Franchise', 'code' => 'PFC', 'is_active' => true]);
        $brand = Division::create(['company_id' => $company->id, 'name' => 'JCHICKEN', 'is_active' => true]);
        $outlet = CompanyLocation::create(['company_id' => $company->id, 'division_id' => $brand->id, 'name' => 'JChicken Ciledug', 'latitude' => -6.2, 'longitude' => 106.7]);
        $shift = Shift::create(['company_id' => $company->id, 'division_id' => $brand->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '17:00']);

        $pic = User::factory()->create();
        $pic->assignRole('Koordinator');
        $pic->grantDataAccess(DataScope::Divisions, divisionIds: [$brand->id]);

        $approved = Freelancer::create(['company_id' => $company->id, 'division_id' => $brand->id, 'full_name' => 'A Approved', 'status' => 'active']);
        $rejected = Freelancer::create(['company_id' => $company->id, 'division_id' => $brand->id, 'full_name' => 'B Rejected', 'status' => 'active']);

        foreach ([[$approved, 'approved'], [$rejected, 'rejected']] as [$worker, $status]) {
            ShiftSchedule::create(['freelancer_id' => $worker->id, 'date' => '2026-11-02', 'shift_id' => $shift->id, 'company_location_id' => $outlet->id]);
            Attendance::create(['freelancer_id' => $worker->id, 'date' => '2026-11-02', 'check_in' => '2026-11-02 08:00:00', 'check_out' => '2026-11-02 19:00:00', 'status' => 'on_time', 'late_minutes' => 0, 'company_location_id' => $outlet->id]);
            OvertimeRequest::create(['freelancer_id' => $worker->id, 'date' => '2026-11-02', 'start_time' => '17:00', 'end_time' => '19:00', 'duration_minutes' => 120, 'actual_duration_minutes' => 120, 'reason' => 'Ramai', 'status' => $status]);
        }

        $rows = collect(app(TeamRecapService::class)->recap($pic, Carbon::parse('2026-11-01')))->keyBy(fn (array $row): string => $row['worker']['name']);

        $a = $rows['A Approved'];
        $b = $rows['B Rejected'];
        unset($a['worker'], $b['worker']);

        // Observed so far: both rows are identical (worked_hours 11.0), with no overtime field at all.
        $this->assertNotSame($a, $b, 'Approved vs rejected overtime must not produce an identical payroll row. Row: '.json_encode($a));

        $overtimeKeys = array_filter(array_keys($a), fn (string $key): bool => str_contains($key, 'overtime') || str_contains($key, 'lembur'));
        $this->assertNotEmpty($overtimeKeys, 'The recap has no overtime (lembur) field. Keys: '.implode(',', array_keys($a)));
    }
}
