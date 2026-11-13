<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Filament\Pages\KalenderCuti;
use App\Filament\Pages\LaporanAbsensi;
use App\Filament\Resources\Attendances\AttendanceResource;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\EmployeeResource;
use App\Filament\Resources\FreelanceResource;
use App\Filament\Resources\FreelanceResource\Pages\ListFreelancers;
use App\Filament\Resources\InternResource;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Resources\Shifts\Pages\ManageShifts;
use App\Filament\Resources\Shifts\ShiftResource;
use App\Filament\Resources\ShiftSchedules\Pages\ManageShiftSchedules;
use App\Filament\Resources\ShiftSchedules\ShiftScheduleResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Widgets\OutletTodayWidget;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Services\DataAccessService;
use App\Services\OutletRosterService;
use App\Services\ShiftAssignmentService;
use App\Services\WeeklyScheduleService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CoordinatorAccessTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $coordinator;

    private Division $jchicken;

    private Division $momoyo;

    private CompanyLocation $jchickenOutlet;

    private CompanyLocation $momoyoOutlet;

    private Shift $jchickenMorning;

    private Shift $momoyoMorning;

    private Shift $companyWide;

    private Freelancer $bayu;

    private Freelancer $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Superadmin', 'Admin', 'Koordinator', 'Freelancer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->company = Company::create(['name' => 'PT Franchise', 'code' => 'PFC', 'is_active' => true]);
        $this->jchicken = Division::create(['company_id' => $this->company->id, 'name' => 'JCHICKEN', 'is_active' => true]);
        $luuca = Division::create(['company_id' => $this->company->id, 'name' => 'LUUCA', 'is_active' => true]);
        $this->momoyo = Division::create(['company_id' => $this->company->id, 'name' => 'MOMOYO', 'is_active' => true]);
        $jchickenCiledug = Division::create(['company_id' => $this->company->id, 'parent_id' => $this->jchicken->id, 'name' => 'JCHICKEN CILEDUG', 'is_active' => true]);

        $this->jchickenOutlet = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $this->jchicken->id, 'name' => 'JChicken Ciledug', 'latitude' => -6.2, 'longitude' => 106.7]);
        $this->momoyoOutlet = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $this->momoyo->id, 'name' => 'Momoyo Ciledug', 'latitude' => -6.2, 'longitude' => 106.7]);

        $this->jchickenMorning = Shift::create(['company_id' => $this->company->id, 'division_id' => $this->jchicken->id, 'name' => 'Pagi', 'code' => 'P', 'start_time' => '08:00', 'end_time' => '17:00']);
        $this->momoyoMorning = Shift::create(['company_id' => $this->company->id, 'division_id' => $this->momoyo->id, 'name' => 'Pagi', 'code' => 'P', 'start_time' => '08:30', 'end_time' => '17:30']);
        $this->companyWide = Shift::create(['company_id' => $this->company->id, 'name' => 'Siang', 'start_time' => '13:00', 'end_time' => '22:00']);

        $this->coordinator = User::factory()->create(['name' => 'Loocita']);
        $this->coordinator->assignRole('Koordinator');
        $this->coordinator->grantDataAccess(DataScope::Divisions, divisionIds: [$this->jchicken->id, $luuca->id]);

        $this->bayu = Freelancer::create(['company_id' => $this->company->id, 'division_id' => $jchickenCiledug->id, 'full_name' => 'Bayu Adillah', 'status' => 'active']);
        $this->outsider = Freelancer::create(['company_id' => $this->company->id, 'division_id' => $this->momoyo->id, 'full_name' => 'Orang Momoyo', 'status' => 'active']);

        $this->actingAs($this->coordinator);
    }

    public function test_menu_only_holds_what_a_coordinator_needs(): void
    {
        $this->assertTrue(FreelanceResource::canViewAny());
        $this->assertTrue(ShiftScheduleResource::canViewAny());
        $this->assertTrue(ShiftResource::canViewAny());
        $this->assertTrue(AttendanceResource::canViewAny());

        $this->assertFalse(EmployeeResource::canViewAny());
        $this->assertFalse(InternResource::canViewAny());
        $this->assertFalse(UserResource::canViewAny());
        $this->assertFalse(CompanyResource::canViewAny());
        $this->assertFalse(LeaveRequestResource::shouldRegisterNavigation());
        $this->assertFalse(LaporanAbsensi::shouldRegisterNavigation());
        $this->assertFalse(KalenderCuti::shouldRegisterNavigation());
        $this->assertSame([], DataAccessService::manageableCompanyIds($this->coordinator), 'No company settings.');
    }

    public function test_admins_keep_their_full_menu(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $admin->grantDataAccess(DataScope::Divisions, divisionIds: [$this->jchicken->id]);
        $this->actingAs($admin);

        $this->assertTrue(EmployeeResource::canViewAny());
        $this->assertTrue(LeaveRequestResource::shouldRegisterNavigation());
        $this->assertFalse(ShiftResource::canViewAny(), 'Division admins still have no shift settings.');
    }

    public function test_coordinator_manages_only_the_shifts_of_their_brands(): void
    {
        $this->assertSame([$this->jchickenMorning->id], ShiftResource::getEloquentQuery()->pluck('id')->all());
        $this->assertSame([$this->jchicken->id], array_values(array_intersect(array_keys(ShiftResource::brandOptions($this->company->id)), [$this->jchicken->id, $this->momoyo->id])));

        $form = ['company_id' => $this->company->id, 'name' => 'Sore Closing', 'start_time' => '17:00', 'end_time' => '22:30', 'is_active' => true];

        Livewire::test(ManageShifts::class)
            ->callAction('create', data: [...$form, 'division_id' => $this->jchicken->id])
            ->assertHasNoFormErrors();

        Livewire::test(ManageShifts::class)
            ->callAction('create', data: [...$form, 'division_id' => $this->momoyo->id])
            ->assertHasFormErrors(['division_id']);

        Livewire::test(ManageShifts::class)
            ->callAction('create', data: [...$form, 'name' => 'Umum', 'division_id' => null])
            ->assertHasFormErrors(['division_id' => 'required']);

        $this->assertSame(1, Shift::where('name', 'Sore Closing')->count());
    }

    public function test_default_shift_and_placement_options_stay_inside_the_brands_but_scheduling_may_lend_a_worker(): void
    {
        // Placing a worker (default shift, home outlet) stays inside the brands.
        $this->assertSame([$this->jchickenOutlet->id], array_keys(ShiftScheduleResource::locationOptions($this->company->id)));
        $this->assertEqualsCanonicalizing([$this->jchickenMorning->id, $this->companyWide->id], array_keys(ShiftScheduleResource::shiftOptions($this->company->id)));
        $this->assertSame(["freelancer:{$this->bayu->id}"], array_keys(ShiftScheduleResource::workerOptions($this->company->id)['Freelancer']));

        // Scheduling a day may lend the worker to another brand's outlet and shift.
        $this->assertEqualsCanonicalizing([$this->jchickenOutlet->id, $this->momoyoOutlet->id], array_keys(ShiftScheduleResource::locationOptions($this->company->id, borrowing: true)));
        $this->assertContains($this->momoyoMorning->id, array_keys(ShiftScheduleResource::shiftOptions($this->company->id, borrowing: true)));

        Livewire::test(ManageShiftSchedules::class)->instance()->assignSchedule([
            'company_id' => $this->company->id,
            'workers' => ["freelancer:{$this->bayu->id}"],
            'date_from' => today()->toDateString(),
            'date_until' => today()->toDateString(),
            'weekdays' => [1, 2, 3, 4, 5, 6, 7],
            'mode' => 'shift',
            'shift_id' => $this->momoyoMorning->id,
            'company_location_id' => $this->momoyoOutlet->id,
            'overwrite' => true,
        ]);

        $schedule = ShiftSchedule::query()->firstOrFail();
        $this->assertSame([$this->momoyoMorning->id, $this->momoyoOutlet->id], [$schedule->shift_id, $schedule->company_location_id]);
    }

    public function test_the_receiving_brand_sees_a_lent_worker_but_cannot_change_the_schedule(): void
    {
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => today(), 'shift_id' => $this->momoyoMorning->id, 'company_location_id' => $this->momoyoOutlet->id]);
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => today()->addDay(), 'shift_id' => $this->jchickenMorning->id, 'company_location_id' => $this->jchickenOutlet->id]);

        $momoyoCoordinator = User::factory()->create(['name' => 'Koordinator Momoyo']);
        $momoyoCoordinator->assignRole('Koordinator');
        $momoyoCoordinator->grantDataAccess(DataScope::Divisions, divisionIds: [$this->momoyo->id]);

        $roster = app(OutletRosterService::class)->roster($momoyoCoordinator, today());
        $this->assertSame('Momoyo Ciledug', $roster[0]['location']['name']);
        $this->assertSame('Bayu Adillah', $roster[0]['entries'][0]['worker']['name']);
        $this->assertSame('JCHICKEN CILEDUG', $roster[0]['entries'][0]['borrowed_from']);

        $this->assertSame([], app(OutletRosterService::class)->roster($momoyoCoordinator, today()->addDay()), 'Only the days spent at their outlets.');

        $this->expectException(AuthorizationException::class);
        app(ShiftAssignmentService::class)->assignDay($momoyoCoordinator, $this->bayu, today()->toDateString(), 'off');
    }

    public function test_roster_and_week_load_several_lent_workers_without_lazy_loading(): void
    {
        // Laravel only enforces lazy-loading rules on results with more than one model: use several.
        $second = Freelancer::create(['company_id' => $this->company->id, 'division_id' => $this->jchicken->id, 'full_name' => 'Kedua Dipinjam', 'status' => 'active']);

        foreach ([$this->bayu, $second] as $worker) {
            ShiftSchedule::create(['freelancer_id' => $worker->id, 'date' => today(), 'shift_id' => $this->momoyoMorning->id, 'company_location_id' => $this->momoyoOutlet->id]);
        }

        $roster = app(OutletRosterService::class)->roster($this->coordinator, today());
        $this->assertCount(2, $roster[0]['entries']);
        $brands = array_column($roster[0]['entries'], 'borrowed_from');
        sort($brands);
        $this->assertSame(['JCHICKEN', 'JCHICKEN CILEDUG'], $brands);

        $week = app(WeeklyScheduleService::class)->week($this->coordinator, today());
        $this->assertCount(2, $week['workers']);
        $this->assertNotNull($week['workers'][0]['days'][today()->toDateString()]['borrowed_from']);

    }

    public function test_freelancer_list_and_contract_actions_stay_inside_the_brands(): void
    {
        Livewire::test(ListFreelancers::class)
            ->assertCanSeeTableRecords([$this->bayu])
            ->assertCanNotSeeTableRecords([$this->outsider])
            ->callAction(TestAction::make('endContract')->table($this->bayu))
            ->assertNotified('Kontrak diakhiri');

        $this->assertSame('completed', $this->bayu->fresh()->status);
    }

    public function test_dashboard_shows_today_at_the_coordinator_outlets(): void
    {
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => today(), 'shift_id' => $this->jchickenMorning->id, 'company_location_id' => $this->jchickenOutlet->id, 'section' => 'Kasir']);
        ShiftSchedule::create(['freelancer_id' => $this->outsider->id, 'date' => today(), 'shift_id' => $this->momoyoMorning->id, 'company_location_id' => $this->momoyoOutlet->id]);
        Attendance::create(['freelancer_id' => $this->bayu->id, 'date' => today(), 'check_in' => today()->setTime(8, 20), 'status' => 'late', 'late_minutes' => 20]);

        Livewire::test(OutletTodayWidget::class)
            ->assertSee('JChicken Ciledug')
            ->assertSee('Bayu Adillah')
            ->assertSee('Terlambat')
            ->assertSee('1 terjadwal')
            ->assertDontSee('Orang Momoyo');
    }
}
