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
use App\Models\ShiftSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * BUG-07: a check-in on a scheduled day off must not be stored as an ordinary "Tepat Waktu" working day.
 */
class Probe_checkout_mobile_BUG07Test extends TestCase
{
    use RefreshDatabase;

    public function test_check_in_on_scheduled_day_off_is_not_reported_as_on_time(): void
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

        $this->travelTo(Carbon::parse('2026-11-16 10:00'));
        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => '2026-11-16', 'is_day_off' => true]);

        $this->actingAs($user);
        $cover = Shift::create(['company_id' => $company->id, 'name' => 'Siang', 'code' => 'SG', 'start_time' => '10:00', 'end_time' => '18:00', 'late_tolerance_minutes' => 0]);
        Livewire::test(AbsenHariIni::class)->set('offScheduleShiftId', (string) $cover->id)->call('processCheckIn');

        $attendance = Attendance::where('employee_id', $employee->id)->firstOrFail();
        $this->assertNotNull($attendance->check_in, 'check-in was not recorded');
        $this->assertSame(0, $attendance->late_minutes);

        $this->assertNotSame(
            AttendanceStatus::OnTime,
            $attendance->status,
            'Checked in at 10:00 on a scheduled day off, yet stored as "Tepat Waktu" like a normal working day.'
        );
    }
}
