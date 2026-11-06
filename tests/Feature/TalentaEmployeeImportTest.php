<?php

namespace Tests\Feature;

use App\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use App\Models\ApprovalFlow;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\User;
use App\Services\TalentaEmployeeImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TalentaEmployeeImportTest extends TestCase
{
    use RefreshDatabase;

    private Company $top;

    private Division $depo;

    private Employee $existing;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Superadmin', 'Admin', 'Employee'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->top = Company::create(['name' => 'CV Top Selular', 'code' => 'TOP', 'is_active' => true]);
        $root = Division::create(['company_id' => $this->top->id, 'name' => 'DISTRIBUTION SALES', 'is_active' => true]);
        $this->depo = Division::create(['company_id' => $this->top->id, 'parent_id' => $root->id, 'name' => 'DEPO BANDUNG', 'is_active' => true]);
        $staff = JobLevel::create(['name' => 'STAFF', 'level_order' => 1]);

        $this->existing = Employee::create([
            'company_id' => $this->top->id,
            'division_id' => $this->depo->id,
            'job_level_id' => $staff->id,
            'job_title_id' => JobTitle::create(['name' => 'SALES', 'division_id' => $this->depo->id])->id,
            'nip' => '2020.01.01.01',
            'full_name' => 'BUDI LAMA',
            'join_date' => '2020-01-01',
            'status' => 'active',
        ]);
    }

    public function test_import_updates_existing_employees_and_creates_new_ones(): void
    {
        $result = app(TalentaEmployeeImportService::class)->importFromContent($this->payload());

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(['Toko Mas An An'], $result['new_companies']);

        $existing = $this->existing->fresh();
        $this->assertSame('BUDI SANTOSO', $existing->full_name);
        $this->assertSame($this->depo->id, $existing->division_id, 'Existing employees keep their deeper division.');
        $this->assertSame('2020-01-01', $existing->join_date->toDateString(), 'Talenta has no join date, so it is kept.');
        $this->assertSame('6281234567890', $existing->phone);
        $this->assertSame('1991-02-28', $existing->birth_date->toDateString());
        $this->assertSame(['Male', 'Married', 'K/1', '111'], [$existing->gender, $existing->marital_status, $existing->tax_status, $existing->talenta_id]);

        $newcomer = Employee::where('nip', '2026.01.01.01')->firstOrFail();
        $company = Company::where('name', 'Toko Mas An An')->firstOrFail();
        $this->assertSame('TMAA', $company->code);
        $this->assertSame($company->id, $newcomer->company_id);
        $this->assertSame('TOKO MAS AN AN', $newcomer->division->name);
        $this->assertSame('SALES TOKO', $newcomer->jobTitle->name);
        $this->assertSame('TEAM LEADER', $newcomer->jobLevel->name);
        $this->assertSame(5, ApprovalFlow::where('company_id', $company->id)->count(), 'New companies get the default approval flows.');
    }

    public function test_dry_run_changes_nothing(): void
    {
        $result = app(TalentaEmployeeImportService::class)->importFromContent($this->payload(), dryRun: true);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['created']);
        $this->assertSame(1, Employee::count());
        $this->assertSame('BUDI LAMA', $this->existing->fresh()->full_name);
        $this->assertDatabaseMissing('companies', ['name' => 'Toko Mas An An']);
    }

    public function test_company_admin_only_imports_their_own_companies(): void
    {
        $result = app(TalentaEmployeeImportService::class)->importFromContent($this->payload(), allowedCompanyIds: [$this->top->id]);

        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame([], $result['new_companies']);
        $this->assertDatabaseMissing('employees', ['nip' => '2026.01.01.01']);
    }

    public function test_missing_employees_can_be_deactivated_within_imported_companies(): void
    {
        $missing = $this->existing->replicate()->fill(['nip' => '2019.05.05.05', 'full_name' => 'SUDAH KELUAR']);
        $missing->save();

        $other = Company::create(['name' => 'CV Lain', 'code' => 'LAIN', 'is_active' => true]);
        $otherDivision = Division::create(['company_id' => $other->id, 'name' => 'UMUM', 'is_active' => true]);
        $untouched = $this->existing->replicate()->fill(['nip' => '2018.01.01.01', 'company_id' => $other->id, 'division_id' => $otherDivision->id]);
        $untouched->save();

        $result = app(TalentaEmployeeImportService::class)->importFromContent($this->payload(), deactivateMissing: true);

        $this->assertSame(1, $result['deactivated']);
        $this->assertSame('inactive', $missing->fresh()->status);
        $this->assertSame('active', $untouched->fresh()->status);
    }

    public function test_invalid_json_is_rejected(): void
    {
        $result = app(TalentaEmployeeImportService::class)->importFromContent('{not json');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Format JSON tidak valid', $result['message']);
    }

    public function test_superadmin_can_import_from_the_employee_list(): void
    {
        $superadmin = User::factory()->create();
        $superadmin->assignRole('Superadmin');
        $this->actingAs($superadmin);

        Livewire::test(ListEmployees::class)
            ->callAction('importTalenta', data: [
                'file' => UploadedFile::fake()->createWithContent('talenta.json', $this->payload()),
                'dry_run' => false,
            ])
            ->assertNotified('Import karyawan Talenta selesai.');

        $this->assertDatabaseHas('employees', ['nip' => '2026.01.01.01']);
    }

    private function payload(): string
    {
        return json_encode(['message' => 'success', 'status' => 200, 'data' => ['data' => [
            [
                'id' => '111',
                'id_employee' => '2020.01.01.01',
                'branch' => 'CV Top Selular',
                'organization' => 'DISTRIBUTION SALES',
                'job' => 'SALES',
                'title' => 'STAFF',
                'first_name' => 'BUDI',
                'last_name' => 'SANTOSO',
                'email' => 'budi@example.com',
                'mobile_phone' => '081234567890',
                'gender' => 'Male',
                'birth_date' => '28 Feb 1991',
                'marital_status' => 'Married',
                'tax_status' => 'K/1',
                'avatar' => 'https://example.com/budi.jpg',
                'join_date' => null,
            ],
            [
                'id' => '222',
                'id_employee' => '2026.01.01.01',
                'branch' => 'Toko Mas An An',
                'organization' => 'TOKO MAS AN AN',
                'job' => 'SALES TOKO',
                'title' => 'TEAM LEADER',
                'first_name' => 'SITI',
                'last_name' => '',
                'email' => 'siti@example.com',
                'mobile_phone' => '+6289876543210',
                'gender' => 'Female',
                'birth_date' => '01 Jan 2000',
                'marital_status' => 'Single',
                'tax_status' => 'TK/0',
                'avatar' => null,
                'join_date' => null,
            ],
        ], '_total_data' => 2]]);
    }
}
