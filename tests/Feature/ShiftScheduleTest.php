<?php

namespace Tests\Feature;

use App\Filament\Pages\AbsenHariIni;
use App\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use App\Filament\Resources\Shifts\ShiftResource;
use App\Filament\Resources\ShiftSchedules\Pages\ManageShiftSchedules;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Intern;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Services\WorkScheduleService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ShiftScheduleTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Division $division;

    private Shift $morning;

    private Shift $afternoon;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Superadmin', 'Admin', 'Employee', 'Intern'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->company = Company::create(['name' => 'Toko Retail', 'code' => 'TR', 'is_active' => true]);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Toko', 'is_active' => true]);
        $this->morning = Shift::create(['company_id' => $this->company->id, 'name' => 'Pagi', 'start_time' => '07:00', 'end_time' => '15:00', 'late_tolerance_minutes' => 5]);
        $this->afternoon = Shift::create(['company_id' => $this->company->id, 'name' => 'Siang', 'start_time' => '13:00', 'end_time' => '21:00']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        $this->grantDataAccessTo($this->admin, $this->company->id);
    }

    public function test_working_hours_come_from_schedule_then_default_shift_then_policy(): void
    {
        $service = app(WorkScheduleService::class);
        $employee = $this->makeEmployee();
        $date = today();

        $this->assertSame('policy', $service->resolve($employee, $date)['source']);

        $employee->update(['shift_id' => $this->morning->id]);
        $hours = $service->resolve($employee->fresh(), $date);
        $this->assertSame(['default', '07:00:00', 5], [$hours['source'], substr($hours['start_time'], 0, 5).':00', $hours['late_tolerance_minutes']]);

        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => $date, 'shift_id' => $this->afternoon->id]);
        $hours = $service->resolve($employee->fresh(), $date);
        $this->assertSame(['schedule', 'Siang', 15], [$hours['source'], $hours['shift']->name, $hours['late_tolerance_minutes']], 'A shift without tolerance follows the company policy.');

        ShiftSchedule::where('employee_id', $employee->id)->update(['is_day_off' => true, 'shift_id' => null]);
        $this->assertTrue($service->resolve($employee->fresh(), $date)['is_day_off']);
    }

    public function test_check_in_lateness_follows_the_shift(): void
    {
        $this->travelTo(today()->setTime(8, 0));

        $employee = $this->makeEmployee(['shift_id' => $this->morning->id]);
        $this->actingAs($employee->user);

        Livewire::test(AbsenHariIni::class)->call('processCheckIn');

        $attendance = Attendance::where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame('late', $attendance->status->value);
        $this->assertSame(60, $attendance->late_minutes);
        $this->assertSame($this->morning->id, $attendance->shift_id);
    }

    public function test_afternoon_shift_is_on_time_in_the_morning(): void
    {
        $this->travelTo(today()->setTime(12, 50));

        $employee = $this->makeEmployee();
        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => today(), 'shift_id' => $this->afternoon->id]);
        $this->actingAs($employee->user);

        Livewire::test(AbsenHariIni::class)->call('processCheckIn');

        $attendance = Attendance::where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame('on_time', $attendance->status->value);
        $this->assertSame(0, $attendance->late_minutes);
    }

    public function test_check_in_on_a_scheduled_day_off_is_never_late(): void
    {
        $this->travelTo(today()->setTime(11, 0));

        $employee = $this->makeEmployee(['shift_id' => $this->morning->id]);
        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => today(), 'is_day_off' => true]);
        $this->actingAs($employee->user);

        Livewire::test(AbsenHariIni::class)->call('processCheckIn');

        $this->assertSame(0, Attendance::where('employee_id', $employee->id)->firstOrFail()->late_minutes);
    }

    public function test_company_admin_assigns_schedules_in_bulk(): void
    {
        $employee = $this->makeEmployee();
        $intern = Intern::create(['company_id' => $this->company->id, 'division_id' => $this->division->id, 'nis' => 'MG-TR', 'full_name' => 'Magang Toko', 'institution' => 'SMK', 'status' => 'active']);
        $monday = today()->startOfWeek();

        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => $monday, 'shift_id' => $this->afternoon->id]);

        $this->actingAs($this->admin);

        Livewire::test(ManageShiftSchedules::class)
            ->callAction('assignSchedule', data: [
                'company_id' => $this->company->id,
                'workers' => ["employee:{$employee->id}", "intern:{$intern->id}"],
                'date_from' => $monday->toDateString(),
                'date_until' => $monday->copy()->addDays(6)->toDateString(),
                'mode' => 'shift',
                'shift_id' => $this->morning->id,
                'overwrite' => false,
            ])
            ->assertHasNoFormErrors()
            ->assertNotified('Jadwal shift disimpan');

        $this->assertSame(7, ShiftSchedule::where('intern_id', $intern->id)->where('shift_id', $this->morning->id)->count());
        $this->assertSame(6, ShiftSchedule::where('employee_id', $employee->id)->where('shift_id', $this->morning->id)->count());
        $this->assertSame($this->afternoon->id, ShiftSchedule::where('employee_id', $employee->id)->whereDate('date', $monday)->value('shift_id'), 'Existing entries are kept when overwrite is off.');
    }

    public function test_bulk_assign_only_fills_the_chosen_weekdays(): void
    {
        $employee = $this->makeEmployee();
        $monday = today()->startOfWeek();

        $this->actingAs($this->admin);

        Livewire::test(ManageShiftSchedules::class)->instance()->assignSchedule([
            'company_id' => $this->company->id,
            'workers' => ["employee:{$employee->id}"],
            'date_from' => $monday->toDateString(),
            'date_until' => $monday->copy()->addDays(13)->toDateString(),
            'weekdays' => ['6', '7'],
            'mode' => 'day_off',
            'overwrite' => true,
        ]);

        $dates = ShiftSchedule::where('employee_id', $employee->id)->pluck('date');

        $this->assertCount(4, $dates);
        $this->assertTrue($dates->every(fn ($date): bool => $date->isWeekend()));
    }

    public function test_bulk_assign_ignores_workers_of_other_companies(): void
    {
        $other = Company::create(['name' => 'Lain', 'code' => 'LN', 'is_active' => true]);
        $outsider = $this->makeEmployee([], $other);
        $employee = $this->makeEmployee();

        $this->actingAs($this->admin);

        Livewire::test(ManageShiftSchedules::class)->instance()->assignSchedule([
            'company_id' => $this->company->id,
            'workers' => ["employee:{$employee->id}", "employee:{$outsider->id}"],
            'date_from' => today()->toDateString(),
            'date_until' => today()->toDateString(),
            'weekdays' => [1, 2, 3, 4, 5, 6, 7],
            'mode' => 'day_off',
            'overwrite' => true,
        ]);

        $this->assertTrue(ShiftSchedule::where('employee_id', $employee->id)->value('is_day_off'));
        $this->assertFalse(ShiftSchedule::where('employee_id', $outsider->id)->exists());
    }

    public function test_shift_in_use_cannot_be_deleted(): void
    {
        $this->makeEmployee(['shift_id' => $this->morning->id]);

        $this->assertFalse($this->morning->delete());
        $this->assertNotNull($this->morning->deletionBlockedReason());
        $this->assertTrue(Shift::create(['company_id' => $this->company->id, 'name' => 'Cadangan', 'start_time' => '09:00', 'end_time' => '17:00'])->delete());
    }

    public function test_default_shift_bulk_action_only_touches_the_shift_company(): void
    {
        $employee = $this->makeEmployee();
        $other = Company::create(['name' => 'Lain', 'code' => 'LN', 'is_active' => true]);
        $outsider = $this->makeEmployee([], $other);

        $superadmin = User::factory()->create();
        $superadmin->assignRole('Superadmin');
        $this->actingAs($superadmin);

        Livewire::test(ListEmployees::class)
            ->selectTableRecords([$employee->id, $outsider->id])
            ->callAction(TestAction::make('assignDefaultShift')->table()->bulk(), data: ['shift_id' => $this->morning->id]);

        $this->assertSame($this->morning->id, $employee->fresh()->shift_id);
        $this->assertNull($outsider->fresh()->shift_id);
    }

    public function test_shift_list_is_limited_to_managed_companies(): void
    {
        $other = Company::create(['name' => 'Lain', 'code' => 'LN', 'is_active' => true]);
        Shift::create(['company_id' => $other->id, 'name' => 'Malam', 'start_time' => '22:00', 'end_time' => '06:00']);

        $this->actingAs($this->admin);

        $this->assertEqualsCanonicalizing([$this->morning->id, $this->afternoon->id], ShiftResource::getEloquentQuery()->pluck('id')->all());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeEmployee(array $attributes = [], ?Company $company = null): Employee
    {
        $company ??= $this->company;
        $user = User::factory()->create();
        $user->assignRole('Employee');

        return Employee::create($attributes + [
            'user_id' => $user->id,
            'company_id' => $company->id,
            'division_id' => Division::firstOrCreate(['company_id' => $company->id, 'name' => 'Toko'], ['is_active' => true])->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Kasir'])->id,
            'nip' => 'TR-'.$user->id,
            'full_name' => "Kasir {$user->id}",
            'status' => 'active',
        ]);
    }
}
