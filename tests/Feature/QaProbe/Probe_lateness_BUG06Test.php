<?php

namespace Tests\Feature\QaProbe;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyPolicy;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * BUG-06 probe: with tolerance 0, a check-in 45 seconds after the shift start is past the window and must not be Tepat Waktu.
 */
class Probe_lateness_BUG06Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Division $division;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Notification::fake();
        Queue::fake();
        Http::fake();

        $this->company = Company::create(['name' => 'PT Late', 'code' => 'LATE', 'latitude' => -6.2, 'longitude' => 106.8166, 'is_active' => true]);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Ops', 'code' => 'OPS', 'is_active' => true]);
        $this->company->policy->update([
            ...CompanyPolicy::flagsForSelfieMode('none'),
            'require_gps' => false,
            'work_start_time' => '08:00:00',
            'work_end_time' => '17:00:00',
            'late_tolerance_minutes' => 15,
        ]);
    }

    private function freelancer(?Shift $default = null): Freelancer
    {
        $user = User::factory()->create();
        $user->assignRole('Employee');

        return Freelancer::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'full_name' => 'FL '.$user->id,
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonth(),
            'status' => 'active',
            'shift_id' => $default?->id,
        ]);
    }

    private function checkIn(Freelancer $worker): array
    {
        Sanctum::actingAs(User::findOrFail($worker->user_id));

        return $this->post('/api/v1/attendance/check-in', [], ['Accept' => 'application/json'])->json();
    }

    private function checkInAt(int $tolerance, string $at): Attendance
    {
        $this->travelTo(Carbon::parse('2026-11-17 06:00:00'));
        $shift = Shift::create(['company_id' => $this->company->id, 'name' => 'Pagi '.$tolerance, 'start_time' => '08:00', 'end_time' => '17:00', 'late_tolerance_minutes' => $tolerance]);
        $worker = $this->freelancer();
        ShiftSchedule::create(['date' => '2026-11-17', 'freelancer_id' => $worker->id, 'shift_id' => $shift->id]);

        $this->travelTo(Carbon::parse('2026-11-17 '.$at));
        $this->checkIn($worker);

        return Attendance::where('freelancer_id', $worker->id)->firstOrFail();
    }

    public function test_tolerance_zero_check_in_45_seconds_late_is_not_on_time(): void
    {
        $row = $this->checkInAt(0, '08:00:45');

        fwrite(STDERR, "\nBUG06 tol0 08:00:45: ".json_encode(['check_in' => (string) $row->check_in, 'status' => $row->status?->value, 'late' => $row->late_minutes])."\n");

        $this->assertNotSame(AttendanceStatus::OnTime, $row->status, 'Past a zero-minute tolerance window must not be Tepat Waktu');
        $this->assertGreaterThan(0, (int) $row->late_minutes);
    }

    public function test_tolerance_fifteen_check_in_30_seconds_past_window_reference(): void
    {
        $row = $this->checkInAt(15, '08:15:30');

        fwrite(STDERR, "\nBUG06 tol15 08:15:30: ".json_encode(['check_in' => (string) $row->check_in, 'status' => $row->status?->value, 'late' => $row->late_minutes])."\n");

        $this->assertSame(AttendanceStatus::Late, $row->status);
        $this->assertSame(15, (int) $row->late_minutes);
    }
}
