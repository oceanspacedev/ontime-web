<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Filament\Imports\EmployeeImporter;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\EmployeeResource;
use App\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\UserResource;
use App\Models\ApprovalFlow;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\ApprovalFlowService;
use App\Services\DataAccessService;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ERP-style data access: role decides what a user does, users.data_scope decides for whom.
 */
class DataAccessTest extends TestCase
{
    use RefreshDatabase;

    private Company $top;

    private Company $cs;

    private Division $sales;

    private Division $depoBandung;

    private Division $finance;

    private Division $csSales;

    private Employee $salesWorker;

    private Employee $depoWorker;

    private Employee $financeWorker;

    private Employee $csWorker;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Superadmin', 'Admin', 'Approver', 'BOD', 'Employee'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->top = Company::create(['name' => 'CV Top', 'code' => 'TOP', 'is_active' => true]);
        $this->cs = Company::create(['name' => 'CV CS', 'code' => 'CS', 'is_active' => true]);
        $this->sales = Division::create(['company_id' => $this->top->id, 'name' => 'DISTRIBUTION SALES', 'is_active' => true]);
        $this->depoBandung = Division::create(['company_id' => $this->top->id, 'parent_id' => $this->sales->id, 'name' => 'DEPO BANDUNG', 'is_active' => true]);
        $this->finance = Division::create(['company_id' => $this->top->id, 'name' => 'FINANCE', 'is_active' => true]);
        $this->csSales = Division::create(['company_id' => $this->cs->id, 'name' => 'SALES CS', 'is_active' => true]);

        $this->salesWorker = $this->makeEmployee($this->sales);
        $this->depoWorker = $this->makeEmployee($this->depoBandung);
        $this->financeWorker = $this->makeEmployee($this->finance);
        $this->csWorker = $this->makeEmployee($this->csSales);
    }

    public function test_superadmin_always_has_access_to_everything(): void
    {
        $superadmin = User::factory()->create(['data_scope' => DataScope::Own]);
        $superadmin->assignRole('Superadmin');

        $this->assertSame(DataScope::All, $superadmin->dataScope());
        $this->assertNull(DataAccessService::workerFilter($superadmin));
        $this->assertSame(4, DataAccessService::scopeWorkers(Employee::query(), $superadmin)->count());
    }

    public function test_own_access_only_sees_own_records(): void
    {
        $user = $this->makeEmployee($this->sales, 'Approver')->user;
        $ownLeave = $this->makeLeave($user->employee);
        $this->makeLeave($this->depoWorker);

        $this->assertSame(DataScope::Own, $user->dataScope());
        $this->assertSame([$ownLeave->id], LeaveRequest::query()->visibleTo($user)->pluck('id')->all());
    }

    public function test_company_access_sees_every_worker_of_the_chosen_companies(): void
    {
        $bod = User::factory()->create();
        $bod->assignRole('BOD');
        $bod->grantDataAccess(DataScope::Companies, companyIds: [$this->top->id]);

        $this->assertEqualsCanonicalizing(
            [$this->salesWorker->id, $this->depoWorker->id, $this->financeWorker->id],
            DataAccessService::scopeWorkers(Employee::query(), $bod)->pluck('id')->all(),
        );
    }

    public function test_division_access_includes_sub_divisions_and_nothing_else(): void
    {
        $approver = User::factory()->create();
        $approver->assignRole('Approver');
        $approver->grantDataAccess(DataScope::Divisions, divisionIds: [$this->sales->id]);

        $this->assertEqualsCanonicalizing(
            [$this->salesWorker->id, $this->depoWorker->id],
            DataAccessService::scopeWorkers(Employee::query(), $approver)->pluck('id')->all(),
        );

        $leaves = collect([$this->salesWorker, $this->depoWorker, $this->financeWorker, $this->csWorker])->map(fn (Employee $worker) => $this->makeLeave($worker));
        $this->assertEqualsCanonicalizing($leaves->take(2)->pluck('id')->all(), LeaveRequest::query()->visibleTo($approver)->pluck('id')->all());
    }

    public function test_a_new_sub_division_is_covered_right_away(): void
    {
        $approver = User::factory()->create();
        $approver->assignRole('Approver');
        $approver->grantDataAccess(DataScope::Divisions, divisionIds: [$this->sales->id]);
        $this->assertFalse($approver->canAccessWorker($this->financeWorker));

        $depoBaru = Division::create(['company_id' => $this->top->id, 'parent_id' => $this->depoBandung->id, 'name' => 'DEPO BARU', 'is_active' => true]);

        $this->assertTrue(DataAccessService::canAccessPlacement($approver, $this->top->id, $depoBaru->id));
    }

    public function test_approval_follows_the_role_step_and_data_access(): void
    {
        ApprovalFlow::create(['company_id' => $this->top->id, 'request_type' => 'leave', 'step_number' => 1, 'step_order' => 1, 'name' => 'Approver', 'approver_type' => 'role', 'approver_role' => 'Approver']);
        ApprovalFlow::create(['company_id' => $this->top->id, 'request_type' => 'leave', 'step_number' => 2, 'step_order' => 2, 'name' => 'Superadmin', 'approver_type' => 'role', 'approver_role' => 'Superadmin']);

        $service = app(ApprovalFlowService::class);
        $leave = $this->makeLeave($this->depoWorker);
        $service->generateSteps($leave, 'leave');
        $leave = $leave->fresh();

        $salesApprover = User::factory()->create();
        $salesApprover->assignRole('Approver');
        $salesApprover->grantDataAccess(DataScope::Divisions, divisionIds: [$this->sales->id]);

        $financeApprover = User::factory()->create();
        $financeApprover->assignRole('Approver');
        $financeApprover->grantDataAccess(DataScope::Divisions, divisionIds: [$this->finance->id]);

        $bodWithAccess = User::factory()->create();
        $bodWithAccess->assignRole('BOD');
        $bodWithAccess->grantDataAccess(DataScope::All);

        $this->assertTrue($service->isUserAuthorizedToApprove($leave, $salesApprover), 'Parent division access covers DEPO BANDUNG.');
        $this->assertFalse($service->isUserAuthorizedToApprove($leave, $financeApprover), 'A sibling division is out of reach.');
        $this->assertFalse($service->isUserAuthorizedToApprove($leave, $bodWithAccess), 'Data access never replaces the role of the step.');
    }

    public function test_division_admin_manages_workers_but_not_company_settings(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $admin->grantDataAccess(DataScope::Divisions, divisionIds: [$this->sales->id]);
        $this->actingAs($admin);

        $this->assertTrue(EmployeeResource::canViewAny());
        $this->assertEqualsCanonicalizing([$this->salesWorker->id, $this->depoWorker->id], EmployeeResource::getEloquentQuery()->pluck('id')->all());
        $this->assertSame([$this->top->id => 'CV Top'], EmployeeResource::placeableCompanyOptions());
        $this->assertEqualsCanonicalizing([$this->sales->id, $this->depoBandung->id], array_keys(EmployeeResource::placeableDivisionOptions($this->top->id)));

        $this->assertFalse(CompanyResource::canViewAny());
        $this->assertTrue(LeaveRequestResource::canEdit($this->makeLeave($this->depoWorker)));
        $this->assertFalse(LeaveRequestResource::canEdit($this->makeLeave($this->financeWorker)));
    }

    public function test_admin_worker_lists_render_rows_with_linked_accounts(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $admin->grantDataAccess(DataScope::Divisions, divisionIds: [$this->sales->id]);
        $this->actingAs($admin);

        // Both sales workers have a login account, so the account actions are evaluated for several rows
        // at once, which used to lazy load the account's worker profiles.
        Livewire::test(ListEmployees::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$this->salesWorker, $this->depoWorker]);
    }

    public function test_admin_with_own_access_manages_nothing(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        $this->assertFalse(EmployeeResource::canViewAny());
        $this->assertFalse(UserResource::canViewAny());
        $this->assertFalse(CompanyResource::canViewAny());
    }

    public function test_division_admin_cannot_import_into_a_sibling_division(): void
    {
        JobLevel::firstOrCreate(['name' => 'STAFF'], ['level_order' => 1]);

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $admin->grantDataAccess(DataScope::Divisions, divisionIds: [$this->sales->id]);

        $import = Import::create(['file_name' => 'karyawan.csv', 'file_path' => 'karyawan.csv', 'importer' => EmployeeImporter::class, 'total_rows' => 1, 'user_id' => $admin->id]);
        $columns = ['nip' => 'NIP', 'full_name' => 'Nama', 'company' => 'Badan Usaha', 'division' => 'Divisi', 'job_level' => 'Level', 'job_title' => 'Posisi'];

        ($import->getImporter($columns, []))(['NIP' => 'NEW-1', 'Nama' => 'BARU', 'Badan Usaha' => 'TOP', 'Divisi' => 'DEPO BANDUNG', 'Level' => 'STAFF', 'Posisi' => 'SALES']);
        $this->assertSame($this->depoBandung->id, Employee::where('nip', 'NEW-1')->value('division_id'));

        $this->expectException(RowImportFailedException::class);
        $this->expectExceptionMessage('di luar akses Anda');

        ($import->getImporter($columns, []))(['NIP' => 'NEW-2', 'Nama' => 'SALAH', 'Badan Usaha' => 'TOP', 'Divisi' => 'FINANCE', 'Level' => 'STAFF', 'Posisi' => 'KASIR']);
    }

    public function test_only_superadmin_sees_the_data_access_fields(): void
    {
        $superadmin = User::factory()->create();
        $superadmin->assignRole('Superadmin');

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $admin->grantDataAccess(DataScope::Companies, companyIds: [$this->top->id]);

        $account = $this->salesWorker->user;

        $this->actingAs($superadmin);
        Livewire::test(EditUser::class, ['record' => $account->id])
            ->assertFormFieldIsVisible('data_scope')
            ->fillForm(['data_scope' => DataScope::Divisions->value, 'accessibleDivisions' => [$this->sales->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([$this->sales->id], $account->fresh()->accessibleDivisions()->pluck('divisions.id')->all());

        $this->actingAs($admin);
        Livewire::test(EditUser::class, ['record' => $account->id])
            ->assertFormFieldIsHidden('data_scope');
    }

    public function test_approvers_api_lists_who_covers_a_division(): void
    {
        $superadmin = User::factory()->create();
        $superadmin->assignRole('Superadmin');

        $salesApprover = User::factory()->create(['name' => 'Approver Sales']);
        $salesApprover->assignRole('Approver');
        $salesApprover->grantDataAccess(DataScope::Divisions, divisionIds: [$this->sales->id]);

        $financeApprover = User::factory()->create(['name' => 'Approver Finance']);
        $financeApprover->assignRole('Approver');
        $financeApprover->grantDataAccess(DataScope::Divisions, divisionIds: [$this->finance->id]);

        Sanctum::actingAs($superadmin);

        $this->getJson('/api/v1/master/approvers?division_id='.$this->depoBandung->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $salesApprover->id)
            ->assertJsonPath('data.0.data_scope', 'divisions')
            ->assertJsonPath('data.0.divisions.0.id', $this->sales->id);
    }

    public function test_migration_turns_the_old_mappings_into_data_access(): void
    {
        $superadmin = User::factory()->create();
        $superadmin->assignRole('Superadmin');

        $companyAdmin = User::factory()->create();
        $companyAdmin->assignRole('Admin');

        $companyBod = User::factory()->create();
        $companyBod->assignRole('BOD');

        $mixedApprover = User::factory()->create();
        $mixedApprover->assignRole('Approver');

        $plainEmployee = User::factory()->create();
        $plainEmployee->assignRole('Employee');

        DB::table('company_user')->insert(['company_id' => $this->top->id, 'user_id' => $companyAdmin->id]);
        DB::table('approvers')->insert([
            ['user_id' => $companyBod->id, 'company_id' => $this->cs->id, 'division_id' => null, 'level' => 2],
            ['user_id' => $mixedApprover->id, 'company_id' => $this->top->id, 'division_id' => $this->finance->id, 'level' => 1],
            ['user_id' => $mixedApprover->id, 'company_id' => $this->cs->id, 'division_id' => null, 'level' => 1],
        ]);
        DB::table('users')->update(['data_scope' => DataScope::Own->value]);

        $migration = require database_path('migrations/2026_11_04_115146_add_data_access_to_users.php');
        (fn () => $this->migrateExistingAccess())->call($migration);

        $this->assertSame('all', DB::table('users')->where('id', $superadmin->id)->value('data_scope'));
        $this->assertSame('companies', DB::table('users')->where('id', $companyAdmin->id)->value('data_scope'));
        $this->assertSame('companies', DB::table('users')->where('id', $companyBod->id)->value('data_scope'));
        $this->assertSame([$this->cs->id], DB::table('company_user')->where('user_id', $companyBod->id)->pluck('company_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame('divisions', DB::table('users')->where('id', $mixedApprover->id)->value('data_scope'));
        $this->assertEqualsCanonicalizing(
            [$this->finance->id, $this->csSales->id],
            DB::table('division_user')->where('user_id', $mixedApprover->id)->pluck('division_id')->map(fn ($id): int => (int) $id)->all(),
            'A company-wide row becomes that company\'s top-level divisions.',
        );
        $this->assertSame('own', DB::table('users')->where('id', $plainEmployee->id)->value('data_scope'));
    }

    private function makeEmployee(Division $division, string $role = 'Employee'): Employee
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return Employee::create([
            'user_id' => $user->id,
            'company_id' => $division->company_id,
            'division_id' => $division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Staff'])->id,
            'nip' => 'NIP-'.$user->id,
            'full_name' => "Pekerja {$user->id}",
            'join_date' => '2025-01-06',
            'status' => 'active',
        ])->load('user');
    }

    private function makeLeave(Employee $worker): LeaveRequest
    {
        return LeaveRequest::create([
            'employee_id' => $worker->id,
            'leave_type' => 'permission',
            'start_date' => '2026-11-09',
            'end_date' => '2026-11-09',
            'days_count' => 1,
            'reason' => 'Izin',
            'status' => 'pending',
        ]);
    }
}
