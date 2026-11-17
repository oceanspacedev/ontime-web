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
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * QA probe: payroll reports for freelancers. Each test prints the numbers the report gives.
 */
class RPTProbeTest extends TestCase
{
    use RefreshDatabase;

    private User $coordinator;

    private Freelancer $bayu;

    private CompanyLocation $outlet;

    private Shift $morning;

    private Shift $split;

    private Shift $night;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-15 12:00'));

        foreach (['Koordinator', 'Freelancer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $company = Company::create(['name' => 'PT Probe', 'code' => 'PRB', 'is_active' => true]);
        $brand = Division::create(['company_id' => $company->id, 'name' => 'BRAND', 'is_active' => true]);
        $this->outlet = CompanyLocation::create(['company_id' => $company->id, 'division_id' => $brand->id, 'name' => 'Outlet A', 'latitude' => -6.2, 'longitude' => 106.7]);
        $this->morning = Shift::create(['company_id' => $company->id, 'division_id' => $brand->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '17:00']);
        $this->split = Shift::create(['company_id' => $company->id, 'division_id' => $brand->id, 'name' => 'Split', 'start_time' => '08:00', 'end_time' => '12:00', 'second_start_time' => '17:00', 'second_end_time' => '21:00']);
        $this->night = Shift::create(['company_id' => $company->id, 'division_id' => $brand->id, 'name' => 'Malam', 'start_time' => '22:00', 'end_time' => '06:00']);

        $this->coordinator = User::factory()->create();
        $this->coordinator->assignRole('Koordinator');
        $this->coordinator->grantDataAccess(DataScope::Divisions, divisionIds: [$brand->id]);

        $this->bayu = Freelancer::create(['company_id' => $company->id, 'division_id' => $brand->id, 'full_name' => 'Bayu', 'status' => 'active', 'start_date' => '2026-11-01']);
    }

    public function test_a_regular_split_overnight(): void
    {
        $this->sched('2026-11-02', $this->morning);
        $this->att('2026-11-02', '2026-11-02 08:20:00', '2026-11-02 17:00:00', 'late', 20);
        $this->sched('2026-11-03', $this->split);
        $this->att('2026-11-03', '2026-11-03 08:00:00', '2026-11-03 12:00:00', 'on_time', 0, 1);
        $this->att('2026-11-03', '2026-11-03 17:10:00', '2026-11-03 21:00:00', 'on_time', 0, 2);
        $this->sched('2026-11-04', $this->night);
        $this->att('2026-11-04', '2026-11-04 22:00:00', '2026-11-05 06:00:00', 'on_time', 0);

        dump(app(TeamRecapService::class)->recap($this->coordinator, Carbon::parse('2026-11-01')));
        $this->assertTrue(true);
    }

    public function test_leave_rejected_pending_missing_checkout_dayoff(): void
    {
        // approved leave on a scheduled day
        $this->sched('2026-11-02', $this->morning);
        $leave = LeaveRequest::create(['freelancer_id' => $this->bayu->id, 'leave_type' => 'izin', 'start_date' => '2026-11-02', 'end_date' => '2026-11-02', 'days_count' => 1, 'reason' => 'x', 'status' => 'approved']);
        $leave->applyLeave();
        // rejected geofence
        $this->sched('2026-11-03', $this->morning);
        $this->att('2026-11-03', '2026-11-03 08:00:00', '2026-11-03 17:00:00', 'rejected', 0);
        // pending geofence, really 40 minutes late
        $this->sched('2026-11-04', $this->morning);
        Attendance::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-04', 'segment' => 1, 'check_in' => '2026-11-04 08:40:00', 'check_out' => '2026-11-04 17:00:00', 'status' => 'pending_approval', 'late_minutes' => 0, 'check_in_late_minutes' => 40, 'company_location_id' => $this->outlet->id]);
        // missing checkout
        $this->sched('2026-11-05', $this->morning);
        $this->att('2026-11-05', '2026-11-05 08:00:00', null, 'on_time', 0);
        // day off worked
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-06', 'is_day_off' => true]);
        $this->att('2026-11-06', '2026-11-06 08:00:00', '2026-11-06 17:00:00', 'on_time', 0);

        dump(app(TeamRecapService::class)->recap($this->coordinator, Carbon::parse('2026-11-01')));

        Sanctum::actingAs($this->coordinator);
        dump($this->getJson('/api/v1/laporan-absensi?start_date=2026-11-01&end_date=2026-11-06')->json('summary'));
        $this->assertTrue(true);
    }

    public function test_api_absent_without_schedule_and_weekend_schedule(): void
    {
        // no schedule at all Mon 2 - Fri 6; scheduled Sat 7 and Sun 8, no-show
        $this->sched('2026-11-07', $this->morning);
        $this->sched('2026-11-08', $this->morning);
        Sanctum::actingAs($this->coordinator);
        dump($this->getJson('/api/v1/laporan-absensi?start_date=2026-11-02&end_date=2026-11-08')->json('summary'));
        dump(app(TeamRecapService::class)->recap($this->coordinator, Carbon::parse('2026-11-01')));
        $this->assertTrue(true);
    }

    private function sched(string $date, Shift $shift): void
    {
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => $date, 'shift_id' => $shift->id, 'company_location_id' => $this->outlet->id]);
    }

    private function att(string $date, ?string $in, ?string $out, string $status, int $late, int $segment = 1): void
    {
        Attendance::create(['freelancer_id' => $this->bayu->id, 'date' => $date, 'segment' => $segment, 'check_in' => $in, 'check_out' => $out, 'status' => $status, 'late_minutes' => $late, 'company_location_id' => $this->outlet->id]);
    }
}
