<?php

namespace Tests\Feature\QaProbe;

use App\Filament\Resources\OvertimeRequests\OvertimeRequestResource;
use App\Filament\Resources\OvertimeRequests\Pages\EditOvertimeRequest;
use App\Models\ApprovalRequestStep;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\OvertimeRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * BUG-OVT-6: an already approved overtime can have its hours changed from the panel
 * while the status stays "approved" and no approver re-checks the new hours.
 */
class Probe_overtime_BUGOVT6Test extends TestCase
{
    use RefreshDatabase;

    public function test_changing_hours_of_an_approved_overtime_does_not_stay_silently_approved(): void
    {
        foreach (['Superadmin', 'Approver', 'BOD', 'Employee'] as $role) {
            Role::create(['name' => $role]);
        }

        $admin = User::factory()->create();
        $admin->assignRole('Superadmin');
        $this->actingAs($admin);

        $company = Company::create(['name' => 'PT Lembur', 'code' => 'LBR', 'is_active' => true]);
        $division = Division::create(['company_id' => $company->id, 'name' => 'Ops', 'is_active' => true]);
        $employee = Employee::create([
            'company_id' => $company->id,
            'division_id' => $division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Crew'])->id,
            'nip' => 'LBR-001',
            'full_name' => 'Budi Lembur',
            'join_date' => '2025-01-06',
            'status' => 'active',
        ]);

        $overtime = OvertimeRequest::create([
            'employee_id' => $employee->id,
            'date' => '2026-11-16',
            'start_time' => '17:00',
            'end_time' => '20:00',
            'duration_minutes' => 180,
            'reason' => 'Stock opname',
            'status' => 'approved',
            'current_step' => 1,
        ]);
        ApprovalRequestStep::create([
            'approvable_type' => OvertimeRequest::class,
            'approvable_id' => $overtime->id,
            'step_order' => 1,
            'step_name' => 'Approver',
            'approver_type' => 'role',
            'approver_role' => 'Approver',
            'status' => 'approved',
            'actioned_by' => $admin->id,
            'actioned_at' => now(),
        ]);

        $editAllowed = OvertimeRequestResource::canEdit($overtime->fresh());

        if ($editAllowed) {
            Livewire::test(EditOvertimeRequest::class, ['record' => $overtime->id])
                ->fillForm(['end_time' => '23:00', 'duration_minutes' => 360])
                ->call('save')
                ->assertHasNoFormErrors();
        }

        $fresh = $overtime->fresh();
        $hoursChanged = $fresh->duration_minutes !== 180;

        // Correct behaviour: either editing approved hours is blocked, or the changed hours need re-approval.
        $this->assertFalse(
            $hoursChanged && $fresh->status === 'approved',
            "Approved overtime changed to {$fresh->start_time}-{$fresh->end_time} / {$fresh->duration_minutes} min "
            ."but status is still '{$fresh->status}' and steps untouched (".
            $fresh->approvalSteps()->pluck('status')->implode(',').')'
        );
    }
}
