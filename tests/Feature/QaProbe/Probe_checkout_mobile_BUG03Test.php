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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * BUG-03: segment 1 has no earliest check-in window. A night worker (22:00-06:00) who closes last
 * night's shift at 06:00 and taps check-in at 06:05 gets an "on time" row for today, and the real
 * check-in at 22:00 is then refused.
 */
class Probe_checkout_mobile_BUG03Test extends TestCase
{
    use RefreshDatabase;

    public function test_check_in_sixteen_hours_before_night_shift_does_not_consume_the_day(): void
    {
        Role::create(['name' => 'Employee']);
        $company = Company::create(['name' => 'PT P', 'code' => 'PP', 'is_active' => true]);
        $division = Division::create(['company_id' => $company->id, 'name' => 'Ops', 'is_active' => true]);
        $shift = Shift::create(['company_id' => $company->id, 'name' => 'Malam', 'code' => 'MLM', 'start_time' => '22:00', 'end_time' => '06:00', 'late_tolerance_minutes' => 0]);
        $user = User::factory()->create();
        $user->assignRole('Employee');
        $employee = Employee::create(['user_id' => $user->id, 'company_id' => $company->id, 'division_id' => $division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Staff'])->id,
            'nip' => 'N'.$user->id, 'full_name' => 'E'.$user->id, 'status' => 'active', 'shift_id' => $shift->id]);
        $this->actingAs($user);

        // Last night's shift: in at 22:00 on the 15th, out at 06:00 on the 16th.
        $this->travelTo(Carbon::parse('2026-11-15 22:00'));
        Livewire::test(AbsenHariIni::class)->call('processCheckIn');
        $this->travelTo(Carbon::parse('2026-11-16 06:00'));
        Livewire::test(AbsenHariIni::class)->call('processCheckOut');
        $this->assertNotNull(Attendance::where('employee_id', $employee->id)->whereDate('date', '2026-11-15')->value('check_out'));

        // Five minutes later the worker taps "Absen Masuk" again, 15h55m before tonight's shift.
        $this->travelTo(Carbon::parse('2026-11-16 06:05'));
        Livewire::test(AbsenHariIni::class)->call('processCheckIn');
        $early = Attendance::where('employee_id', $employee->id)->whereDate('date', '2026-11-16')->first();
        dump(['06:05' => $early ? [(string) $early->check_in, $early->status->value, $early->late_minutes] : null]);

        // The real shift start.
        $this->travelTo(Carbon::parse('2026-11-16 22:00'));
        Livewire::test(AbsenHariIni::class)->call('processCheckIn');
        $rows = Attendance::where('employee_id', $employee->id)->whereDate('date', '2026-11-16')->get();
        dump(['rows_16th' => $rows->map(fn ($r) => [$r->segment, (string) $r->check_in, $r->status->value])->all()]);

        // Business expectation: tonight's shift is recorded as starting at 22:00, not at 06:05.
        $this->assertNull($early?->check_in, 'A check-in 16 hours before the shift was accepted.');
        $this->assertSame('2026-11-16 22:00:00', (string) $rows->firstWhere('segment', 1)?->check_in);
    }
}
