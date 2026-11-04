<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Filament\Resources\EmployeeResource\Pages\EditEmployee;
use App\Filament\Resources\FreelanceResource\Pages\EditFreelancer;
use App\Filament\Resources\InternResource\Pages\EditIntern;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LegacyRecordEditingTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    private Company $company;

    private Division $division;

    private Employee $resignedEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Superadmin', 'Approver', 'BOD', 'Employee'] as $role) {
            Role::create(['name' => $role]);
        }

        $this->superadmin = User::factory()->create();
        $this->superadmin->assignRole('Superadmin');
        $this->actingAs($this->superadmin);

        $this->company = Company::create(['name' => 'PT Lama', 'code' => 'LMA', 'is_active' => true]);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Penjualan', 'is_active' => true]);
        $this->resignedEmployee = $this->makeEmployee('LMA-001', 'resigned');
    }

    public function test_resigned_employee_can_be_saved(): void
    {
        Livewire::test(EditEmployee::class, ['record' => $this->resignedEmployee->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('resigned', $this->resignedEmployee->fresh()->status);
    }

    public function test_user_linked_to_an_employee_can_be_saved(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Employee');
        $employee = $this->makeEmployee('LMA-002', 'active');
        $employee->update(['user_id' => $user->id]);

        Livewire::test(EditUser::class, ['record' => $user->id])
            ->fillForm(['name' => 'Nama Baru'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Nama Baru', $user->fresh()->name);
        $this->assertSame('active', $employee->fresh()->status);
    }

    public function test_user_without_employee_profile_can_be_saved_without_creating_one(): void
    {
        Livewire::test(EditUser::class, ['record' => $this->superadmin->id])
            ->assertSchemaStateSet(['has_employee_profile' => false])
            ->fillForm(['name' => 'Super Admin Baru'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Super Admin Baru', $this->superadmin->fresh()->name);
        $this->assertNull($this->superadmin->fresh()->employee);
    }

    public function test_user_can_be_created_without_employee_profile(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Direksi',
                'email' => 'direksi@example.com',
                'password' => 'rahasia-123',
                'roles' => [Role::findByName('BOD')->id],
                'has_employee_profile' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNull(User::where('email', 'direksi@example.com')->firstOrFail()->employee);
    }

    public function test_user_can_be_created_with_an_active_employee_profile(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Staf Baru',
                'email' => 'staf@example.com',
                'password' => 'rahasia-123',
                'roles' => [Role::findByName('Employee')->id],
                'has_employee_profile' => true,
                'employee' => [
                    'company_id' => $this->company->id,
                    'division_id' => $this->division->id,
                    'job_level_id' => $this->resignedEmployee->job_level_id,
                    'job_title_id' => $this->resignedEmployee->job_title_id,
                    'nip' => 'LMA-NEW',
                    'full_name' => 'Staf Baru',
                    'join_date' => '2026-11-01',
                    'status' => 'active',
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('employees', ['nip' => 'LMA-NEW', 'status' => 'active']);
    }

    public function test_intern_with_a_resigned_mentor_can_be_saved(): void
    {
        $intern = Intern::create([
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'mentor_id' => $this->resignedEmployee->id,
            'nis' => 'MG-LMA',
            'full_name' => 'Magang Lama',
            'institution' => 'SMK',
            'start_date' => '2026-07-20',
            'end_date' => '2026-10-20',
            'status' => 'active',
        ]);

        Livewire::test(EditIntern::class, ['record' => $intern->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($this->resignedEmployee->id, $intern->fresh()->mentor_id);
    }

    public function test_freelancer_with_a_resigned_supervisor_can_be_saved(): void
    {
        $freelancer = Freelancer::create([
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'supervisor_id' => $this->resignedEmployee->id,
            'freelancer_number' => 'FL-LMA',
            'full_name' => 'Freelance Lama',
            'start_date' => '2026-07-20',
            'end_date' => '2026-11-20',
            'status' => 'active',
        ]);

        Livewire::test(EditFreelancer::class, ['record' => $freelancer->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($this->resignedEmployee->id, $freelancer->fresh()->supervisor_id);
    }

    public function test_data_access_can_change_from_division_to_company(): void
    {
        $approverUser = $this->makeApproverUser();
        $this->grantDataAccessTo($approverUser, $this->company->id, $this->division->id);

        Livewire::test(EditUser::class, ['record' => $approverUser->id])
            ->fillForm(['data_scope' => DataScope::Companies->value, 'accessibleCompanies' => [$this->company->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $approverUser = $approverUser->fresh();
        $this->assertSame(DataScope::Companies, $approverUser->dataScope());
        $this->assertSame([$this->company->id], $approverUser->accessibleCompanies()->pluck('companies.id')->all());
        $this->assertSame(0, $approverUser->accessibleDivisions()->count(), 'Division picks the new level does not use are cleared.');
    }

    public function test_missing_data_access_picks_show_the_error_on_the_form(): void
    {
        $approverUser = $this->makeApproverUser();

        Livewire::test(EditUser::class, ['record' => $approverUser->id])
            ->fillForm(['data_scope' => DataScope::Divisions->value, 'accessibleDivisions' => []])
            ->call('save')
            ->assertHasFormErrors(['accessibleDivisions']);

        $this->assertSame(DataScope::Own, $approverUser->fresh()->dataScope());
    }

    private function makeEmployee(string $nip, string $status): Employee
    {
        return Employee::create([
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Sales'])->id,
            'nip' => $nip,
            'full_name' => "Karyawan {$nip}",
            'join_date' => '2025-01-06',
            'status' => $status,
        ]);
    }

    private function makeApproverUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Approver');
        $this->makeEmployee('LMA-APR-'.$user->id, 'active')->update(['user_id' => $user->id]);

        return $user;
    }
}
