<?php

namespace Tests\Feature;

use App\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmployeeListFilterTest extends TestCase
{
    use RefreshDatabase;

    private Employee $active;

    private Employee $resigned;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Superadmin', 'web');
        $superadmin = User::factory()->create();
        $superadmin->assignRole('Superadmin');
        $this->actingAs($superadmin);

        $this->active = $this->makeEmployee('AKT-001', 'active');
        $this->resigned = $this->makeEmployee('RSG-001', 'resigned');
    }

    public function test_employee_list_shows_active_employees_by_default(): void
    {
        Livewire::test(ListEmployees::class)
            ->assertCanSeeTableRecords([$this->active])
            ->assertCanNotSeeTableRecords([$this->resigned]);
    }

    public function test_resigned_employees_are_still_reachable_through_the_filter(): void
    {
        Livewire::test(ListEmployees::class)
            ->filterTable('status', 'resigned')
            ->assertCanSeeTableRecords([$this->resigned])
            ->assertCanNotSeeTableRecords([$this->active]);

        Livewire::test(ListEmployees::class)
            ->removeTableFilter('status')
            ->assertCanSeeTableRecords([$this->active, $this->resigned]);
    }

    private function makeEmployee(string $nip, string $status): Employee
    {
        $company = Company::firstOrCreate(['code' => 'FLT'], ['name' => 'PT Filter', 'is_active' => true]);

        return Employee::create([
            'company_id' => $company->id,
            'division_id' => Division::firstOrCreate(['company_id' => $company->id, 'name' => 'Umum'], ['is_active' => true])->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Staff'])->id,
            'nip' => $nip,
            'full_name' => "Karyawan {$nip}",
            'status' => $status,
        ]);
    }
}
