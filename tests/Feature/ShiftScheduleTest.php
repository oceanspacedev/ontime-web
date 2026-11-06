<?php

namespace Tests\Feature;

use App\Filament\Pages\AbsenHariIni;
use App\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use App\Filament\Resources\Shifts\Pages\ManageShifts;
use App\Filament\Resources\Shifts\ShiftResource;
use App\Filament\Resources\ShiftSchedules\Pages\ManageShiftSchedules;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Services\Attendance\AttendanceCheckInput;
use App\Services\AttendanceCheckService;
use App\Services\WorkScheduleService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
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

    public function test_shift_names_repeat_across_brands_but_not_within_one(): void
    {
        $jchicken = Division::create(['company_id' => $this->company->id, 'name' => 'JCHICKEN', 'is_active' => true]);
        $luuca = Division::create(['company_id' => $this->company->id, 'name' => 'LUUCA', 'is_active' => true]);
        $form = ['company_id' => $this->company->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '17:00', 'is_active' => true];

        $this->actingAs($this->admin);

        Livewire::test(ManageShifts::class)
            ->callAction('create', data: [...$form, 'division_id' => $jchicken->id])
            ->assertHasNoFormErrors();

        Livewire::test(ManageShifts::class)
            ->callAction('create', data: [...$form, 'division_id' => $luuca->id, 'start_time' => '09:00', 'end_time' => '18:00'])
            ->assertHasNoFormErrors();

        Livewire::test(ManageShifts::class)
            ->callAction('create', data: [...$form, 'division_id' => $luuca->id])
            ->assertHasFormErrors(['name' => 'unique']);

        Livewire::test(ManageShifts::class)
            ->callAction('create', data: [...$form, 'division_id' => null])
            ->assertHasFormErrors(['name' => 'unique']); // The company-wide "Pagi" from setUp still blocks a second one.

        $this->assertSame(['JCHICKEN · Pagi (08:00 - 17:00)', 'LUUCA · Pagi (09:00 - 18:00)'], Shift::whereNotNull('division_id')->orderBy('division_id')->get()->map->label()->all());
    }

    public function test_freelancer_is_scheduled_at_another_brand_outlet_with_a_section(): void
    {
        $jchicken = Division::create(['company_id' => $this->company->id, 'name' => 'JCHICKEN CILEDUG', 'is_active' => true]);
        $momoyo = Division::create(['company_id' => $this->company->id, 'name' => 'MOMOYO', 'is_active' => true]);
        $housekeeping = Shift::create(['company_id' => $this->company->id, 'division_id' => $momoyo->id, 'name' => 'Housekeeping', 'start_time' => '10:00', 'end_time' => '19:00']);
        $outlet = CompanyLocation::create(['company_id' => $this->company->id, 'name' => 'Momoyo Ciledug', 'latitude' => -6.2297, 'longitude' => 106.7112]);
        $freelancer = Freelancer::create(['company_id' => $this->company->id, 'division_id' => $jchicken->id, 'freelancer_number' => 'FL-1', 'full_name' => 'Bayu Adillah', 'status' => 'active']);
        $monday = today()->startOfWeek();

        $this->actingAs($this->admin);

        Livewire::test(ManageShiftSchedules::class)
            ->callAction('assignSchedule', data: [
                'company_id' => $this->company->id,
                'workers' => ["freelancer:{$freelancer->id}"],
                'date_from' => $monday->toDateString(),
                'date_until' => $monday->copy()->addDays(2)->toDateString(),
                'mode' => 'shift',
                'shift_id' => $housekeeping->id,
                'company_location_id' => $outlet->id,
                'section' => ' HK ',
                'overwrite' => true,
            ])
            ->assertHasNoFormErrors()
            ->assertNotified('Jadwal shift disimpan');

        $this->assertSame(3, ShiftSchedule::where('freelancer_id', $freelancer->id)->where('shift_id', $housekeeping->id)->where('company_location_id', $outlet->id)->where('section', 'HK')->count());

        $hours = app(WorkScheduleService::class)->resolve($freelancer, $monday);
        $this->assertSame([$outlet->id, 'HK', '10:00'], [$hours['location']?->id, $hours['section'], substr($hours['start_time'], 0, 5)]);
    }

    public function test_bulk_assign_rejects_an_outlet_of_another_company(): void
    {
        $other = Company::create(['name' => 'Lain', 'code' => 'LN', 'is_active' => true]);
        $foreignOutlet = CompanyLocation::create(['company_id' => $other->id, 'name' => 'Outlet Lain', 'latitude' => -6.2, 'longitude' => 106.8]);
        $employee = $this->makeEmployee();

        $this->actingAs($this->admin);

        try {
            Livewire::test(ManageShiftSchedules::class)->instance()->assignSchedule([
                'company_id' => $this->company->id,
                'workers' => ["employee:{$employee->id}"],
                'date_from' => today()->toDateString(),
                'date_until' => today()->toDateString(),
                'weekdays' => [1, 2, 3, 4, 5, 6, 7],
                'mode' => 'shift',
                'shift_id' => $this->morning->id,
                'company_location_id' => $foreignOutlet->id,
                'overwrite' => true,
            ]);
            $this->fail('An outlet of another company must be refused.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $this->assertFalse(ShiftSchedule::where('employee_id', $employee->id)->exists());
    }

    public function test_day_off_clears_outlet_and_section(): void
    {
        $outlet = CompanyLocation::create(['company_id' => $this->company->id, 'name' => 'Outlet', 'latitude' => -6.2, 'longitude' => 106.8]);
        $employee = $this->makeEmployee();

        $schedule = ShiftSchedule::create(['employee_id' => $employee->id, 'date' => today(), 'shift_id' => $this->morning->id, 'company_location_id' => $outlet->id, 'section' => 'Kasir']);
        $schedule->update(['is_day_off' => true]);

        $this->assertSame([null, null, null], [$schedule->fresh()->shift_id, $schedule->fresh()->company_location_id, $schedule->fresh()->section]);
    }

    public function test_check_in_only_accepts_the_scheduled_outlet_and_records_it(): void
    {
        $this->travelTo(today()->setTime(9, 55));
        $this->company->policy->update(['require_gps' => true, 'geofence_radius_meters' => 100]);

        $jchicken = CompanyLocation::create(['company_id' => $this->company->id, 'name' => 'JChicken Ciledug', 'latitude' => -6.2297000, 'longitude' => 106.7112000]);
        $momoyoBekasi = CompanyLocation::create(['company_id' => $this->company->id, 'name' => 'Momoyo Bekasi', 'latitude' => -6.2383000, 'longitude' => 106.9756000]);
        $atJchicken = new AttendanceCheckInput(latitude: -6.2297000, longitude: 106.7112000);

        $scheduledHere = $this->makeEmployee();
        ShiftSchedule::create(['employee_id' => $scheduledHere->id, 'date' => today(), 'shift_id' => $this->morning->id, 'company_location_id' => $jchicken->id]);

        $scheduledElsewhere = $this->makeEmployee();
        ShiftSchedule::create(['employee_id' => $scheduledElsewhere->id, 'date' => today(), 'shift_id' => $this->morning->id, 'company_location_id' => $momoyoBekasi->id]);

        $unplaced = $this->makeEmployee();

        $service = app(AttendanceCheckService::class);

        $here = $service->checkIn($scheduledHere->user, $atJchicken);
        $this->assertFalse($here->requiresApproval);
        $this->assertSame($jchicken->id, $here->attendance->company_location_id);

        $elsewhere = $service->checkIn($scheduledElsewhere->user, $atJchicken);
        $this->assertTrue($elsewhere->requiresApproval);
        $this->assertSame(['di luar area outlet jadwal (Momoyo Bekasi)'], $elsewhere->approvalReasons);
        $this->assertSame($momoyoBekasi->id, $elsewhere->attendance->company_location_id);

        $free = $service->checkIn($unplaced->user, $atJchicken);
        $this->assertFalse($free->requiresApproval, 'Without a scheduled outlet any company location is accepted.');
        $this->assertNull($free->attendance->company_location_id);
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
