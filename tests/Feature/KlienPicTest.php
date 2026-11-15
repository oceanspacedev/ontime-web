<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Filament\Imports\EmployeeImporter;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\CompanyResource\Pages\CreateCompany;
use App\Filament\Resources\EmployeeResource;
use App\Filament\Resources\JobLevelResource;
use App\Filament\Resources\JobTitleResource;
use App\Filament\Resources\TenantResource;
use App\Filament\Resources\TenantResource\Pages\CreateTenant;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\UserResource;
use App\Models\ApprovalFlow;
use App\Models\ApprovalRequestStep;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Holiday;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ApprovalRequestedNotification;
use App\Services\ApprovalFlowService;
use App\Services\DataAccessService;
use App\Services\TenantOnboardingService;
use App\Services\WorkerAccountService;
use Filament\Actions\Imports\Models\Import;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SaaS-style clients: the Superadmin makes a Klien and its PIC, and from there each client runs on its
 * own without ever reaching another client's data.
 */
class KlienPicTest extends TestCase
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

        config(['services.whatsapp_gateway.url' => null, 'services.whatsapp_gateway.token' => null]);
        Http::fake();

        foreach (['Superadmin', 'Admin', 'PIC', 'Koordinator', 'Approver', 'BOD', 'Employee', 'Freelancer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->superadmin = User::factory()->create();
        $this->superadmin->assignRole('Superadmin');

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

    private function account(Tenant $tenant, array $roles, DataScope $scope = DataScope::Own, array $companyIds = []): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['tenant_id' => $tenant->id])->save();
        $user->assignRole($roles);
        $user->grantDataAccess($scope, companyIds: $companyIds);

        return $user->fresh();
    }

    private function pic(Tenant $tenant): User
    {
        return app(TenantOnboardingService::class)->createPic($tenant, 'PIC '.$tenant->name, fake()->unique()->safeEmail())['user']->fresh();
    }

    private function crew(Company $company): Freelancer
    {
        $division = Division::firstOrCreate(['company_id' => $company->id, 'name' => 'Outlet'], ['is_active' => true]);
        $user = User::factory()->create();
        $user->forceFill(['tenant_id' => $company->tenant_id])->save();
        $user->assignRole('Freelancer');

        return Freelancer::create(['user_id' => $user->id, 'company_id' => $company->id, 'division_id' => $division->id, 'full_name' => 'Crew '.$company->name, 'status' => 'active']);
    }

    public function test_semua_for_a_client_admin_means_every_company_of_their_klien_only(): void
    {
        $admin = $this->account($this->klienA, ['Admin'], DataScope::All);
        $crewA = $this->crew($this->companyA);
        $crewB = $this->crew($this->companyB);

        $this->assertSame([$this->companyA->id], $admin->manageableCompanyIds());
        $this->assertSame([$crewA->id], DataAccessService::scopeWorkers(Freelancer::query(), $admin, includeOwn: false)->pluck('id')->all());
        $this->assertTrue($admin->canManageWorker($crewA));
        $this->assertFalse($admin->canManageWorker($crewB));
        $this->assertFalse(DataAccessService::hasGlobalAccess($admin));

        // A Badan Usaha added later to the Klien comes into reach by itself.
        $later = $this->company($this->klienA, 'PT A Dua', 'DUA');
        DataAccessService::flush();
        $this->assertEqualsCanonicalizing([$this->companyA->id, $later->id], $admin->fresh()->manageableCompanyIds());
    }

    public function test_a_pick_of_another_clients_company_never_opens_it(): void
    {
        $admin = $this->account($this->klienA, ['Admin'], DataScope::Companies, [$this->companyA->id, $this->companyB->id]);

        $this->assertSame([$this->companyA->id], $admin->manageableCompanyIds());
        $this->assertFalse($admin->canManageWorker($this->crew($this->companyB)));
    }

    public function test_superadmin_creates_a_klien_with_its_pic_account(): void
    {
        $this->actingAs($this->superadmin);

        Livewire::test(CreateTenant::class)
            ->fillForm([
                'name' => 'Grup Franchise',
                'code' => 'FRANCHISE',
                'is_active' => true,
                'pic_name' => 'Bu Rina',
                'pic_email' => 'rina@franchise.test',
                'pic_phone' => '081234567890',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $tenant = Tenant::query()->where('code', 'FRANCHISE')->firstOrFail();
        $pic = User::query()->where('email', 'rina@franchise.test')->firstOrFail();

        $this->assertSame($tenant->id, $pic->tenant_id);
        $this->assertTrue($pic->hasRole('Admin') && $pic->hasRole('PIC'));
        $this->assertSame(DataScope::All, $pic->dataScope());
        $this->assertTrue(DataAccessService::isTenantOwner($pic));
    }

    public function test_only_the_superadmin_sees_the_klien_menu(): void
    {
        $this->actingAs($this->superadmin);
        $this->assertTrue(TenantResource::canViewAny());

        $this->actingAs($this->pic($this->klienA));
        $this->assertFalse(TenantResource::canViewAny());
    }

    public function test_a_pic_adds_a_badan_usaha_to_their_own_klien(): void
    {
        $empty = Tenant::factory()->create();
        $pic = $this->pic($empty);
        $this->actingAs($pic);

        // Even with no company yet, the PIC opens Badan Usaha to add the first one.
        $this->assertTrue(CompanyResource::canViewAny());
        $this->assertTrue(CompanyResource::canCreate());

        // The same code as another client's company is fine: codes are unique per Klien.
        Livewire::test(CreateCompany::class)
            ->fillForm(['name' => 'PT Baru', 'code' => 'SAMA', 'is_active' => true, 'locations' => []])
            ->call('create')
            ->assertHasNoFormErrors();

        $company = Company::query()->where('name', 'PT Baru')->firstOrFail();
        $this->assertSame($empty->id, $company->tenant_id);
        $this->assertTrue(ApprovalFlow::query()->where('company_id', $company->id)->exists(), 'A new company gets the default approval flows.');

        DataAccessService::flush();
        $this->assertSame([$company->id], $pic->fresh()->manageableCompanyIds());

        Livewire::test(CreateCompany::class)
            ->fillForm(['name' => 'PT Kembar', 'code' => 'SAMA', 'is_active' => true, 'locations' => []])
            ->call('create')
            ->assertHasFormErrors(['code' => 'unique']);
    }

    public function test_a_client_admin_who_is_not_the_pic_cannot_add_or_delete_a_badan_usaha(): void
    {
        $this->actingAs($this->account($this->klienA, ['Admin'], DataScope::All));

        $this->assertTrue(CompanyResource::canViewAny());
        $this->assertFalse(CompanyResource::canCreate());
        $this->assertFalse(CompanyResource::canDelete($this->companyA));
    }

    public function test_a_pic_deletes_only_companies_of_their_klien(): void
    {
        $this->actingAs($this->pic($this->klienA));

        $this->assertTrue(CompanyResource::canDelete($this->companyA));
        $this->assertFalse(CompanyResource::canDelete($this->companyB));
    }

    public function test_the_pic_grants_admin_but_never_pic_or_superadmin(): void
    {
        $this->actingAs($this->pic($this->klienA));
        $this->assertSame(['Admin', 'Approver', 'BOD', 'Employee', 'Freelancer', 'Koordinator'], array_keys(UserResource::assignableRoleOptions()));

        $this->actingAs($this->account($this->klienA, ['Admin'], DataScope::All));
        $this->assertSame(['Approver', 'BOD', 'Employee', 'Freelancer', 'Koordinator'], array_keys(UserResource::assignableRoleOptions()));
    }

    public function test_an_account_made_by_the_pic_belongs_to_their_klien(): void
    {
        $pic = $this->pic($this->klienA);
        $this->actingAs($pic);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'HR Kedua',
                'email' => 'hr2@a.test',
                'password' => 'rahasia123',
                'roles' => [Role::findByName('Admin', 'web')->id],
                'has_employee_profile' => false,
                'data_scope' => DataScope::All->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $hr = User::query()->where('email', 'hr2@a.test')->firstOrFail();
        $this->assertSame($this->klienA->id, $hr->tenant_id);
        $this->assertSame([$this->companyA->id], $hr->manageableCompanyIds());
    }

    public function test_accounts_of_another_klien_are_out_of_reach(): void
    {
        $pic = $this->pic($this->klienA);
        $admin = $this->account($this->klienA, ['Admin'], DataScope::All);
        $foreignAdmin = $this->account($this->klienB, ['Admin'], DataScope::All);
        $foreignUnlinked = $this->account($this->klienB, ['Employee']);
        $ownUnlinked = $this->account($this->klienA, ['Employee']);

        $this->actingAs($pic);
        $this->assertTrue(UserResource::canManageAccount($admin), 'The PIC manages the other Admins of their client.');
        $this->assertFalse(UserResource::canManageAccount($foreignAdmin));
        $this->assertFalse(UserResource::canManageAccount($foreignUnlinked));

        // The "Akun User" picker used to offer every unlinked account on the platform.
        $this->actingAs($admin);
        $options = UserResource::assignableUserOptions();
        $this->assertArrayHasKey($ownUnlinked->id, $options);
        $this->assertArrayNotHasKey($foreignUnlinked->id, $options);
        $this->assertFalse(UserResource::canManageAccount($foreignUnlinked));
    }

    public function test_approvals_never_reach_another_klien_or_the_superadmin(): void
    {
        $foreignApprover = $this->account($this->klienB, ['Approver'], DataScope::All);
        $ownApprover = $this->account($this->klienA, ['Approver'], DataScope::All);

        $options = ApprovalFlowService::approverUserOptions($this->companyA->id);
        $this->assertArrayHasKey($ownApprover->id, $options);
        $this->assertArrayNotHasKey($foreignApprover->id, $options);
        $this->assertArrayNotHasKey($this->superadmin->id, $options);
        $this->assertNotContains('Superadmin', ApprovalFlowService::APPROVER_ROLES);

        $ownApprover->update(['is_active' => false]);
        $this->assertFalse(ApprovalFlowService::hasHolderForRole('Approver', $this->companyA->id), 'An Approver of another client never staffs this client\'s stage.');
        $this->assertFalse(ApprovalFlowService::hasHolderForRole('Superadmin', $this->companyA->id));
    }

    public function test_the_superadmin_is_not_notified_about_a_clients_requests(): void
    {
        $approver = $this->account($this->klienA, ['Approver'], DataScope::All);
        $foreignApprover = $this->account($this->klienB, ['Approver'], DataScope::All);
        app(ApprovalFlowService::class)->createDefaultFlows($this->companyA);
        $crew = $this->crew($this->companyA);
        config(['services.onesignal.app_id' => 'app-id', 'services.onesignal.rest_api_key' => 'rest-key']);
        Notification::fake();

        $leave = LeaveRequest::create(['freelancer_id' => $crew->id, 'leave_type' => 'sick', 'start_date' => now()->addDays(2)->toDateString(), 'end_date' => now()->addDays(2)->toDateString(), 'days_count' => 1, 'reason' => 'Sakit', 'status' => 'pending']);
        app(ApprovalFlowService::class)->generateSteps($leave, 'leave');

        Notification::assertSentTo($approver, ApprovalRequestedNotification::class);
        Notification::assertNotSentTo($foreignApprover, ApprovalRequestedNotification::class);
        Notification::assertNotSentTo($this->superadmin, ApprovalRequestedNotification::class);

        // The platform owner can still step in for support.
        $this->assertTrue(app(ApprovalFlowService::class)->isUserAuthorizedToApprove($leave->fresh(), $this->superadmin));
    }

    public function test_the_migration_moves_superadmin_stages_to_the_clients_admin(): void
    {
        $crew = $this->crew($this->companyA);
        ApprovalFlow::create(['company_id' => $this->companyA->id, 'request_type' => 'leave', 'step_number' => 1, 'step_order' => 1, 'name' => 'Persetujuan Akhir Superadmin', 'approver_type' => 'role', 'approver_role' => 'Superadmin']);
        $leave = LeaveRequest::create(['freelancer_id' => $crew->id, 'leave_type' => 'sick', 'start_date' => now()->addDays(2)->toDateString(), 'end_date' => now()->addDays(2)->toDateString(), 'days_count' => 1, 'reason' => 'Sakit', 'status' => 'pending']);
        $step = ApprovalRequestStep::create(['approvable_type' => $leave->getMorphClass(), 'approvable_id' => $leave->id, 'step_order' => 1, 'step_name' => 'Persetujuan Akhir Superadmin', 'approver_type' => 'role', 'approver_role' => 'Superadmin', 'status' => 'pending']);

        (include database_path('migrations/2026_11_15_090320_create_pic_role_and_drop_superadmin_approval_stages.php'))->up();

        $this->assertSame('Admin', ApprovalFlow::query()->where('company_id', $this->companyA->id)->value('approver_role'));
        $this->assertSame(['Admin', 'Persetujuan Akhir Admin'], [$step->fresh()->approver_role, $step->fresh()->step_name]);
    }

    public function test_shared_master_data_is_edited_only_by_the_superadmin(): void
    {
        $level = JobLevel::create(['name' => 'Staff', 'level_order' => 1]);

        $this->actingAs($this->pic($this->klienA));
        $this->assertFalse(JobLevelResource::canCreate());
        $this->assertFalse(JobLevelResource::canEdit($level));

        $this->actingAs($this->superadmin);
        $this->assertTrue(JobLevelResource::canEdit($level));
    }

    public function test_a_worker_account_belongs_to_the_klien_of_the_workers_company(): void
    {
        $division = Division::firstOrCreate(['company_id' => $this->companyB->id, 'name' => 'Outlet'], ['is_active' => true]);
        $worker = Freelancer::create(['company_id' => $this->companyB->id, 'division_id' => $division->id, 'full_name' => 'Crew Baru', 'phone' => '081200000000', 'status' => 'active']);

        $account = app(WorkerAccountService::class)->createAccount($worker, 'crew.baru@b.test');

        $this->assertSame($this->klienB->id, $account['user']->tenant_id);
    }

    public function test_job_titles_of_another_klien_stay_hidden(): void
    {
        $shared = JobTitle::create(['name' => 'Staff Umum']);
        $ownTitle = JobTitle::create(['name' => 'Kasir A', 'division_id' => Division::create(['company_id' => $this->companyA->id, 'name' => 'Toko A', 'is_active' => true])->id]);
        $foreignTitle = JobTitle::create(['name' => 'Kasir B', 'division_id' => Division::create(['company_id' => $this->companyB->id, 'name' => 'Toko B', 'is_active' => true])->id]);

        $this->actingAs($admin = $this->account($this->klienA, ['Admin'], DataScope::All));

        $this->assertEqualsCanonicalizing([$shared->id, $ownTitle->id], JobTitleResource::getEloquentQuery()->pluck('id')->all());
        $this->assertArrayNotHasKey(Division::query()->where('name', 'Toko B')->value('id'), JobTitleResource::tenantDivisionOptions());

        $admin->givePermissionTo(Permission::findOrCreate('ViewAny:JobTitle', 'web'));
        $this->getJson('/api/v1/master/job-titles')->assertOk()->assertJsonMissing(['name' => 'Kasir B']);
        $this->getJson("/api/v1/master/job-titles/{$foreignTitle->id}")->assertNotFound();
    }

    public function test_worker_numbers_only_have_to_be_unique_inside_a_klien(): void
    {
        $divisionA = Division::create(['company_id' => $this->companyA->id, 'name' => 'Kantor', 'is_active' => true]);
        $level = JobLevel::create(['name' => 'Staff', 'level_order' => 1]);
        $title = JobTitle::create(['name' => 'Staff']);
        Employee::create(['company_id' => $this->companyA->id, 'division_id' => $divisionA->id, 'job_level_id' => $level->id, 'job_title_id' => $title->id, 'nip' => 'EMP-001', 'full_name' => 'Karyawan A', 'join_date' => now(), 'status' => 'permanent']);

        $this->actingAs($this->account($this->klienB, ['Admin'], DataScope::All));
        $rule = fn (Company $company): array => ['nip' => [EmployeeResource::uniqueWithinTenant(Rule::unique('employees', 'nip'), $company->id)]];

        $this->assertTrue(Validator::make(['nip' => 'EMP-001'], $rule($this->companyB))->passes(), 'Another client\'s NIP never blocks this one.');

        $secondA = $this->company($this->klienA, 'PT A Dua', 'DUA');
        $this->assertTrue(Validator::make(['nip' => 'EMP-001'], $rule($secondA))->fails(), 'Inside one Klien a NIP stays unique across its companies.');
    }

    public function test_an_import_matches_the_company_code_inside_the_importers_klien(): void
    {
        JobLevel::firstOrCreate(['name' => 'STAFF'], ['level_order' => 1]);
        Division::create(['company_id' => $this->companyA->id, 'name' => 'GUDANG', 'is_active' => true]);
        Division::create(['company_id' => $this->companyB->id, 'name' => 'GUDANG', 'is_active' => true]);

        // Both clients use the code "SAMA"; Klien A's company has the lower id.
        $admin = $this->account($this->klienB, ['Admin'], DataScope::All);
        $import = Import::create(['file_name' => 'karyawan.csv', 'file_path' => 'karyawan.csv', 'importer' => EmployeeImporter::class, 'total_rows' => 1, 'user_id' => $admin->id]);
        $columns = ['nip' => 'NIP', 'full_name' => 'Nama', 'company' => 'Badan Usaha', 'division' => 'Divisi', 'job_level' => 'Level', 'job_title' => 'Posisi'];

        ($import->getImporter($columns, []))(['NIP' => 'B-1', 'Nama' => 'BARU', 'Badan Usaha' => 'SAMA', 'Divisi' => 'GUDANG', 'Level' => 'STAFF', 'Posisi' => 'STAFF']);

        $this->assertSame($this->companyB->id, Employee::query()->where('nip', 'B-1')->value('company_id'));
    }

    public function test_switching_a_klien_off_signs_its_accounts_out(): void
    {
        $admin = $this->account($this->klienA, ['Admin'], DataScope::All);
        $admin->createToken('mobile');
        $other = $this->account($this->klienB, ['Admin'], DataScope::All);
        $other->createToken('mobile');

        $this->klienA->update(['is_active' => false]);

        $this->assertFalse($admin->fresh()->canSignIn());
        $this->assertFalse($admin->fresh()->canAccessPanel(Filament::getDefaultPanel()));
        $this->assertSame(0, $admin->tokens()->count());
        $this->assertSame(1, $other->tokens()->count(), 'Other clients keep working.');
        $this->assertTrue($this->superadmin->fresh()->canSignIn());
    }

    public function test_a_stage_naming_the_superadmin_counts_as_unstaffed(): void
    {
        ApprovalFlow::create(['company_id' => $this->companyA->id, 'request_type' => 'leave', 'step_number' => 1, 'step_order' => 1, 'name' => 'Owner', 'approver_type' => 'user', 'user_id' => $this->superadmin->id]);

        $this->assertSame(['leave'], array_column(ApprovalFlowService::unstaffedStages($this->companyA), 'request_type'));
    }

    public function test_a_phone_shared_with_an_inactive_account_still_signs_in_the_active_one(): void
    {
        $active = $this->account($this->klienA, ['Employee']);
        $active->update(['phone' => '081311112222']);
        $ended = $this->account($this->klienB, ['Freelancer']);
        $ended->update(['phone' => '081311112222', 'is_active' => false]);

        $this->assertTrue($active->is(User::findByPhone('081311112222')));
    }

    public function test_the_app_shows_national_and_own_company_holidays_only(): void
    {
        Holiday::create(['name' => 'Tahun Baru', 'date' => '2026-01-01']);
        Holiday::create(['name' => 'Ulang Tahun PT A', 'date' => '2026-03-01', 'company_id' => $this->companyA->id]);
        $foreign = Holiday::create(['name' => 'Ulang Tahun PT B', 'date' => '2026-04-01', 'company_id' => $this->companyB->id]);

        $admin = $this->account($this->klienA, ['Admin'], DataScope::All);
        $admin->givePermissionTo(Permission::findOrCreate('ViewAny:Holiday', 'web'));
        $this->actingAs($admin);

        $names = collect($this->getJson('/api/v1/master/holidays')->assertOk()->json('data'))->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['Tahun Baru', 'Ulang Tahun PT A'], $names);
        $this->getJson("/api/v1/master/holidays/{$foreign->id}")->assertNotFound();
    }
}
