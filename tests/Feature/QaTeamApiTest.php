<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\LeaveRequest;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Services\WorkerAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * QA cluster F-team-api: contracts that ended, approved leave on the roster, workers lent in from
 * another brand, and Indonesian answers on the team's mutations.
 */
class QaTeamApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $coordinator;

    private CompanyLocation $jchickenOutlet;

    private CompanyLocation $momoyoOutlet;

    private Shift $jchickenMorning;

    private Shift $momoyoMorning;

    private Freelancer $bayu;

    private Freelancer $sari;

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
        $momoyo = Division::create(['company_id' => $this->company->id, 'name' => 'MOMOYO', 'is_active' => true]);

        $this->jchickenOutlet = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $jchicken->id, 'name' => 'JChicken Ciledug', 'latitude' => -6.2, 'longitude' => 106.7]);
        $this->momoyoOutlet = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $momoyo->id, 'name' => 'Momoyo Ciledug', 'latitude' => -6.2, 'longitude' => 106.7]);

        $this->jchickenMorning = Shift::create(['company_id' => $this->company->id, 'division_id' => $jchicken->id, 'name' => 'Pagi', 'code' => 'P', 'start_time' => '08:00', 'end_time' => '17:00']);
        $this->momoyoMorning = Shift::create(['company_id' => $this->company->id, 'division_id' => $momoyo->id, 'name' => 'Pagi', 'code' => 'P', 'start_time' => '08:30', 'end_time' => '17:30']);

        $this->coordinator = User::factory()->create(['name' => 'Loocita']);
        $this->coordinator->assignRole('Koordinator');
        $this->coordinator->grantDataAccess(DataScope::Divisions, divisionIds: [$jchicken->id]);

        $this->bayu = Freelancer::create(['company_id' => $this->company->id, 'division_id' => $jchicken->id, 'full_name' => 'Bayu Adillah', 'status' => 'active']);
        $this->sari = Freelancer::create(['company_id' => $this->company->id, 'division_id' => $jchicken->id, 'full_name' => 'Sari Wulan', 'status' => 'active']);
        $this->outsider = Freelancer::create(['company_id' => $this->company->id, 'division_id' => $momoyo->id, 'full_name' => 'Orang Momoyo', 'status' => 'active']);

        Sanctum::actingAs($this->coordinator);
    }

    public function test_copying_a_week_skips_workers_whose_contract_has_ended(): void
    {
        $this->travelTo('2026-11-25 10:00');

        foreach (range(23, 29) as $day) {
            $this->schedule($this->bayu, "2026-11-{$day}");
        }
        $this->schedule($this->sari, '2026-11-23');

        app(WorkerAccountService::class)->endContract($this->bayu->fresh());

        $createdThroughModel = 0;
        $deletedThroughModel = 0;
        ShiftSchedule::created(function () use (&$createdThroughModel): void {
            $createdThroughModel++;
        });
        ShiftSchedule::deleted(function () use (&$deletedThroughModel): void {
            $deletedThroughModel++;
        });

        $this->postJson('/api/v1/team/schedules/copy', ['from' => '2026-11-23', 'to' => '2026-11-30'])
            ->assertOk()
            ->assertJsonPath('data.copied', 1)
            ->assertJsonPath('data.skipped_ended', 3)
            ->assertJsonPath('message', '1 jadwal disalin. 3 dilewati karena di luar masa kontrak pekerja.');

        $this->assertFalse(ShiftSchedule::query()->where('freelancer_id', $this->bayu->id)->where('date', '>=', '2026-11-30')->exists());
        $this->assertTrue(ShiftSchedule::query()->where('freelancer_id', $this->sari->id)->whereDate('date', '2026-11-30')->exists());
        $this->assertSame(3, ShiftSchedule::query()->where('freelancer_id', $this->bayu->id)->count());
        $this->assertSame([1, 0], [$createdThroughModel, $deletedThroughModel], 'Days outside the contract are never written, so there is nothing to remove afterwards.');
    }

    public function test_scheduling_outside_the_contract_is_refused_with_a_reason(): void
    {
        $this->travelTo('2026-11-25 10:00');
        app(WorkerAccountService::class)->endContract($this->bayu->fresh());
        $this->sari->update(['start_date' => '2026-12-01']);

        $this->putJson('/api/v1/team/schedules', $this->change($this->bayu, '2026-12-05'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date' => 'Kontrak pekerja ini berakhir 25 November 2026. Hari setelahnya tidak bisa dijadwalkan.']);

        $this->putJson('/api/v1/team/schedules', $this->change($this->sari, '2026-11-28'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date' => 'Kontrak pekerja ini baru mulai 01 Desember 2026. Hari sebelumnya tidak bisa dijadwalkan.']);

        $this->putJson('/api/v1/team/schedules', $this->change($this->bayu, '2026-11-25'))->assertOk();
        $this->putJson('/api/v1/team/schedules', $this->change($this->sari, '2026-12-01'))->assertOk();
        $this->putJson('/api/v1/team/schedules', ['worker_type' => 'freelancer', 'worker_id' => $this->bayu->id, 'date' => '2026-12-05', 'mode' => 'clear'])->assertOk();

        $this->bayu->update(['end_date' => null]);
        $this->putJson('/api/v1/team/schedules', $this->change($this->bayu, '2026-12-05'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date' => 'Kontrak pekerja ini sudah berakhir.']);

        $this->assertFalse(ShiftSchedule::query()->whereDate('date', '>', '2026-12-01')->exists());
    }

    public function test_approved_leave_shows_as_cuti_on_the_roster_not_belum_absen(): void
    {
        $this->travelTo('2026-11-23 12:00');
        $this->schedule($this->bayu, '2026-11-23');
        $this->schedule($this->sari, '2026-11-23');
        LeaveRequest::create(['freelancer_id' => $this->bayu->id, 'leave_type' => 'sick', 'start_date' => '2026-11-23', 'end_date' => '2026-11-23', 'days_count' => 1, 'reason' => 'Demam', 'status' => 'approved']);
        LeaveRequest::create(['freelancer_id' => $this->sari->id, 'leave_type' => 'sick', 'start_date' => '2026-11-23', 'end_date' => '2026-11-23', 'days_count' => 1, 'reason' => 'Demam', 'status' => 'pending']);

        $response = $this->getJson('/api/v1/team/roster?date=2026-11-23')
            ->assertOk()
            ->assertJsonPath('outlets.0.counts.cuti', 1)
            ->assertJsonPath('outlets.0.counts.belum', 1)
            ->assertJsonPath('outlets.0.counts.scheduled', 1);

        $states = collect($response->json('outlets.0.entries'))->pluck('state', 'worker.name')->all();
        $this->assertSame(['Bayu Adillah' => 'cuti', 'Sari Wulan' => 'belum'], $states);
    }

    public function test_worker_lent_in_from_another_brand_is_read_only_with_a_clear_reason(): void
    {
        $this->travelTo('2026-11-23 06:00');
        $this->schedule($this->bayu, '2026-11-23');
        ShiftSchedule::create(['freelancer_id' => $this->outsider->id, 'date' => '2026-11-23', 'shift_id' => $this->momoyoMorning->id, 'company_location_id' => $this->jchickenOutlet->id]);

        $roster = $this->getJson('/api/v1/team/roster?date=2026-11-23')->assertOk();
        $this->assertSame(['Bayu Adillah' => true, 'Orang Momoyo' => false], collect($roster->json('outlets.0.entries'))->pluck('can_edit', 'worker.name')->all());

        $week = $this->getJson('/api/v1/team/week?date=2026-11-23')->assertOk();
        $this->assertSame(['Bayu Adillah' => true, 'Orang Momoyo' => false], collect($week->json('workers'))->pluck('can_edit', 'worker.name')->all());

        $this->putJson('/api/v1/team/schedules', $this->change($this->outsider, '2026-11-24'))
            ->assertForbidden()
            ->assertJsonPath('message', 'Pekerja ini dipinjam dari brand lain. Jadwalnya diatur koordinator brand asalnya.');

        $this->assertFalse(ShiftSchedule::query()->whereDate('date', '2026-11-24')->exists());
    }

    public function test_team_mutations_by_a_non_manager_answer_in_indonesian(): void
    {
        $worker = User::factory()->create();
        $worker->assignRole('Employee');
        Sanctum::actingAs($worker);

        $this->putJson('/api/v1/team/schedules', $this->change($this->bayu, '2026-11-24'))
            ->assertForbidden()
            ->assertJsonPath('message', 'Fitur tim hanya untuk koordinator.');

        $this->postJson('/api/v1/team/freelancers', ['full_name' => 'X', 'phone' => '0812', 'company_location_id' => $this->jchickenOutlet->id])
            ->assertForbidden()
            ->assertJsonPath('message', 'Fitur tim hanya untuk koordinator.');
    }

    private function schedule(Freelancer $worker, string $date): ShiftSchedule
    {
        return ShiftSchedule::create(['freelancer_id' => $worker->id, 'date' => $date, 'shift_id' => $this->jchickenMorning->id, 'company_location_id' => $this->jchickenOutlet->id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function change(Freelancer $worker, string $date): array
    {
        return ['worker_type' => 'freelancer', 'worker_id' => $worker->id, 'date' => $date, 'mode' => 'shift', 'shift_id' => $this->jchickenMorning->id, 'company_location_id' => $this->jchickenOutlet->id];
    }
}
