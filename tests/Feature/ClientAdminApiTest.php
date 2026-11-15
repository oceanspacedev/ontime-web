<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Filament\Resources\Users\UserResource;
use App\Models\ApprovalFlow;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DataAccessService;
use App\Services\Face\FaceDetection;
use App\Services\Face\FaceMatch;
use App\Services\Face\FaceVerifier;
use App\Services\TenantOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The PIC or Admin of a Klien runs it from the app with the same rules as the web panel, and never
 * reaches another client's data.
 */
class ClientAdminApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $klienA;

    private Tenant $klienB;

    private Company $companyA;

    private Company $companyB;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.whatsapp_gateway.url' => null, 'services.whatsapp_gateway.token' => null, 'services.face.driver' => 'basic']);
        Http::fake();

        foreach (['Superadmin', 'Admin', 'PIC', 'Koordinator', 'Approver', 'BOD', 'Employee', 'Freelancer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->klienA = Tenant::factory()->create(['name' => 'Klien A', 'code' => 'KA']);
        $this->klienB = Tenant::factory()->create(['name' => 'Klien B', 'code' => 'KB']);
        $this->companyA = $this->company($this->klienA, 'PT A', 'SAMA');
        $this->companyB = $this->company($this->klienB, 'PT B', 'SAMA');
    }

    private function company(Tenant $tenant, string $name, string $code): Company
    {
        $company = new Company(['name' => $name, 'code' => $code, 'is_active' => true]);
        $company->tenant_id = $tenant->id;
        $company->save();

        return $company;
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

    private function pic(Tenant $tenant): User
    {
        return app(TenantOnboardingService::class)->createPic($tenant, 'PIC '.$tenant->name, fake()->unique()->safeEmail())['user']->fresh();
    }

    private function admin(Tenant $tenant): User
    {
        return $this->account($tenant, ['Admin'], DataScope::All);
    }

    private function division(Company $company, string $name = 'Outlet', ?int $parentId = null): Division
    {
        return Division::create(['company_id' => $company->id, 'parent_id' => $parentId, 'name' => $name, 'is_active' => true]);
    }

    private function location(Company $company): CompanyLocation
    {
        return CompanyLocation::create(['company_id' => $company->id, 'name' => 'Kantor', 'latitude' => -6.2, 'longitude' => 106.8, 'is_active' => true]);
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
            'selfie_mode' => 'photo',
            'face_match_threshold' => 60,
            'face_fail_action' => 'reject',
            'annual_leave_quota' => 12,
            'default_approval_stages' => 1,
            'work_start_time' => '08:30',
            'work_end_time' => '17:30',
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
                return new FaceMatch(true, 92, 'Wajah cocok.');
            }

            public function detect(string $imagePath): FaceDetection
            {
                return new FaceDetection(1);
            }
        });
    }

    // ── Overview & permissions ─────────────────────────────────────────────

    public function test_overview_lists_the_pics_companies_and_permissions(): void
    {
        $pic = $this->pic($this->klienA);
        $this->location($this->companyA);
        $division = $this->division($this->companyA);
        Freelancer::create(['company_id' => $this->companyA->id, 'division_id' => $division->id, 'full_name' => 'Crew', 'status' => 'active']);
        Sanctum::actingAs($pic);

        $this->getJson('/api/v1/client/overview')
            ->assertOk()
            ->assertJsonPath('permissions', ['is_pic' => true, 'can_manage_companies' => true, 'can_create_company' => true, 'can_manage_accounts' => true])
            ->assertJsonPath('tenant.name', 'Klien A')
            ->assertJsonCount(1, 'companies')
            ->assertJsonPath('companies.0.id', $this->companyA->id)
            ->assertJsonPath('companies.0.mode_label', 'Klik saja')
            ->assertJsonPath('companies.0.locations_count', 1)
            ->assertJsonPath('companies.0.divisions_count', 1)
            ->assertJsonPath('companies.0.workers_count', 1);
    }

    public function test_the_profile_carries_the_client_admin_permissions(): void
    {
        Sanctum::actingAs($this->admin($this->klienA));

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonFragment(['client_admin' => ['is_pic' => false, 'can_manage_companies' => true, 'can_manage_accounts' => true]]);
    }

    public function test_a_coordinator_is_refused(): void
    {
        $division = $this->division($this->companyA);
        Sanctum::actingAs($this->account($this->klienA, ['Koordinator'], DataScope::Divisions, divisionIds: [$division->id]));

        $this->getJson('/api/v1/client/overview')->assertForbidden();
        $this->getJson("/api/v1/client/companies/{$this->companyA->id}/policy")->assertForbidden();
        $this->getJson('/api/v1/client/accounts')->assertForbidden();
        $this->postJson('/api/v1/client/accounts', [])->assertForbidden()->assertJsonPath('message', 'Akun & akses hanya untuk PIC atau Admin.');
    }

    public function test_a_worker_is_refused(): void
    {
        Sanctum::actingAs($this->account($this->klienA, ['Employee']));

        $this->getJson('/api/v1/client/overview')->assertForbidden();
    }

    // ── Badan Usaha ────────────────────────────────────────────────────────

    public function test_the_pic_adds_a_company_to_their_klien_with_default_flows(): void
    {
        Sanctum::actingAs($this->pic($this->klienA));

        // Codes only have to be unique inside one Klien: "SAMA" exists in Klien B only... and in A.
        $this->postJson('/api/v1/client/companies', ['name' => 'PT A Dua', 'code' => 'DUA', 'latitude' => -6.1, 'longitude' => 106.8])
            ->assertCreated()
            ->assertJsonPath('data.code', 'DUA')
            ->assertJsonPath('data.latitude', -6.1)
            ->assertJsonPath('data.is_active', true);

        $company = Company::where('code', 'DUA')->firstOrFail();
        $this->assertSame($this->klienA->id, (int) $company->tenant_id);
        $this->assertTrue(ApprovalFlow::where('company_id', $company->id)->exists());
    }

    public function test_company_code_is_unique_per_klien(): void
    {
        Sanctum::actingAs($this->pic($this->klienA));

        $this->postJson('/api/v1/client/companies', ['name' => 'PT Lagi', 'code' => 'SAMA'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code' => 'Kode ini sudah dipakai badan usaha lain di klien ini.']);

        $pic = $this->pic($this->klienB);
        $this->company($this->klienA, 'PT A Unik', 'UNIK');
        Sanctum::actingAs($pic);

        $this->postJson('/api/v1/client/companies', ['name' => 'PT B Unik', 'code' => 'UNIK'])->assertCreated();
    }

    public function test_a_pic_with_listed_companies_keeps_the_new_company_in_reach(): void
    {
        $pic = $this->account($this->klienA, ['Admin', 'PIC'], DataScope::Companies, [$this->companyA->id]);
        Sanctum::actingAs($pic);

        $id = $this->postJson('/api/v1/client/companies', ['name' => 'PT Baru', 'code' => 'BARU'])->assertCreated()->json('data.id');

        DataAccessService::flush();
        $this->assertContains($id, $pic->fresh()->manageableCompanyIds());
    }

    public function test_an_admin_who_is_not_the_pic_cannot_add_a_company(): void
    {
        Sanctum::actingAs($this->admin($this->klienA));

        $this->postJson('/api/v1/client/companies', ['name' => 'PT X', 'code' => 'X'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Hanya PIC yang bisa menambah badan usaha.');
    }

    public function test_an_admin_updates_their_company(): void
    {
        Sanctum::actingAs($this->admin($this->klienA));

        $this->putJson("/api/v1/client/companies/{$this->companyA->id}", ['name' => 'PT A Baru', 'code' => 'SAMA', 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.name', 'PT A Baru')
            ->assertJsonPath('data.is_active', false);
    }

    public function test_another_klien_company_is_not_found(): void
    {
        Sanctum::actingAs($this->admin($this->klienB));

        $this->putJson("/api/v1/client/companies/{$this->companyA->id}", ['name' => 'X', 'code' => 'X'])->assertNotFound();
        $this->getJson("/api/v1/client/companies/{$this->companyA->id}/policy")->assertNotFound();
        $this->putJson("/api/v1/client/companies/{$this->companyA->id}/policy", $this->policyPayload())->assertNotFound();
        $this->getJson("/api/v1/client/companies/{$this->companyA->id}/locations")->assertNotFound();
        $this->postJson("/api/v1/client/companies/{$this->companyA->id}/locations", ['name' => 'X', 'latitude' => 1, 'longitude' => 1])->assertNotFound();
        $this->getJson("/api/v1/client/companies/{$this->companyA->id}/divisions")->assertNotFound();
        $this->postJson("/api/v1/client/companies/{$this->companyA->id}/divisions", ['name' => 'X'])->assertNotFound();
        $this->assertSame('PT A', $this->companyA->fresh()->name);
    }

    // ── Kebijakan absensi ──────────────────────────────────────────────────

    public function test_policy_shows_the_stored_values_and_options(): void
    {
        Sanctum::actingAs($this->admin($this->klienA));

        $this->getJson("/api/v1/client/companies/{$this->companyA->id}/policy")
            ->assertOk()
            ->assertJsonPath('data.selfie_mode', 'none')
            ->assertJsonPath('data.work_start_time', '08:00')
            ->assertJsonPath('data.mode_label', 'Klik saja')
            ->assertJsonPath('has_locations', false)
            ->assertJsonPath('options.selfie_modes.3.value', 'liveness')
            ->assertJsonPath('options.selfie_modes.3.available', false)
            ->assertJsonCount(2, 'options.mock_location_actions')
            ->assertJsonCount(2, 'options.face_fail_actions');
    }

    public function test_policy_is_saved_like_the_panel(): void
    {
        $this->location($this->companyA);
        Sanctum::actingAs($this->admin($this->klienA));

        $this->putJson("/api/v1/client/companies/{$this->companyA->id}/policy", $this->policyPayload([
            'require_gps' => true,
            'geofence_radius_meters' => 150,
            'mock_location_action' => 'approval',
            'selfie_mode' => 'face',
            'face_match_threshold' => 75,
        ]))
            ->assertOk()
            ->assertJsonPath('data.mode_label', 'GPS + Foto + Wajah')
            ->assertJsonPath('data.work_end_time', '17:30');

        $policy = $this->companyA->policy->fresh();
        $this->assertTrue($policy->require_gps);
        $this->assertSame(150, $policy->geofence_radius_meters);
        $this->assertSame('approval', $policy->mock_location_action);
        $this->assertTrue($policy->require_photo);
        $this->assertTrue($policy->require_face_recognition);
        $this->assertFalse($policy->require_liveness);
        $this->assertSame(75, $policy->face_match_threshold);
        $this->assertSame(10, $policy->late_tolerance_minutes);
    }

    public function test_gps_without_a_location_is_refused(): void
    {
        Sanctum::actingAs($this->admin($this->klienA));

        $this->putJson("/api/v1/client/companies/{$this->companyA->id}/policy", $this->policyPayload(['require_gps' => true]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['require_gps' => 'Wajib Lokasi GPS membutuhkan Koordinat GPS Utama atau minimal satu cabang aktif.']);

        $this->assertFalse($this->companyA->policy->fresh()->require_gps);
    }

    public function test_liveness_without_compreface_is_refused(): void
    {
        Sanctum::actingAs($this->admin($this->klienA));

        $this->putJson("/api/v1/client/companies/{$this->companyA->id}/policy", $this->policyPayload(['selfie_mode' => 'liveness']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['selfie_mode' => 'Liveness butuh CompreFace (FACE_DRIVER=compreface).']);

        $this->assertFalse($this->companyA->policy->fresh()->require_liveness);
    }

    public function test_liveness_is_saved_when_the_face_service_reads_head_pose(): void
    {
        $this->fakePoseCapableVerifier();
        Sanctum::actingAs($this->admin($this->klienA));

        $this->putJson("/api/v1/client/companies/{$this->companyA->id}/policy", $this->policyPayload(['selfie_mode' => 'liveness']))
            ->assertOk()
            ->assertJsonPath('data.selfie_mode', 'liveness');
    }

    public function test_policy_requires_radius_when_gps_is_on(): void
    {
        $this->location($this->companyA);
        Sanctum::actingAs($this->admin($this->klienA));

        $this->putJson("/api/v1/client/companies/{$this->companyA->id}/policy", $this->policyPayload(['require_gps' => true, 'geofence_radius_meters' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['geofence_radius_meters']);
    }

    // ── Lokasi ─────────────────────────────────────────────────────────────

    public function test_locations_are_listed_added_and_deactivated(): void
    {
        $brand = $this->division($this->companyA, 'Brand');
        Sanctum::actingAs($this->admin($this->klienA));

        $id = $this->postJson("/api/v1/client/companies/{$this->companyA->id}/locations", [
            'name' => 'Outlet Mall', 'latitude' => -6.25, 'longitude' => 106.85, 'radius_meters' => 80, 'division_id' => $brand->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.division_id', $brand->id)
            ->assertJsonPath('data.radius_meters', 80)
            ->assertJsonPath('data.is_active', true)
            ->json('data.id');

        $this->getJson("/api/v1/client/companies/{$this->companyA->id}/locations")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.latitude', -6.25);

        $this->putJson("/api/v1/client/locations/{$id}", ['name' => 'Outlet Mall', 'latitude' => -6.25, 'longitude' => 106.85, 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_a_location_brand_must_belong_to_the_company(): void
    {
        $otherBrand = $this->division($this->companyB, 'Brand B');
        Sanctum::actingAs($this->admin($this->klienA));

        $this->postJson("/api/v1/client/companies/{$this->companyA->id}/locations", ['name' => 'X', 'latitude' => 1, 'longitude' => 1, 'division_id' => $otherBrand->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['division_id']);
    }

    public function test_another_klien_location_is_not_found(): void
    {
        $location = $this->location($this->companyA);
        Sanctum::actingAs($this->admin($this->klienB));

        $this->putJson("/api/v1/client/locations/{$location->id}", ['name' => 'X', 'latitude' => 1, 'longitude' => 1])->assertNotFound();
        $this->assertSame('Kantor', $location->fresh()->name);
    }

    // ── Divisi ─────────────────────────────────────────────────────────────

    public function test_divisions_are_listed_with_their_path_and_added_under_a_parent(): void
    {
        $parent = $this->division($this->companyA, 'Sales');
        Sanctum::actingAs($this->admin($this->klienA));

        $this->postJson("/api/v1/client/companies/{$this->companyA->id}/divisions", ['name' => 'Depo Bandung', 'code' => 'DB', 'parent_id' => $parent->id])
            ->assertCreated()
            ->assertJsonPath('data.path', 'Sales > Depo Bandung')
            ->assertJsonPath('data.parent_id', $parent->id);

        $this->getJson("/api/v1/client/companies/{$this->companyA->id}/divisions")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.path', 'Sales > Depo Bandung');
    }

    public function test_a_division_parent_must_be_in_the_same_company_and_never_a_child(): void
    {
        $parent = $this->division($this->companyA, 'Sales');
        $child = $this->division($this->companyA, 'Depo', $parent->id);
        $otherCompany = $this->division($this->companyB, 'Lain');
        Sanctum::actingAs($this->admin($this->klienA));

        $this->postJson("/api/v1/client/companies/{$this->companyA->id}/divisions", ['name' => 'X', 'parent_id' => $otherCompany->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id']);

        $this->putJson("/api/v1/client/divisions/{$parent->id}", ['name' => 'Sales', 'parent_id' => $child->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id']);

        $this->putJson("/api/v1/client/divisions/{$parent->id}", ['name' => 'Sales Baru', 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.name', 'Sales Baru')
            ->assertJsonPath('data.is_active', false);
    }

    public function test_another_klien_division_is_not_found(): void
    {
        $division = $this->division($this->companyA);
        Sanctum::actingAs($this->admin($this->klienB));

        $this->putJson("/api/v1/client/divisions/{$division->id}", ['name' => 'X'])->assertNotFound();
    }

    // ── Akun & akses ───────────────────────────────────────────────────────

    public function test_the_pic_lists_every_account_of_their_klien_only(): void
    {
        $pic = $this->pic($this->klienA);
        $bod = $this->account($this->klienA, ['BOD'], DataScope::All);
        $otherAdmin = $this->admin($this->klienA);
        $this->admin($this->klienB);
        Sanctum::actingAs($pic);

        $response = $this->getJson('/api/v1/client/accounts')->assertOk();

        $this->assertEqualsCanonicalizing([$bod->id, $otherAdmin->id], collect($response->json('data'))->pluck('id')->all());
        $response->assertJsonPath('pagination.total', 2)->assertJsonPath('data.0.can_edit', true);
    }

    public function test_options_offer_only_what_the_user_may_give(): void
    {
        $this->division($this->companyA, 'Sales');
        $this->division($this->companyB, 'Sales B');
        Sanctum::actingAs($this->admin($this->klienA));

        $response = $this->getJson('/api/v1/client/accounts/options')->assertOk();

        $this->assertNotContains('Superadmin', $response->json('roles'));
        $this->assertNotContains('PIC', $response->json('roles'));
        $this->assertNotContains('Admin', $response->json('roles'));
        $this->assertContains('Koordinator', $response->json('roles'));
        $this->assertSame([['id' => $this->companyA->id, 'name' => 'PT A']], $response->json('companies'));
        $this->assertCount(1, $response->json('divisions'));
        $this->assertContains('all', collect($response->json('scopes'))->pluck('value')->all());
    }

    public function test_the_pic_creates_an_admin_with_generated_credentials(): void
    {
        $pic = $this->pic($this->klienA);
        Sanctum::actingAs($pic);

        $response = $this->postJson('/api/v1/client/accounts', [
            'name' => 'HR Baru',
            'email' => 'HR@Example.com',
            'phone' => '081234567890',
            'roles' => ['Admin'],
            'data_scope' => 'companies',
            'company_ids' => [$this->companyA->id],
        ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'hr@example.com')
            ->assertJsonPath('data.roles', ['Admin'])
            ->assertJsonPath('data.data_scope', 'companies')
            ->assertJsonPath('data.companies.0.id', $this->companyA->id)
            ->assertJsonPath('credentials.email', 'hr@example.com')
            ->assertJsonPath('credentials.whatsapp_sent', false);

        $account = User::where('email', 'hr@example.com')->firstOrFail();
        $this->assertSame($this->klienA->id, (int) $account->tenant_id);
        $this->assertTrue(Hash::check($response->json('credentials.password'), $account->password));
        $this->assertNotNull($response->json('credentials.wa_url'));
    }

    public function test_an_admin_who_is_not_the_pic_cannot_grant_admin(): void
    {
        Sanctum::actingAs($this->admin($this->klienA));

        $this->postJson('/api/v1/client/accounts', ['name' => 'X', 'email' => 'x@example.com', 'roles' => ['Admin'], 'data_scope' => 'own'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['roles.0' => 'Role ini tidak bisa Anda berikan.']);

        $this->postJson('/api/v1/client/accounts', ['name' => 'Y', 'email' => 'y@example.com', 'roles' => ['Koordinator'], 'data_scope' => 'own'])
            ->assertCreated();
    }

    public function test_superadmin_and_pic_roles_are_never_assignable_by_a_client(): void
    {
        Sanctum::actingAs($this->pic($this->klienA));

        foreach (['Superadmin', 'PIC'] as $role) {
            $this->postJson('/api/v1/client/accounts', ['name' => 'X', 'email' => "{$role}@example.com", 'roles' => [$role], 'data_scope' => 'own'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['roles.0']);
        }

        $this->assertDatabaseMissing('users', ['email' => 'superadmin@example.com']);
    }

    public function test_data_access_cannot_reach_another_klien_or_exceed_the_users_own(): void
    {
        $admin = $this->account($this->klienA, ['Admin'], DataScope::Companies, [$this->companyA->id]);
        $foreignDivision = $this->division($this->companyB);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/client/accounts', ['name' => 'X', 'email' => 'x@example.com', 'roles' => ['Approver'], 'data_scope' => 'companies', 'company_ids' => [$this->companyB->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['data_scope' => 'Akses data tidak boleh lebih luas dari akses Anda sendiri.']);

        $this->postJson('/api/v1/client/accounts', ['name' => 'X', 'email' => 'x@example.com', 'roles' => ['Approver'], 'data_scope' => 'divisions', 'division_ids' => [$foreignDivision->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['data_scope']);

        $this->postJson('/api/v1/client/accounts', ['name' => 'X', 'email' => 'x@example.com', 'roles' => ['Approver'], 'data_scope' => 'all'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['data_scope']);
    }

    public function test_an_account_is_updated_and_its_password_reset(): void
    {
        $pic = $this->pic($this->klienA);
        $division = $this->division($this->companyA);
        $account = $this->account($this->klienA, ['Approver'], DataScope::Own);
        Sanctum::actingAs($pic);

        $this->putJson("/api/v1/client/accounts/{$account->id}", [
            'name' => 'Approver Depo',
            'phone' => '0812000111',
            'roles' => ['Approver', 'Koordinator'],
            'data_scope' => 'divisions',
            'division_ids' => [$division->id],
            'is_active' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Approver Depo')
            ->assertJsonPath('data.phone', '62812000111')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.data_scope', 'divisions')
            ->assertJsonPath('data.divisions.0.id', $division->id);

        $this->assertEqualsCanonicalizing(['Approver', 'Koordinator'], $account->fresh()->getRoleNames()->all());

        $password = $this->postJson("/api/v1/client/accounts/{$account->id}/reset-password")
            ->assertOk()
            ->assertJsonPath('credentials.email', $account->email)
            ->json('credentials.password');

        $this->assertTrue(Hash::check($password, $account->fresh()->password));
    }

    public function test_nobody_edits_their_own_account(): void
    {
        $pic = $this->pic($this->klienA);
        Sanctum::actingAs($pic);

        $this->putJson("/api/v1/client/accounts/{$pic->id}", ['name' => 'X', 'roles' => ['Admin'], 'data_scope' => 'all', 'is_active' => true])
            ->assertForbidden()
            ->assertJsonPath('message', 'Anda tidak bisa mengubah akun sendiri.');
        $this->postJson("/api/v1/client/accounts/{$pic->id}/reset-password")->assertForbidden();
    }

    public function test_an_admin_cannot_edit_another_admin_of_their_klien(): void
    {
        $otherAdmin = $this->admin($this->klienA);
        Sanctum::actingAs($this->admin($this->klienA));

        $this->putJson("/api/v1/client/accounts/{$otherAdmin->id}", ['name' => 'X', 'roles' => ['Approver'], 'data_scope' => 'own', 'is_active' => true])
            ->assertForbidden();
        $this->assertTrue($otherAdmin->fresh()->hasRole('Admin'));
    }

    public function test_another_klien_account_is_not_found(): void
    {
        $account = $this->account($this->klienA, ['Approver']);
        $superadmin = User::factory()->create();
        $superadmin->assignRole('Superadmin');
        Sanctum::actingAs($this->pic($this->klienB));

        $this->putJson("/api/v1/client/accounts/{$account->id}", ['name' => 'X', 'roles' => ['Approver'], 'data_scope' => 'own', 'is_active' => true])->assertNotFound();
        $this->postJson("/api/v1/client/accounts/{$account->id}/reset-password")->assertNotFound();
        $this->postJson("/api/v1/client/accounts/{$superadmin->id}/reset-password")->assertNotFound();
        $this->assertNotContains($account->id, collect($this->getJson('/api/v1/client/accounts')->json('data'))->pluck('id')->all());
    }

    public function test_the_email_of_an_account_is_unique_and_cannot_be_changed(): void
    {
        $pic = $this->pic($this->klienA);
        $account = $this->account($this->klienA, ['Approver']);
        Sanctum::actingAs($pic);

        $this->postJson('/api/v1/client/accounts', ['name' => 'X', 'email' => $account->email, 'roles' => ['Approver'], 'data_scope' => 'own'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'Email ini sudah dipakai akun lain.']);

        $this->putJson("/api/v1/client/accounts/{$account->id}", ['name' => 'X', 'email' => 'baru@example.com', 'roles' => ['Approver'], 'data_scope' => 'own', 'is_active' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_a_division_scoped_admin_cannot_manage_an_unlinked_account_of_their_klien(): void
    {
        $division = $this->division($this->companyA);
        $admin = $this->account($this->klienA, ['Admin'], DataScope::Divisions, divisionIds: [$division->id]);
        $unlinked = $this->account($this->klienA, ['Approver']);

        $this->actingAs($admin);
        $this->assertFalse(UserResource::canManageAccount($unlinked));
        $this->assertArrayNotHasKey($unlinked->id, UserResource::assignableUserOptions());

        Sanctum::actingAs($admin);
        $this->putJson("/api/v1/client/accounts/{$unlinked->id}", ['name' => 'X', 'roles' => ['Approver'], 'data_scope' => 'own', 'is_active' => true])
            ->assertForbidden()
            ->assertJsonPath('message', 'Anda tidak bisa mengubah akun ini.');
        $this->postJson("/api/v1/client/accounts/{$unlinked->id}/reset-password")->assertForbidden();
        $this->getJson("/api/v1/client/accounts/{$unlinked->id}")->assertNotFound();
        $this->assertNotSame('X', $unlinked->fresh()->name);
    }

    public function test_a_company_scoped_admin_cannot_manage_an_unlinked_account_but_an_all_scoped_admin_can(): void
    {
        $unlinked = $this->account($this->klienA, ['Approver']);

        $this->actingAs($this->account($this->klienA, ['Admin'], DataScope::Companies, [$this->companyA->id]));
        $this->assertFalse(UserResource::canManageAccount($unlinked));

        $this->actingAs($this->admin($this->klienA));
        $this->assertTrue(UserResource::canManageAccount($unlinked));
        $this->assertArrayHasKey($unlinked->id, UserResource::assignableUserOptions());

        $this->actingAs($this->pic($this->klienA));
        $this->assertTrue(UserResource::canManageAccount($unlinked));
    }

    public function test_a_division_scoped_admin_still_manages_the_accounts_of_their_workers(): void
    {
        $division = $this->division($this->companyA);
        $admin = $this->account($this->klienA, ['Admin'], DataScope::Divisions, divisionIds: [$division->id]);
        $crew = $this->account($this->klienA, ['Freelancer']);
        Freelancer::create(['user_id' => $crew->id, 'company_id' => $this->companyA->id, 'division_id' => $division->id, 'full_name' => 'Crew', 'status' => 'active']);

        $this->actingAs($admin);
        $this->assertTrue(UserResource::canManageAccount($crew->fresh()));

        Sanctum::actingAs($admin);
        $this->getJson("/api/v1/client/accounts/{$crew->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $crew->id)
            ->assertJsonPath('data.worker.type', 'freelancer')
            ->assertJsonPath('data.can_edit', true);
    }

    public function test_roles_follow_an_allow_list_and_custom_roles_are_only_for_superadmin(): void
    {
        Role::findOrCreate('Intern', 'web');
        Role::findOrCreate('Auditor', 'web');

        $this->actingAs($this->pic($this->klienA));
        $this->assertSame(['Admin', 'Approver', 'BOD', 'Employee', 'Freelancer', 'Intern', 'Koordinator'], array_keys(UserResource::assignableRoleOptions()));

        $this->actingAs($this->admin($this->klienA));
        $this->assertSame(['Approver', 'BOD', 'Employee', 'Freelancer', 'Intern', 'Koordinator'], array_keys(UserResource::assignableRoleOptions()));

        $superadmin = User::factory()->create();
        $superadmin->assignRole('Superadmin');
        $this->actingAs($superadmin);
        $this->assertArrayHasKey('Auditor', UserResource::assignableRoleOptions());
        $this->assertArrayHasKey('PIC', UserResource::assignableRoleOptions());
    }

    public function test_an_account_holding_a_role_the_user_cannot_grant_is_beyond_reach(): void
    {
        Role::findOrCreate('Auditor', 'web');
        $auditor = $this->account($this->klienA, ['Approver', 'Auditor'], DataScope::All);
        $pic = $this->pic($this->klienA);

        $this->actingAs($pic);
        $this->assertFalse(UserResource::canManageAccount($auditor));

        Sanctum::actingAs($pic);
        $this->assertNotContains($auditor->id, collect($this->getJson('/api/v1/client/accounts')->json('data'))->pluck('id')->all());
        $this->getJson("/api/v1/client/accounts/{$auditor->id}")->assertNotFound();
        $this->putJson("/api/v1/client/accounts/{$auditor->id}", ['name' => 'X', 'roles' => ['Approver'], 'data_scope' => 'own', 'is_active' => true])
            ->assertForbidden();
        $this->assertTrue($auditor->fresh()->hasRole('Auditor'));
    }

    public function test_a_password_reset_signs_the_account_out_of_the_app(): void
    {
        $account = $this->account($this->klienA, ['Approver']);
        $account->createToken('mobile');
        $account->createToken('tablet');
        Sanctum::actingAs($this->pic($this->klienA));

        $this->postJson("/api/v1/client/accounts/{$account->id}/reset-password")->assertOk();

        $this->assertSame(0, $account->tokens()->count());
    }

    public function test_credential_endpoints_are_throttled(): void
    {
        $account = $this->account($this->klienA, ['Approver']);
        Sanctum::actingAs($this->pic($this->klienA));

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson("/api/v1/client/accounts/{$account->id}/reset-password")->assertOk();
        }

        $this->postJson("/api/v1/client/accounts/{$account->id}/reset-password")->assertTooManyRequests();
        $this->postJson('/api/v1/client/accounts', ['name' => 'X', 'email' => 'x@example.com', 'roles' => ['Approver'], 'data_scope' => 'own'])
            ->assertTooManyRequests();
    }

    public function test_the_superadmin_adds_companies_and_accounts_from_the_web_panel_only(): void
    {
        $superadmin = User::factory()->create(['is_active' => true]);
        $superadmin->assignRole('Superadmin');
        $superadmin->grantDataAccess(DataScope::All);
        Sanctum::actingAs($superadmin);

        $this->getJson('/api/v1/client/overview')->assertOk()->assertJsonPath('permissions.can_create_company', false);

        $this->postJson('/api/v1/client/companies', ['name' => 'PT Baru', 'code' => 'BARU'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Superadmin mengelola klien lewat panel web.');
        $this->postJson('/api/v1/client/accounts', ['name' => 'X', 'email' => 'x@example.com', 'roles' => ['Approver'], 'data_scope' => 'own'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Superadmin mengelola klien lewat panel web.');

        $this->assertDatabaseMissing('companies', ['code' => 'BARU']);
        $this->assertDatabaseMissing('users', ['email' => 'x@example.com']);
    }

    public function test_the_pic_reads_one_account_of_their_klien(): void
    {
        $pic = $this->pic($this->klienA);
        $bod = $this->account($this->klienA, ['BOD'], DataScope::All);
        $foreign = $this->account($this->klienB, ['Approver']);
        $superadmin = User::factory()->create();
        $superadmin->assignRole('Superadmin');
        Sanctum::actingAs($pic);

        $this->getJson("/api/v1/client/accounts/{$bod->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $bod->id)
            ->assertJsonPath('data.roles', ['BOD'])
            ->assertJsonPath('data.data_scope', 'all')
            ->assertJsonPath('data.can_edit', true);

        $this->getJson("/api/v1/client/accounts/{$foreign->id}")->assertNotFound();
        $this->getJson("/api/v1/client/accounts/{$superadmin->id}")->assertNotFound();
        $this->getJson("/api/v1/client/accounts/{$pic->id}")->assertNotFound();
        $this->getJson('/api/v1/client/accounts/999999')->assertNotFound();
    }

    public function test_a_worker_cannot_read_an_account(): void
    {
        $account = $this->account($this->klienA, ['Approver']);
        Sanctum::actingAs($this->account($this->klienA, ['Employee']));

        $this->getJson("/api/v1/client/accounts/{$account->id}")->assertForbidden();
    }
}
