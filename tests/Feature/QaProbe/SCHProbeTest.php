<?php

namespace Tests\Feature\QaProbe;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyPolicy;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\Holiday;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Services\ScheduleCopyService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * QA probe for the scheduling subsystem: how dated schedules, default shifts, overnight and split
 * shifts, day offs and holidays decide the status written at check-in (GPS off, no selfie).
 */
class SCHProbeTest extends TestCase
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

        $this->company = Company::create(['name' => 'PT SCH', 'code' => 'SCH', 'latitude' => -6.2, 'longitude' => 106.8, 'is_active' => true]);
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
            'full_name' => 'Freelance '.$user->id,
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonth(),
            'status' => 'active',
            'shift_id' => $default?->id,
        ]);
    }

    private function shift(string $name, string $start, string $end, ?string $start2 = null, ?string $end2 = null, ?int $tolerance = 15): Shift
    {
        return Shift::create([
            'company_id' => $this->company->id,
            'name' => $name,
            'start_time' => $start,
            'end_time' => $end,
            'second_start_time' => $start2,
            'second_end_time' => $end2,
            'late_tolerance_minutes' => $tolerance,
            'is_active' => true,
        ]);
    }

    private function schedule(Freelancer $worker, string $date, ?Shift $shift, bool $off = false): ShiftSchedule
    {
        return ShiftSchedule::create(['freelancer_id' => $worker->id, 'date' => $date, 'shift_id' => $shift?->id, 'is_day_off' => $off]);
    }

    private function hit(Freelancer $worker, string $what): TestResponse
    {
        Sanctum::actingAs(User::findOrFail($worker->user_id));

        return $this->postJson("/api/v1/attendance/{$what}", []);
    }

    private function row(Freelancer $worker, string $date, int $segment = 1): ?Attendance
    {
        return Attendance::query()->where('freelancer_id', $worker->id)->whereDate('date', $date)->where('segment', $segment)->first();
    }

    public function test_sch01_dated_schedule_overrides_default_shift(): void
    {
        $pagi = $this->shift('Pagi', '08:00', '16:00');
        $siang = $this->shift('Siang', '13:00', '21:00');
        $worker = $this->freelancer($pagi);
        $this->schedule($worker, '2026-11-18', $siang);

        $this->travelTo(Carbon::parse('2026-11-18 13:20'));
        $this->hit($worker, 'check-in')->assertSuccessful();

        $row = $this->row($worker, '2026-11-18');
        dump(['sch01' => [$row->status->value, $row->late_minutes, $row->shift_id === $siang->id]]);
        $this->assertSame('late', $row->status->value);
        $this->assertSame(20, $row->late_minutes);
    }

    public function test_sch02_overnight_check_in_after_midnight(): void
    {
        $malam = $this->shift('Malam', '22:00', '06:00');
        $worker = $this->freelancer();
        $this->schedule($worker, '2026-11-18', $malam);
        $this->schedule($worker, '2026-11-19', $malam);

        // 2h10m late for the 18 Nov night shift: arrives 00:10 on 19 Nov.
        $this->travelTo(Carbon::parse('2026-11-19 00:10'));
        $this->hit($worker, 'check-in')->assertSuccessful();

        $row = Attendance::query()->where('freelancer_id', $worker->id)->first();
        dump(['sch02' => [$row->date->toDateString(), $row->status->value, $row->late_minutes]]);

        // The 19 Nov night shift is then blocked.
        $this->travelTo(Carbon::parse('2026-11-19 21:55'));
        $second = $this->hit($worker, 'check-in');
        dump(['sch02b' => [$second->status(), $second->json('message')]]);
    }

    public function test_sch03_check_in_on_scheduled_day_off(): void
    {
        $worker = $this->freelancer();
        $this->schedule($worker, '2026-11-18', null, true);

        $this->travelTo(Carbon::parse('2026-11-18 15:00'));
        $response = $this->hit($worker, 'check-in');
        $row = $this->row($worker, '2026-11-18');
        dump(['sch03' => [$response->status(), $row?->status->value, $row?->late_minutes, $row?->shift_id]]);
    }

    public function test_sch04_holiday_is_ignored(): void
    {
        $worker = $this->freelancer();
        Holiday::create(['company_id' => null, 'name' => 'Libur Nasional', 'date' => '2026-11-18', 'is_national' => true]);

        $this->travelTo(Carbon::parse('2026-11-18 10:00'));
        $response = $this->hit($worker, 'check-in');
        $row = $this->row($worker, '2026-11-18');
        dump(['sch04' => [$response->status(), $row?->status->value, $row?->late_minutes]]);
    }

    public function test_sch05_split_shift_second_segment_late(): void
    {
        $split = $this->shift('Split', '07:45', '13:00', '17:45', '22:00');
        $worker = $this->freelancer();
        $this->schedule($worker, '2026-11-18', $split);

        $this->travelTo(Carbon::parse('2026-11-18 07:50'));
        $this->hit($worker, 'check-in')->assertSuccessful();
        $this->travelTo(Carbon::parse('2026-11-18 13:05'));
        $this->hit($worker, 'check-out')->assertSuccessful();
        $this->travelTo(Carbon::parse('2026-11-18 16:30'));
        $early = $this->hit($worker, 'check-in');
        $this->travelTo(Carbon::parse('2026-11-18 18:30'));
        $this->hit($worker, 'check-in')->assertSuccessful();

        $one = $this->row($worker, '2026-11-18', 1);
        $two = $this->row($worker, '2026-11-18', 2);
        dump(['sch05' => [$one->status->value, $one->late_minutes, $early->status(), $two->status->value, $two->late_minutes]]);
        $this->assertSame('on_time', $one->status->value);
        $this->assertSame(422, $early->status());
        $this->assertSame('late', $two->status->value);
        $this->assertSame(45, $two->late_minutes);
    }

    public function test_sch06_no_schedule_no_default_uses_policy_hours(): void
    {
        $worker = $this->freelancer();
        $pagi = $this->shift('Pagi', '08:00', '16:00');
        $this->travelTo(Carbon::parse('2026-11-18 08:15'));
        // Decision "Masuk di luar jadwal": an unrostered freelancer names the shift covered and waits for approval.
        $this->hit($worker, 'check-in')->assertUnprocessable()->assertJsonValidationErrors('cover_shift_id');
        Sanctum::actingAs(User::findOrFail($worker->user_id));
        $this->postJson('/api/v1/attendance/check-in', ['cover_shift_id' => $pagi->id])->assertSuccessful();
        $row = $this->row($worker, '2026-11-18');
        dump(['sch06' => [$row->status->value, $row->late_minutes, $row->shift_id]]);
        $this->assertSame('pending_approval', $row->status->value);
        $this->assertTrue($row->is_off_schedule);
        $this->assertSame($pagi->id, $row->shift_id);
    }

    public function test_sch07_overnight_on_time_and_checkout_next_morning(): void
    {
        $malam = $this->shift('Malam', '22:00', '06:00');
        $worker = $this->freelancer();
        $this->schedule($worker, '2026-11-18', $malam);

        $this->travelTo(Carbon::parse('2026-11-18 22:20'));
        $this->hit($worker, 'check-in')->assertSuccessful();
        $this->travelTo(Carbon::parse('2026-11-19 06:05'));
        $out = $this->hit($worker, 'check-out');
        $row = $this->row($worker, '2026-11-18');
        dump(['sch07' => [$row->status->value, $row->late_minutes, $out->status(), $row->check_out?->toDateTimeString()]]);
    }

    public function test_sch08_copy_week_includes_sunday(): void
    {
        $pagi = $this->shift('Pagi', '08:00', '16:00');
        $worker = $this->freelancer();
        $admin = User::factory()->create();
        $admin->assignRole('Superadmin');
        foreach (['2026-11-16', '2026-11-22'] as $date) {
            $this->schedule($worker, $date, $pagi);
        }

        $result = app(ScheduleCopyService::class)->copyWeek($admin, Carbon::parse('2026-11-16'), Carbon::parse('2026-11-23'));
        dump(['sch08' => $result, 'sunday' => ShiftSchedule::query()->whereDate('date', '2026-11-29')->exists()]);
    }
}
