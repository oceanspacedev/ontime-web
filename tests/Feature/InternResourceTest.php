<?php

namespace Tests\Feature;

use App\Filament\Resources\InternResource\Pages\CreateIntern;
use App\Filament\Resources\InternResource\Pages\EditIntern;
use App\Filament\Resources\InternResource\Pages\ListInterns;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Intern;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InternResourceTest extends TestCase
{
    use RefreshDatabase;

    protected User $superadmin;

    protected Company $company;

    protected Division $division;

    protected Employee $mentor;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Superadmin']);

        $this->superadmin = User::factory()->create();
        $this->superadmin->assignRole('Superadmin');

        $this->company = Company::create([
            'code' => 'COMP1',
            'name' => 'PT Tech Indonesia',
            'is_active' => true,
        ]);

        $this->division = Division::create([
            'company_id' => $this->company->id,
            'name' => 'Engineering',
            'code' => 'ENG',
            'is_active' => true,
        ]);

        $jobLevel = JobLevel::create(['name' => 'Senior', 'level_order' => 1]);
        $jobTitle = JobTitle::create(['name' => 'Software Engineer']);

        $this->mentor = Employee::create([
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'job_level_id' => $jobLevel->id,
            'job_title_id' => $jobTitle->id,
            'nip' => 'EMP-001',
            'full_name' => 'Senior Mentor',
            'status' => 'active',
        ]);
    }

    public function test_can_render_intern_list_page(): void
    {
        $this->actingAs($this->superadmin);

        $intern = Intern::create([
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'mentor_id' => $this->mentor->id,
            'nis' => 'MG-001',
            'full_name' => 'Budi Intern',
            'institution' => 'Universitas Indonesia',
            'status' => 'active',
        ]);

        Livewire::test(ListInterns::class)
            ->assertCanSeeTableRecords([$intern]);
    }

    public function test_can_create_intern(): void
    {
        $this->actingAs($this->superadmin);

        Livewire::test(CreateIntern::class)
            ->fillForm([
                'nis' => 'MG-002',
                'full_name' => 'Siti Intern',
                'institution' => 'Institut Teknologi Bandung',
                'company_id' => $this->company->id,
                'division_id' => $this->division->id,
                'mentor_id' => $this->mentor->id,
                'email' => 'siti@example.com',
                'phone' => '08123456789',
                'start_date' => '2026-08-01',
                'end_date' => '2026-11-01',
                'status' => 'active',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('interns', [
            'nis' => 'MG-002',
            'full_name' => 'Siti Intern',
            'institution' => 'Institut Teknologi Bandung',
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'mentor_id' => $this->mentor->id,
            'email' => 'siti@example.com',
            'status' => 'active',
        ]);
    }

    public function test_can_edit_intern(): void
    {
        $this->actingAs($this->superadmin);

        $intern = Intern::create([
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'mentor_id' => $this->mentor->id,
            'nis' => 'MG-003',
            'full_name' => 'Andi Magang',
            'institution' => 'Universitas Gadjah Mada',
            'status' => 'active',
        ]);

        Livewire::test(EditIntern::class, ['record' => $intern->id])
            ->fillForm([
                'status' => 'completed',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('interns', [
            'id' => $intern->id,
            'status' => 'completed',
        ]);
    }

    public function test_validates_unique_nis(): void
    {
        $this->actingAs($this->superadmin);

        Intern::create([
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'nis' => 'MG-EXISTING',
            'full_name' => 'Existing Intern',
            'institution' => 'SMK 1',
            'status' => 'active',
        ]);

        Livewire::test(CreateIntern::class)
            ->fillForm([
                'nis' => 'MG-EXISTING',
                'full_name' => 'Duplicate Intern',
                'institution' => 'SMK 2',
                'company_id' => $this->company->id,
                'division_id' => $this->division->id,
            ])
            ->call('create')
            ->assertHasFormErrors(['nis' => 'unique']);
    }

    public function test_selecting_mentor_autofills_company_and_division(): void
    {
        $this->actingAs($this->superadmin);

        Livewire::test(CreateIntern::class)
            ->fillForm([
                'mentor_id' => $this->mentor->id,
            ])
            ->assertFormSet([
                'company_id' => $this->company->id,
                'division_id' => $this->division->id,
            ]);
    }

    public function test_can_auto_create_user_account_for_intern(): void
    {
        $this->actingAs($this->superadmin);

        $intern = Intern::create([
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'nis' => 'MG-999',
            'full_name' => 'Auto User Intern',
            'email' => 'autouser@example.com',
            'institution' => 'Universitas Indonesia',
            'status' => 'active',
        ]);

        $this->assertNull($intern->user_id);

        Role::create(['name' => 'Intern']);

        Livewire::test(ListInterns::class)
            ->callTableAction('createUser', $intern)
            ->assertHasNoTableActionErrors();

        $intern->refresh();
        $this->assertNotNull($intern->user_id);
        $this->assertEquals('autouser@example.com', $intern->user->email);
    }
}
