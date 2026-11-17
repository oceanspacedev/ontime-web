<?php

namespace Tests\Feature\QaProbe;

use App\Filament\Pages\AbsenHariIni;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\Shift;
use App\Models\User;
use App\Services\AttendanceCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CheckoutProbeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Division $division;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'Employee']);
        $this->company = Company::create(['name' => 'PT P', 'code' => 'PP', 'is_active' => true]);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Ops', 'is_active' => true]);
    }

    private function emp(string $start, string $end): Employee
    {
        $shift = Shift::create(['company_id' => $this->company->id, 'name' => 'S', 'code' => 'S'.rand(1, 99999), 'start_time' => $start, 'end_time' => $end, 'late_tolerance_minutes' => 0]);
        $user = User::factory()->create();
        $user->assignRole('Employee');

        return Employee::create(['user_id' => $user->id, 'company_id' => $this->company->id, 'division_id' => $this->division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Staff'])->id,
            'nip' => 'N'.$user->id, 'full_name' => 'E'.$user->id, 'status' => 'active', 'shift_id' => $shift->id]);
    }

    public function test_early_leave_stays_on_time(): void
    {
        $this->travelTo(Carbon::parse('2026-11-16 08:00'));
        $e = $this->emp('08:00', '17:00');
        $this->actingAs($e->user);
        Livewire::test(AbsenHariIni::class)->call('processCheckIn');
        $this->travelTo(Carbon::parse('2026-11-16 09:00'));
        Livewire::test(AbsenHariIni::class)->call('processCheckOut');
        $a = Attendance::where('employee_id', $e->id)->firstOrFail();
        dump(['early_leave' => $a->status->value, 'late' => $a->late_minutes, 'worked' => $a->workedMinutes()]);
        $this->assertTrue(true);
    }

    public function test_overnight_check_in_after_midnight(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17 00:30'));
        $e = $this->emp('22:00', '06:00');
        $this->actingAs($e->user);
        Livewire::test(AbsenHariIni::class)->call('processCheckIn');
        $a = Attendance::where('employee_id', $e->id)->firstOrFail();
        dump(['overnight_late_after_midnight' => $a->status->value, 'late' => $a->late_minutes, 'date' => $a->date->toDateString()]);
        $this->assertTrue(true);
    }

    public function test_early_check_in_hours_before(): void
    {
        $this->travelTo(Carbon::parse('2026-11-16 06:05'));
        $e = $this->emp('22:00', '06:00');
        $this->actingAs($e->user);
        Livewire::test(AbsenHariIni::class)->call('processCheckIn');
        // Decision: check-in opens 60 minutes before the shift start, so a check-in 16h early is refused and
        // leaves no row (it used to be recorded and then blocked the real check-in at 22:00).
        $this->assertSame(0, Attendance::where('employee_id', $e->id)->count(), 'A check-in 16h before the shift is refused.');
        $this->travelTo(Carbon::parse('2026-11-16 22:00'));
        Livewire::test(AbsenHariIni::class)->call('processCheckIn');
        $this->assertSame(1, Attendance::where('employee_id', $e->id)->count());
        $this->assertSame('2026-11-16 22:00:00', (string) Attendance::where('employee_id', $e->id)->first()->check_in);
    }

    public function test_day_shift_forgot_checkout_next_day(): void
    {
        $this->travelTo(Carbon::parse('2026-11-16 15:00'));
        $e = $this->emp('15:00', '23:00');
        $this->actingAs($e->user);
        Livewire::test(AbsenHariIni::class)->call('processCheckIn');
        $this->travelTo(Carbon::parse('2026-11-17 01:00'));
        $svc = app(AttendanceCheckService::class);
        dump(['overnight_open' => $svc->openOvernightAttendance($e->user)?->id]);
        $resp = $this->actingAs($e->user, 'sanctum')->getJson('/api/v1/attendance/today');
        dump(['today_has_checked_in' => $resp->json('has_checked_in'), 'status' => $resp->json('attendance.status')]);
        Livewire::test(AbsenHariIni::class)->call('processCheckIn');
        $rows = Attendance::where('employee_id', $e->id)->orderBy('date')->get(['date', 'check_in', 'check_out', 'status', 'late_minutes']);
        dump($rows->map(fn ($r) => [$r->date->toDateString(), (string) $r->check_in, (string) $r->check_out, $r->status->value, $r->late_minutes])->all());
        $this->assertTrue(true);
    }
}
