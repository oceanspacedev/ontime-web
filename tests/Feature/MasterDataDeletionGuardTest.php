<?php

namespace Tests\Feature;

use App\Filament\Resources\CompanyResource\Pages\EditCompany;
use App\Filament\Resources\JobLevelResource\Pages\ListJobLevels;
use App\Filament\Resources\JobTitleResource\Pages\ListJobTitles;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Intern;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MasterDataDeletionGuardTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Division $division;

    private JobLevel $jobLevel;

    private JobTitle $jobTitle;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Superadmin']);

        $superadmin = User::factory()->create();
        $superadmin->assignRole('Superadmin');
        $this->actingAs($superadmin);

        $this->company = Company::create(['name' => 'PT Master', 'code' => 'MST', 'is_active' => true]);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Gudang', 'is_active' => true]);
        $this->jobLevel = JobLevel::create(['name' => 'Staff', 'level_order' => 1]);
        $this->jobTitle = JobTitle::create(['name' => 'Staff Gudang', 'division_id' => $this->division->id]);

        $this->employee = Employee::create([
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'job_level_id' => $this->jobLevel->id,
            'job_title_id' => $this->jobTitle->id,
            'nip' => 'MST-001',
            'full_name' => 'Karyawan Gudang',
            'status' => 'active',
        ]);
    }

    public function test_job_level_in_use_is_not_deleted_and_the_reason_is_shown(): void
    {
        Livewire::test(ListJobLevels::class)
            ->callAction(TestAction::make('delete')->table($this->jobLevel))
            ->assertNotified('Data tidak dapat dihapus');

        $this->assertModelExists($this->jobLevel);
        $this->assertModelExists($this->employee);
    }

    public function test_unused_job_level_can_be_deleted(): void
    {
        $unused = JobLevel::create(['name' => 'Direktur', 'level_order' => 9]);

        Livewire::test(ListJobLevels::class)
            ->callAction(TestAction::make('delete')->table($unused));

        $this->assertModelMissing($unused);
    }

    public function test_bulk_delete_skips_job_levels_in_use(): void
    {
        $unused = JobLevel::create(['name' => 'Direktur', 'level_order' => 9]);

        Livewire::test(ListJobLevels::class)
            ->selectTableRecords([$this->jobLevel->id, $unused->id])
            ->callAction(TestAction::make('delete')->table()->bulk());

        $this->assertModelExists($this->jobLevel);
        $this->assertModelMissing($unused);
        $this->assertModelExists($this->employee);
    }

    public function test_job_title_in_use_is_not_deleted(): void
    {
        Livewire::test(ListJobTitles::class)
            ->callAction(TestAction::make('delete')->table($this->jobTitle))
            ->assertNotified('Data tidak dapat dihapus');

        $this->assertModelExists($this->jobTitle);
        $this->assertModelExists($this->employee);
    }

    public function test_division_with_only_interns_is_not_deleted(): void
    {
        $division = Division::create(['company_id' => $this->company->id, 'name' => 'Magang IT', 'is_active' => true]);
        $intern = Intern::create([
            'company_id' => $this->company->id,
            'division_id' => $division->id,
            'nis' => 'MG-MST-01',
            'full_name' => 'Magang IT',
            'institution' => 'SMK Test',
            'status' => 'active',
        ]);

        $this->assertFalse($division->delete());
        $this->assertModelExists($division);
        $this->assertModelExists($intern);
        $this->assertNotNull($division->deletionBlockedReason());
    }

    public function test_division_with_employees_is_not_deleted_and_names_the_division(): void
    {
        $division = Division::create(['company_id' => $this->company->id, 'name' => 'Divisi Tanpa Anak', 'is_active' => true]);
        $division->employees()->create([
            'company_id' => $this->company->id,
            'job_level_id' => $this->jobLevel->id,
            'job_title_id' => $this->jobTitle->id,
            'nip' => 'MST-002',
            'full_name' => 'Karyawan Divisi',
            'status' => 'active',
        ]);

        $this->assertFalse($division->delete());
        $this->assertModelExists($division);
        $this->assertStringContainsString('Divisi Tanpa Anak', (string) $division->deletionBlockedReason());
    }

    public function test_company_with_divisions_is_not_deleted_and_the_reason_is_shown(): void
    {
        Livewire::test(EditCompany::class, ['record' => $this->company->id])
            ->callAction(DeleteAction::class)
            ->assertNotified('Data tidak dapat dihapus');

        $this->assertModelExists($this->company);
    }

    public function test_database_refuses_to_cascade_delete_workers(): void
    {
        $this->expectException(QueryException::class);

        DB::table('job_levels')->where('id', $this->jobLevel->id)->delete();
    }
}
