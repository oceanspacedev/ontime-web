<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TeamApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $coordinator;

    private CompanyLocation $jchickenOutlet;

    private CompanyLocation $luucaOutlet;

    private CompanyLocation $momoyoOutlet;

    private Shift $jchickenMorning;

    private Shift $momoyoMorning;

    private Freelancer $bayu;

    private Freelancer $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        foreach (['Koordinator', 'Freelancer', 'Employee'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->company = Company::create(['name' => 'PT Franchise', 'code' => 'PFC', 'is_active' => true]);
        $jchicken = Division::create(['company_id' => $this->company->id, 'name' => 'JCHICKEN', 'is_active' => true]);
        $luuca = Division::create(['company_id' => $this->company->id, 'name' => 'LUUCA', 'is_active' => true]);
        $momoyo = Division::create(['company_id' => $this->company->id, 'name' => 'MOMOYO', 'is_active' => true]);

        $this->jchickenOutlet = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $jchicken->id, 'name' => 'JChicken Ciledug', 'latitude' => -6.2, 'longitude' => 106.7]);
        $this->luucaOutlet = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $luuca->id, 'name' => 'Luuca Ciledug', 'latitude' => -6.2, 'longitude' => 106.7]);
        $this->momoyoOutlet = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $momoyo->id, 'name' => 'Momoyo Ciledug', 'latitude' => -6.2, 'longitude' => 106.7]);

        $this->jchickenMorning = Shift::create(['company_id' => $this->company->id, 'division_id' => $jchicken->id, 'name' => 'Pagi', 'code' => 'P', 'start_time' => '08:00', 'end_time' => '17:00']);
        $this->momoyoMorning = Shift::create(['company_id' => $this->company->id, 'division_id' => $momoyo->id, 'name' => 'Pagi', 'code' => 'P', 'start_time' => '08:30', 'end_time' => '17:30']);

        $this->coordinator = User::factory()->create(['name' => 'Loocita']);
        $this->coordinator->assignRole('Koordinator');
        $this->coordinator->grantDataAccess(DataScope::Divisions, divisionIds: [$jchicken->id, $luuca->id]);

        $this->bayu = Freelancer::create(['company_id' => $this->company->id, 'division_id' => $jchicken->id, 'full_name' => 'Bayu Adillah', 'status' => 'active']);
        $this->outsider = Freelancer::create(['company_id' => $this->company->id, 'division_id' => $momoyo->id, 'full_name' => 'Orang Momoyo', 'status' => 'active']);

        Sanctum::actingAs($this->coordinator);
    }

    public function test_me_tells_the_app_this_user_runs_a_team(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.access.can_manage_team', true)
            ->assertJsonPath('user.access.is_coordinator', true);
    }

    public function test_workers_without_management_rights_get_no_team_endpoints(): void
    {
        $worker = User::factory()->create();
        $worker->assignRole('Employee');
        Sanctum::actingAs($worker);

        $this->getJson('/api/v1/auth/me')->assertJsonPath('user.access.can_manage_team', false);
        $this->getJson('/api/v1/team/roster')->assertForbidden();
        $this->postJson('/api/v1/team/freelancers', ['full_name' => 'X', 'phone' => '0812', 'company_location_id' => $this->jchickenOutlet->id])->assertForbidden();
    }

    public function test_roster_lists_today_per_outlet_inside_the_brands(): void
    {
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => today(), 'shift_id' => $this->jchickenMorning->id, 'company_location_id' => $this->jchickenOutlet->id, 'section' => 'Kasir']);
        ShiftSchedule::create(['freelancer_id' => $this->outsider->id, 'date' => today(), 'shift_id' => $this->momoyoMorning->id, 'company_location_id' => $this->momoyoOutlet->id]);
        Attendance::create(['freelancer_id' => $this->bayu->id, 'date' => today(), 'check_in' => today()->setTime(7, 55), 'status' => 'on_time']);

        $this->getJson('/api/v1/team/roster')
            ->assertOk()
            ->assertJsonPath('date', today()->toDateString())
            ->assertJsonCount(1, 'outlets')
            ->assertJsonPath('outlets.0.location.name', 'JChicken Ciledug')
            ->assertJsonPath('outlets.0.counts.scheduled', 1)
            ->assertJsonPath('outlets.0.entries.0.worker.name', 'Bayu Adillah')
            ->assertJsonPath('outlets.0.entries.0.shift.code', 'P')
            ->assertJsonPath('outlets.0.entries.0.section', 'Kasir')
            ->assertJsonPath('outlets.0.entries.0.state', 'hadir')
            ->assertJsonPath('outlets.0.entries.0.check_in', '07:55');

        $this->getJson('/api/v1/team/roster?date='.today()->addDay()->toDateString())->assertOk()->assertJsonCount(0, 'outlets');
    }

    public function test_options_hold_only_brand_outlets_and_shifts(): void
    {
        $this->getJson('/api/v1/team/options')
            ->assertOk()
            ->assertJsonPath('companies.0.outlets.*.name', ['JChicken Ciledug', 'Luuca Ciledug'])
            ->assertJsonPath('companies.0.shifts.*.id', [$this->jchickenMorning->id]);

        $this->getJson('/api/v1/team/workers?search=bay')->assertOk()->assertJsonPath('data.*.name', ['Bayu Adillah']);
        $this->getJson('/api/v1/team/workers')->assertOk()->assertJsonMissing(['name' => 'Orang Momoyo']);
    }

    public function test_changing_one_day_moves_the_freelancer_to_another_brand_outlet(): void
    {
        $date = today()->addDay()->toDateString();

        $this->putJson('/api/v1/team/schedules', ['worker_type' => 'freelancer', 'worker_id' => $this->bayu->id, 'date' => $date, 'mode' => 'shift', 'shift_id' => $this->jchickenMorning->id, 'company_location_id' => $this->luucaOutlet->id, 'section' => 'Bar'])
            ->assertOk()
            ->assertJsonPath('data.company_location_id', $this->luucaOutlet->id)
            ->assertJsonPath('data.section', 'Bar');

        $this->putJson('/api/v1/team/schedules', ['worker_type' => 'freelancer', 'worker_id' => $this->bayu->id, 'date' => $date, 'mode' => 'off'])
            ->assertOk()
            ->assertJsonPath('data.is_day_off', true)
            ->assertJsonPath('data.company_location_id', null);

        $this->putJson('/api/v1/team/schedules', ['worker_type' => 'freelancer', 'worker_id' => $this->bayu->id, 'date' => $date, 'mode' => 'clear'])
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->assertFalse(ShiftSchedule::where('freelancer_id', $this->bayu->id)->exists());
    }

    public function test_schedule_changes_refuse_other_brands(): void
    {
        $date = today()->toDateString();

        $this->putJson('/api/v1/team/schedules', ['worker_type' => 'freelancer', 'worker_id' => $this->bayu->id, 'date' => $date, 'mode' => 'shift', 'shift_id' => $this->momoyoMorning->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('shift_id');

        $this->putJson('/api/v1/team/schedules', ['worker_type' => 'freelancer', 'worker_id' => $this->bayu->id, 'date' => $date, 'mode' => 'shift', 'shift_id' => $this->jchickenMorning->id, 'company_location_id' => $this->momoyoOutlet->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('company_location_id');

        $this->putJson('/api/v1/team/schedules', ['worker_type' => 'freelancer', 'worker_id' => $this->outsider->id, 'date' => $date, 'mode' => 'off'])
            ->assertNotFound();

        $this->putJson('/api/v1/team/schedules', ['worker_type' => 'freelancer', 'worker_id' => $this->bayu->id, 'date' => $date, 'mode' => 'shift'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['shift_id' => 'Pilih shift.']);

        $this->assertFalse(ShiftSchedule::query()->exists());
    }

    public function test_adding_a_freelancer_needs_only_name_phone_and_outlet(): void
    {
        $response = $this->postJson('/api/v1/team/freelancers', ['full_name' => 'Dafid Nugroho', 'phone' => '081298765432', 'company_location_id' => $this->luucaOutlet->id])
            ->assertCreated()
            ->assertJsonPath('data.number', 'FL-PFC-0003')
            ->assertJsonPath('data.brand', 'LUUCA')
            ->assertJsonPath('data.has_account', true)
            ->assertJsonPath('account.email', 'fl-pfc-0003@freelance.local');

        $this->assertNotEmpty($response->json('account.password'));
        $this->assertTrue(Freelancer::where('freelancer_number', 'FL-PFC-0003')->sole()->user->hasRole('Freelancer'));

        $this->postJson('/api/v1/team/freelancers', ['full_name' => 'X', 'phone' => '0812', 'company_location_id' => $this->momoyoOutlet->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('company_location_id');

        $this->postJson('/api/v1/team/freelancers', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['full_name' => 'Nama wajib diisi.', 'phone', 'company_location_id']);
    }

    public function test_coordinator_opens_an_account_for_a_freelancer_without_one(): void
    {
        $this->postJson("/api/v1/team/freelancers/{$this->bayu->id}/account")
            ->assertCreated()
            ->assertJsonPath('data.has_account', true)
            ->assertJsonPath('account.email', strtolower($this->bayu->freelancer_number).'@freelance.local');

        $this->assertTrue($this->bayu->fresh()->user->hasRole('Freelancer'));

        $this->postJson("/api/v1/team/freelancers/{$this->bayu->id}/account")->assertStatus(409);
        $this->postJson("/api/v1/team/freelancers/{$this->outsider->id}/account")->assertNotFound();
    }

    public function test_freelancers_end_and_come_back_with_the_same_account(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('Freelancer');
        $this->bayu->update(['user_id' => $user->id]);

        $this->getJson('/api/v1/team/freelancers')->assertOk()->assertJsonPath('data.*.name', ['Bayu Adillah']);

        $this->postJson("/api/v1/team/freelancers/{$this->bayu->id}/end")
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.status', 'completed');
        $this->assertFalse($user->fresh()->is_active);

        $this->getJson('/api/v1/team/freelancers')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/team/freelancers?status=ended')->assertJsonPath('data.*.name', ['Bayu Adillah']);

        $this->postJson("/api/v1/team/freelancers/{$this->bayu->id}/reactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
        $this->assertTrue($user->fresh()->is_active);

        $this->postJson("/api/v1/team/freelancers/{$this->outsider->id}/end")->assertNotFound();
    }
}
