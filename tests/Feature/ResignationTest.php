<?php

namespace Tests\Feature;

use App\Filament\Resources\ResignationResource\Pages\CreateResignation;
use App\Filament\Resources\ResignationResource\Pages\ListResignations;
use App\Models\ApprovalFlow;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\Resignation;
use App\Models\User;
use App\Services\ApprovalFlowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ResignationTest extends TestCase
{
    use RefreshDatabase;

    protected User $superadmin;

    protected User $approverUser;

    protected User $employeeUser;

    protected Company $company;

    protected Division $division;

    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Superadmin']);
        Role::create(['name' => 'Approver']);
        Role::create(['name' => 'Employee']);

        $this->superadmin = User::factory()->create();
        $this->superadmin->assignRole('Superadmin');

        $this->company = Company::create([
            'code' => 'COMP1',
            'name' => 'PT Resign Test',
            'is_active' => true,
        ]);

        $this->division = Division::create([
            'company_id' => $this->company->id,
            'name' => 'Operations',
            'code' => 'OPS',
            'is_active' => true,
        ]);

        $jobLevel = JobLevel::create(['name' => 'Staff', 'level_order' => 1]);
        $jobTitle = JobTitle::create(['name' => 'Operator']);

        $this->employeeUser = User::factory()->create([
            'name' => 'Resigning Employee',
            'email' => 'resigner@example.com',
            'is_active' => true,
        ]);
        $this->employeeUser->assignRole('Employee');

        $this->employee = Employee::create([
            'user_id' => $this->employeeUser->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'job_level_id' => $jobLevel->id,
            'job_title_id' => $jobTitle->id,
            'nip' => 'EMP-RESIGN-01',
            'full_name' => 'Resigning Employee',
            'status' => 'active',
        ]);

        $this->approverUser = User::factory()->create([
            'name' => 'Manager Approver',
            'email' => 'manager@example.com',
            'is_active' => true,
        ]);
        $this->approverUser->assignRole('Approver');

        $this->grantDataAccessTo($this->approverUser, $this->company->id, $this->division->id);

        ApprovalFlow::create([
            'company_id' => $this->company->id,
            'request_type' => 'resignation',
            'step_number' => 1,
            'step_order' => 1,
            'name' => 'Persetujuan Manager',
            'approver_type' => 'role',
            'approver_role' => 'Approver',
        ]);
    }

    public function test_can_create_resignation_request(): void
    {
        $this->actingAs($this->superadmin);

        Livewire::test(CreateResignation::class)
            ->fillForm([
                'employee_id' => $this->employee->id,
                'resignation_date' => '2026-08-01',
                'last_working_day' => '2026-08-31',
                'reason' => 'Pindah domisili',
                'handover_notes' => 'Serah terima laptop kantor ke IT',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('resignations', [
            'employee_id' => $this->employee->id,
            'reason' => 'Pindah domisili',
            'status' => 'pending',
        ]);
    }

    public function test_approving_resignation_deactivates_employee_and_user(): void
    {
        $resignation = Resignation::create([
            'employee_id' => $this->employee->id,
            'resignation_date' => '2026-08-01',
            'last_working_day' => '2026-08-31',
            'reason' => 'Ingin studi lanjut',
            'status' => 'pending',
            'current_step' => 1,
        ]);

        $service = new ApprovalFlowService;
        $service->generateSteps($resignation, 'resignation');

        $this->assertEquals('active', $this->employee->fresh()->status);
        $this->assertTrue($this->employeeUser->fresh()->is_active);

        $service->approveStep($resignation, $this->approverUser);

        $resignation->refresh();
        $this->assertEquals('approved', $resignation->status);

        // Employee status should now be inactive
        $this->assertEquals('inactive', $this->employee->fresh()->status);

        // User account should now be deactivated
        $this->assertFalse($this->employeeUser->fresh()->is_active);
    }

    public function test_can_render_resignation_list(): void
    {
        $this->actingAs($this->superadmin);

        $resignation = Resignation::create([
            'employee_id' => $this->employee->id,
            'resignation_date' => '2026-08-01',
            'last_working_day' => '2026-08-31',
            'reason' => 'Membuka usaha',
            'status' => 'pending',
        ]);

        Livewire::test(ListResignations::class)
            ->assertCanSeeTableRecords([$resignation]);
    }
}
