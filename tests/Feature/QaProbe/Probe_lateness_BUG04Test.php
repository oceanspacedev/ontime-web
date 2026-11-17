<?php

namespace Tests\Feature\QaProbe;

use App\Enums\AttendanceStatus;
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
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * BUG-04 probe: a late check-in that waits for geofence approval must not look on time in the Team Recap.
 */
class Probe_lateness_BUG04Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Division $division;

    private User $coordinator;

    private Shift $morning;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Role::findOrCreate('Koordinator', 'web');
        Notification::fake();
        Queue::fake();
        Http::fake();

        $this->travelTo(Carbon::parse('2026-11-16 07:00:00'));

        $this->company = Company::create(['name' => 'PT Late4', 'code' => 'LT4', 'latitude' => -6.1753924, 'longitude' => 106.8271528, 'is_active' => true]);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Ops', 'code' => 'OPS', 'is_active' => true]);
        $this->company->policy->update([
            ...CompanyPolicy::flagsForSelfieMode('none'),
            'require_gps' => true,
            'geofence_radius_meters' => 100,
            'work_start_time' => '08:00:00',
            'work_end_time' => '17:00:00',
            'late_tolerance_minutes' => 15,
        ]);
        $this->morning = Shift::create(['company_id' => $this->company->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '17:00', 'late_tolerance_minutes' => 15]);

        $this->coordinator = User::factory()->create();
        $this->coordinator->assignRole('Koordinator');
        $this->coordinator->grantDataAccess(DataScope::Divisions, divisionIds: [$this->division->id]);
    }

    private function freelancer(): Freelancer
    {
        $user = User::factory()->create();
        $user->assignRole('Employee');

        $worker = Freelancer::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'full_name' => 'FL '.$user->id,
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonth(),
            'status' => 'active',
        ]);
        ShiftSchedule::create(['date' => '2026-11-16', 'freelancer_id' => $worker->id, 'shift_id' => $this->morning->id]);

        return $worker;
    }

    /**
     * @return array<string, mixed>
     */
    private function checkInAt0840(Freelancer $worker, float $lat, float $lng): array
    {
        $this->travelTo(Carbon::parse('2026-11-16 08:40:00'));
        Sanctum::actingAs(User::findOrFail($worker->user_id));

        return $this->postJson('/api/v1/attendance/check-in', ['latitude' => $lat, 'longitude' => $lng])->json();
    }

    /**
     * @return array<string, mixed>
     */
    private function recapRow(Freelancer $worker): array
    {
        $rows = app(TeamRecapService::class)->recap($this->coordinator, Carbon::parse('2026-11-01'));
        $row = collect($rows)->first(fn (array $row): bool => $row['worker']['name'] === $worker->full_name);
        $this->assertNotNull($row, 'Worker must appear in the recap');

        return array_intersect_key($row, array_flip(['present_days', 'late_days', 'late_minutes']));
    }

    public function test_control_inside_geofence_late_check_in_is_late_in_recap(): void
    {
        $worker = $this->freelancer();
        $this->checkInAt0840($worker, -6.1753924, 106.8271528);
        $row = Attendance::where('freelancer_id', $worker->id)->firstOrFail();

        $this->assertSame(AttendanceStatus::Late, $row->status);
        $this->assertSame(40, (int) $row->late_minutes);
        $this->assertSame(['present_days' => 1, 'late_days' => 1, 'late_minutes' => 40], $this->recapRow($worker));
    }

    public function test_pending_late_check_in_outside_geofence_is_not_on_time_in_recap(): void
    {
        $worker = $this->freelancer();
        $this->checkInAt0840($worker, -6.2000000, 106.8500000);
        $row = Attendance::where('freelancer_id', $worker->id)->firstOrFail();
        $recap = $this->recapRow($worker);

        fwrite(STDERR, "\nBUG04 pending: ".json_encode(['status' => $row->status?->value, 'late_minutes' => $row->late_minutes, 'check_in_late_minutes' => $row->check_in_late_minutes, 'recap' => $recap])."\n");

        $this->assertSame(AttendanceStatus::PendingApproval, $row->status);
        $this->assertSame(40, (int) $row->check_in_late_minutes, 'The real lateness is known at check-in');

        // Business: a row counted as present for payroll must carry its lateness; otherwise it must not be counted present at all.
        $countedPresentAndLate = $recap === ['present_days' => 1, 'late_days' => 1, 'late_minutes' => 40];
        $notCountedYet = $recap['present_days'] === 0;
        $this->assertTrue(
            $countedPresentAndLate || $notCountedYet,
            'Pending 40-min-late check-in shows as present and on time in Team Recap: '.json_encode($recap),
        );
    }

    public function test_after_approval_recap_shows_the_lateness(): void
    {
        $worker = $this->freelancer();
        $this->checkInAt0840($worker, -6.2000000, 106.8500000);
        $row = Attendance::where('freelancer_id', $worker->id)->firstOrFail();

        $row->applyGeofenceApproval();

        $this->assertSame(['present_days' => 1, 'late_days' => 1, 'late_minutes' => 40], $this->recapRow($worker));
    }
}
