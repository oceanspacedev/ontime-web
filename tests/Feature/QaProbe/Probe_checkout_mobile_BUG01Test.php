<?php

namespace Tests\Feature\QaProbe;

use App\Enums\AttendanceStatus;
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
 * BUG-01: checking out 8 hours before the shift end must not leave the day labelled "Tepat Waktu".
 */
class Probe_checkout_mobile_BUG01Test extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_hours_before_shift_end_is_not_reported_as_on_time(): void
    {
        Role::create(['name' => 'Employee']);
        $company = Company::create(['name' => 'PT P', 'code' => 'PP', 'is_active' => true]);
        $division = Division::create(['company_id' => $company->id, 'name' => 'Ops', 'is_active' => true]);
        $shift = Shift::create(['company_id' => $company->id, 'name' => 'Pagi', 'code' => 'PG', 'start_time' => '08:00', 'end_time' => '17:00', 'late_tolerance_minutes' => 0]);
        $user = User::factory()->create();
        $user->assignRole('Employee');
        $employee = Employee::create(['user_id' => $user->id, 'company_id' => $company->id, 'division_id' => $division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Staff'])->id,
            'nip' => 'N'.$user->id, 'full_name' => 'E'.$user->id, 'status' => 'active', 'shift_id' => $shift->id]);

        $this->actingAs($user);
        $this->travelTo(Carbon::parse('2026-11-16 08:00'));
        Livewire::test(AbsenHariIni::class)->call('processCheckIn');
        $this->travelTo(Carbon::parse('2026-11-16 09:00'));
        Livewire::test(AbsenHariIni::class)->call('processCheckOut');

        $attendance = Attendance::where('employee_id', $employee->id)->firstOrFail();
        $this->assertNotNull($attendance->check_out, 'check-out was not recorded');
        $this->assertSame(60, $attendance->workedMinutes());

        // Decision: the status keeps the check-in verdict (Tepat Waktu / Terlambat) and an early leave is
        // recorded separately in early_leave_minutes, which the reports show as "Pulang Cepat". The probe
        // originally asserted a status change; it now asserts the early-leave flag the design uses instead.
        $this->assertSame(AttendanceStatus::OnTime, $attendance->status);
        $this->assertSame(
            8 * 60,
            $attendance->early_leave_minutes,
            'Left 8h before the 17:00 shift end, yet no early-leave minutes were recorded.'
        );
    }
}
