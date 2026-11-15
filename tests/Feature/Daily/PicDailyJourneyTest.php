<?php

namespace Tests\Feature\Daily;

use App\Enums\DataScope;
use App\Filament\Resources\CompanyLocationResource\Pages\CreateCompanyLocation;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\CompanyResource\Pages\CreateCompany;
use App\Filament\Resources\CompanyResource\Pages\EditCompany;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Filament\Resources\DivisionResource\Pages\CreateDivision;
use App\Filament\Resources\JobLevelResource;
use App\Filament\Resources\TenantResource;
use App\Filament\Resources\TenantResource\Pages\CreateTenant;
use App\Filament\Resources\TenantResource\Pages\EditTenant;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\ApprovalFlow;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalFlowService;
use App\Services\DataAccessService;
use App\Services\Face\FaceDetection;
use App\Services\Face\FaceMatch;
use App\Services\Face\FaceVerifier;
use App\Services\GeofenceService;
use App\Services\TenantOnboardingService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A realistic day of the PIC of a Klien: the Superadmin only creates the Klien and its PIC, then the
 * PIC runs everything (Badan Usaha, attendance policy, locations, divisions, accounts) from the app
 * and the web panel, without ever reaching Klien B and without ever needing the Superadmin.
 */
class PicDailyJourneyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $klienA;

    private Tenant $klienB;

    private Company $companyB;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp_gateway.url' => null,
            'services.whatsapp_gateway.token' => null,
            'services.face.driver' => 'basic',
            'services.onesignal.app_id' => null,
            'services.onesignal.rest_api_key' => null,
        ]);
        Http::fake();

        foreach (['Superadmin', 'Admin', 'PIC', 'Koordinator', 'Approver', 'BOD', 'Employee', 'Intern', 'Freelancer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->superadmin = User::factory()->create(['is_active' => true]);
        $this->superadmin->assignRole('Superadmin');

        $this->klienA = Tenant::factory()->create(['name' => 'Klien A', 'code' => 'KA', 'is_active' => true]);
        $this->klienB = Tenant::factory()->create(['name' => 'Klien B', 'code' => 'KB', 'is_active' => true]);
        $this->companyB = $this->company($this->klienB, 'PT Bravo', 'BRAVO', ['latitude' => -6.3, 'longitude' => 106.9]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function company(Tenant $tenant, string $name, string $code, array $attributes = []): Company
    {
        $company = new Company(['name' => $name, 'code' => $code, 'is_active' => true, ...$attributes]);
        $company->tenant_id = $tenant->id;
        $company->save();
        app(ApprovalFlowService::class)->createDefaultFlows($company);

        return $company;
    }

    /**
     * The PIC as the Superadmin hands it over: account plus the generated password.
     *
     * @return array{user: User, password: string}
     */
    private function pic(Tenant $tenant, ?string $email = null): array
    {
        $result = app(TenantOnboardingService::class)->createPic($tenant, 'PIC '.$tenant->name, $email ?? fake()->unique()->safeEmail(), '081200000001');

        return ['user' => $result['user']->fresh(), 'password' => $result['password']];
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

    /**
     * Sign in like the mobile app and return the bearer token.
     */
    private function login(string $email, string $password): string
    {
        $this->forgetAuth();

        return (string) $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])
            ->assertOk()
            ->json('token');
    }

    /**
     * Drop the cached guard user so the next request authenticates its own bearer token.
     */
    private function forgetAuth(): void
    {
        config(['auth.defaults.guard' => 'web']);
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
        // The API login runs Auth::attempt() on the web guard; the test app keeps that session between
        // requests, which a stateless mobile client never has.
        $this->app['session']->flush();
        $this->flushHeaders();
        DataAccessService::flush();
    }

    private function asToken(string $token): static
    {
        $this->forgetAuth();

        return $this->withToken($token);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function policyPayload(array $overrides = []): array
    {
        return [
            'late_tolerance_minutes' => 10,
            'require_gps' => false,
            'geofence_radius_meters' => 100,
            'mock_location_action' => 'reject',
            'selfie_mode' => 'none',
            'face_match_threshold' => 60,
            'face_fail_action' => 'reject',
            'annual_leave_quota' => 12,
            'default_approval_stages' => 1,
            'work_start_time' => '08:00',
            'work_end_time' => '17:00',
            ...$overrides,
        ];
    }

    private function fakePoseCapableVerifier(): void
    {
        $this->app->instance(FaceVerifier::class, new class implements FaceVerifier
        {
            public function name(): string
            {
                return 'fake';
            }

            public function supportsPose(): bool
            {
                return true;
            }

            public function isAccurate(): bool
            {
                return true;
            }

            public function verify(string $selfiePath, string $masterPath, int $thresholdPercent): FaceMatch
            {
                return new FaceMatch(true, 99.0, 'fake');
            }

            public function detect(string $imagePath): FaceDetection
            {
                return new FaceDetection(true, 0.0, 0.0, 0.0);
            }
        });
    }

    // ── Day 0: the Superadmin hands over, the PIC takes it from there ─────

    public function test_superadmin_hanya_membuat_klien_dan_pic_lalu_pic_menyiapkan_semuanya_sendiri(): void
    {
        $this->actingAs($this->superadmin);
        Livewire::test(CreateTenant::class)
            ->fillForm(['name' => 'Grup Kopi', 'code' => 'KOPI', 'is_active' => true, 'pic_name' => 'Bu Rina', 'pic_email' => 'rina@kopi.test', 'pic_phone' => '081234567890'])
            ->call('create')
            ->assertHasNoFormErrors();

        $tenant = Tenant::query()->where('code', 'KOPI')->firstOrFail();
        $pic = User::query()->where('email', 'rina@kopi.test')->firstOrFail();
        $this->assertTrue($pic->isTenantOwner());
        $this->assertSame(0, $tenant->companies()->count(), 'The Superadmin hands over an empty Klien.');

        // From here only the PIC works.
        $pic->forceFill(['password' => 'rahasia-pic'])->save();
        $token = $this->login('rina@kopi.test', 'rahasia-pic');

        $this->asToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.access.client_admin.is_pic', true)
            ->assertJsonPath('user.access.client_admin.can_manage_companies', true);

        $companyId = $this->asToken($token)->postJson('/api/v1/client/companies', ['name' => 'PT Kopi Nusantara', 'code' => 'KN', 'latitude' => -6.2, 'longitude' => 106.8])
            ->assertCreated()
            ->json('data.id');
        $this->asToken($token)->postJson("/api/v1/client/companies/{$companyId}/locations", ['name' => 'Outlet Senayan', 'latitude' => -6.22, 'longitude' => 106.80, 'radius_meters' => 75])->assertCreated();
        $brandId = $this->asToken($token)->postJson("/api/v1/client/companies/{$companyId}/divisions", ['name' => 'Brand Kopi', 'code' => 'BK'])->assertCreated()->json('data.id');
        $this->asToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonFragment(['client_admin' => ['is_pic' => true, 'can_manage_companies' => true, 'can_manage_accounts' => true]]);
        $this->asToken($token)->putJson("/api/v1/client/companies/{$companyId}/policy", $this->policyPayload(['require_gps' => true, 'geofence_radius_meters' => 100, 'selfie_mode' => 'photo']))
            ->assertOk()
            ->assertJsonPath('data.mode_label', 'GPS + Foto');

        // Without an Approver the default stages have nobody to act on them.
        $company = Company::findOrFail($companyId);
        $this->assertNotSame([], ApprovalFlowService::unstaffedStages($company));

        $this->asToken($token)->postJson('/api/v1/client/accounts', ['name' => 'HR Kopi', 'email' => 'hr@kopi.test', 'roles' => ['Admin'], 'data_scope' => 'companies', 'company_ids' => [$companyId]])->assertCreated();
        $this->asToken($token)->postJson('/api/v1/client/accounts', ['name' => 'Kepala Brand', 'email' => 'kepala@kopi.test', 'roles' => ['Approver'], 'data_scope' => 'divisions', 'division_ids' => [$brandId]])->assertCreated();

        DataAccessService::flush();
        $this->assertSame([], ApprovalFlowService::unstaffedStages($company->fresh()), 'The Approver made by the PIC staffs every default stage: no Superadmin needed.');
        $this->assertSame($tenant->id, (int) $company->tenant_id);
        $this->assertSame(2, User::query()->where('tenant_id', $tenant->id)->whereKeyNot($pic->id)->count());

        // The Superadmin only monitors: the new Klien and its company are visible to them.
        $this->forgetAuth();
        $this->actingAs($this->superadmin);
        Livewire::test(ListCompanies::class)->assertCanSeeTableRecords([$company]);
        Http::assertNothingSent();
    }

    // ── Badan Usaha ────────────────────────────────────────────────────────

    public function test_pagi_hari_pic_menambah_badan_usaha_dari_aplikasi(): void
    {
        ['user' => $pic, 'password' => $password] = $this->pic($this->klienA);
        $token = $this->login($pic->email, $password);

        $this->asToken($token)->getJson('/api/v1/client/overview')
            ->assertOk()
            ->assertJsonPath('permissions.can_create_company', true)
            ->assertJsonPath('tenant.id', $this->klienA->id)
            ->assertJsonCount(0, 'companies');

        // A tenant_id smuggled in the payload never moves the company to Klien B.
        $this->asToken($token)->postJson('/api/v1/client/companies', ['name' => 'PT Alfa', 'code' => 'BRAVO', 'tenant_id' => $this->klienB->id, 'email' => 'alfa@a.test'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'BRAVO');
        $alfa = Company::query()->where('name', 'PT Alfa')->firstOrFail();
        $this->assertSame($this->klienA->id, (int) $alfa->tenant_id);
        $this->assertTrue(ApprovalFlow::query()->where('company_id', $alfa->id)->exists());

        $this->asToken($token)->postJson('/api/v1/client/companies', ['name' => 'PT Alfa Kembar', 'code' => 'BRAVO'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code' => 'Kode ini sudah dipakai badan usaha lain di klien ini.']);

        $this->asToken($token)->postJson('/api/v1/client/companies', ['name' => '', 'code' => '', 'latitude' => 120])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'code', 'latitude']);

        $this->asToken($token)->putJson("/api/v1/client/companies/{$alfa->id}", ['name' => 'PT Alfa Jaya', 'code' => 'ALFA', 'phone' => '0215550000'])
            ->assertOk()
            ->assertJsonPath('data.name', 'PT Alfa Jaya')
            ->assertJsonPath('data.code', 'ALFA');

        $this->asToken($token)->getJson('/api/v1/client/overview')
            ->assertOk()
            ->assertJsonCount(1, 'companies')
            ->assertJsonPath('companies.0.name', 'PT Alfa Jaya');
    }

    public function test_pic_menambah_dan_mengubah_badan_usaha_dari_panel_web(): void
    {
        ['user' => $pic] = $this->pic($this->klienA);
        $this->actingAs($pic);

        $this->assertTrue(CompanyResource::canCreate());

        Livewire::test(CreateCompany::class)
            ->fillForm([
                'name' => 'PT Panel',
                'code' => 'PANEL',
                'is_active' => true,
                'latitude' => -6.2,
                'longitude' => 106.8,
                'locations' => [
                    ['name' => 'Cabang Bandung', 'latitude' => -6.9, 'longitude' => 107.6, 'radius_meters' => 120, 'is_active' => true],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $company = Company::query()->where('code', 'PANEL')->firstOrFail();
        $this->assertSame($this->klienA->id, (int) $company->tenant_id);
        $this->assertSame(120, (int) $company->locations()->value('radius_meters'));
        $this->assertTrue(ApprovalFlow::query()->where('company_id', $company->id)->exists());

        // Next request: the new company is now in the PIC's reach.
        DataAccessService::flush();
        Livewire::test(EditCompany::class, ['record' => $company->getRouteKey()])
            ->fillForm(['name' => 'PT Panel Baru', 'is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('PT Panel Baru', $company->fresh()->name);
        $this->assertFalse($company->fresh()->is_active);

        Livewire::test(ListCompanies::class)
            ->assertCanSeeTableRecords([$company])
            ->assertCanNotSeeTableRecords([$this->companyB]);
    }

    // ── Attendance policy per mode ─────────────────────────────────────────

    public function test_pic_mengatur_kebijakan_absensi_untuk_setiap_mode(): void
    {
        ['user' => $pic] = $this->pic($this->klienA);
        $company = $this->company($this->klienA, 'PT Mode', 'MODE');
        $this->actingAs($pic);
        $url = "/api/v1/client/companies/{$company->id}/policy";

        $this->getJson($url)->assertOk()->assertJsonPath('data.mode_label', 'Klik saja')->assertJsonPath('has_locations', false);

        $this->putJson($url, $this->policyPayload(['selfie_mode' => 'none']))->assertOk()->assertJsonPath('data.mode_label', 'Klik saja');
        $this->putJson($url, $this->policyPayload(['selfie_mode' => 'photo']))->assertOk()->assertJsonPath('data.mode_label', 'Foto');
        $this->putJson($url, $this->policyPayload(['selfie_mode' => 'face', 'face_match_threshold' => 70, 'face_fail_action' => 'approval']))
            ->assertOk()
            ->assertJsonPath('data.mode_label', 'Foto + Wajah')
            ->assertJsonPath('data.face_match_threshold', 70)
            ->assertJsonPath('data.face_fail_action', 'approval');

        // GPS needs a coordinate first; the PIC adds a location and tries again.
        $this->putJson($url, $this->policyPayload(['require_gps' => true]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['require_gps']);
        $this->postJson("/api/v1/client/companies/{$company->id}/locations", ['name' => 'Kantor', 'latitude' => -6.2, 'longitude' => 106.8])->assertCreated();
        $this->putJson($url, $this->policyPayload(['require_gps' => true, 'geofence_radius_meters' => 150, 'mock_location_action' => 'approval', 'selfie_mode' => 'face']))
            ->assertOk()
            ->assertJsonPath('data.mode_label', 'GPS + Foto + Wajah')
            ->assertJsonPath('data.geofence_radius_meters', 150)
            ->assertJsonPath('data.mock_location_action', 'approval');

        // Liveness needs a face service that reads head pose.
        $this->putJson($url, $this->policyPayload(['require_gps' => true, 'selfie_mode' => 'liveness']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['selfie_mode']);
        $this->fakePoseCapableVerifier();
        $this->putJson($url, $this->policyPayload(['require_gps' => true, 'selfie_mode' => 'liveness']))
            ->assertOk()
            ->assertJsonPath('data.mode_label', 'GPS + Liveness + Wajah');

        $policy = $company->fresh()->policy;
        $this->assertTrue($policy->require_gps && $policy->require_photo && $policy->require_face_recognition && $policy->require_liveness);

        // Back to click-only at the end of a campaign.
        $this->putJson($url, $this->policyPayload(['selfie_mode' => 'none', 'require_gps' => false]))->assertOk()->assertJsonPath('data.mode_label', 'Klik saja');
        $this->assertFalse($company->fresh()->policy->require_photo);
    }

    public function test_pic_mengatur_kebijakan_absensi_dari_panel_web(): void
    {
        ['user' => $pic] = $this->pic($this->klienA);
        $company = $this->company($this->klienA, 'PT Web', 'WEB', ['latitude' => -6.2, 'longitude' => 106.8]);
        $this->actingAs($pic);

        Livewire::test(ListCompanies::class)
            ->callAction(TestAction::make('managePolicy')->table($company), data: [
                'late_tolerance_minutes' => 5,
                'require_gps' => true,
                'geofence_radius_meters' => 60,
                'mock_location_action' => 'reject',
                'selfie_mode' => 'face',
                'face_match_threshold' => 65,
                'face_fail_action' => 'reject',
                'annual_leave_quota' => 12,
                'default_approval_stages' => 1,
            ])
            ->assertHasNoFormErrors();

        $policy = $company->fresh()->policy;
        $this->assertSame('GPS + Foto + Wajah', $policy->modeLabel());
        $this->assertSame(60, $policy->geofence_radius_meters);
        $this->assertSame(5, $policy->late_tolerance_minutes);
    }

    // ── Locations ──────────────────────────────────────────────────────────

    public function test_pic_menambah_dan_menonaktifkan_lokasi_dengan_radius(): void
    {
        ['user' => $pic] = $this->pic($this->klienA);
        $company = $this->company($this->klienA, 'PT Lokasi', 'LOK');
        $brand = Division::create(['company_id' => $company->id, 'name' => 'Brand Mie', 'is_active' => true]);
        $this->actingAs($pic);

        $mall = $this->postJson("/api/v1/client/companies/{$company->id}/locations", ['name' => 'Outlet Mall', 'latitude' => -6.2250, 'longitude' => 106.8000, 'radius_meters' => 50, 'division_id' => $brand->id])
            ->assertCreated()
            ->assertJsonPath('data.radius_meters', 50)
            ->assertJsonPath('data.division_id', $brand->id)
            ->json('data.id');
        $this->postJson("/api/v1/client/companies/{$company->id}/locations", ['name' => 'Gudang', 'latitude' => -6.3000, 'longitude' => 106.9000])
            ->assertCreated()
            ->assertJsonPath('data.radius_meters', null);

        $this->postJson("/api/v1/client/companies/{$company->id}/locations", ['name' => 'Salah', 'latitude' => -6.2, 'longitude' => 106.8, 'radius_meters' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['radius_meters' => 'Radius geofence minimal 1 meter.']);
        $this->postJson("/api/v1/client/companies/{$company->id}/locations", ['name' => 'Salah', 'latitude' => -100, 'longitude' => 200])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude', 'longitude']);

        $this->getJson("/api/v1/client/companies/{$company->id}/locations")->assertOk()->assertJsonCount(2, 'data');

        // A crew standing at the mall is inside its 50 m radius.
        $this->assertTrue(GeofenceService::validateCompanyGeofence($company->fresh(), -6.2251, 106.8001)['is_valid']);

        // The outlet closes: the PIC deactivates it, and the mall no longer counts for attendance.
        $this->putJson("/api/v1/client/locations/{$mall}", ['name' => 'Outlet Mall', 'latitude' => -6.2250, 'longitude' => 106.8000, 'radius_meters' => 50, 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
        $this->assertFalse(GeofenceService::validateCompanyGeofence($company->fresh(), -6.2251, 106.8001)['is_valid']);
        $this->assertTrue(GeofenceService::validateCompanyGeofence($company->fresh(), -6.3000, 106.9000)['is_valid'], 'The remaining Gudang still counts with the default radius.');
    }

    public function test_pic_menambah_lokasi_dari_panel_web(): void
    {
        ['user' => $pic] = $this->pic($this->klienA);
        $company = $this->company($this->klienA, 'PT Lokasi Web', 'LOKWEB');
        $this->actingAs($pic);

        Livewire::test(CreateCompanyLocation::class)
            ->fillForm(['company_id' => $company->id, 'name' => 'Outlet Kemang', 'latitude' => -6.26, 'longitude' => 106.81, 'radius_meters' => 40, 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(40, (int) CompanyLocation::query()->where('name', 'Outlet Kemang')->value('radius_meters'));
    }

    public function test_menonaktifkan_lokasi_terakhir_saat_gps_wajib_tidak_membuka_absen_dari_mana_saja(): void
    {
        ['user' => $pic] = $this->pic($this->klienA);
        $company = $this->company($this->klienA, 'PT Satu Lokasi', 'SATU');
        $this->actingAs($pic);

        $location = $this->postJson("/api/v1/client/companies/{$company->id}/locations", ['name' => 'Satu-satunya Outlet', 'latitude' => -6.2, 'longitude' => 106.8, 'radius_meters' => 50])
            ->assertCreated()
            ->json('data.id');
        $this->putJson("/api/v1/client/companies/{$company->id}/policy", $this->policyPayload(['require_gps' => true, 'geofence_radius_meters' => 50]))->assertOk();

        $response = $this->putJson("/api/v1/client/locations/{$location}", ['name' => 'Satu-satunya Outlet', 'latitude' => -6.2, 'longitude' => 106.8, 'is_active' => false]);

        // Either the PIC is stopped from removing the last coordinate while GPS is required (like turning
        // GPS on without one), or GPS stays enforced: a worker 50 km away must never pass the geofence.
        if ($response->status() === 422) {
            $this->assertTrue((bool) CompanyLocation::find($location)->is_active);

            return;
        }

        $response->assertOk();
        $this->assertTrue($company->fresh()->policy->require_gps);
        $this->assertFalse(
            GeofenceService::validateCompanyGeofence($company->fresh(), -6.65, 106.8)['is_valid'],
            'GPS is still "Wajib" but with no active coordinate left every check-in passes from anywhere.',
        );
    }

    // ── Divisions ──────────────────────────────────────────────────────────

    public function test_pic_menyusun_pohon_divisi_dan_sub_divisi_ikut_ke_akses_data(): void
    {
        ['user' => $pic] = $this->pic($this->klienA);
        $company = $this->company($this->klienA, 'PT Divisi', 'DIV');
        $this->actingAs($pic);

        $ops = $this->postJson("/api/v1/client/companies/{$company->id}/divisions", ['name' => 'Operasional', 'code' => 'OPS'])->assertCreated()->json('data.id');
        $jakarta = $this->postJson("/api/v1/client/companies/{$company->id}/divisions", ['name' => 'Area Jakarta', 'parent_id' => $ops])
            ->assertCreated()
            ->assertJsonPath('data.path', 'Operasional > Area Jakarta')
            ->json('data.id');

        $approver = $this->postJson('/api/v1/client/accounts', ['name' => 'Kepala Ops', 'email' => 'ops@a.test', 'roles' => ['Approver'], 'data_scope' => 'divisions', 'division_ids' => [$ops]])
            ->assertCreated()
            ->json('data.id');

        // A sub-division added after the access was given comes into reach by itself.
        $outlet = $this->postJson("/api/v1/client/companies/{$company->id}/divisions", ['name' => 'Outlet Blok M', 'parent_id' => $jakarta])
            ->assertCreated()
            ->assertJsonPath('data.path', 'Operasional > Area Jakarta > Outlet Blok M')
            ->json('data.id');
        DataAccessService::flush();
        $this->assertTrue(DataAccessService::canAccessPlacement(User::findOrFail($approver), $company->id, $outlet));

        // The tree never loops and never borrows a parent from another company.
        $this->putJson("/api/v1/client/divisions/{$ops}", ['name' => 'Operasional', 'parent_id' => $outlet])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id']);
        $foreign = Division::create(['company_id' => $this->companyB->id, 'name' => 'Divisi B', 'is_active' => true]);
        $this->postJson("/api/v1/client/companies/{$company->id}/divisions", ['name' => 'Nyasar', 'parent_id' => $foreign->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id']);

        $this->putJson("/api/v1/client/divisions/{$jakarta}", ['name' => 'Area Jakarta', 'parent_id' => $ops, 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->getJson("/api/v1/client/companies/{$company->id}/divisions")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.path', 'Operasional');
    }

    public function test_pic_membuat_sub_divisi_dari_panel_web(): void
    {
        ['user' => $pic] = $this->pic($this->klienA);
        $company = $this->company($this->klienA, 'PT Divisi Web', 'DIVWEB');
        $parent = Division::create(['company_id' => $company->id, 'name' => 'Sales', 'is_active' => true]);
        $this->actingAs($pic);

        Livewire::test(CreateDivision::class)
            ->fillForm(['company_id' => $company->id, 'parent_id' => $parent->id, 'name' => 'Depo Bandung', 'code' => 'DB', 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($parent->id, (int) Division::query()->where('name', 'Depo Bandung')->value('parent_id'));
    }

    // ── Accounts ───────────────────────────────────────────────────────────

    public function test_pic_membuat_akun_tim_operasional_dengan_akses_data_dan_masing_masing_bisa_bekerja(): void
    {
        ['user' => $pic] = $this->pic($this->klienA);
        $company = $this->company($this->klienA, 'PT Tim', 'TIM');
        $brand = Division::create(['company_id' => $company->id, 'name' => 'Brand Ayam', 'is_active' => true]);
        $this->actingAs($pic);

        $options = $this->getJson('/api/v1/client/accounts/options')->assertOk();
        $this->assertEqualsCanonicalizing(['Admin', 'Approver', 'BOD', 'Employee', 'Freelancer', 'Intern', 'Koordinator'], $options->json('roles'));
        $this->assertSame(['all', 'companies', 'divisions', 'own'], collect($options->json('scopes'))->pluck('value')->sort()->values()->all());
        $this->assertSame([['id' => $company->id, 'name' => 'PT Tim']], $options->json('companies'));

        $team = [
            'admin' => ['name' => 'HR Tim', 'email' => 'hr@tim.test', 'roles' => ['Admin'], 'data_scope' => 'companies', 'company_ids' => [$company->id]],
            'koordinator' => ['name' => 'Koordinator Ayam', 'email' => 'koor@tim.test', 'roles' => ['Koordinator'], 'data_scope' => 'divisions', 'division_ids' => [$brand->id]],
            'approver' => ['name' => 'Supervisor Ayam', 'email' => 'spv@tim.test', 'roles' => ['Approver'], 'data_scope' => 'divisions', 'division_ids' => [$brand->id]],
            'bod' => ['name' => 'Direktur', 'email' => 'bod@tim.test', 'roles' => ['BOD'], 'data_scope' => 'all'],
            'crew' => ['name' => 'Crew Ayam', 'email' => 'crew@tim.test', 'phone' => '081277700011', 'roles' => ['Employee'], 'data_scope' => 'own'],
        ];

        $credentials = [];
        foreach ($team as $key => $payload) {
            $response = $this->postJson('/api/v1/client/accounts', $payload)
                ->assertCreated()
                ->assertJsonPath('data.roles', $payload['roles'])
                ->assertJsonPath('data.data_scope', $payload['data_scope'])
                ->assertJsonPath('credentials.whatsapp_sent', false);
            $credentials[$key] = [$payload['email'], (string) $response->json('credentials.password')];
            $this->assertSame($this->klienA->id, (int) User::query()->where('email', $payload['email'])->value('tenant_id'));
        }

        $this->assertSame(5, $this->getJson('/api/v1/client/accounts')->assertOk()->json('pagination.total'));
        Http::assertNothingSent();

        // Each new account signs in with the generated password and gets exactly its own menus.
        $adminToken = $this->login(...$credentials['admin']);
        $this->asToken($adminToken)->getJson('/api/v1/client/overview')
            ->assertOk()
            ->assertJsonPath('permissions.is_pic', false)
            ->assertJsonPath('permissions.can_create_company', false)
            ->assertJsonPath('permissions.can_manage_accounts', true);
        $this->asToken($adminToken)->postJson('/api/v1/client/companies', ['name' => 'PT Liar', 'code' => 'LIAR'])->assertForbidden();

        foreach (['koordinator', 'approver', 'bod', 'crew'] as $key) {
            $token = $this->login(...$credentials[$key]);
            $this->asToken($token)->getJson('/api/v1/client/overview')->assertForbidden();
            $this->asToken($token)->postJson('/api/v1/client/accounts', ['name' => 'X', 'email' => "x-{$key}@tim.test", 'roles' => ['Employee'], 'data_scope' => 'own'])->assertForbidden();
        }
    }

    public function test_pic_membuat_akun_admin_dan_approver_dari_panel_web(): void
    {
        ['user' => $pic] = $this->pic($this->klienA);
        $company = $this->company($this->klienA, 'PT Panel Akun', 'PAKUN');
        $brand = Division::create(['company_id' => $company->id, 'name' => 'Brand Panel', 'is_active' => true]);
        $this->actingAs($pic);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Approver Panel',
                'email' => 'approver.panel@a.test',
                'password' => 'rahasia123',
                'roles' => [Role::findByName('Approver', 'web')->id],
                'has_employee_profile' => false,
                'data_scope' => DataScope::Divisions->value,
                'accessibleDivisions' => [$brand->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $approver = User::query()->where('email', 'approver.panel@a.test')->firstOrFail();
        $this->assertSame($this->klienA->id, (int) $approver->tenant_id);
        $this->assertSame(DataScope::Divisions, $approver->dataScope());
        $this->assertTrue(DataAccessService::canAccessPlacement($approver, $company->id, $brand->id));

        Livewire::test(ListUsers::class)->assertCanSeeTableRecords([$approver]);
    }

    public function test_pic_reset_password_dan_akun_keluar_dari_semua_perangkat(): void
    {
        ['user' => $pic] = $this->pic($this->klienA);
        $this->company($this->klienA, 'PT Reset', 'RESET');
        $crew = $this->account($this->klienA, ['Employee']);
        $crew->forceFill(['password' => 'lama-123'])->save();

        $phone = $this->login($crew->email, 'lama-123');
        $tablet = $this->login($crew->email, 'lama-123');
        $this->assertSame(2, $crew->tokens()->count());

        $this->forgetAuth();
        $this->actingAs($pic);
        $newPassword = $this->postJson("/api/v1/client/accounts/{$crew->id}/reset-password")
            ->assertOk()
            ->assertJsonPath('credentials.email', $crew->email)
            ->json('credentials.password');

        $this->assertSame(0, $crew->tokens()->count());
        $this->asToken($phone)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->asToken($tablet)->getJson('/api/v1/auth/me')->assertUnauthorized();

        $this->forgetAuth();
        $this->postJson('/api/v1/auth/login', ['email' => $crew->email, 'password' => 'lama-123'])->assertUnauthorized();
        $this->login($crew->email, $newPassword);
    }

    public function test_ganti_password_dari_panel_web_juga_mengeluarkan_akun_dari_aplikasi(): void
    {
        ['user' => $pic] = $this->pic($this->klienA);
        $this->company($this->klienA, 'PT Reset Web', 'RESETWEB');
        $approver = $this->account($this->klienA, ['Approver'], DataScope::All);
        $approver->forceFill(['password' => 'lama-123'])->save();
        $token = $this->login($approver->email, 'lama-123');

        $this->forgetAuth();
        $this->actingAs($pic);
        Livewire::test(EditUser::class, ['record' => $approver->getRouteKey()])
            ->fillForm(['password' => 'baru-456'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(password_verify('baru-456', $approver->fresh()->password));

        // AKSES.md: "Reset password: akun langsung keluar dari aplikasi di semua perangkat." The app path
        // revokes tokens; the web panel path (Manajemen User) must do the same.
        $this->assertSame(0, $approver->tokens()->count(), 'A password set from the web panel leaves the old app sessions signed in.');
        $this->asToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_pic_menonaktifkan_akun_yang_keluar_dan_mengaktifkannya_kembali(): void
    {
        ['user' => $pic] = $this->pic($this->klienA);
        $company = $this->company($this->klienA, 'PT Nonaktif', 'NON');
        $koordinator = $this->account($this->klienA, ['Koordinator'], DataScope::Companies, [$company->id]);
        $koordinator->forceFill(['password' => 'koor-123'])->save();
        $token = $this->login($koordinator->email, 'koor-123');

        $this->forgetAuth();
        $this->actingAs($pic);
        $payload = ['name' => $koordinator->name, 'roles' => ['Koordinator'], 'data_scope' => 'companies', 'company_ids' => [$company->id], 'is_active' => false];
        $this->putJson("/api/v1/client/accounts/{$koordinator->id}", $payload)
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->asToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->forgetAuth();
        $this->postJson('/api/v1/auth/login', ['email' => $koordinator->email, 'password' => 'koor-123'])->assertForbidden();

        $this->actingAs($pic);
        $this->putJson("/api/v1/client/accounts/{$koordinator->id}", [...$payload, 'is_active' => true])->assertOk()->assertJsonPath('data.is_active', true);
        $this->login($koordinator->email, 'koor-123');
    }

    // ── What the PIC must NOT be able to do ────────────────────────────────

    public function test_pic_tidak_bisa_mengubah_akun_sendiri_pic_lain_atau_memberi_role_platform(): void
    {
        ['user' => $pic] = $this->pic($this->klienA);
        ['user' => $coPic] = $this->pic($this->klienA);
        $this->company($this->klienA, 'PT Batas', 'BATAS');
        Role::findOrCreate('Auditor', 'web');
        $this->actingAs($pic);

        $this->putJson("/api/v1/client/accounts/{$pic->id}", ['name' => 'X', 'roles' => ['Admin'], 'data_scope' => 'all', 'is_active' => false])
            ->assertForbidden()
            ->assertJsonPath('message', 'Anda tidak bisa mengubah akun sendiri.');
        $this->postJson("/api/v1/client/accounts/{$pic->id}/reset-password")->assertForbidden();

        $this->putJson("/api/v1/client/accounts/{$coPic->id}", ['name' => 'X', 'roles' => ['Employee'], 'data_scope' => 'own', 'is_active' => false])->assertForbidden();
        $this->postJson("/api/v1/client/accounts/{$coPic->id}/reset-password")->assertForbidden();
        $this->assertTrue($coPic->fresh()->isTenantOwner());
        $this->assertTrue((bool) $coPic->fresh()->is_active);
        $this->assertNotContains($coPic->id, collect($this->getJson('/api/v1/client/accounts')->json('data'))->pluck('id')->all());

        foreach (['Superadmin', 'PIC', 'Auditor'] as $role) {
            $this->postJson('/api/v1/client/accounts', ['name' => 'X', 'email' => strtolower($role).'@batas.test', 'roles' => [$role], 'data_scope' => 'own'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['roles.0' => 'Role ini tidak bisa Anda berikan.']);
        }
        $this->assertSame(['Admin', 'Approver', 'BOD', 'Employee', 'Freelancer', 'Intern', 'Koordinator'], array_keys(UserResource::assignableRoleOptions()));
    }

    public function test_pic_tidak_pernah_menyentuh_data_klien_b(): void
    {
        ['user' => $pic] = $this->pic($this->klienA);
        $companyA = $this->company($this->klienA, 'PT Alfa', 'ALFA');
        $locationB = CompanyLocation::create(['company_id' => $this->companyB->id, 'name' => 'Outlet B', 'latitude' => -6.3, 'longitude' => 106.9, 'is_active' => true]);
        $divisionB = Division::create(['company_id' => $this->companyB->id, 'name' => 'Divisi B', 'is_active' => true]);
        $accountB = $this->account($this->klienB, ['Approver'], DataScope::All);
        ['user' => $picB] = $this->pic($this->klienB);
        $this->actingAs($pic);
        $b = $this->companyB->id;

        // App: everything of Klien B answers 404 and is never changed.
        $this->putJson("/api/v1/client/companies/{$b}", ['name' => 'Diretas', 'code' => 'X'])->assertNotFound();
        $this->getJson("/api/v1/client/companies/{$b}/policy")->assertNotFound();
        $this->putJson("/api/v1/client/companies/{$b}/policy", $this->policyPayload())->assertNotFound();
        $this->getJson("/api/v1/client/companies/{$b}/locations")->assertNotFound();
        $this->postJson("/api/v1/client/companies/{$b}/locations", ['name' => 'X', 'latitude' => 1, 'longitude' => 1])->assertNotFound();
        $this->putJson("/api/v1/client/locations/{$locationB->id}", ['name' => 'X', 'latitude' => 1, 'longitude' => 1, 'is_active' => false])->assertNotFound();
        $this->getJson("/api/v1/client/companies/{$b}/divisions")->assertNotFound();
        $this->postJson("/api/v1/client/companies/{$b}/divisions", ['name' => 'X'])->assertNotFound();
        $this->putJson("/api/v1/client/divisions/{$divisionB->id}", ['name' => 'X'])->assertNotFound();
        foreach ([$accountB, $picB, $this->superadmin] as $foreign) {
            $this->getJson("/api/v1/client/accounts/{$foreign->id}")->assertNotFound();
            $this->putJson("/api/v1/client/accounts/{$foreign->id}", ['name' => 'X', 'roles' => ['Employee'], 'data_scope' => 'own', 'is_active' => false])->assertNotFound();
            $this->postJson("/api/v1/client/accounts/{$foreign->id}/reset-password")->assertNotFound();
        }
        $this->postJson('/api/v1/client/accounts', ['name' => 'X', 'email' => 'x@a.test', 'roles' => ['Approver'], 'data_scope' => 'companies', 'company_ids' => [$b]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['data_scope']);
        $this->postJson('/api/v1/client/accounts', ['name' => 'Y', 'email' => 'y@a.test', 'roles' => ['Approver'], 'data_scope' => 'divisions', 'division_ids' => [$divisionB->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['data_scope']);

        $overview = $this->getJson('/api/v1/client/overview')->assertOk();
        $this->assertSame([$companyA->id], collect($overview->json('companies'))->pluck('id')->all());
        $options = $this->getJson('/api/v1/client/accounts/options')->assertOk();
        $this->assertNotContains($b, collect($options->json('companies'))->pluck('id')->all());
        $this->assertNotContains($divisionB->id, collect($options->json('divisions'))->pluck('id')->all());
        $this->assertSame([], collect($this->getJson('/api/v1/client/accounts')->json('data'))->pluck('id')->intersect([$accountB->id, $picB->id, $this->superadmin->id])->values()->all());

        // Web panel: Klien B's records are out of every list, page and picker.
        $this->get(CompanyResource::getUrl('edit', ['record' => $this->companyB]))->assertNotFound();
        $this->assertFalse(CompanyResource::canDelete($this->companyB));
        Livewire::test(CreateDivision::class)
            ->fillForm(['company_id' => $b, 'name' => 'Nyusup', 'is_active' => true])
            ->call('create')
            ->assertHasFormErrors(['company_id']);
        Livewire::test(CreateCompanyLocation::class)
            ->fillForm(['company_id' => $b, 'name' => 'Nyusup', 'latitude' => -6.3, 'longitude' => 106.9, 'is_active' => true])
            ->call('create')
            ->assertHasFormErrors(['company_id']);
        Livewire::test(ListUsers::class)->assertCanNotSeeTableRecords([$accountB, $picB, $this->superadmin]);
        $this->assertFalse(UserResource::canManageAccount($accountB));

        $this->assertSame('PT Bravo', $this->companyB->fresh()->name);
        $this->assertTrue((bool) $locationB->fresh()->is_active);
        $this->assertSame(0, Division::query()->where('name', 'Nyusup')->count());
        $this->assertTrue((bool) $accountB->fresh()->is_active);
    }

    public function test_pic_tidak_bisa_masuk_menu_platform_milik_superadmin(): void
    {
        ['user' => $pic] = $this->pic($this->klienA);
        $this->company($this->klienA, 'PT Platform', 'PLAT');
        $this->actingAs($pic);

        $this->assertFalse(TenantResource::canViewAny());
        $this->get(TenantResource::getUrl('index'))->assertForbidden();
        $this->get(TenantResource::getUrl('edit', ['record' => $this->klienA]))->assertForbidden();
        $this->assertFalse(JobLevelResource::canCreate());
        $this->get('/horizon')->assertForbidden();
        $this->get('/log-viewer')->assertForbidden();

        // The PIC can never switch their own Klien on or off, nor rename it.
        $this->assertTrue($this->klienA->fresh()->is_active);
    }

    // ── Klien deactivation ─────────────────────────────────────────────────

    public function test_klien_dinonaktifkan_menghentikan_semua_akun_klien_itu_dan_klien_lain_tetap_jalan(): void
    {
        ['user' => $pic, 'password' => $picPassword] = $this->pic($this->klienA);
        ['user' => $picB, 'password' => $picBPassword] = $this->pic($this->klienB);
        $this->company($this->klienA, 'PT Kontrak', 'KONTRAK');
        $crew = $this->account($this->klienA, ['Employee']);
        $crew->forceFill(['password' => 'crew-123'])->save();

        $picToken = $this->login($pic->email, $picPassword);
        $crewToken = $this->login($crew->email, 'crew-123');
        $picBToken = $this->login($picB->email, $picBPassword);

        // Only the Superadmin switches a Klien off, from the Klien menu.
        $this->forgetAuth();
        $this->actingAs($this->superadmin);
        Livewire::test(EditTenant::class, ['record' => $this->klienA->getRouteKey()])
            ->fillForm(['is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertFalse($this->klienA->fresh()->is_active);

        $this->asToken($picToken)->getJson('/api/v1/client/overview')->assertUnauthorized();
        $this->asToken($crewToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->forgetAuth();
        $this->postJson('/api/v1/auth/login', ['email' => $pic->email, 'password' => $picPassword])->assertForbidden();
        $this->postJson('/api/v1/auth/login', ['email' => $crew->email, 'password' => 'crew-123'])->assertForbidden();
        $this->actingAs($pic->fresh())->get(CompanyResource::getUrl('index'))->assertForbidden();

        // Klien B keeps working.
        $this->asToken($picBToken)->getJson('/api/v1/client/overview')->assertOk()->assertJsonPath('tenant.id', $this->klienB->id);

        // Contract renewed: the PIC signs in again without anybody resetting anything.
        $this->forgetAuth();
        $this->actingAs($this->superadmin);
        Livewire::test(EditTenant::class, ['record' => $this->klienA->getRouteKey()])
            ->fillForm(['is_active' => true])
            ->call('save')
            ->assertHasNoFormErrors();
        $token = $this->login($pic->email, $picPassword);
        $this->asToken($token)->getJson('/api/v1/client/overview')->assertOk()->assertJsonPath('permissions.is_pic', true);
    }
}
