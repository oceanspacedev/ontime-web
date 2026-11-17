<?php

namespace Tests\Feature\QaProbe;

use App\Enums\DataScope;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\LeaveRequest;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Services\TeamRecapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * RPT-B3: a scheduled day covered by an approved leave must not be counted as "Tidak Hadir" in Rekap Tim.
 */
class Probe_payroll_report_RPTB3Test extends TestCase
{
    use RefreshDatabase;

    public function test_approved_leave_on_scheduled_day_is_not_absent_in_team_recap(): void
    {
        $this->travelTo(Carbon::parse('2026-11-15 12:00'));
        Role::findOrCreate('Koordinator', 'web');

        $company = Company::create(['name' => 'PT Franchise', 'code' => 'PFC', 'is_active' => true]);
        $brand = Division::create(['company_id' => $company->id, 'name' => 'JCHICKEN', 'is_active' => true]);
        $outlet = CompanyLocation::create(['company_id' => $company->id, 'division_id' => $brand->id, 'name' => 'JChicken Ciledug', 'latitude' => -6.2, 'longitude' => 106.7]);
        $pagi = Shift::create(['company_id' => $company->id, 'division_id' => $brand->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '17:00']);

        $coordinator = User::factory()->create();
        $coordinator->assignRole('Koordinator');
        $coordinator->grantDataAccess(DataScope::Divisions, divisionIds: [$brand->id]);

        $bayu = Freelancer::create(['company_id' => $company->id, 'division_id' => $brand->id, 'full_name' => 'Bayu Adillah', 'status' => 'active']);

        ShiftSchedule::create(['freelancer_id' => $bayu->id, 'date' => '2026-11-02', 'shift_id' => $pagi->id, 'company_location_id' => $outlet->id]);

        $leave = LeaveRequest::create([
            'freelancer_id' => $bayu->id,
            'leave_type' => 'sick',
            'start_date' => '2026-11-02',
            'end_date' => '2026-11-02',
            'days_count' => 1,
            'reason' => 'Demam',
            'status' => 'approved',
        ]);
        $leave->applyLeave();

        $row = Attendance::query()->where('freelancer_id', $bayu->id)->sole();
        $this->assertSame('leave', $row->status instanceof \BackedEnum ? $row->status->value : $row->status);
        $this->assertNull($row->check_in);

        $rows = app(TeamRecapService::class)->recap($coordinator, Carbon::parse('2026-11-01'));
        $this->assertCount(1, $rows);

        // Business: approved leave is not alpha; it must not inflate "Tidak Hadir" used for freelancer pay deductions.
        $this->assertSame(0, $rows[0]['absent_days'], 'Approved leave day counted as Tidak Hadir (absent) in Rekap Tim');
    }
}
