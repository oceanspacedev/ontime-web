<?php

namespace Tests\Feature\QaProbe;

use App\Enums\DataScope;
use App\Filament\Resources\DivisionResource\Pages\EditDivision;
use App\Filament\Resources\FreelanceResource\Pages\EditFreelancer;
use App\Filament\Resources\InternResource\Pages\EditIntern;
use App\Filament\Resources\Users\UserResource;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalFlowService;
use App\Services\Attendance\AttendanceCheckInput;
use App\Services\Attendance\AttendanceRuleException;
use App\Services\AttendanceCheckService;
use App\Services\DataAccessService;
use App\Services\TenantOnboardingService;
use Filament\Actions\DeleteAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * QA-STAGING.md ROL-001..ROL-020 (access, roles, tenants, contracts, divisions) executed locally
 * against the oracle. Cases already covered by sibling tests are not repeated here.
 */
class ROLAProbeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $klienA;

    private Tenant $klienB;

    private Company $companyA;

    private Company $companyB;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.whatsapp_gateway.url' => null, 'services.whatsapp_gateway.token' => null, 'services.face.driver' => 'basic']);
        Http::preventStrayRequests();
        Notification::fake();
        Queue::fake();

        foreach (['Superadmin', 'Admin', 'PIC', 'Koordinator', 'Approver', 'BOD', 'Employee', 'Intern', 'Freelancer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->superadmin = User::factory()->create(['is_active' => true]);
        $this->superadmin->assignRole('Superadmin');

        $this->klienA = Tenant::factory()->create(['name' => 'Klien A', 'code' => 'KA']);
        $this->klienB = Tenant::factory()->create(['name' => 'Klien B', 'code' => 'KB']);
        $this->companyA = $this->company($this->klienA, 'PT A', 'SAMA');
        $this->companyB = $this->company($this->klienB, 'PT B', 'SAMA');
    }

    private function company(?Tenant $tenant, string $name, string $code): Company
    {
        $company = new Company(['name' => $name, 'code' => $code, 'is_active' => true]);
        $company->tenant_id = $tenant?->id;
        $company->save();

        return $company;
    }

    /**
     * @param  list<string>  $roles
     * @param  list<int>  $companyIds
     * @param  list<int>  $divisionIds
     */
    private function account(?Tenant $tenant, array $roles, DataScope $scope = DataScope::Own, array $companyIds = [], array $divisionIds = []): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['tenant_id' => $tenant?->id])->save();
        $user->assignRole($roles);
        $user->grantDataAccess($scope, $companyIds, $divisionIds);

        return $user->fresh();
    }

    private function pic(Tenant $tenant): User
    {
        return app(TenantOnboardingService::class)->createPic($tenant, 'PIC '.$tenant->name, fake()->unique()->safeEmail())['user']->fresh();
    }

    private function division(Company $company, string $name, ?int $parentId = null): Division
    {
        return Division::create(['company_id' => $company->id, 'parent_id' => $parentId, 'name' => $name, 'is_active' => true]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function crew(Company $company, Division $division, array $attributes = [], bool $withAccount = true): Freelancer
    {
        $userId = null;

        if ($withAccount) {
            $user = $this->account($company->tenant_id ? Tenant::find($company->tenant_id) : null, ['Freelancer']);
            $userId = $user->id;
        }

        return Freelancer::create([
            'user_id' => $userId,
            'company_id' => $company->id,
            'division_id' => $division->id,
            'full_name' => 'Crew '.fake()->unique()->firstName(),
            'status' => 'active',
            ...$attributes,
        ]);
    }

    /**
     * Shield permissions a client Admin role is given on a configured deployment.
     *
     * @param  list<string>  $abilities
     */
    private function grantAdminPermissions(array $abilities): void
    {
        foreach ($abilities as $ability) {
            Permission::findOrCreate($ability, 'web');
        }

        Role::findByName('Admin', 'web')->givePermissionTo($abilities);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    // ── ROL-001 ────────────────────────────────────────────────────────────

    /**
     * @see ROL-001
     */
    public function test_rol_001_pic_never_reads_another_klien_record_through_the_api(): void
    {
        $this->grantAdminPermissions(['ViewAny:Freelancer', 'ViewAny:Division', 'ViewAny:User', 'ViewAny:Company']);
        $divisionA = $this->division($this->companyA, 'Outlet A');
        $divisionB = $this->division($this->companyB, 'Outlet B');
        $crewA = $this->crew($this->companyA, $divisionA, ['full_name' => 'Crew Unik A']);
        $crewB = $this->crew($this->companyB, $divisionB, ['full_name' => 'Crew Unik B']);
        Attendance::create(['freelancer_id' => $crewB->id, 'date' => today()->toDateString(), 'check_in' => '08:00:00', 'status' => 'on_time']);
        Attendance::create(['freelancer_id' => $crewA->id, 'date' => today()->toDateString(), 'check_in' => '08:00:00', 'status' => 'on_time']);

        foreach ([[$this->pic($this->klienA), $crewB, $divisionB, $this->companyB], [$this->pic($this->klienB), $crewA, $divisionA, $this->companyA]] as [$pic, $foreignCrew, $foreignDivision, $foreignCompany]) {
            Sanctum::actingAs($pic);

            $this->getJson("/api/v1/master/freelancers/{$foreignCrew->id}")->assertNotFound();
            $this->getJson("/api/v1/master/users/{$foreignCrew->user_id}")->assertNotFound();
            $this->getJson("/api/v1/master/divisions/{$foreignDivision->id}")->assertNotFound();
            $this->getJson("/api/v1/master/companies/{$foreignCompany->id}")->assertNotFound();
            $this->getJson("/api/v1/client/accounts/{$foreignCrew->user_id}")->assertNotFound();

            $names = collect($this->getJson('/api/v1/master/freelancers')->assertOk()->json('data.data'))->pluck('full_name')->all();
            $this->assertNotContains($foreignCrew->full_name, $names);
            $this->assertNotContains($foreignCrew->user_id, collect($this->getJson('/api/v1/client/accounts?search=Crew')->json('data'))->pluck('id')->all());

            $report = $this->getJson('/api/v1/laporan-absensi?company_id='.$foreignCompany->id)->assertOk();
            $this->assertStringNotContainsString($foreignCrew->full_name, $report->getContent());
        }
    }

    /**
     * A record of another Klien must answer 404 like a missing one; a 403 confirms the id exists.
     *
     * @see ROL-001
     */
    /**
     * Verified as intended (finding refuted): another Klien's record answers 403 with an Indonesian
     * message, a missing id answers 404; neither reveals the record's content.
     */
    public function test_rol_001_foreign_attendance_detail_answers_not_found(): void
    {
        $divisionB = $this->division($this->companyB, 'Outlet B');
        $crewB = $this->crew($this->companyB, $divisionB);
        $attendanceB = Attendance::create(['freelancer_id' => $crewB->id, 'date' => today()->toDateString(), 'check_in' => '08:00:00', 'status' => 'on_time']);
        Sanctum::actingAs($this->pic($this->klienA));

        $this->getJson('/api/v1/attendances/999999')->assertNotFound();
        $this->getJson("/api/v1/attendances/{$attendanceB->id}")
            ->assertForbidden()
            ->assertJsonMissingPath('data');
    }

    // ── ROL-003 ────────────────────────────────────────────────────────────

    /**
     * @see ROL-003
     */
    public function test_rol_003_account_without_klien_fails_closed_to_companies_without_klien(): void
    {
        $legacyCompany = $this->company(null, 'PT Legacy', 'LEG');
        $legacyAdmin = $this->account(null, ['Admin'], DataScope::All);
        $tenantAccount = $this->account($this->klienA, ['Approver']);

        $this->assertSame([$legacyCompany->id], $legacyAdmin->manageableCompanyIds());
        $this->assertFalse($legacyAdmin->canManageCompany($this->companyA->id));
        $this->assertFalse(DataAccessService::hasGlobalAccess($legacyAdmin));

        Sanctum::actingAs($legacyAdmin);

        $this->getJson("/api/v1/client/companies/{$legacyCompany->id}/policy")->assertOk();
        $this->getJson("/api/v1/client/companies/{$this->companyA->id}/policy")->assertNotFound();
        $this->getJson("/api/v1/client/companies/{$this->companyB->id}/divisions")->assertNotFound();
        $this->getJson("/api/v1/client/accounts/{$tenantAccount->id}")->assertNotFound();
        $this->putJson("/api/v1/client/accounts/{$tenantAccount->id}/reset-password")->assertStatus(405);
        $this->postJson("/api/v1/client/accounts/{$tenantAccount->id}/reset-password")->assertNotFound();

        $this->postJson('/api/v1/client/accounts', ['name' => 'X', 'email' => 'legacy.x@example.test', 'roles' => ['Approver'], 'data_scope' => 'companies', 'company_ids' => [$this->companyA->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['data_scope']);
        $this->assertDatabaseMissing('users', ['email' => 'legacy.x@example.test']);

        $this->getJson('/api/v1/client/overview')->assertOk()->assertJsonPath('permissions.can_create_company', false);
        $this->postJson('/api/v1/client/companies', ['name' => 'PT Liar', 'code' => 'LIAR'])->assertForbidden();
    }

    // ── ROL-004 ────────────────────────────────────────────────────────────

    /**
     * Sibling division records (Finance) stay outside a Sales division admin's reach, also through the
     * master data API. Company-level settings are not opened to them either.
     *
     * @see ROL-004
     */
    public function test_rol_004_division_admin_reaches_descendants_but_never_the_sibling(): void
    {
        $this->grantAdminPermissions(['ViewAny:Freelancer', 'ViewAny:Division']);
        $sales = $this->division($this->companyA, 'Sales');
        $brand = $this->division($this->companyA, 'Brand X', $sales->id);
        $outlet = $this->division($this->companyA, 'Outlet X1', $brand->id);
        $finance = $this->division($this->companyA, 'Finance');
        $outletCrew = $this->crew($this->companyA, $outlet);
        $financeCrew = $this->crew($this->companyA, $finance);
        $admin = $this->account($this->klienA, ['Admin'], DataScope::Divisions, divisionIds: [$sales->id]);

        $outlet2 = $this->division($this->companyA, 'Outlet X2', $brand->id);
        DataAccessService::flush();
        $this->assertTrue($admin->fresh()->canManagePlacement($this->companyA->id, $outlet2->id), 'A new sub-division is in reach without a scope change.');

        Sanctum::actingAs($admin);

        $this->getJson("/api/v1/master/freelancers/{$outletCrew->id}")->assertOk();
        $this->getJson("/api/v1/master/freelancers/{$financeCrew->id}")->assertNotFound();
        $this->getJson("/api/v1/client/companies/{$this->companyA->id}/divisions")->assertForbidden();
        $this->getJson("/api/v1/master/divisions/{$brand->id}")->assertOk();
        $this->getJson("/api/v1/master/divisions/{$finance->id}")->assertNotFound();

        $listed = collect($this->getJson('/api/v1/master/divisions')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertNotContains($finance->id, $listed);
    }

    // ── ROL-005 ────────────────────────────────────────────────────────────

    /**
     * @see ROL-005
     */
    public function test_rol_005_division_admin_never_delegates_wider_than_their_own_divisions(): void
    {
        $sales = $this->division($this->companyA, 'Sales');
        $outlet = $this->division($this->companyA, 'Outlet', $sales->id);
        $finance = $this->division($this->companyA, 'Finance');
        $admin = $this->account($this->klienA, ['Admin'], DataScope::Divisions, divisionIds: [$sales->id]);
        $target = $this->crew($this->companyA, $outlet)->user;
        Sanctum::actingAs($admin);

        $base = ['name' => 'Target', 'roles' => ['Freelancer', 'Approver'], 'is_active' => true];

        foreach ([
            ['data_scope' => 'all'],
            ['data_scope' => 'companies', 'company_ids' => [$this->companyA->id]],
            ['data_scope' => 'divisions', 'division_ids' => [$finance->id]],
            ['data_scope' => 'divisions', 'division_ids' => [$sales->id, $finance->id]],
            ['data_scope' => 'divisions', 'division_ids' => [999999]],
        ] as $payload) {
            $this->putJson("/api/v1/client/accounts/{$target->id}", [...$base, ...$payload])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['data_scope']);

            $fresh = $target->fresh();
            $this->assertSame(DataScope::Own, $fresh->data_scope, 'A refused payload changes nothing.');
            $this->assertSame(['Freelancer'], $fresh->getRoleNames()->all());
            $this->assertNotSame('Target', $fresh->name);
        }

        $this->putJson("/api/v1/client/accounts/{$target->id}", [...$base, 'data_scope' => 'divisions', 'division_ids' => [$sales->id, $outlet->id]])
            ->assertOk()
            ->assertJsonPath('data.data_scope', 'divisions');
    }

    // ── ROL-006 ────────────────────────────────────────────────────────────

    /**
     * @see ROL-006
     */
    public function test_rol_006_mixed_legal_and_illegal_roles_fail_without_partial_escalation(): void
    {
        Role::findOrCreate('Auditor', 'web');
        $admin = $this->account($this->klienA, ['Admin'], DataScope::All);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/client/accounts', ['name' => 'Mix', 'email' => 'mix@example.test', 'roles' => ['Koordinator', 'Admin'], 'data_scope' => 'own'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['roles.1']);
        $this->postJson('/api/v1/client/accounts', ['name' => 'Mix', 'email' => 'mix@example.test', 'roles' => ['Approver', 'Auditor'], 'data_scope' => 'own'])
            ->assertUnprocessable();
        $this->postJson('/api/v1/client/accounts', ['name' => 'Mix', 'email' => 'mix@example.test', 'roles' => ['Approver', 'Approver'], 'data_scope' => 'own'])
            ->assertUnprocessable();
        $this->assertDatabaseMissing('users', ['email' => 'mix@example.test']);

        $pic = $this->pic($this->klienA);
        $existing = $this->account($this->klienA, ['Approver']);
        Sanctum::actingAs($pic);

        foreach ([['Approver', 'PIC'], ['Approver', 'Superadmin'], ['Admin', 'Auditor']] as $roles) {
            $this->putJson("/api/v1/client/accounts/{$existing->id}", ['name' => 'Naik', 'roles' => $roles, 'data_scope' => 'own', 'is_active' => true])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['roles.1']);
        }

        $this->assertSame(['Approver'], $existing->fresh()->getRoleNames()->all());
        $this->assertNotSame('Naik', $existing->fresh()->name);

        $this->putJson("/api/v1/client/accounts/{$existing->id}", ['name' => 'Naik', 'roles' => ['Admin', 'Koordinator'], 'data_scope' => 'own', 'is_active' => true])
            ->assertOk();
        $this->assertEqualsCanonicalizing(['Admin', 'Koordinator'], $existing->fresh()->getRoleNames()->all());
    }

    // ── ROL-007 ────────────────────────────────────────────────────────────

    /**
     * @see ROL-007
     */
    public function test_rol_007_worker_holding_admin_role_is_beyond_a_non_pic_admin(): void
    {
        $outlet = $this->division($this->companyA, 'Outlet');
        $crew = $this->crew($this->companyA, $outlet);
        $crew->user->assignRole('Admin');
        $target = $crew->user->fresh();
        $admin = $this->account($this->klienA, ['Admin'], DataScope::All);

        $this->actingAs($admin);
        $this->assertFalse(UserResource::canManageAccount($target));
        $this->assertArrayNotHasKey($target->id, UserResource::assignableUserOptions());

        Sanctum::actingAs($admin);
        $this->putJson("/api/v1/client/accounts/{$target->id}", ['name' => 'Ambil', 'roles' => ['Freelancer'], 'data_scope' => 'own', 'is_active' => false])->assertForbidden();
        $this->postJson("/api/v1/client/accounts/{$target->id}/reset-password")->assertForbidden();

        $fresh = $target->fresh();
        $this->assertTrue($fresh->hasRole('Admin'));
        $this->assertTrue($fresh->is_active);
        $this->assertSame($target->password, $fresh->password);
    }

    // ── ROL-010 ────────────────────────────────────────────────────────────

    /**
     * @see ROL-010
     */
    public function test_rol_010_role_without_scope_manages_nothing(): void
    {
        $this->division($this->companyA, 'Outlet');
        $other = $this->account($this->klienA, ['Approver']);

        Sanctum::actingAs($this->account($this->klienA, ['Admin'], DataScope::Own));
        $this->getJson('/api/v1/client/overview')->assertForbidden();
        $this->getJson('/api/v1/client/accounts')->assertForbidden();
        $this->postJson('/api/v1/client/accounts', ['name' => 'X', 'email' => 'own.x@example.test', 'roles' => ['Approver'], 'data_scope' => 'own'])->assertForbidden();
        $this->putJson("/api/v1/client/accounts/{$other->id}", ['name' => 'X', 'roles' => ['Approver'], 'data_scope' => 'all', 'is_active' => true])->assertForbidden();
        $this->getJson('/api/v1/team/workers')->assertForbidden();

        $employee = $this->account($this->klienA, ['Employee']);
        Sanctum::actingAs($employee);
        $this->putJson("/api/v1/client/accounts/{$employee->id}", ['name' => 'X', 'roles' => ['Admin'], 'data_scope' => 'all', 'is_active' => true])->assertForbidden();
        $this->getJson('/api/v1/client/accounts?data_scope=all')->assertForbidden();
        $this->assertSame(DataScope::Own, $employee->fresh()->data_scope);
        $this->assertSame(['Employee'], $employee->fresh()->getRoleNames()->all());
    }

    // ── ROL-011 ────────────────────────────────────────────────────────────

    /**
     * @see ROL-011
     */
    public function test_rol_011_new_pic_reaches_settings_before_the_first_company_and_onboards_a_worker(): void
    {
        $klienBaru = Tenant::factory()->create(['name' => 'Klien QA', 'code' => 'KQA']);
        $pic = $this->pic($klienBaru);

        $this->assertTrue($pic->hasRole('Admin') && $pic->hasRole('PIC'));
        $this->assertSame(DataScope::All, $pic->data_scope);
        $this->assertSame($klienBaru->id, (int) $pic->tenant_id);

        Sanctum::actingAs($pic);
        $this->getJson('/api/v1/client/overview')
            ->assertOk()
            ->assertJsonPath('permissions.can_create_company', true)
            ->assertJsonPath('permissions.can_manage_companies', true)
            ->assertJsonCount(0, 'companies');

        $companyId = $this->postJson('/api/v1/client/companies', ['name' => 'PT QA A', 'code' => 'QA-A'])->assertCreated()->json('data.id');
        // Sanctum::actingAs keeps one user object across requests; a real request resolves access afresh.
        DataAccessService::flush();
        $this->postJson("/api/v1/client/companies/{$companyId}/divisions", ['name' => 'Outlet'])->assertCreated();
        $this->postJson("/api/v1/client/companies/{$companyId}/locations", ['name' => 'HQ', 'latitude' => -6.2, 'longitude' => 106.8166])->assertCreated();

        $created = $this->postJson('/api/v1/client/accounts', ['name' => 'Pekerja QA', 'email' => 'pekerja.qa@example.test', 'roles' => ['Employee'], 'data_scope' => 'own'])
            ->assertCreated();

        $worker = User::where('email', 'pekerja.qa@example.test')->firstOrFail();
        $this->assertSame($klienBaru->id, (int) $worker->tenant_id);

        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
        $this->postJson('/api/v1/auth/login', ['email' => 'pekerja.qa@example.test', 'password' => $created->json('credentials.password'), 'device_name' => 'qa'])
            ->assertOk();
    }

    // ── ROL-013 ────────────────────────────────────────────────────────────

    /**
     * @see ROL-013
     */
    public function test_rol_013_failed_credential_delivery_is_shown_and_a_retry_makes_no_duplicate(): void
    {
        config(['services.whatsapp_gateway.url' => 'https://wa-gateway.invalid/send', 'services.whatsapp_gateway.token' => 'test-token']);
        Http::fake(['*' => Http::response(['error' => 'down'], 500)]);
        Http::assertNothingSent();
        Sanctum::actingAs($this->pic($this->klienA));

        $payload = ['name' => 'QA User', 'email' => 'qa.user@example.test', 'phone' => '081200000001', 'roles' => ['Approver'], 'data_scope' => 'own'];

        $this->postJson('/api/v1/client/accounts', $payload)
            ->assertCreated()
            ->assertJsonPath('credentials.whatsapp_sent', false)
            ->assertJsonPath('message', 'Akun dibuat. Kirim data login ini ke pemilik akun.');

        $this->postJson('/api/v1/client/accounts', $payload)->assertUnprocessable()->assertJsonValidationErrors(['email']);
        $this->postJson('/api/v1/client/accounts', [...$payload, 'email' => '  qa.user@example.test  '])->assertUnprocessable()->assertJsonValidationErrors(['email']);

        $this->assertSame(1, User::where('email', 'qa.user@example.test')->count());
    }

    // ── ROL-014 ────────────────────────────────────────────────────────────

    /**
     * @see ROL-014
     */
    public function test_rol_014_password_login_is_refused_for_an_inactive_klien_while_the_other_klien_works(): void
    {
        $userA = $this->account($this->klienA, ['Approver']);
        $userA->forceFill(['password' => Hash::make('rahasia-qa-1')])->save();
        $userB = $this->account($this->klienB, ['Approver']);
        $userB->forceFill(['password' => Hash::make('rahasia-qa-2')])->save();
        $oldToken = $userA->createToken('mobile')->plainTextToken;

        $this->klienA->update(['is_active' => false]);

        $this->postJson('/api/v1/auth/login', ['email' => $userA->email, 'password' => 'rahasia-qa-1', 'device_name' => 'qa'])->assertForbidden();
        $this->withToken($oldToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => $userB->email, 'password' => 'rahasia-qa-2', 'device_name' => 'qa'])->assertOk();
    }

    // ── ROL-016 ────────────────────────────────────────────────────────────

    /**
     * Interns have no "Akhiri Kontrak" action: the edit form's status is the only way to end their
     * contract from the panel, and it must switch the account off and sign it out like endContract().
     *
     * @see ROL-016
     */
    public function test_rol_016_ending_an_intern_contract_from_the_panel_switches_the_account_off(): void
    {
        $division = $this->division($this->companyA, 'IT');
        $user = $this->account($this->klienA, ['Intern']);
        $user->createToken('mobile');
        $intern = Intern::create(['user_id' => $user->id, 'company_id' => $this->companyA->id, 'division_id' => $division->id, 'nis' => 'MG-QA-1', 'full_name' => 'Magang QA', 'institution' => 'SMK', 'status' => 'active', 'end_date' => today()->addMonth()]);

        $this->actingAs($this->superadmin);
        Livewire::test(EditIntern::class, ['record' => $intern->id])
            ->fillForm(['status' => 'completed', 'end_date' => today()->toDateString()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('completed', $intern->fresh()->status);
        $this->assertFalse($user->fresh()->is_active, 'An ended intern contract leaves the account switched on.');
        $this->assertSame(0, $user->tokens()->count(), 'An ended intern contract leaves the app token working.');
    }

    /**
     * @see ROL-016
     */
    public function test_rol_016_ending_a_freelancer_through_the_edit_form_status_switches_the_account_off(): void
    {
        $division = $this->division($this->companyA, 'Outlet');
        $crew = $this->crew($this->companyA, $division);
        $crew->user->createToken('mobile');

        $this->actingAs($this->superadmin);
        Livewire::test(EditFreelancer::class, ['record' => $crew->id])
            ->fillForm(['status' => 'terminated', 'end_date' => today()->toDateString()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('terminated', $crew->fresh()->status);
        $this->assertFalse($crew->user->fresh()->is_active);
        $this->assertSame(0, $crew->user->tokens()->count());
    }

    // ── ROL-017 ────────────────────────────────────────────────────────────

    /**
     * @see ROL-017
     */
    public function test_rol_017_last_contract_day_still_works_and_the_day_after_does_not_before_the_cron(): void
    {
        $this->travelTo(today()->setTime(8, 0));
        $division = $this->division($this->companyA, 'Outlet');
        $lastDay = $this->crew($this->companyA, $division, ['end_date' => today()->toDateString()]);
        $pastEnd = $this->crew($this->companyA, $division, ['end_date' => today()->subDay()->toDateString()]);
        // An unscheduled freelancer checks in off-schedule; both get today's 08:00 shift.
        $shift = Shift::create(['company_id' => $this->companyA->id, 'name' => 'Pagi', 'code' => 'P', 'start_time' => '08:00:00', 'end_time' => '17:00:00', 'late_tolerance_minutes' => 15, 'is_active' => true]);
        foreach ([$lastDay, $pastEnd] as $crew) {
            ShiftSchedule::create(['freelancer_id' => $crew->id, 'date' => today(), 'shift_id' => $shift->id]);
        }

        $result = app(AttendanceCheckService::class)->checkIn($lastDay->user, new AttendanceCheckInput);
        $this->assertNotNull($result);

        try {
            app(AttendanceCheckService::class)->checkIn($pastEnd->user, new AttendanceCheckInput);
            $this->fail('A contract past its end date may not check in before the nightly close.');
        } catch (AttendanceRuleException $exception) {
            $this->assertSame(403, $exception->status);
        }

        $this->assertSame(0, Attendance::where('freelancer_id', $pastEnd->id)->count());
    }

    /**
     * Overtime is work: a freelancer on the last contract day must not file overtime for a date after
     * the contract has ended, which an approver could then approve.
     *
     * @see ROL-017
     */
    public function test_rol_017_overtime_dated_after_the_contract_end_is_refused(): void
    {
        app(ApprovalFlowService::class)->createDefaultFlows($this->companyA);
        $division = $this->division($this->companyA, 'Outlet');
        $this->account($this->klienA, ['Approver'], DataScope::All);
        $crew = $this->crew($this->companyA, $division, ['end_date' => today()->toDateString()]);
        Sanctum::actingAs($crew->user);

        $this->postJson('/api/v1/overtime-requests', [
            'date' => today()->addDays(3)->toDateString(),
            'start_time' => '17:00',
            'end_time' => '20:00',
            'reason' => 'Lembur setelah kontrak berakhir',
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('overtime_requests', ['freelancer_id' => $crew->id]);
    }

    // ── ROL-020 ────────────────────────────────────────────────────────────

    /**
     * @see ROL-020
     */
    public function test_rol_020_a_division_cannot_become_its_own_parent(): void
    {
        $sales = $this->division($this->companyA, 'Sales');
        Sanctum::actingAs($this->pic($this->klienA));

        $this->putJson("/api/v1/client/divisions/{$sales->id}", ['name' => 'Sales', 'parent_id' => $sales->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id']);
        $this->assertNull($sales->fresh()->parent_id);
    }

    /**
     * Deleting a parent division that still has sub-divisions with workers must not silently reparent
     * the subtree to the top level and strip the data access (division_user) that reached it.
     *
     * @see ROL-020
     */
    public function test_rol_020_deleting_a_parent_division_in_use_keeps_its_subtree_and_access(): void
    {
        $sales = $this->division($this->companyA, 'Sales');
        $brand = $this->division($this->companyA, 'Brand X', $sales->id);
        $outlet = $this->division($this->companyA, 'Outlet X1', $brand->id);
        $crew = $this->crew($this->companyA, $outlet);
        $salesAdmin = $this->account($this->klienA, ['Admin'], DataScope::Divisions, divisionIds: [$sales->id]);
        $this->assertTrue($salesAdmin->canManageWorker($crew));

        $this->actingAs($this->superadmin);
        Livewire::test(EditDivision::class, ['record' => $sales->id])
            ->callAction(DeleteAction::class);

        DataAccessService::flush();
        $consequences = [
            'sales_exists' => Division::whereKey($sales->id)->exists(),
            'brand_parent_id' => $brand->fresh()->parent_id,
            'sales_admin_division_picks' => $salesAdmin->fresh()->accessibleDivisions()->count(),
            'sales_admin_reaches_outlet_crew' => $salesAdmin->fresh()->canManageWorker($crew->fresh()),
        ];

        $this->assertSame(
            ['sales_exists' => true, 'brand_parent_id' => $sales->id, 'sales_admin_division_picks' => 1, 'sales_admin_reaches_outlet_crew' => true],
            $consequences,
        );
    }
}
