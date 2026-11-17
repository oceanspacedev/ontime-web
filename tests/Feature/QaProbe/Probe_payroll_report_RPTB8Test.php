<?php

namespace Tests\Feature\QaProbe;

use App\Enums\DataScope;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyPolicy;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Services\TeamRecapService;
use App\Services\WorkScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * RPT-B8: a scheduled working day without a shift counts as a scheduled day but adds 0 Jam Terjadwal,
 * although the attendance engine (WorkScheduleService::resolve) applies the policy hours that day.
 */
class Probe_payroll_report_RPTB8Test extends TestCase
{
    use RefreshDatabase;

    private User $coordinator;

    private Freelancer $bayu;

    private Company $company;

    private Division $brand;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-15 12:00'));

        foreach (['Koordinator', 'Freelancer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->company = Company::create(['name' => 'PT Franchise', 'code' => 'PFC', 'is_active' => true]);
        CompanyPolicy::create([
            'company_id' => $this->company->id,
            'late_tolerance_minutes' => 15,
            'require_photo' => false,
            'require_gps' => false,
            'annual_leave_quota' => 12,
            'work_start_time' => '08:00:00',
            'work_end_time' => '17:00:00',
        ]);
        $this->brand = Division::create(['company_id' => $this->company->id, 'name' => 'JCHICKEN', 'is_active' => true]);

        $this->coordinator = User::factory()->create();
        $this->coordinator->assignRole('Koordinator');
        $this->coordinator->grantDataAccess(DataScope::Divisions, divisionIds: [$this->brand->id]);

        $this->bayu = Freelancer::create(['company_id' => $this->company->id, 'division_id' => $this->brand->id, 'full_name' => 'Bayu Adillah', 'status' => 'active']);
    }

    public function test_working_day_without_shift_counts_the_policy_hours(): void
    {
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-02', 'shift_id' => null, 'is_day_off' => false]);
        Attendance::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-02', 'check_in' => '2026-11-02 08:00:00', 'check_out' => '2026-11-02 17:00:00', 'status' => 'on_time', 'late_minutes' => 0]);

        $resolved = app(WorkScheduleService::class)->resolve($this->bayu, Carbon::parse('2026-11-02'));
        $this->assertSame(['08:00:00', '17:00:00', 'policy'], [$resolved['start_time'], $resolved['end_time'], $resolved['source']]);

        [$row] = app(TeamRecapService::class)->recap($this->coordinator, Carbon::parse('2026-11-01'));

        $this->assertSame(1, $row['scheduled_days']);
        $this->assertSame(9.0, $row['worked_hours']);
        $this->assertSame(9.0, $row['scheduled_hours'], 'Jam Terjadwal should be the 9 policy hours the attendance was judged against.');
    }

    public function test_deleting_a_shift_used_only_in_past_schedules_zeroes_their_scheduled_hours(): void
    {
        $pagi = Shift::create(['company_id' => $this->company->id, 'division_id' => $this->brand->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '17:00']);
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-02', 'shift_id' => $pagi->id]);
        Attendance::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-02', 'check_in' => '2026-11-02 08:00:00', 'check_out' => '2026-11-02 17:00:00', 'status' => 'on_time', 'late_minutes' => 0]);

        [$before] = app(TeamRecapService::class)->recap($this->coordinator, Carbon::parse('2026-11-01'));
        $this->assertSame(9.0, $before['scheduled_hours']);

        // Decision (reports, phase 2): a shift used by any schedule or attendance can no longer be deleted, only
        // deactivated, so its hours stay attached to the closed month. The probe used to assert that deletion
        // was allowed and nulled shift_id; it now asserts the delete is refused and the history is kept.
        $this->assertNotNull($pagi->deletionBlockedReason());
        $this->assertFalse($pagi->delete());
        $this->assertSame($pagi->id, ShiftSchedule::first()->shift_id);

        [$after] = app(TeamRecapService::class)->recap($this->coordinator, Carbon::parse('2026-11-01'));
        $this->assertSame(1, $after['scheduled_days']);
        $this->assertSame(9.0, $after['scheduled_hours'], 'A closed month\'s Jam Terjadwal should not drop to 0 after a shift is deleted.');
    }
}
