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
 * Probe: paths where a late worker ends up "Tepat Waktu" (on_time). Each test records observed behaviour.
 */
class LATEProbeTest extends TestCase
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

    public function test_overnight_check_in_after_midnight(): void
    {
        $this->travelTo(Carbon::parse('2026-11-16 12:00:00'));
        $night = Shift::create(['company_id' => $this->company->id, 'name' => 'Malam', 'start_time' => '22:00', 'end_time' => '06:00', 'late_tolerance_minutes' => 15]);
        $worker = $this->freelancer();
        ShiftSchedule::create(['date' => '2026-11-16', 'freelancer_id' => $worker->id, 'shift_id' => $night->id]);
        ShiftSchedule::create(['date' => '2026-11-17', 'freelancer_id' => $worker->id, 'shift_id' => $night->id]);

        $this->travelTo(Carbon::parse('2026-11-17 00:30:00'));
        $body = $this->checkIn($worker);
        $row = Attendance::where('freelancer_id', $worker->id)->first();
        fwrite(STDERR, "\nOVERNIGHT 00:30 for 22:00 shift: ".json_encode(['date' => $row?->date?->toDateString(), 'status' => $row?->status?->value, 'late' => $row?->late_minutes, 'msg' => $body['message'] ?? null])."\n");
        $this->assertTrue(true);
    }

    public function test_overnight_check_in_after_midnight_no_schedule_next_day(): void
    {
        $this->travelTo(Carbon::parse('2026-11-16 12:00:00'));
        $night = Shift::create(['company_id' => $this->company->id, 'name' => 'Malam', 'start_time' => '22:00', 'end_time' => '06:00', 'late_tolerance_minutes' => 15]);
        $worker = $this->freelancer();
        ShiftSchedule::create(['date' => '2026-11-16', 'freelancer_id' => $worker->id, 'shift_id' => $night->id]);

        $this->travelTo(Carbon::parse('2026-11-17 01:45:00'));
        $this->checkIn($worker);
        $row = Attendance::where('freelancer_id', $worker->id)->first();
        fwrite(STDERR, "\nOVERNIGHT 01:45, no D+1 schedule: ".json_encode(['date' => $row?->date?->toDateString(), 'status' => $row?->status?->value, 'late' => $row?->late_minutes, 'shift_id' => $row?->shift_id])."\n");
        $this->assertTrue(true);
    }

    public function test_zero_tolerance_sub_minute(): void
    {
        $shift = Shift::create(['company_id' => $this->company->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '16:00', 'late_tolerance_minutes' => 0]);
        $worker = $this->freelancer($shift);
        $this->travelTo(Carbon::parse('2026-11-17 08:00:45'));
        $this->checkIn($worker);
        $row = Attendance::where('freelancer_id', $worker->id)->first();
        fwrite(STDERR, "\nTOL0 08:00:45: ".json_encode(['status' => $row?->status?->value, 'late' => $row?->late_minutes])."\n");
        $this->assertTrue(true);
    }

    public function test_tolerance_boundary(): void
    {
        $shift = Shift::create(['company_id' => $this->company->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '16:00', 'late_tolerance_minutes' => 15]);
        $out = [];
        foreach (['08:15:00', '08:15:30', '08:16:00'] as $t) {
            $worker = $this->freelancer($shift);
            $this->travelTo(Carbon::parse("2026-11-17 {$t}"));
            $this->checkIn($worker);
            $row = Attendance::where('freelancer_id', $worker->id)->first();
            $out[$t] = [$row?->status?->value, $row?->late_minutes];
        }
        fwrite(STDERR, "\nTOL15: ".json_encode($out)."\n");
        $this->assertTrue(true);
    }

    public function test_day_off_check_in(): void
    {
        $shift = Shift::create(['company_id' => $this->company->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '16:00', 'late_tolerance_minutes' => 15]);
        $worker = $this->freelancer($shift);
        ShiftSchedule::create(['date' => '2026-11-17', 'freelancer_id' => $worker->id, 'is_day_off' => true]);
        $this->travelTo(Carbon::parse('2026-11-17 11:00:00'));
        $this->checkIn($worker);
        $row = Attendance::where('freelancer_id', $worker->id)->first();
        fwrite(STDERR, "\nDAYOFF 11:00: ".json_encode(['status' => $row?->status?->value, 'late' => $row?->late_minutes])."\n");
        $this->assertTrue(true);
    }

    public function test_no_schedule_no_default_falls_back_to_policy(): void
    {
        $worker = $this->freelancer();
        $this->travelTo(Carbon::parse('2026-11-17 08:10:00'));
        $this->checkIn($worker);
        $row = Attendance::where('freelancer_id', $worker->id)->first();
        fwrite(STDERR, "\nPOLICY 08:10: ".json_encode(['status' => $row?->status?->value, 'late' => $row?->late_minutes, 'shift_id' => $row?->shift_id])."\n");
        $this->assertTrue(true);
    }

    public function test_admin_edit_check_in_keeps_on_time(): void
    {
        $shift = Shift::create(['company_id' => $this->company->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '16:00', 'late_tolerance_minutes' => 15]);
        $worker = $this->freelancer($shift);
        $this->travelTo(Carbon::parse('2026-11-17 07:55:00'));
        $this->checkIn($worker);
        $row = Attendance::where('freelancer_id', $worker->id)->first();
        $row->update(['check_in' => Carbon::parse('2026-11-17 09:30:00')]);
        $row->refresh();
        fwrite(STDERR, "\nADMIN EDIT 07:55->09:30: ".json_encode(['status' => $row->status->value, 'late' => $row->late_minutes, 'snap' => $row->check_in_late_minutes])."\n");
        $this->assertSame(AttendanceStatus::OnTime, $row->status);
    }
}
