<?php

namespace Tests\Feature\Daily;

use App\Enums\DataScope;
use App\Filament\Pages\ApprovalSaya;
use App\Filament\Pages\LaporanAbsensi;
use App\Filament\Resources\Attendances\AttendanceResource;
use App\Filament\Resources\Attendances\Pages\EditAttendance;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\DivisionResource;
use App\Filament\Resources\EmployeeResource;
use App\Filament\Resources\EmployeeResource\Pages\CreateEmployee;
use App\Filament\Resources\EmployeeResource\Pages\EditEmployee;
use App\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use App\Filament\Resources\FreelanceResource;
use App\Filament\Resources\FreelanceResource\Pages\CreateFreelancer;
use App\Filament\Resources\InternResource;
use App\Filament\Resources\InternResource\Pages\CreateIntern;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Resources\ShiftSchedules\Pages\ManageShiftSchedules;
use App\Filament\Resources\ShiftSchedules\ShiftScheduleResource;
use App\Filament\Resources\TenantResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
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
use App\Services\TenantOnboardingService;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

/**
 * A working day of an Admin/HR of a Klien: the Superadmin only monitors, the HR staff of the client
 * runs workers, accounts, schedules, attendance data, reports and approvals inside their data access,
 * and never reaches another Klien.
 *
 * Cast (Klien A): PIC, "HR Badan Usaha" (Admin, Badan Usaha: PT Alfa Retail, with an employee profile
 * of their own), "Admin Toko" (Admin, Divisi: STORE), workers in PT Alfa Retail (HRGA and Toko Kemang)
 * and in a second company PT Alfa Logistik outside the HR's access. Klien B has its own company,
 * worker and Admin.
 */
#[Group('daily')]
class AdminHrDailyJourneyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $klienA;

    private Tenant $klienB;

    private Company $alfaRetail;

    private Company $alfaLogistik;

    private Company $betaCorp;

    private Division $headOffice;

    private Division $hrga;

    private Division $store;

    private Division $tokoKemang;

    private Division $betaDivision;

    private User $superadmin;

    private User $pic;

    private User $hrAdmin;

    private Employee $hrProfile;

    private User $storeAdmin;

    private Employee $staffHrga;

    private Employee $crewKemang;

    private Employee $driverLogistik;

    private Employee $betaWorker;

    private Shift $shiftPagi;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.whatsapp_gateway.url' => null, 'services.whatsapp_gateway.token' => null, 'services.face.driver' => 'basic']);
        Http::fake();
        Mail::fake();
        Notification::fake();

        $this->travelTo(Carbon::parse('2026-11-18 09:00:00'));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(RoleSeeder::class);

        $this->superadmin = User::factory()->create(['is_active' => true]);
        $this->superadmin->assignRole('Superadmin');

        $this->klienA = Tenant::factory()->create(['name' => 'Klien Alfa', 'code' => 'KA']);
        $this->klienB = Tenant::factory()->create(['name' => 'Klien Beta', 'code' => 'KB']);

        $this->alfaRetail = $this->company($this->klienA, 'PT Alfa Retail', 'ALR');
        $this->alfaLogistik = $this->company($this->klienA, 'PT Alfa Logistik', 'ALL');
        $this->betaCorp = $this->company($this->klienB, 'PT Beta Corp', 'BTC');

        $this->headOffice = $this->division($this->alfaRetail, 'HEAD OFFICE');
        $this->hrga = $this->division($this->alfaRetail, 'HRGA', $this->headOffice->id);
        $this->store = $this->division($this->alfaRetail, 'STORE');
        $this->tokoKemang = $this->division($this->alfaRetail, 'TOKO KEMANG', $this->store->id);
        $this->betaDivision = $this->division($this->betaCorp, 'OPERASIONAL');

        $this->shiftPagi = Shift::create(['company_id' => $this->alfaRetail->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '17:00', 'late_tolerance_minutes' => 10, 'is_active' => true]);

        $this->pic = app(TenantOnboardingService::class)->createPic($this->klienA, 'PIC Klien Alfa', 'pic.alfa@example.com')['user']->fresh();

        $this->hrAdmin = $this->account($this->klienA, ['Admin'], DataScope::Companies, [$this->alfaRetail->id]);
        $this->hrProfile = $this->employee($this->alfaRetail, $this->hrga, 'ALR-HR', 'Hana HR', $this->hrAdmin);

        $this->storeAdmin = $this->account($this->klienA, ['Admin'], DataScope::Divisions, divisionIds: [$this->store->id]);

        $this->staffHrga = $this->employee($this->alfaRetail, $this->hrga, 'ALR-001', 'Sari Staff HRGA');
        $this->crewKemang = $this->employee($this->alfaRetail, $this->tokoKemang, 'ALR-002', 'Kurnia Crew Kemang');
        $this->driverLogistik = $this->employee($this->alfaLogistik, $this->division($this->alfaLogistik, 'GUDANG'), 'ALL-001', 'Dodi Driver Logistik');
        $this->betaWorker = $this->employee($this->betaCorp, $this->betaDivision, 'BTC-001', 'Bima Pekerja Beta');

        DataAccessService::flush();
    }

    // ── Pagi: master data ──────────────────────────────────────────────────

    public function test_pagi_hr_membuka_master_data_pekerja_dari_aplikasi_hanya_dalam_aksesnya(): void
    {
        $this->grantAdminMasterDataPermissions();
        $intern = $this->intern($this->alfaRetail, $this->tokoKemang, 'Ilham Magang');
        $betaIntern = $this->intern($this->betaCorp, $this->betaDivision, 'Intan Magang Beta');
        $freelancer = $this->freelancer($this->alfaRetail, $this->tokoKemang, 'Fajar Freelance');
        $betaFreelancer = $this->freelancer($this->betaCorp, $this->betaDivision, 'Feri Freelance Beta');

        Sanctum::actingAs($this->hrAdmin);

        $employeeIds = $this->getJson('/api/v1/master/employees')->assertOk()->json('data.data.*.id');
        $this->assertEqualsCanonicalizing([$this->hrProfile->id, $this->staffHrga->id, $this->crewKemang->id], $employeeIds);

        $this->assertSame([$intern->id], $this->getJson('/api/v1/master/interns')->assertOk()->json('data.data.*.id'));
        $this->assertSame([$freelancer->id], $this->getJson('/api/v1/master/freelancers')->assertOk()->json('data.data.*.id'));

        $this->getJson("/api/v1/master/employees/{$this->crewKemang->id}")->assertOk()->assertJsonPath('data.full_name', 'Kurnia Crew Kemang');

        // Another company of the same Klien outside the HR's access, and Klien B, are not found.
        $this->getJson("/api/v1/master/employees/{$this->driverLogistik->id}")->assertNotFound();
        $this->getJson("/api/v1/master/employees/{$this->betaWorker->id}")->assertNotFound();
        $this->getJson("/api/v1/master/interns/{$betaIntern->id}")->assertNotFound();
        $this->getJson("/api/v1/master/freelancers/{$betaFreelancer->id}")->assertNotFound();

        $companyIds = $this->getJson('/api/v1/master/companies')->assertOk()->json('data.*.id');
        $this->assertSame([$this->alfaRetail->id], $companyIds);
    }

    public function test_master_data_api_tertutup_bila_shield_belum_memberi_izin_ke_admin(): void
    {
        Sanctum::actingAs($this->hrAdmin);

        // API.md 4.1: master data for Admin is "Izin", not granted by the default seeder.
        $this->getJson('/api/v1/master/employees')->assertForbidden();
        $this->getJson('/api/v1/master/users')->assertForbidden();
        $this->getJson('/api/v1/shield/roles')->assertForbidden();
    }

    public function test_hr_mendaftarkan_karyawan_magang_dan_freelancer_baru_di_panel(): void
    {
        $this->actingAs($this->hrAdmin);

        $this->assertTrue(EmployeeResource::canViewAny());
        $this->assertTrue(InternResource::canViewAny());
        $this->assertTrue(FreelanceResource::canViewAny());

        Livewire::test(CreateEmployee::class)
            ->fillForm([
                'nip' => 'ALR-100',
                'full_name' => 'Rina Karyawan Baru',
                'email' => 'rina.baru@example.com',
                'phone' => '081200000100',
                'company_id' => $this->alfaRetail->id,
                'division_id' => $this->tokoKemang->id,
                'job_level_id' => JobLevel::firstOrFail()->id,
                'job_title_id' => JobTitle::firstOrFail()->id,
                'join_date' => '2026-11-18',
                'status' => 'active',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('employees', ['nip' => 'ALR-100', 'company_id' => $this->alfaRetail->id, 'division_id' => $this->tokoKemang->id]);

        Livewire::test(CreateIntern::class)
            ->fillForm([
                'nis' => 'MG-ALR-01',
                'full_name' => 'Maya Magang Baru',
                'institution' => 'SMK Negeri 1',
                'company_id' => $this->alfaRetail->id,
                'division_id' => $this->hrga->id,
                'start_date' => '2026-11-18',
                'end_date' => '2027-02-18',
                'status' => 'active',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('interns', ['nis' => 'MG-ALR-01', 'company_id' => $this->alfaRetail->id]);

        Livewire::test(CreateFreelancer::class)
            ->fillForm([
                'freelancer_number' => 'FL-ALR-01',
                'full_name' => 'Fikri Freelance Baru',
                'company_id' => $this->alfaRetail->id,
                'division_id' => $this->tokoKemang->id,
                'start_date' => '2026-11-18',
                'end_date' => '2026-12-31',
                'status' => 'active',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('freelancers', ['freelancer_number' => 'FL-ALR-01', 'company_id' => $this->alfaRetail->id]);
    }

    public function test_hr_tidak_bisa_menempatkan_pekerja_di_badan_usaha_di_luar_aksesnya_atau_klien_lain(): void
    {
        $this->actingAs($this->hrAdmin);

        $this->assertSame([$this->alfaRetail->id => 'PT Alfa Retail'], EmployeeResource::placeableCompanyOptions());

        foreach ([$this->alfaLogistik, $this->betaCorp] as $company) {
            Livewire::test(CreateEmployee::class)
                ->fillForm([
                    'nip' => 'X-'.$company->code,
                    'full_name' => 'Penyusup',
                    'company_id' => $company->id,
                    'division_id' => $company->is($this->betaCorp) ? $this->betaDivision->id : Division::where('company_id', $company->id)->value('id'),
                    'job_level_id' => JobLevel::firstOrFail()->id,
                    'job_title_id' => JobTitle::firstOrFail()->id,
                    'status' => 'active',
                ])
                ->call('create')
                ->assertHasFormErrors(['company_id']);
        }

        $this->assertDatabaseMissing('employees', ['full_name' => 'Penyusup']);
    }

    public function test_hr_tidak_bisa_memindahkan_pekerja_ke_badan_usaha_di_luar_aksesnya(): void
    {
        $logistikDivision = Division::where('company_id', $this->alfaLogistik->id)->firstOrFail();

        $this->actingAs($this->hrAdmin);

        Livewire::test(EditEmployee::class, ['record' => $this->staffHrga->getRouteKey()])
            ->fillForm(['company_id' => $this->alfaLogistik->id, 'division_id' => $logistikDivision->id])
            ->call('save')
            ->assertHasFormErrors(['company_id']);

        Livewire::test(EditEmployee::class, ['record' => $this->staffHrga->getRouteKey()])
            ->fillForm(['company_id' => $this->betaCorp->id, 'division_id' => $this->betaDivision->id])
            ->call('save')
            ->assertHasFormErrors(['company_id']);

        $this->assertSame($this->alfaRetail->id, (int) $this->staffHrga->fresh()->company_id);

        // Moving inside the HR's own company is a normal daily change.
        Livewire::test(EditEmployee::class, ['record' => $this->staffHrga->getRouteKey()])
            ->fillForm(['division_id' => $this->tokoKemang->id, 'job_title_id' => JobTitle::firstOrFail()->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($this->tokoKemang->id, (int) $this->staffHrga->fresh()->division_id);
    }

    public function test_admin_berakses_divisi_tidak_bisa_mendaftarkan_pekerja_di_divisi_lain(): void
    {
        $this->actingAs($this->storeAdmin);

        $this->assertSame([$this->store->id, $this->tokoKemang->id], array_keys(EmployeeResource::placeableDivisionOptions($this->alfaRetail->id)));

        Livewire::test(CreateEmployee::class)
            ->fillForm([
                'nip' => 'ALR-200',
                'full_name' => 'Staf HO Titipan',
                'company_id' => $this->alfaRetail->id,
                'division_id' => $this->hrga->id,
                'job_level_id' => JobLevel::firstOrFail()->id,
                'job_title_id' => JobTitle::firstOrFail()->id,
                'status' => 'active',
            ])
            ->call('create')
            ->assertHasFormErrors(['division_id']);

        $this->assertDatabaseMissing('employees', ['nip' => 'ALR-200']);
    }

    // ── Akun pekerja ───────────────────────────────────────────────────────

    public function test_hr_membuatkan_akun_login_untuk_karyawan_dari_panel(): void
    {
        $this->actingAs($this->hrAdmin);

        Livewire::test(ListEmployees::class)
            ->callAction(TestAction::make('createUser')->table($this->staffHrga), data: ['role' => 'Employee'])
            ->assertHasNoFormErrors()
            ->assertNotified();

        $account = $this->staffHrga->fresh()->user;
        $this->assertNotNull($account);
        $this->assertTrue($account->hasRole('Employee'));
        $this->assertSame($this->klienA->id, (int) $account->tenant_id);
        $this->assertSame(DataScope::Own, $account->dataScope());

        // Admin is never in the role list of a non-PIC Admin.
        Livewire::test(ListEmployees::class)
            ->callAction(TestAction::make('createUser')->table($this->crewKemang), data: ['role' => 'Admin'])
            ->assertHasFormErrors(['role']);

        $this->assertNull($this->crewKemang->fresh()->user_id);
    }

    public function test_hr_membuat_akun_dari_aplikasi_tanpa_bisa_memberi_admin_pic_atau_superadmin(): void
    {
        Sanctum::actingAs($this->hrAdmin);

        $roles = $this->getJson('/api/v1/client/accounts/options')->assertOk()->json('roles');
        $roleNames = collect($roles)->map(fn ($role) => is_array($role) ? ($role['value'] ?? $role['name'] ?? null) : $role)->all();
        $this->assertContains('Employee', $roleNames);
        $this->assertContains('Koordinator', $roleNames);
        $this->assertNotContains('Admin', $roleNames);
        $this->assertNotContains('PIC', $roleNames);
        $this->assertNotContains('Superadmin', $roleNames);

        $this->postJson('/api/v1/client/accounts', [
            'name' => 'Koordinator Toko Kemang',
            'email' => 'koor.kemang@example.com',
            'roles' => ['Koordinator'],
            'data_scope' => 'divisions',
            'division_ids' => [$this->tokoKemang->id],
        ])
            ->assertCreated()
            ->assertJsonPath('credentials.whatsapp_sent', false);

        $this->assertSame($this->klienA->id, (int) User::where('email', 'koor.kemang@example.com')->value('tenant_id'));

        foreach (['Admin', 'PIC', 'Superadmin'] as $role) {
            $this->postJson('/api/v1/client/accounts', ['name' => 'X', 'email' => strtolower($role).'.x@example.com', 'roles' => [$role], 'data_scope' => 'own'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['roles.0']);
        }

        // Data access never wider than the HR's own, never another Klien.
        $this->postJson('/api/v1/client/accounts', ['name' => 'Y', 'email' => 'y@example.com', 'roles' => ['Approver'], 'data_scope' => 'all'])
            ->assertUnprocessable()->assertJsonValidationErrors(['data_scope']);
        $this->postJson('/api/v1/client/accounts', ['name' => 'Y', 'email' => 'y@example.com', 'roles' => ['Approver'], 'data_scope' => 'companies', 'company_ids' => [$this->alfaLogistik->id]])
            ->assertUnprocessable()->assertJsonValidationErrors(['data_scope']);
        $this->postJson('/api/v1/client/accounts', ['name' => 'Y', 'email' => 'y@example.com', 'roles' => ['Approver'], 'data_scope' => 'divisions', 'division_ids' => [$this->betaDivision->id]])
            ->assertUnprocessable()->assertJsonValidationErrors(['data_scope']);

        $this->assertDatabaseMissing('users', ['email' => 'admin.x@example.com']);
        $this->assertDatabaseMissing('users', ['email' => 'y@example.com']);
    }

    public function test_hr_tidak_bisa_mengubah_akun_pic_admin_lain_atau_akun_klien_b(): void
    {
        $betaAdmin = $this->account($this->klienB, ['Admin'], DataScope::All);
        $betaWorkerAccount = $this->linkAccount($this->betaWorker);

        Sanctum::actingAs($this->hrAdmin);

        $this->putJson("/api/v1/client/accounts/{$this->pic->id}", ['name' => 'Diambil alih', 'roles' => ['Employee'], 'data_scope' => 'own', 'is_active' => true])->assertForbidden();
        $this->putJson("/api/v1/client/accounts/{$this->storeAdmin->id}", ['name' => 'Diambil alih', 'roles' => ['Employee'], 'data_scope' => 'own', 'is_active' => true])->assertForbidden();
        $this->postJson("/api/v1/client/accounts/{$this->pic->id}/reset-password")->assertForbidden();
        $this->putJson("/api/v1/client/accounts/{$this->hrAdmin->id}", ['name' => 'Saya', 'roles' => ['Admin'], 'data_scope' => 'all', 'is_active' => true])->assertForbidden();

        $this->getJson("/api/v1/client/accounts/{$betaAdmin->id}")->assertNotFound();
        $this->getJson("/api/v1/client/accounts/{$betaWorkerAccount->id}")->assertNotFound();
        $this->putJson("/api/v1/client/accounts/{$betaWorkerAccount->id}", ['name' => 'X', 'roles' => ['Employee'], 'data_scope' => 'own', 'is_active' => false])->assertNotFound();
        $this->getJson("/api/v1/client/accounts/{$this->superadmin->id}")->assertNotFound();

        $listed = $this->getJson('/api/v1/client/accounts')->assertOk()->json('data.*.id');
        $this->assertNotContains($betaAdmin->id, $listed);
        $this->assertNotContains($betaWorkerAccount->id, $listed);
        $this->assertNotContains($this->superadmin->id, $listed);

        $this->assertSame('PIC Klien Alfa', $this->pic->fresh()->name);
        $this->assertTrue($betaWorkerAccount->fresh()->is_active);

        $this->actingAs($this->hrAdmin);
        $this->assertFalse(UserResource::canEdit($this->pic));
        $this->assertFalse(UserResource::canEdit($this->storeAdmin));
        $this->assertFalse(UserResource::canEdit($betaWorkerAccount));
        $this->assertFalse(UserResource::canDelete($this->pic));
    }

    public function test_hr_menonaktifkan_dan_mereset_akun_pekerja_dalam_aksesnya(): void
    {
        $crewAccount = $this->linkAccount($this->crewKemang);
        $crewAccount->createToken('mobile');

        Sanctum::actingAs($this->hrAdmin);

        $this->postJson("/api/v1/client/accounts/{$crewAccount->id}/reset-password")
            ->assertOk()
            ->assertJsonPath('credentials.whatsapp_sent', false);
        $this->assertSame(0, $crewAccount->tokens()->count(), 'A password reset signs the worker out of the app.');

        $this->putJson("/api/v1/client/accounts/{$crewAccount->id}", ['name' => $crewAccount->name, 'roles' => ['Employee'], 'data_scope' => 'own', 'is_active' => false])
            ->assertOk();
        $this->assertFalse($crewAccount->fresh()->is_active);

        // Widening the worker's data access beyond the HR's own is refused.
        $this->putJson("/api/v1/client/accounts/{$crewAccount->id}", ['name' => $crewAccount->name, 'roles' => ['Approver'], 'data_scope' => 'companies', 'company_ids' => [$this->alfaLogistik->id], 'is_active' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['data_scope']);
        $this->putJson("/api/v1/client/accounts/{$crewAccount->id}", ['name' => $crewAccount->name, 'roles' => ['Admin'], 'data_scope' => 'own', 'is_active' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['roles.0']);
        $this->assertFalse($crewAccount->fresh()->hasRole('Admin'));

        // Klien B worker accounts are invisible to the reset as well.
        $betaAccount = $this->linkAccount($this->betaWorker);
        $this->postJson("/api/v1/client/accounts/{$betaAccount->id}/reset-password")->assertNotFound();
    }

    // ── Jadwal shift ───────────────────────────────────────────────────────

    public function test_hr_menyusun_jadwal_shift_minggu_lalu_lalu_menyalinnya_ke_minggu_ini(): void
    {
        $lastMonday = today()->subWeek()->startOfWeek();
        $thisMonday = today()->startOfWeek();

        $this->actingAs($this->hrAdmin);
        $this->assertTrue(ShiftScheduleResource::canViewAny());

        Livewire::test(ManageShiftSchedules::class)
            ->callAction('assignSchedule', data: [
                'company_id' => $this->alfaRetail->id,
                'workers' => ["employee:{$this->staffHrga->id}", "employee:{$this->crewKemang->id}"],
                'date_from' => $lastMonday->toDateString(),
                'date_until' => $lastMonday->copy()->addDays(4)->toDateString(),
                'mode' => 'shift',
                'shift_id' => $this->shiftPagi->id,
                'overwrite' => true,
            ])
            ->assertHasNoFormErrors()
            ->assertNotified('Jadwal shift disimpan');

        $this->assertSame(10, ShiftSchedule::whereBetween('date', [$lastMonday->toDateString(), $lastMonday->copy()->endOfWeek()->toDateString()])->count());

        // Kurnia already has Monday of this week: the copy keeps it.
        ShiftSchedule::create(['employee_id' => $this->crewKemang->id, 'date' => $thisMonday->toDateString(), 'is_day_off' => true]);

        Livewire::test(ManageShiftSchedules::class)
            ->callAction('copyWeek', data: ['from' => $lastMonday->toDateString(), 'to' => $thisMonday->toDateString()])
            ->assertHasNoFormErrors()
            ->assertNotified('Jadwal disalin');

        $thisWeek = ShiftSchedule::whereBetween('date', [$thisMonday->toDateString(), $thisMonday->copy()->endOfWeek()->toDateString()]);
        $this->assertSame(10, (clone $thisWeek)->count());
        $this->assertTrue((bool) ShiftSchedule::where('employee_id', $this->crewKemang->id)->whereDate('date', $thisMonday)->value('is_day_off'));
        $this->assertSame(5, (clone $thisWeek)->where('employee_id', $this->staffHrga->id)->where('shift_id', $this->shiftPagi->id)->count());
    }

    public function test_salin_minggu_dari_aplikasi_tidak_menyalin_jadwal_pekerja_di_luar_akses(): void
    {
        $lastMonday = today()->subWeek()->startOfWeek();
        $thisMonday = today()->startOfWeek();
        $logistikShift = Shift::create(['company_id' => $this->alfaLogistik->id, 'name' => 'Gudang', 'start_time' => '07:00', 'end_time' => '15:00', 'is_active' => true]);
        $betaShift = Shift::create(['company_id' => $this->betaCorp->id, 'name' => 'Beta', 'start_time' => '07:00', 'end_time' => '15:00', 'is_active' => true]);

        ShiftSchedule::create(['employee_id' => $this->staffHrga->id, 'date' => $lastMonday->toDateString(), 'shift_id' => $this->shiftPagi->id]);
        ShiftSchedule::create(['employee_id' => $this->driverLogistik->id, 'date' => $lastMonday->toDateString(), 'shift_id' => $logistikShift->id]);
        ShiftSchedule::create(['employee_id' => $this->betaWorker->id, 'date' => $lastMonday->toDateString(), 'shift_id' => $betaShift->id]);

        Sanctum::actingAs($this->hrAdmin);

        $this->postJson('/api/v1/team/schedules/copy', ['from' => $lastMonday->toDateString(), 'to' => $thisMonday->toDateString()])
            ->assertOk()
            ->assertJsonPath('data.copied', 1);

        $this->assertTrue(ShiftSchedule::where('employee_id', $this->staffHrga->id)->whereDate('date', $thisMonday)->exists());
        $this->assertFalse(ShiftSchedule::where('employee_id', $this->driverLogistik->id)->whereDate('date', $thisMonday)->exists());
        $this->assertFalse(ShiftSchedule::where('employee_id', $this->betaWorker->id)->whereDate('date', $thisMonday)->exists());

        // Assigning a Klien B worker through the panel never lands.
        $this->actingAs($this->hrAdmin);
        try {
            Livewire::test(ManageShiftSchedules::class)->instance()->assignSchedule([
                'company_id' => $this->alfaRetail->id,
                'workers' => ["employee:{$this->betaWorker->id}"],
                'date_from' => today()->toDateString(),
                'date_until' => today()->toDateString(),
                'weekdays' => [1, 2, 3, 4, 5, 6, 7],
                'mode' => 'day_off',
                'overwrite' => true,
            ]);
        } catch (\Throwable) {
            // Refusing outright is fine too.
        }

        $this->assertFalse(ShiftSchedule::where('employee_id', $this->betaWorker->id)->whereDate('date', today())->exists());
    }

    // ── Data absensi ───────────────────────────────────────────────────────

    public function test_hr_mengoreksi_data_absensi_pekerja_di_panel(): void
    {
        $attendance = Attendance::create(['employee_id' => $this->crewKemang->id, 'date' => today()->subDay()->toDateString(), 'check_in' => today()->subDay()->setTime(9, 30), 'status' => 'late', 'late_minutes' => 80]);

        $this->actingAs($this->hrAdmin);
        $this->assertTrue(AttendanceResource::canViewAny());

        Livewire::test(EditAttendance::class, ['record' => $attendance->getRouteKey()])
            ->fillForm([
                'check_in' => today()->subDay()->setTime(8, 0)->toDateTimeString(),
                'check_out' => today()->subDay()->setTime(17, 0)->toDateTimeString(),
                'status' => 'on_time',
                'late_minutes' => 0,
                'notes' => 'Mesin absen toko mati, dikonfirmasi kepala toko.',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $attendance->refresh();
        $this->assertSame('on_time', $attendance->status->value);
        $this->assertSame(0, (int) $attendance->late_minutes);
        $this->assertSame('08:00', $attendance->check_in->format('H:i'));
        $this->assertSame('17:00', $attendance->check_out->format('H:i'));
    }

    public function test_hr_tidak_melihat_atau_mengubah_absensi_di_luar_akses_dan_klien_b(): void
    {
        $own = Attendance::create(['employee_id' => $this->staffHrga->id, 'date' => today()->toDateString(), 'check_in' => now()->setTime(8, 0), 'status' => 'on_time']);
        $logistik = Attendance::create(['employee_id' => $this->driverLogistik->id, 'date' => today()->toDateString(), 'check_in' => now()->setTime(8, 0), 'status' => 'on_time']);
        $beta = Attendance::create(['employee_id' => $this->betaWorker->id, 'date' => today()->toDateString(), 'check_in' => now()->setTime(8, 0), 'status' => 'on_time']);

        $this->actingAs($this->hrAdmin);

        $this->assertSame([$own->id], AttendanceResource::getEloquentQuery()->pluck('id')->all());
        $this->get(AttendanceResource::getUrl('edit', ['record' => $own]))->assertOk();
        $this->get(AttendanceResource::getUrl('edit', ['record' => $logistik]))->assertNotFound();
        $this->get(AttendanceResource::getUrl('edit', ['record' => $beta]))->assertNotFound();

        Sanctum::actingAs($this->hrAdmin);
        $this->assertSame([$own->id], $this->getJson('/api/v1/attendances')->assertOk()->json('data.*.id'));
        $this->getJson("/api/v1/attendances/{$beta->id}")->assertForbidden();
        $this->getJson("/api/v1/attendances/{$logistik->id}")->assertForbidden();
    }

    // ── Persetujuan ────────────────────────────────────────────────────────

    public function test_karyawan_mengajukan_koreksi_dan_hr_menyetujuinya_sebagai_admin_dari_aplikasi(): void
    {
        $crewAccount = $this->linkAccount($this->crewKemang);
        $yesterday = today()->subDay();
        Attendance::create(['employee_id' => $this->crewKemang->id, 'date' => $yesterday->toDateString(), 'check_in' => $yesterday->copy()->setTime(9, 45), 'status' => 'late', 'late_minutes' => 105]);

        Sanctum::actingAs($crewAccount);
        $correctionId = $this->postJson('/api/v1/attendance-corrections', [
            'date' => $yesterday->toDateString(),
            'corrected_check_in' => '08:00',
            'corrected_check_out' => '17:00',
            'reason' => 'Lupa absen masuk, sudah di toko sejak pagi.',
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($this->hrAdmin);

        $pending = $this->getJson('/api/v1/approvals/pending')->assertOk();
        $this->assertContains($correctionId, $pending->json('attendance_corrections.*.id'));

        $this->putJson("/api/v1/approvals/correction/{$correctionId}/process", ['action' => 'approved'])
            ->assertOk()
            ->assertJsonPath('status', 'approved');

        $attendance = Attendance::where('employee_id', $this->crewKemang->id)->whereDate('date', $yesterday)->firstOrFail();
        $this->assertSame('08:00', $attendance->check_in->format('H:i'));
        $this->assertSame('17:00', $attendance->check_out->format('H:i'));
        $this->assertTrue((bool) $attendance->is_corrected);
        $this->assertSame('on_time', $attendance->status->value);
    }

    public function test_hr_menolak_lembur_wajib_dengan_alasan_dan_menyetujui_cuti_dari_panel(): void
    {
        $overtime = $this->pendingOvertime($this->staffHrga);
        $leave = $this->pendingLeave($this->crewKemang);

        Sanctum::actingAs($this->hrAdmin);

        $this->putJson("/api/v1/approvals/overtime/{$overtime->id}/process", ['action' => 'rejected'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rejection_note']);
        $this->assertSame('pending', $overtime->fresh()->status);

        $this->putJson("/api/v1/approvals/overtime/{$overtime->id}/process", ['action' => 'rejected', 'rejection_note' => 'Lembur tidak direncanakan.'])
            ->assertOk()
            ->assertJsonPath('status', 'rejected');

        $this->actingAs($this->hrAdmin);
        $this->assertTrue(ApprovalSaya::canAccess());

        $inbox = Livewire::test(ApprovalSaya::class)->assertSuccessful();
        $this->assertSame([$leave->id], $inbox->instance()->pendingLeaveRequests->pluck('id')->values()->all());

        Livewire::test(ApprovalSaya::class)
            ->callAction(TestAction::make('approve')->arguments(['type' => 'leave', 'id' => $leave->id]))
            ->assertHasNoActionErrors();

        $this->assertSame('approved', $leave->fresh()->status);
    }

    public function test_hr_tidak_bisa_memproses_pengajuannya_sendiri(): void
    {
        $ownLeave = $this->pendingLeave($this->hrProfile);

        Sanctum::actingAs($this->hrAdmin);

        $this->assertNotContains($ownLeave->id, $this->getJson('/api/v1/approvals/pending')->assertOk()->json('leave_requests.*.id'));

        $this->putJson("/api/v1/approvals/leave/{$ownLeave->id}/process", ['action' => 'approved'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['approve']);
        $this->putJson("/api/v1/approvals/leave/{$ownLeave->id}/process", ['action' => 'rejected', 'rejection_note' => 'Batal'])
            ->assertUnprocessable();

        $this->assertSame('pending', $ownLeave->fresh()->status);
    }

    public function test_hr_tidak_bisa_memproses_pengajuan_badan_usaha_lain_atau_klien_b(): void
    {
        $logistikLeave = $this->pendingLeave($this->driverLogistik);
        $betaLeave = $this->pendingLeave($this->betaWorker);

        Sanctum::actingAs($this->hrAdmin);

        $pendingIds = $this->getJson('/api/v1/approvals/pending')->assertOk()->json('leave_requests.*.id');
        $this->assertNotContains($logistikLeave->id, $pendingIds);
        $this->assertNotContains($betaLeave->id, $pendingIds);

        $this->putJson("/api/v1/approvals/leave/{$betaLeave->id}/process", ['action' => 'approved'])->assertUnprocessable();
        $this->putJson("/api/v1/approvals/leave/{$logistikLeave->id}/process", ['action' => 'rejected', 'rejection_note' => 'x'])->assertUnprocessable();
        $this->getJson("/api/v1/leave-requests/{$betaLeave->id}")->assertForbidden();

        $this->assertSame('pending', $betaLeave->fresh()->status);
        $this->assertSame('pending', $logistikLeave->fresh()->status);

        $this->actingAs($this->hrAdmin);
        $this->assertFalse(LeaveRequestResource::canEdit($betaLeave->load('employee')));
        $this->assertNotContains($betaLeave->id, LeaveRequestResource::getEloquentQuery()->pluck('id')->all());
    }

    // ── Laporan ────────────────────────────────────────────────────────────

    public function test_hr_menarik_laporan_absensi_bulanan_dan_ekspor_rekap(): void
    {
        $monthStart = today()->startOfMonth();
        foreach ([$this->staffHrga, $this->crewKemang, $this->driverLogistik, $this->betaWorker] as $worker) {
            Attendance::create(['employee_id' => $worker->id, 'date' => $monthStart->copy()->addDays(2)->toDateString(), 'check_in' => $monthStart->copy()->addDays(2)->setTime(8, 0), 'check_out' => $monthStart->copy()->addDays(2)->setTime(17, 0), 'status' => 'on_time']);
        }
        Attendance::create(['employee_id' => $this->crewKemang->id, 'date' => $monthStart->copy()->addDays(3)->toDateString(), 'check_in' => $monthStart->copy()->addDays(3)->setTime(8, 30), 'status' => 'late', 'late_minutes' => 20]);

        $this->actingAs($this->hrAdmin);

        $page = new LaporanAbsensi;
        $page->filterData = ['date_from' => $monthStart->toDateString(), 'date_to' => today()->toDateString()];
        $employeeIds = $page->getReportDataProperty()->pluck('employee_id')->unique()->values()->all();
        $this->assertEqualsCanonicalizing([$this->staffHrga->id, $this->crewKemang->id], $employeeIds);

        $export = $page->exportExcel();
        $this->assertInstanceOf(BinaryFileResponse::class, $export);
        $sheet = IOFactory::load($export->getFile()->getPathname())->getSheetByName('Rekap');
        $names = collect($sheet->toArray())->pluck(0)->filter()->all();
        $this->assertContains('Kurnia Crew Kemang', $names);
        $this->assertNotContains('Dodi Driver Logistik', $names);
        $this->assertNotContains('Bima Pekerja Beta', $names);

        Sanctum::actingAs($this->hrAdmin);
        $report = $this->getJson('/api/v1/laporan-absensi?start_date='.$monthStart->toDateString().'&end_date='.today()->toDateString())->assertOk();
        $this->assertSame(1, $report->json('summary.total_late'));
        $this->assertSame(3, $report->json('pagination.total'));

        $recapNames = collect($this->getJson('/api/v1/team/recap?month='.today()->format('Y-m'))->assertOk()->json('data'))->flatten()->filter(fn ($value) => is_string($value))->all();
        $this->assertContains('Kurnia Crew Kemang', $recapNames);
        $this->assertNotContains('Bima Pekerja Beta', $recapNames);
        $this->assertNotContains('Dodi Driver Logistik', $recapNames);

        $this->actingAs($this->hrAdmin);
        Livewire::test(LaporanAbsensi::class)
            ->callAction('teamRecap', data: ['month' => today()->format('Y-m')])
            ->assertHasNoActionErrors()
            ->assertFileDownloaded();
    }

    // ── Batas akses data ───────────────────────────────────────────────────

    public function test_admin_berakses_divisi_hanya_mengelola_pekerja_divisinya_tanpa_pengaturan_badan_usaha(): void
    {
        $bod = $this->account($this->klienA, ['BOD'], DataScope::All);
        $kemangLeave = $this->pendingLeave($this->crewKemang);
        $hrgaLeave = $this->pendingLeave($this->staffHrga);

        $this->actingAs($this->storeAdmin);

        $this->assertSame([$this->crewKemang->id], EmployeeResource::getEloquentQuery()->pluck('id')->all());
        $this->assertFalse(CompanyResource::canViewAny());
        $this->assertFalse(DivisionResource::canViewAny());
        $this->assertFalse(EmployeeResource::canImportFromTalenta());
        $this->assertFalse(EmployeeResource::canManageAccount($bod));
        $this->get(EmployeeResource::getUrl('edit', ['record' => $this->staffHrga]))->assertNotFound();

        Sanctum::actingAs($this->storeAdmin);

        $this->getJson("/api/v1/client/companies/{$this->alfaRetail->id}/policy")->assertForbidden();
        $this->putJson("/api/v1/client/accounts/{$bod->id}", ['name' => 'X', 'roles' => ['BOD'], 'data_scope' => 'own', 'is_active' => true])->assertForbidden();

        $pending = $this->getJson('/api/v1/approvals/pending')->assertOk()->json('leave_requests.*.id');
        $this->assertSame([$kemangLeave->id], $pending);
        $this->putJson("/api/v1/approvals/leave/{$hrgaLeave->id}/process", ['action' => 'approved'])->assertUnprocessable();
        $this->assertSame('pending', $hrgaLeave->fresh()->status);
    }

    public function test_admin_badan_usaha_mengatur_badan_usahanya_tapi_tidak_menambah_badan_usaha_atau_klien(): void
    {
        $this->actingAs($this->hrAdmin);

        $this->assertTrue(CompanyResource::canViewAny());
        $this->assertFalse(CompanyResource::canCreate());
        $this->assertFalse(CompanyResource::canDelete($this->alfaRetail));
        $this->assertSame([$this->alfaRetail->id], CompanyResource::getEloquentQuery()->pluck('id')->all());
        $this->assertFalse(TenantResource::canViewAny());
        $this->assertFalse(Gate::allows('viewHorizon'));
        $this->assertFalse(Gate::allows('viewLogViewer'));

        Sanctum::actingAs($this->hrAdmin);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.access.client_admin.is_pic', false)
            ->assertJsonPath('user.access.client_admin.can_manage_accounts', true);

        $this->postJson('/api/v1/client/companies', ['name' => 'PT Baru', 'code' => 'BARU'])->assertForbidden();
        $this->getJson("/api/v1/client/companies/{$this->alfaRetail->id}/policy")->assertOk();
        $this->getJson("/api/v1/client/companies/{$this->alfaLogistik->id}/policy")->assertNotFound();
        $this->getJson("/api/v1/client/companies/{$this->betaCorp->id}/policy")->assertNotFound();
        $this->putJson("/api/v1/client/companies/{$this->betaCorp->id}", ['name' => 'Diambil', 'code' => 'BTC'])->assertNotFound();
        $this->assertSame('PT Beta Corp', $this->betaCorp->fresh()->name);
    }

    public function test_hr_tidak_pernah_melihat_data_klien_b_di_panel_maupun_aplikasi(): void
    {
        $this->grantAdminMasterDataPermissions();
        $betaIntern = $this->intern($this->betaCorp, $this->betaDivision, 'Intan Magang Beta');
        $betaFreelancer = $this->freelancer($this->betaCorp, $this->betaDivision, 'Feri Freelance Beta');
        $betaAccount = $this->linkAccount($this->betaWorker);
        $betaLeave = $this->pendingLeave($this->betaWorker);
        $betaSchedule = ShiftSchedule::create(['employee_id' => $this->betaWorker->id, 'date' => today()->toDateString(), 'is_day_off' => true]);

        $this->actingAs($this->hrAdmin);

        $this->assertNotContains($this->betaWorker->id, EmployeeResource::getEloquentQuery()->pluck('id')->all());
        $this->assertNotContains($betaIntern->id, InternResource::getEloquentQuery()->pluck('id')->all());
        $this->assertNotContains($betaFreelancer->id, FreelanceResource::getEloquentQuery()->pluck('id')->all());
        $this->assertNotContains($betaAccount->id, UserResource::getEloquentQuery()->pluck('id')->all());
        $this->assertNotContains($betaSchedule->id, ShiftScheduleResource::getEloquentQuery()->pluck('id')->all());
        $this->assertNotContains($betaLeave->id, LeaveRequestResource::getEloquentQuery()->pluck('id')->all());
        $this->get(EmployeeResource::getUrl('edit', ['record' => $this->betaWorker]))->assertNotFound();

        Sanctum::actingAs($this->hrAdmin);

        $this->assertNotContains($betaAccount->id, $this->getJson('/api/v1/master/users')->assertOk()->json('data.data.*.id'));
        $this->getJson("/api/v1/master/users/{$betaAccount->id}")->assertNotFound();
        $this->assertNotContains($this->betaCorp->id, $this->getJson('/api/v1/master/divisions')->assertOk()->json('data.*.company_id'));
        $this->assertNotContains($betaLeave->id, collect($this->getJson('/api/v1/kalender-cuti?month=11&year=2026')->assertOk()->json('data'))->pluck('id')->all());
        $this->assertNotContains($this->betaCorp->id, collect($this->getJson('/api/v1/client/overview')->assertOk()->json('companies'))->pluck('id')->all());
        $this->assertNotContains($this->betaCorp->id, collect($this->getJson('/api/v1/client/accounts/options')->assertOk()->json('companies'))->pluck('id')->all());
        $this->assertNotContains($this->betaDivision->id, collect($this->getJson('/api/v1/client/accounts/options')->json('divisions'))->pluck('id')->all());
        // Filtering on another client's company returns nothing, never their data.
        Attendance::create(['employee_id' => $this->betaWorker->id, 'date' => today()->toDateString(), 'check_in' => now()->setTime(8, 0), 'status' => 'on_time']);
        $this->assertSame([], $this->getJson("/api/v1/laporan-absensi?company_id={$this->betaCorp->id}")->assertOk()->json('data'));
        $this->assertSame([], $this->getJson("/api/v1/kalender-cuti?month=11&year=2026&company_id={$this->betaCorp->id}")->assertOk()->json('data'));
        $this->assertSame([], $this->getJson("/api/v1/master/employees?company_id={$this->betaCorp->id}")->assertOk()->json('data.data'));
        $this->putJson('/api/v1/team/schedules', ['worker_type' => 'employee', 'worker_id' => $this->betaWorker->id, 'date' => today()->addDay()->toDateString(), 'mode' => 'off'])
            ->assertNotFound();
        $this->assertFalse(ShiftSchedule::where('employee_id', $this->betaWorker->id)->whereDate('date', today()->addDay())->exists());

        // Tampering with the panel inbox arguments never approves another client's request.
        $this->actingAs($this->hrAdmin);
        Livewire::test(ApprovalSaya::class)->callAction(TestAction::make('approve')->arguments(['type' => 'leave', 'id' => $betaLeave->id]));
        $this->assertSame('pending', $betaLeave->fresh()->status);

        Sanctum::actingAs($this->hrAdmin);
        $teamWorkers = collect($this->getJson('/api/v1/team/workers')->assertOk()->json('data'))->pluck('name')->all();
        $this->assertContains('Kurnia Crew Kemang', $teamWorkers);
        $this->assertNotContains('Bima Pekerja Beta', $teamWorkers);
        $this->assertNotContains('Dodi Driver Logistik', $teamWorkers);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * What a Superadmin would switch on in Shield so HR may open the master data in the app.
     */
    private function grantAdminMasterDataPermissions(): void
    {
        Role::findByName('Admin')->givePermissionTo(['ViewAny:Employee', 'ViewAny:Intern', 'ViewAny:Freelancer', 'ViewAny:User', 'ViewAny:Company', 'ViewAny:Division']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function company(Tenant $tenant, string $name, string $code): Company
    {
        $company = new Company(['name' => $name, 'code' => $code, 'is_active' => true]);
        $company->tenant_id = $tenant->id;
        $company->save();

        app(ApprovalFlowService::class)->createDefaultFlows($company);

        return $company;
    }

    private function division(Company $company, string $name, ?int $parentId = null): Division
    {
        return Division::create(['company_id' => $company->id, 'parent_id' => $parentId, 'name' => $name, 'is_active' => true]);
    }

    /**
     * @param  list<string>  $roles
     * @param  list<int>  $companyIds
     * @param  list<int>  $divisionIds
     */
    private function account(Tenant $tenant, array $roles, DataScope $scope = DataScope::Own, array $companyIds = [], array $divisionIds = []): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['tenant_id' => $tenant->id])->save();
        $user->assignRole($roles);
        $user->grantDataAccess($scope, $companyIds, $divisionIds);

        return $user->fresh();
    }

    private function employee(Company $company, Division $division, string $nip, string $name, ?User $user = null): Employee
    {
        return Employee::create([
            'user_id' => $user?->id,
            'company_id' => $company->id,
            'division_id' => $division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Staff'])->id,
            'nip' => $nip,
            'full_name' => $name,
            'join_date' => '2025-01-06',
            'status' => 'active',
        ]);
    }

    private function intern(Company $company, Division $division, string $name): Intern
    {
        return Intern::create(['company_id' => $company->id, 'division_id' => $division->id, 'nis' => 'MG-'.fake()->unique()->numerify('####'), 'full_name' => $name, 'institution' => 'SMK', 'status' => 'active']);
    }

    private function freelancer(Company $company, Division $division, string $name): Freelancer
    {
        return Freelancer::create(['company_id' => $company->id, 'division_id' => $division->id, 'full_name' => $name, 'status' => 'active']);
    }

    private function linkAccount(Employee $employee): User
    {
        $account = User::factory()->create(['is_active' => true, 'name' => $employee->full_name]);
        $account->forceFill(['tenant_id' => $employee->company->tenant_id])->save();
        $account->assignRole('Employee');
        $employee->update(['user_id' => $account->id]);

        return $account->fresh();
    }

    private function pendingLeave(Employee $employee): LeaveRequest
    {
        $leave = LeaveRequest::create(['employee_id' => $employee->id, 'leave_type' => 'permission', 'start_date' => '2026-11-23', 'end_date' => '2026-11-23', 'days_count' => 1, 'reason' => 'Urusan keluarga', 'status' => 'pending']);
        app(ApprovalFlowService::class)->generateSteps($leave, 'leave');

        return $leave->fresh();
    }

    private function pendingOvertime(Employee $employee): OvertimeRequest
    {
        $overtime = OvertimeRequest::create(['employee_id' => $employee->id, 'date' => today()->toDateString(), 'start_time' => '17:00', 'end_time' => '19:00', 'duration_minutes' => 120, 'reason' => 'Stock opname', 'status' => 'pending']);
        app(ApprovalFlowService::class)->generateSteps($overtime, 'overtime');

        return $overtime->fresh();
    }
}
