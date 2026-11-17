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
 * BUG-05 probe: policy-hours fallback without a roster, and a check-in on a rostered day off.
 * Records the observed values; the first two cases are documented design (WorkScheduleService docblock,
 * ShiftScheduleTest::test_check_in_on_a_scheduled_day_off_is_never_late). The last case asserts the
 * contested part: a day-off check-in labelled "Tepat Waktu".
 */
class Probe_lateness_BUG05Test extends TestCase
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

        $this->company = Company::create(['name' => 'PT Late5', 'code' => 'LT5', 'latitude' => -6.2, 'longitude' => 106.8166, 'is_active' => true]);
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

    private function checkIn(Freelancer $worker): Attendance
    {
        Sanctum::actingAs(User::findOrFail($worker->user_id));
        $this->post('/api/v1/attendance/check-in', [], ['Accept' => 'application/json'])->assertSuccessful();

        return Attendance::where('freelancer_id', $worker->id)->firstOrFail();
    }

    private function dump(string $label, Attendance $row): void
    {
        fwrite(STDERR, "\nBUG05 {$label}: ".json_encode(['status' => $row->status?->value, 'late' => $row->late_minutes, 'shift_id' => $row->shift_id])."\n");
    }

    /**
     * Decision (Masuk di luar jadwal): a freelancer without a roster or default shift is not judged with
     * the company hours; the check-in waits for approval and, without a cover shift, is never late.
     */
    public function test_no_roster_no_default_shift_must_name_the_cover_shift(): void
    {
        $this->travelTo(Carbon::parse('2026-11-16 08:10:00'));
        $worker = $this->freelancer();
        Sanctum::actingAs(User::findOrFail($worker->user_id));

        // Decision: every off-schedule check-in names the shift it covers, so it is never judged against nothing.
        $this->postJson('/api/v1/attendance/check-in', [])->assertUnprocessable()->assertJsonValidationErrors('cover_shift_id');
        $this->assertSame(0, Attendance::where('freelancer_id', $worker->id)->count());
    }

    public function test_no_roster_check_in_with_a_cover_shift_is_judged_against_that_shift(): void
    {
        $shift = Shift::create(['company_id' => $this->company->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '16:00', 'late_tolerance_minutes' => 0]);
        $worker = $this->freelancer();
        $this->travelTo(Carbon::parse('2026-11-16 08:16:00'));
        Sanctum::actingAs(User::findOrFail($worker->user_id));
        $this->postJson('/api/v1/attendance/check-in', ['cover_shift_id' => $shift->id])->assertSuccessful();
        $row = Attendance::where('freelancer_id', $worker->id)->firstOrFail();
        $this->dump('no roster cover 08:16', $row);

        $this->assertSame(AttendanceStatus::PendingApproval, $row->status);
        $this->assertSame(16, (int) $row->check_in_late_minutes);
        $this->assertSame(16, $row->reportedLateMinutes());
    }

    public function test_check_in_on_rostered_day_off_is_not_labelled_on_time(): void
    {
        $this->travelTo(Carbon::parse('2026-11-16 06:00:00'));
        $morning = Shift::create(['company_id' => $this->company->id, 'name' => 'Pagi', 'start_time' => '07:00', 'end_time' => '15:00', 'late_tolerance_minutes' => 0]);
        $worker = $this->freelancer($morning);
        ShiftSchedule::create(['date' => '2026-11-16', 'freelancer_id' => $worker->id, 'is_day_off' => true]);

        $closing = Shift::create(['company_id' => $this->company->id, 'name' => 'Closing', 'start_time' => '11:00', 'end_time' => '19:00', 'late_tolerance_minutes' => 0]);
        $this->travelTo(Carbon::parse('2026-11-16 11:00:00'));
        Sanctum::actingAs(User::findOrFail($worker->user_id));
        $this->postJson('/api/v1/attendance/check-in', ['cover_shift_id' => $closing->id])->assertSuccessful();
        $row = Attendance::where('freelancer_id', $worker->id)->firstOrFail();
        $this->dump('day off 11:00', $row);

        $this->assertSame(0, (int) $row->late_minutes, 'Not late on a day off (existing tested design)');
        $this->assertNotSame(AttendanceStatus::OnTime, $row->status, 'A check-in on a rostered day off is shown as green Tepat Waktu');
    }
}
