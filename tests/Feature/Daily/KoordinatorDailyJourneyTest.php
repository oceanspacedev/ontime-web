<?php

namespace Tests\Feature\Daily;

use App\Enums\DataScope;
use App\Filament\Resources\ApprovalFlows\ApprovalFlowResource;
use App\Filament\Resources\CompanyLocationResource;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\DivisionResource;
use App\Filament\Resources\EmployeeResource;
use App\Filament\Resources\FreelanceResource;
use App\Filament\Resources\FreelanceResource\Pages\ListFreelancers;
use App\Filament\Resources\ShiftSchedules\Pages\ManageShiftSchedules;
use App\Filament\Resources\ShiftSchedules\ShiftScheduleResource;
use App\Filament\Resources\TenantResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\ApprovalFlow;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalFlowService;
use App\Services\DataAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A Koordinator's day in Klien A: today's roster, the weekly grid, lending crew to another brand,
 * taking freelancers on and letting them go, the monthly recap and approving the crew's requests.
 * The Superadmin is not involved; the Koordinator never reaches company settings, other brands or Klien B.
 */
class KoordinatorDailyJourneyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $klienA;

    private Tenant $klienB;

    private Company $company;

    private Company $companyB;

    private Division $kopi;

    private Division $ayam;

    private Division $mie;

    private CompanyLocation $kopiOutlet;

    private CompanyLocation $ayamOutlet;

    private CompanyLocation $mieOutlet;

    private CompanyLocation $outletB;

    private Shift $kopiPagi;

    private Shift $miePagi;

    private Shift $shiftB;

    private User $coordinator;

    private User $kopiCrewUser;

    private Freelancer $kopiCrew;

    private Freelancer $ayamCrew;

    private Freelancer $mieCrew;

    private User $crewBUser;

    private Freelancer $crewB;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.whatsapp_gateway.url' => null, 'services.whatsapp_gateway.token' => null]);
        Http::fake();
        Mail::fake();
        Notification::fake();

        // Wednesday 10:00, so morning shifts have started.
        $this->travelTo(Carbon::parse('2026-11-18 10:00:00'));

        foreach (['Superadmin', 'Admin', 'PIC', 'Koordinator', 'Approver', 'BOD', 'Employee', 'Freelancer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->klienA = Tenant::factory()->create(['name' => 'Klien A', 'code' => 'KA']);
        $this->klienB = Tenant::factory()->create(['name' => 'Klien B', 'code' => 'KB']);

        // Company codes only need to be unique inside one Klien, so both use PFQ.
        $this->company = $this->makeCompany($this->klienA, 'PT Franchise QA');
        $this->companyB = $this->makeCompany($this->klienB, 'PT Franchise Klien B');

        $this->kopi = Division::create(['company_id' => $this->company->id, 'name' => 'KOPI NUSA', 'is_active' => true]);
        $this->ayam = Division::create(['company_id' => $this->company->id, 'name' => 'AYAM GEPREK', 'is_active' => true]);
        $this->mie = Division::create(['company_id' => $this->company->id, 'name' => 'MIE PEDAS', 'is_active' => true]);
        $divisionB = Division::create(['company_id' => $this->companyB->id, 'name' => 'KOPI NUSA', 'is_active' => true]);

        $this->kopiOutlet = $this->makeOutlet($this->kopi, 'Outlet Kopi Ciledug');
        $this->ayamOutlet = $this->makeOutlet($this->ayam, 'Outlet Ayam Ciledug');
        $this->mieOutlet = $this->makeOutlet($this->mie, 'Outlet Mie Ciledug');
        $this->outletB = $this->makeOutlet($divisionB, 'Outlet Klien B');

        $this->kopiPagi = Shift::create(['company_id' => $this->company->id, 'division_id' => $this->kopi->id, 'name' => 'Pagi Kopi', 'code' => 'PK', 'start_time' => '08:00', 'end_time' => '16:00']);
        $this->miePagi = Shift::create(['company_id' => $this->company->id, 'division_id' => $this->mie->id, 'name' => 'Pagi Mie', 'code' => 'PM', 'start_time' => '09:00', 'end_time' => '17:00']);
        $this->shiftB = Shift::create(['company_id' => $this->companyB->id, 'division_id' => $divisionB->id, 'name' => 'Pagi B', 'code' => 'PB', 'start_time' => '08:00', 'end_time' => '16:00']);

        foreach ([$this->company, $this->companyB] as $company) {
            foreach (['leave', 'overtime'] as $requestType) {
                foreach (['Approver', 'BOD'] as $index => $role) {
                    ApprovalFlow::create(['company_id' => $company->id, 'request_type' => $requestType, 'step_number' => $index + 1, 'step_order' => $index + 1, 'name' => $role, 'approver_type' => 'role', 'approver_role' => $role]);
                }
            }
        }

        // As in production, a Koordinator also works as an Employee of one of their brands.
        $this->coordinator = $this->account($this->klienA, ['Koordinator', 'Employee'], 'koord.kopi@qa.test');
        $this->coordinator->grantDataAccess(DataScope::Divisions, divisionIds: [$this->kopi->id, $this->ayam->id]);
        Employee::create([
            'user_id' => $this->coordinator->id,
            'company_id' => $this->company->id,
            'division_id' => $this->kopi->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Koordinator'])->id,
            'nip' => 'PFQ-KOORD-01',
            'full_name' => 'Koordinator Kopi & Ayam',
            'join_date' => '2025-01-06',
            'status' => 'active',
        ]);

        $this->kopiCrewUser = $this->account($this->klienA, ['Freelancer'], 'crew.kopi@qa.test');
        $this->kopiCrew = Freelancer::create(['user_id' => $this->kopiCrewUser->id, 'company_id' => $this->company->id, 'division_id' => $this->kopi->id, 'full_name' => 'Bayu Kopi', 'phone' => '081200000001', 'status' => 'active']);
        $this->ayamCrew = Freelancer::create(['company_id' => $this->company->id, 'division_id' => $this->ayam->id, 'full_name' => 'Citra Ayam', 'phone' => '081200000002', 'status' => 'active']);
        $this->mieCrew = Freelancer::create(['company_id' => $this->company->id, 'division_id' => $this->mie->id, 'full_name' => 'Dodi Mie', 'phone' => '081200000003', 'status' => 'active']);

        $this->crewBUser = $this->account($this->klienB, ['Freelancer'], 'crew.b@qa.test');
        $this->crewB = Freelancer::create(['user_id' => $this->crewBUser->id, 'company_id' => $this->companyB->id, 'division_id' => $divisionB->id, 'full_name' => 'Eka Klien B', 'phone' => '081200000009', 'status' => 'active']);

        DataAccessService::flush();
    }

    public function test_pagi_hari_koordinator_membuka_aplikasi_dan_melihat_roster_outletnya(): void
    {
        ShiftSchedule::create(['freelancer_id' => $this->kopiCrew->id, 'date' => today(), 'shift_id' => $this->kopiPagi->id, 'company_location_id' => $this->kopiOutlet->id, 'section' => 'Bar']);
        ShiftSchedule::create(['freelancer_id' => $this->ayamCrew->id, 'date' => today(), 'shift_id' => $this->kopiPagi->id, 'company_location_id' => $this->ayamOutlet->id, 'section' => 'Kasir']);
        ShiftSchedule::create(['freelancer_id' => $this->mieCrew->id, 'date' => today(), 'shift_id' => $this->miePagi->id, 'company_location_id' => $this->mieOutlet->id]);
        ShiftSchedule::create(['freelancer_id' => $this->crewB->id, 'date' => today(), 'shift_id' => $this->shiftB->id, 'company_location_id' => $this->outletB->id]);
        Attendance::create(['freelancer_id' => $this->kopiCrew->id, 'date' => today(), 'check_in' => today()->setTime(8, 20), 'status' => 'late', 'late_minutes' => 20]);

        Sanctum::actingAs($this->coordinator);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.access.can_manage_team', true)
            ->assertJsonPath('user.access.is_coordinator', true)
            ->assertJsonPath('user.access.client_admin', ['is_pic' => false, 'can_manage_companies' => false, 'can_manage_accounts' => false]);

        $outlets = $this->getJson('/api/v1/team/roster')
            ->assertOk()
            ->assertJsonPath('date', '2026-11-18')
            ->json('outlets');

        $this->assertEqualsCanonicalizing(['Outlet Kopi Ciledug', 'Outlet Ayam Ciledug'], array_column(array_column($outlets, 'location'), 'name'));

        $entries = collect($outlets)->flatMap(fn (array $outlet): array => $outlet['entries'])->keyBy('worker.name');
        $this->assertSame(['Bayu Kopi', 'Citra Ayam'], $entries->keys()->sort()->values()->all());
        $this->assertSame('terlambat', $entries['Bayu Kopi']['state']);
        $this->assertSame('08:20', $entries['Bayu Kopi']['check_in']);
        $this->assertSame(20, $entries['Bayu Kopi']['late_minutes']);
        $this->assertSame('belum', $entries['Citra Ayam']['state'], 'Shift started at 08:00 and no check-in yet.');

        $this->getJson('/api/v1/team/roster')
            ->assertJsonMissing(['name' => 'Dodi Mie'])
            ->assertJsonMissing(['name' => 'Eka Klien B'])
            ->assertJsonMissing(['name' => 'Outlet Klien B']);

        $this->getJson('/api/v1/team/roster?date=hari-ini')->assertUnprocessable()->assertJsonValidationErrors('date');
    }

    public function test_koordinator_menyusun_jadwal_mingguan_lalu_menyalinnya_ke_minggu_depan(): void
    {
        Sanctum::actingAs($this->coordinator);

        $monday = Carbon::parse('2026-11-16');

        foreach (range(0, 4) as $offset) {
            $this->putJson('/api/v1/team/schedules', [
                'worker_type' => 'freelancer',
                'worker_id' => $this->kopiCrew->id,
                'date' => $monday->copy()->addDays($offset)->toDateString(),
                'mode' => 'shift',
                'shift_id' => $this->kopiPagi->id,
                'company_location_id' => $this->kopiOutlet->id,
                'section' => 'Bar',
            ])->assertOk()->assertJsonPath('message', 'Jadwal disimpan.');
        }

        $this->putJson('/api/v1/team/schedules', ['worker_type' => 'freelancer', 'worker_id' => $this->kopiCrew->id, 'date' => '2026-11-21', 'mode' => 'off'])
            ->assertOk()
            ->assertJsonPath('data.is_day_off', true);

        $this->putJson('/api/v1/team/schedules', ['worker_type' => 'freelancer', 'worker_id' => $this->ayamCrew->id, 'date' => '2026-11-18', 'mode' => 'shift', 'shift_id' => $this->kopiPagi->id, 'company_location_id' => $this->ayamOutlet->id])
            ->assertOk();

        // Another brand's own schedule and Klien B's schedule must not be copied by this coordinator.
        ShiftSchedule::create(['freelancer_id' => $this->mieCrew->id, 'date' => '2026-11-17', 'shift_id' => $this->miePagi->id, 'company_location_id' => $this->mieOutlet->id]);
        ShiftSchedule::create(['freelancer_id' => $this->crewB->id, 'date' => '2026-11-17', 'shift_id' => $this->shiftB->id, 'company_location_id' => $this->outletB->id]);

        $week = $this->getJson('/api/v1/team/week?date=2026-11-18')
            ->assertOk()
            ->assertJsonPath('start', '2026-11-16')
            ->assertJsonPath('end', '2026-11-22')
            ->assertJsonCount(7, 'days')
            ->assertJsonCount(2, 'workers')
            ->json('workers');

        $kopiRow = collect($week)->firstWhere('worker.name', 'Bayu Kopi');
        $this->assertCount(6, $kopiRow['days']);
        $this->assertSame('PK', $kopiRow['days']['2026-11-16']['shift']['code']);
        $this->assertSame('Outlet Kopi Ciledug', $kopiRow['days']['2026-11-16']['outlet']);
        $this->assertTrue($kopiRow['days']['2026-11-21']['is_day_off']);

        // A day already planned next week is kept.
        ShiftSchedule::create(['freelancer_id' => $this->kopiCrew->id, 'date' => '2026-11-23', 'is_day_off' => true]);

        $this->postJson('/api/v1/team/schedules/copy', ['from' => '2026-11-18', 'to' => '2026-11-25'])
            ->assertOk()
            ->assertJsonPath('data.from', '2026-11-16')
            ->assertJsonPath('data.to', '2026-11-23')
            ->assertJsonPath('data.copied', 6)
            ->assertJsonPath('data.skipped_existing', 1);

        $this->assertTrue(ShiftSchedule::where('freelancer_id', $this->kopiCrew->id)->whereDate('date', '2026-11-23')->sole()->is_day_off);
        $this->assertSame(1, ShiftSchedule::where('freelancer_id', $this->ayamCrew->id)->whereDate('date', '2026-11-25')->count());
        $this->assertFalse(ShiftSchedule::where('freelancer_id', $this->mieCrew->id)->whereDate('date', '>=', '2026-11-23')->exists());
        $this->assertFalse(ShiftSchedule::where('freelancer_id', $this->crewB->id)->whereDate('date', '>=', '2026-11-23')->exists());

        $this->postJson('/api/v1/team/schedules/copy', ['from' => '2026-11-18'])->assertUnprocessable()->assertJsonValidationErrors('to');
    }

    public function test_koordinator_meminjamkan_crew_ke_outlet_brand_lain_tanpa_mengambil_alih_crew_brand_itu(): void
    {
        Sanctum::actingAs($this->coordinator);

        $options = $this->getJson('/api/v1/team/options')->assertOk()->assertJsonCount(1, 'companies');
        $this->assertSame($this->company->id, $options->json('companies.0.id'));
        $outlets = collect($options->json('companies.0.outlets'))->pluck('own_brand', 'name');
        $this->assertSame(['Outlet Ayam Ciledug' => true, 'Outlet Kopi Ciledug' => true, 'Outlet Mie Ciledug' => false], $outlets->sortKeys()->all());
        $this->assertNotContains($this->shiftB->id, $options->json('companies.0.shifts.*.id'));

        $this->getJson('/api/v1/team/workers')
            ->assertOk()
            ->assertJsonMissing(['name' => 'Dodi Mie'])
            ->assertJsonMissing(['name' => 'Eka Klien B']);
        $this->assertEqualsCanonicalizing(['Bayu Kopi', 'Citra Ayam', 'Koordinator Kopi & Ayam'], $this->getJson('/api/v1/team/workers')->json('data.*.name'));

        // Lend Bayu (KOPI) to the MIE outlet for one day, on the MIE shift.
        $this->putJson('/api/v1/team/schedules', ['worker_type' => 'freelancer', 'worker_id' => $this->kopiCrew->id, 'date' => '2026-11-18', 'mode' => 'shift', 'shift_id' => $this->miePagi->id, 'company_location_id' => $this->mieOutlet->id, 'section' => 'Dapur'])
            ->assertOk()
            ->assertJsonPath('data.company_location_id', $this->mieOutlet->id);

        $lent = collect($this->getJson('/api/v1/team/roster')->json('outlets'))->firstWhere('location.name', 'Outlet Mie Ciledug');
        $this->assertNotNull($lent, 'The coordinator follows their lent crew at the other outlet.');
        $this->assertSame('Bayu Kopi', $lent['entries'][0]['worker']['name']);
        $this->assertSame('KOPI NUSA', $lent['entries'][0]['borrowed_from']);

        // The MIE crew stays out of reach.
        $this->putJson('/api/v1/team/schedules', ['worker_type' => 'freelancer', 'worker_id' => $this->mieCrew->id, 'date' => '2026-11-18', 'mode' => 'off'])->assertNotFound();
        $this->putJson('/api/v1/team/schedules', ['worker_type' => 'employee', 'worker_id' => $this->coordinator->employee->id, 'date' => '2026-11-18', 'mode' => 'off'])
            ->assertOk('A coordinator schedules the employees of their brands, themselves included.');
    }

    public function test_koordinator_tidak_bisa_menjadwalkan_ke_outlet_shift_atau_crew_klien_lain(): void
    {
        Sanctum::actingAs($this->coordinator);

        $this->putJson('/api/v1/team/schedules', ['worker_type' => 'freelancer', 'worker_id' => $this->kopiCrew->id, 'date' => '2026-11-19', 'mode' => 'shift', 'shift_id' => $this->kopiPagi->id, 'company_location_id' => $this->outletB->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('company_location_id');

        $this->putJson('/api/v1/team/schedules', ['worker_type' => 'freelancer', 'worker_id' => $this->kopiCrew->id, 'date' => '2026-11-19', 'mode' => 'shift', 'shift_id' => $this->shiftB->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('shift_id');

        $this->putJson('/api/v1/team/schedules', ['worker_type' => 'freelancer', 'worker_id' => $this->crewB->id, 'date' => '2026-11-19', 'mode' => 'off'])->assertNotFound();
        $this->putJson('/api/v1/team/schedules', ['worker_type' => 'freelancer', 'worker_id' => 999999, 'date' => '2026-11-19', 'mode' => 'off'])->assertNotFound();
        $this->putJson('/api/v1/team/schedules', ['worker_type' => 'admin', 'worker_id' => $this->kopiCrew->id, 'date' => '2026-11-19', 'mode' => 'libur'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['worker_type', 'mode']);

        $this->assertFalse(ShiftSchedule::query()->exists());
    }

    public function test_koordinator_menambah_freelancer_baru_dan_akunnya_masuk_klien_sendiri(): void
    {
        Sanctum::actingAs($this->coordinator);

        $response = $this->postJson('/api/v1/team/freelancers', ['full_name' => 'Fajar Baru', 'phone' => '081211112222', 'company_location_id' => $this->ayamOutlet->id, 'start_date' => '2026-11-18'])
            ->assertCreated()
            ->assertJsonPath('data.brand', 'AYAM GEPREK')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.has_account', true)
            ->assertJsonPath('account.whatsapp_sent', false);

        $this->assertNotEmpty($response->json('account.password'));
        $freelancer = Freelancer::query()->findOrFail($response->json('data.id'));
        $this->assertSame([$this->company->id, $this->ayam->id], [(int) $freelancer->company_id, (int) $freelancer->division_id]);
        $this->assertSame($this->klienA->id, (int) $freelancer->user->tenant_id, 'The new account belongs to Klien A.');
        $this->assertSame(['Freelancer'], $freelancer->user->getRoleNames()->all());
        $this->assertSame(DataScope::Own, DataAccessService::scopeOf($freelancer->user));

        $this->getJson('/api/v1/team/freelancers?search=Fajar')->assertOk()->assertJsonPath('data.*.name', ['Fajar Baru']);

        // Not into another brand's outlet, not into Klien B.
        $this->postJson('/api/v1/team/freelancers', ['full_name' => 'X', 'phone' => '0812', 'company_location_id' => $this->mieOutlet->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('company_location_id');
        $this->postJson('/api/v1/team/freelancers', ['full_name' => 'X', 'phone' => '0812', 'company_location_id' => $this->outletB->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('company_location_id');
        $this->postJson('/api/v1/team/freelancers', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['full_name' => 'Nama wajib diisi.', 'phone' => 'Nomor HP wajib diisi.', 'company_location_id' => 'Pilih outlet.']);

        $this->assertSame(0, Freelancer::where('full_name', 'X')->count());
        Http::assertNothingSent();
    }

    public function test_akun_freelancer_baru_tidak_bentrok_dengan_freelancer_klien_lain_yang_kode_badan_usahanya_sama(): void
    {
        // Klien B uses the same company code (allowed: codes are unique per Klien) and already has FL-PFQ-0004 with an account.
        $userB = $this->account($this->klienB, ['Freelancer'], 'fl-pfq-0004@freelance.local');
        Freelancer::create(['user_id' => $userB->id, 'company_id' => $this->companyB->id, 'division_id' => $this->crewB->division_id, 'freelancer_number' => 'FL-PFQ-0004', 'full_name' => 'Gina Klien B', 'phone' => '081200000010', 'status' => 'active']);

        Sanctum::actingAs($this->coordinator);

        // Klien A's next number is also FL-PFQ-0004.
        $response = $this->postJson('/api/v1/team/freelancers', ['full_name' => 'Hadi Baru', 'phone' => '081233334444', 'company_location_id' => $this->kopiOutlet->id])
            ->assertCreated()
            ->assertJsonPath('data.number', 'FL-PFQ-0004');

        $this->assertStringNotContainsString('sudah dipakai akun lain', (string) $response->json('message'), 'Klien A must not learn about Klien B accounts.');
        $response->assertJsonPath('data.has_account', true);
        $this->assertNotNull($response->json('account'));
        $this->assertSame($this->klienA->id, (int) Freelancer::query()->findOrFail($response->json('data.id'))->user?->tenant_id);
    }

    public function test_koordinator_membuatkan_akun_untuk_freelancer_yang_belum_punya_akun(): void
    {
        Sanctum::actingAs($this->coordinator);

        $this->postJson("/api/v1/team/freelancers/{$this->ayamCrew->id}/account")
            ->assertCreated()
            ->assertJsonPath('data.has_account', true)
            ->assertJsonPath('account.email', strtolower($this->ayamCrew->freelancer_number).'@freelance.local');

        $this->assertSame($this->klienA->id, (int) $this->ayamCrew->fresh()->user->tenant_id);
        $this->assertTrue($this->ayamCrew->fresh()->user->hasRole('Freelancer'));

        $this->postJson("/api/v1/team/freelancers/{$this->ayamCrew->id}/account")->assertStatus(409);
        $this->postJson("/api/v1/team/freelancers/{$this->mieCrew->id}/account")->assertNotFound();
        $this->postJson("/api/v1/team/freelancers/{$this->crewB->id}/account")->assertNotFound();
        $this->assertNull($this->mieCrew->fresh()->user_id);
    }

    public function test_koordinator_mengakhiri_kontrak_freelancer_lalu_mengaktifkannya_lagi(): void
    {
        ShiftSchedule::create(['freelancer_id' => $this->kopiCrew->id, 'date' => '2026-11-20', 'shift_id' => $this->kopiPagi->id, 'company_location_id' => $this->kopiOutlet->id]);
        $crewToken = $this->kopiCrewUser->createToken('mobile')->plainTextToken;

        Sanctum::actingAs($this->coordinator);

        $this->postJson("/api/v1/team/freelancers/{$this->kopiCrew->id}/end")
            ->assertOk()
            ->assertJsonPath('message', 'Kontrak diakhiri.')
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.end_date', '2026-11-18')
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse($this->kopiCrewUser->fresh()->is_active);
        $this->assertSame(0, $this->kopiCrewUser->tokens()->count(), 'The ended crew is signed out of the app.');
        $this->assertFalse(ShiftSchedule::where('freelancer_id', $this->kopiCrew->id)->exists(), 'Future schedules are removed.');

        $this->getJson('/api/v1/team/freelancers')->assertJsonMissing(['name' => 'Bayu Kopi']);
        $this->getJson('/api/v1/team/freelancers?status=ended')->assertJsonPath('data.*.name', ['Bayu Kopi']);

        $this->postJson("/api/v1/team/freelancers/{$this->kopiCrew->id}/reactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.end_date', null);
        $this->assertTrue($this->kopiCrewUser->fresh()->is_active);

        // Other brands and Klien B cannot be ended by this coordinator.
        $this->postJson("/api/v1/team/freelancers/{$this->mieCrew->id}/end")->assertNotFound();
        $this->postJson("/api/v1/team/freelancers/{$this->crewB->id}/end")->assertNotFound();
        $this->postJson("/api/v1/team/freelancers/{$this->crewB->id}/reactivate")->assertNotFound();
        $this->assertSame('active', $this->mieCrew->fresh()->status);
        $this->assertSame('active', $this->crewB->fresh()->status);
        $this->assertTrue($this->crewBUser->fresh()->is_active);
        $this->assertNotEmpty($crewToken);
    }

    public function test_koordinator_melihat_rekap_bulanan_tim_dan_mengunduhnya_lewat_link_bertanda_tangan(): void
    {
        foreach (['2026-11-16', '2026-11-17'] as $date) {
            ShiftSchedule::create(['freelancer_id' => $this->kopiCrew->id, 'date' => $date, 'shift_id' => $this->kopiPagi->id, 'company_location_id' => $this->kopiOutlet->id]);
        }
        Attendance::create(['freelancer_id' => $this->kopiCrew->id, 'date' => '2026-11-16', 'check_in' => '2026-11-16 08:10:00', 'check_out' => '2026-11-16 16:00:00', 'status' => 'late', 'late_minutes' => 10]);
        ShiftSchedule::create(['freelancer_id' => $this->mieCrew->id, 'date' => '2026-11-16', 'shift_id' => $this->miePagi->id, 'company_location_id' => $this->mieOutlet->id]);
        ShiftSchedule::create(['freelancer_id' => $this->crewB->id, 'date' => '2026-11-16', 'shift_id' => $this->shiftB->id, 'company_location_id' => $this->outletB->id]);

        Sanctum::actingAs($this->coordinator);

        $recap = $this->getJson('/api/v1/team/recap?month=2026-11')
            ->assertOk()
            ->assertJsonPath('month', '2026-11')
            ->assertJsonCount(3, 'data')
            ->json('data.0');

        $this->assertSame('Bayu Kopi', $recap['worker']['name']);
        $this->assertSame('Outlet Kopi Ciledug', $recap['outlet']);
        // Since the attendance ledger the recap counts the no-shows laporan absensi always counted. Bayu: the
        // rostered 17th plus the ten weekdays (2 - 13 Nov) before the week of his first roster. The two people
        // of these brands who never had a roster (Citra and the coordinator) are now listed too, with the
        // twelve weekdays up to yesterday; the other brand and Klien B stay out.
        $this->assertSame([2, 1, 1, 10, 11], [$recap['scheduled_days'], $recap['present_days'], $recap['late_days'], $recap['late_minutes'], $recap['absent_days']]);
        $this->getJson('/api/v1/team/recap?month=2026-11')
            ->assertJsonPath('data.*.worker.name', ['Bayu Kopi', 'Citra Ayam', 'Koordinator Kopi & Ayam'])
            ->assertJsonPath('data.*.absent_days', [11, 12, 12])
            ->assertJsonPath('data.*.scheduled_days', [2, 0, 0]);

        $this->getJson('/api/v1/team/recap?month=11-2026')->assertUnprocessable()->assertJsonValidationErrors('month');

        $url = $this->getJson('/api/v1/team/recap/link?month=2026-11')->assertOk()->assertJsonPath('expires_in', 900)->json('url');

        $this->get($url)->assertOk()->assertDownload();

        // The link is bound to this coordinator: pointing it at another account breaks the signature.
        $this->get(str_replace("/team-recap/{$this->coordinator->id}", "/team-recap/{$this->crewBUser->id}", $url))->assertForbidden();

        $this->travel(16)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_satu_persetujuan_koordinator_menyelesaikan_semua_tahap_pengajuan_crew_brandnya(): void
    {
        Sanctum::actingAs($this->kopiCrewUser);
        $leaveId = $this->postJson('/api/v1/leave-requests', ['leave_type' => 'sick', 'start_date' => '2026-11-19', 'end_date' => '2026-11-19', 'reason' => 'Demam'])
            ->assertCreated()
            ->json('data.id');
        $overtimeId = $this->postJson('/api/v1/overtime-requests', ['date' => '2026-11-18', 'start_time' => '16:00', 'end_time' => '18:00', 'reason' => 'Ramai'])
            ->assertCreated()
            ->json('data.id');
        $this->getJson('/api/v1/approvals/pending')->assertOk()->assertJsonCount(0, 'leave_requests');

        $mieLeave = $this->leaveFor($this->mieCrew);
        $leaveB = $this->leaveFor($this->crewB);

        Sanctum::actingAs($this->coordinator);

        $pending = $this->getJson('/api/v1/approvals/pending')->assertOk();
        $this->assertSame([$leaveId], $pending->json('leave_requests.*.id'));
        $this->assertSame([$overtimeId], $pending->json('overtime_requests.*.id'));
        $this->assertNotContains($mieLeave->id, $pending->json('leave_requests.*.id'));
        $this->assertNotContains($leaveB->id, $pending->json('leave_requests.*.id'));

        $this->putJson("/api/v1/approvals/leave/{$leaveId}/process", ['action' => 'approved'])
            ->assertOk()
            ->assertJsonPath('message', 'Pengajuan berhasil disetujui')
            ->assertJsonPath('status', 'approved')
            ->assertJsonPath('current_step', 2);

        $leave = LeaveRequest::query()->findOrFail($leaveId);
        $this->assertSame(['approved', 'approved'], $leave->approvalSteps()->orderBy('step_order')->pluck('status')->all());
        $this->assertSame([$this->coordinator->id], $leave->approvalSteps()->distinct()->pluck('actioned_by')->map(fn ($id): int => (int) $id)->all());

        $this->putJson("/api/v1/approvals/overtime/{$overtimeId}/process", ['action' => 'rejected'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rejection_note');
        $this->putJson("/api/v1/approvals/overtime/{$overtimeId}/process", ['action' => 'rejected', 'rejection_note' => 'Outlet sudah cukup orang'])
            ->assertOk()
            ->assertJsonPath('status', 'rejected');
        $this->assertSame('Outlet sudah cukup orang', OvertimeRequest::query()->findOrFail($overtimeId)->rejection_reason);

        $this->putJson("/api/v1/approvals/leave/{$leaveId}/process", ['action' => 'approved'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Pengajuan ini sudah tidak berstatus pending.');
        $this->getJson('/api/v1/approvals/pending')->assertJsonCount(0, 'leave_requests')->assertJsonCount(0, 'overtime_requests');
    }

    public function test_koordinator_tidak_bisa_memproses_pengajuan_brand_lain_klien_lain_atau_miliknya_sendiri(): void
    {
        $mieLeave = $this->leaveFor($this->mieCrew);
        $leaveB = $this->leaveFor($this->crewB);

        Sanctum::actingAs($this->coordinator);

        $ownLeaveId = $this->postJson('/api/v1/leave-requests', ['leave_type' => 'permission', 'start_date' => '2026-11-20', 'end_date' => '2026-11-20', 'reason' => 'Urusan keluarga'])
            ->assertCreated()
            ->json('data.id');

        $this->assertNotContains($ownLeaveId, $this->getJson('/api/v1/approvals/pending')->json('leave_requests.*.id'));

        foreach ([$mieLeave->id, $leaveB->id, $ownLeaveId] as $id) {
            $this->putJson("/api/v1/approvals/leave/{$id}/process", ['action' => 'approved'])->assertUnprocessable();
            $this->putJson("/api/v1/approvals/leave/{$id}/process", ['action' => 'rejected', 'rejection_note' => 'Tidak'])->assertUnprocessable();

            $leave = LeaveRequest::query()->findOrFail($id);
            $this->assertSame('pending', $leave->status instanceof \BackedEnum ? $leave->status->value : $leave->status);
            $this->assertSame(0, $leave->approvalSteps()->where('status', '!=', 'pending')->count());
        }

        $this->putJson('/api/v1/approvals/cuti/1/process', ['action' => 'approved'])->assertUnprocessable();
        $this->putJson('/api/v1/approvals/leave/999999/process', ['action' => 'approved'])->assertNotFound();
    }

    public function test_koordinator_tidak_bisa_membuka_pengaturan_klien_dari_aplikasi(): void
    {
        Sanctum::actingAs($this->coordinator);

        $this->getJson('/api/v1/client/overview')->assertForbidden();
        $this->postJson('/api/v1/client/companies', ['name' => 'PT Baru', 'code' => 'BARU'])->assertForbidden();
        $this->putJson("/api/v1/client/companies/{$this->company->id}", ['name' => 'Ganti Nama', 'code' => 'PFQ'])->assertForbidden();
        $this->getJson("/api/v1/client/companies/{$this->company->id}/policy")->assertForbidden();
        $this->putJson("/api/v1/client/companies/{$this->company->id}/policy", ['attendance_mode' => 'click'])->assertForbidden();
        $this->postJson("/api/v1/client/companies/{$this->company->id}/locations", ['name' => 'Outlet Liar', 'latitude' => -6.2, 'longitude' => 106.7])->assertForbidden();
        $this->putJson("/api/v1/client/locations/{$this->kopiOutlet->id}", ['name' => 'Ganti'])->assertForbidden();
        $this->postJson("/api/v1/client/companies/{$this->company->id}/divisions", ['name' => 'Brand Liar'])->assertForbidden();
        $this->putJson("/api/v1/client/divisions/{$this->kopi->id}", ['name' => 'Ganti'])->assertForbidden();
        $this->getJson('/api/v1/client/accounts')->assertForbidden();
        $this->postJson('/api/v1/client/accounts', ['name' => 'Admin Liar', 'email' => 'liar@qa.test', 'roles' => ['Admin']])->assertForbidden();
        $this->putJson("/api/v1/client/accounts/{$this->kopiCrewUser->id}", ['is_active' => false])->assertForbidden();
        $this->postJson("/api/v1/client/accounts/{$this->kopiCrewUser->id}/reset-password")->assertForbidden();
        $this->getJson('/api/v1/master/companies')->assertForbidden();
        $this->getJson('/api/v1/master/users')->assertForbidden();
        $this->getJson('/api/v1/master/approval-flows')->assertForbidden();

        $this->assertSame('PT Franchise QA', $this->company->fresh()->name);
        $this->assertSame('KOPI NUSA', $this->kopi->fresh()->name);
        $this->assertTrue($this->kopiCrewUser->fresh()->is_active);
        $this->assertSame(3, Division::where('company_id', $this->company->id)->count());
        $this->assertFalse(User::where('email', 'liar@qa.test')->exists());
    }

    public function test_di_panel_web_koordinator_hanya_melihat_menu_operasional_dan_data_brandnya(): void
    {
        $this->actingAs($this->coordinator);

        $this->assertTrue(FreelanceResource::canViewAny());
        $this->assertTrue(ShiftScheduleResource::canViewAny());

        foreach ([CompanyResource::class, CompanyLocationResource::class, DivisionResource::class, ApprovalFlowResource::class, UserResource::class, EmployeeResource::class, TenantResource::class] as $resource) {
            $this->assertFalse($resource::canViewAny(), "{$resource} is not for a coordinator.");
        }

        $this->get(CompanyResource::getUrl('index'))->assertForbidden();
        $this->get(UserResource::getUrl('index'))->assertForbidden();
        $this->get(TenantResource::getUrl('index'))->assertForbidden();

        Livewire::test(ListFreelancers::class)
            ->assertCanSeeTableRecords([$this->kopiCrew, $this->ayamCrew])
            ->assertCanNotSeeTableRecords([$this->mieCrew, $this->crewB]);

        // Klien B's company offers nothing to place or schedule.
        $this->assertSame([], ShiftScheduleResource::locationOptions($this->companyB->id, borrowing: true));
        $this->assertSame([], ShiftScheduleResource::shiftOptions($this->companyB->id, borrowing: true));
        $this->assertEmpty(array_filter(ShiftScheduleResource::workerOptions($this->companyB->id)));

        Livewire::test(ManageShiftSchedules::class)->instance()->assignSchedule([
            'company_id' => $this->company->id,
            'workers' => ["freelancer:{$this->ayamCrew->id}"],
            'date_from' => '2026-11-23',
            'date_until' => '2026-11-27',
            'weekdays' => [1, 2, 3, 4, 5],
            'mode' => 'shift',
            'shift_id' => $this->kopiPagi->id,
            'company_location_id' => $this->ayamOutlet->id,
            'overwrite' => true,
        ]);

        $this->assertSame(5, ShiftSchedule::where('freelancer_id', $this->ayamCrew->id)->count());
    }

    public function test_akses_divisi_klien_lain_yang_tersimpan_keliru_tetap_diabaikan(): void
    {
        // A pick saved for Klien B's division must never open Klien B to this coordinator.
        $this->coordinator->grantDataAccess(DataScope::Divisions, divisionIds: [$this->kopi->id, $this->ayam->id, $this->crewB->division_id]);

        Sanctum::actingAs($this->coordinator);

        $this->getJson('/api/v1/team/options')->assertOk()->assertJsonCount(1, 'companies');
        $this->getJson('/api/v1/team/workers')->assertJsonMissing(['name' => 'Eka Klien B']);
        $this->getJson('/api/v1/team/freelancers')->assertJsonMissing(['name' => 'Eka Klien B']);
        $this->putJson('/api/v1/team/schedules', ['worker_type' => 'freelancer', 'worker_id' => $this->crewB->id, 'date' => '2026-11-19', 'mode' => 'off'])->assertNotFound();
        $this->postJson("/api/v1/team/freelancers/{$this->crewB->id}/end")->assertNotFound();
        $this->postJson('/api/v1/team/freelancers', ['full_name' => 'X', 'phone' => '0812', 'company_location_id' => $this->outletB->id])->assertUnprocessable();
    }

    public function test_crew_biasa_tidak_mendapat_fitur_tim_koordinator(): void
    {
        Sanctum::actingAs($this->kopiCrewUser);

        $this->getJson('/api/v1/auth/me')->assertJsonPath('user.access.can_manage_team', false)->assertJsonPath('user.access.is_coordinator', false);
        $this->getJson('/api/v1/team/roster')->assertForbidden();
        $this->getJson('/api/v1/team/week')->assertForbidden();
        $this->getJson('/api/v1/team/recap')->assertForbidden();
        $this->putJson('/api/v1/team/schedules', ['worker_type' => 'freelancer', 'worker_id' => $this->kopiCrew->id, 'date' => '2026-11-19', 'mode' => 'off'])->assertForbidden();
        $this->postJson('/api/v1/team/freelancers', ['full_name' => 'X', 'phone' => '0812', 'company_location_id' => $this->kopiOutlet->id])->assertForbidden();
        $this->postJson("/api/v1/team/freelancers/{$this->ayamCrew->id}/end")->assertForbidden();
    }

    private function makeCompany(Tenant $tenant, string $name): Company
    {
        $company = new Company(['name' => $name, 'code' => 'PFQ', 'is_active' => true]);
        $company->tenant_id = $tenant->id;
        $company->save();

        return $company;
    }

    private function makeOutlet(Division $brand, string $name): CompanyLocation
    {
        return CompanyLocation::create(['company_id' => $brand->company_id, 'division_id' => $brand->id, 'name' => $name, 'latitude' => -6.2, 'longitude' => 106.7, 'is_active' => true]);
    }

    /**
     * @param  list<string>  $roles
     */
    private function account(Tenant $tenant, array $roles, string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'is_active' => true]);
        $user->forceFill(['tenant_id' => $tenant->id])->save();
        $user->syncRoles($roles);

        return $user;
    }

    private function leaveFor(Freelancer $crew): LeaveRequest
    {
        $leave = LeaveRequest::create([
            'freelancer_id' => $crew->id,
            'leave_type' => 'sick',
            'start_date' => '2026-11-20',
            'end_date' => '2026-11-20',
            'days_count' => 1,
            'reason' => 'Sakit',
            'status' => 'pending',
        ]);
        app(ApprovalFlowService::class)->generateSteps($leave, 'leave');

        return $leave->refresh();
    }
}
