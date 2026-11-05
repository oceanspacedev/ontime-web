<?php

namespace Tests\Feature;

use App\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use App\Filament\Resources\LeaveRequests\Pages\CreateLeaveRequest;
use App\Filament\Resources\LeaveRequests\Pages\EditLeaveRequest;
use App\Models\ApprovalFlow;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\AnnualLeaveService;
use App\Services\ApprovalFlowService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Annual leave is set per employee. Interns and freelancers never have it, and an employee has it
 * only when HR turned it on, with their own quota or the company quota.
 */
class AnnualLeaveEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Division $division;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Superadmin', 'Approver', 'Employee', 'Intern', 'Freelancer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->company = Company::create(['name' => 'PT Cuti', 'code' => 'CUTI', 'is_active' => true]);
        $this->company->policy()->update(['annual_leave_quota' => 12]);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'UMUM', 'is_active' => true]);

        ApprovalFlow::create(['company_id' => $this->company->id, 'request_type' => 'leave', 'step_number' => 1, 'step_order' => 1, 'name' => 'Approver', 'approver_type' => 'role', 'approver_role' => 'Approver']);
    }

    public function test_new_employees_have_no_annual_leave_until_hr_grants_it(): void
    {
        $employee = $this->makeEmployee();

        $this->assertFalse($employee->fresh()->has_annual_leave);
        $this->assertSame(0, app(AnnualLeaveService::class)->quota($employee->fresh()));
    }

    public function test_employee_without_annual_leave_cannot_file_it_through_the_api(): void
    {
        $employee = $this->makeEmployee();
        Sanctum::actingAs($employee->user);

        $this->getJson('/api/v1/leave-requests')
            ->assertOk()
            ->assertJsonPath('quota_summary.eligible', false)
            ->assertJsonPath('quota_summary.annual_leave_quota', 0)
            ->assertJsonPath('quota_summary.remaining_days', 0);

        $this->postJson('/api/v1/leave-requests', $this->leavePayload('annual_leave'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('leave_type')
            ->assertJsonPath('errors.leave_type.0', 'Anda belum memiliki jatah cuti tahunan. Hubungi HR atau gunakan jenis Izin.');

        $this->postJson('/api/v1/leave-requests', $this->leavePayload('permission'))->assertCreated();
    }

    public function test_entitled_employee_uses_the_company_quota_by_default(): void
    {
        $this->company->policy()->update(['annual_leave_quota' => 14]);
        $employee = $this->makeEmployee(['has_annual_leave' => true]);
        Sanctum::actingAs($employee->user);

        $this->getJson('/api/v1/leave-requests')
            ->assertOk()
            ->assertJsonPath('quota_summary.eligible', true)
            ->assertJsonPath('quota_summary.annual_leave_quota', 14)
            ->assertJsonPath('quota_summary.remaining_days', 14);

        $this->postJson('/api/v1/leave-requests', $this->leavePayload('annual_leave'))->assertCreated();
    }

    public function test_own_quota_overrides_the_company_quota(): void
    {
        $employee = $this->makeEmployee(['has_annual_leave' => true, 'annual_leave_quota' => 2]);
        LeaveRequest::create(['employee_id' => $employee->id, 'leave_type' => LeaveRequest::TYPE_ANNUAL, 'start_date' => now()->startOfYear(), 'end_date' => now()->startOfYear(), 'days_count' => 1, 'reason' => 'Cuti', 'status' => 'approved']);
        Sanctum::actingAs($employee->user);

        $this->getJson('/api/v1/leave-requests')
            ->assertOk()
            ->assertJsonPath('quota_summary.annual_leave_quota', 2)
            ->assertJsonPath('quota_summary.used_days', 1)
            ->assertJsonPath('quota_summary.remaining_days', 1);

        $this->postJson('/api/v1/leave-requests', $this->leavePayload('annual_leave', '2026-12-01', '2026-12-02'))
            ->assertUnprocessable()
            ->assertJsonPath('errors.leave_type.0', 'Kuota cuti tahunan Anda tidak mencukupi (Sisa: 1 hari, Pengajuan: 2 hari).');
    }

    public function test_interns_never_have_annual_leave(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Intern');
        Intern::create(['user_id' => $user->id, 'company_id' => $this->company->id, 'division_id' => $this->division->id, 'nis' => 'MG-01', 'full_name' => 'Peserta Magang', 'institution' => 'SMK', 'status' => 'active']);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/leave-requests')
            ->assertOk()
            ->assertJsonPath('quota_summary.eligible', false)
            ->assertJsonPath('quota_summary.annual_leave_quota', 0);

        $this->postJson('/api/v1/leave-requests', $this->leavePayload('annual_leave'))
            ->assertUnprocessable()
            ->assertJsonPath('errors.leave_type.0', 'Peserta magang tidak memiliki jatah cuti tahunan. Gunakan jenis Izin.');
    }

    public function test_freelancers_never_have_annual_leave(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Freelancer');
        Freelancer::create(['user_id' => $user->id, 'company_id' => $this->company->id, 'division_id' => $this->division->id, 'freelancer_number' => 'FL-01', 'full_name' => 'Pekerja Lepas', 'status' => 'active']);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/leave-requests')->assertOk()->assertJsonPath('quota_summary.eligible', false);

        $this->postJson('/api/v1/leave-requests', $this->leavePayload('annual'))
            ->assertUnprocessable()
            ->assertJsonPath('errors.leave_type.0', 'Freelancer tidak memiliki jatah cuti tahunan. Gunakan jenis Izin.');
    }

    public function test_profile_tells_the_mobile_app_whether_annual_leave_applies(): void
    {
        $withLeave = $this->makeEmployee(['has_annual_leave' => true, 'annual_leave_quota' => 15]);
        $withoutLeave = $this->makeEmployee();

        Sanctum::actingAs($withLeave->user);
        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.employee.has_annual_leave', true)
            ->assertJsonPath('user.employee.annual_leave_quota', 15);

        Sanctum::actingAs($withoutLeave->user);
        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.employee.has_annual_leave', false)
            ->assertJsonPath('user.employee.annual_leave_quota', 0);
    }

    public function test_updating_a_leave_into_annual_leave_follows_the_same_rule(): void
    {
        $employee = $this->makeEmployee();
        Sanctum::actingAs($employee->user);

        $leaveId = $this->postJson('/api/v1/leave-requests', $this->leavePayload('permission'))->assertCreated()->json('data.id');

        $this->putJson("/api/v1/leave-requests/{$leaveId}", ['leave_type' => 'annual_leave'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('leave_type');

        $this->assertSame('permission', LeaveRequest::findOrFail($leaveId)->leave_type);
    }

    public function test_panel_form_only_offers_annual_leave_to_entitled_employees(): void
    {
        $this->actingAs($this->makeEmployee()->user);

        Livewire::test(CreateLeaveRequest::class)
            ->assertFormFieldExists('leave_type')
            ->assertFormSet(['leave_type' => 'permission'])
            ->fillForm(['leave_type' => LeaveRequest::TYPE_ANNUAL, 'start_date' => '2026-12-01', 'end_date' => '2026-12-01', 'reason' => 'Cuti'])
            ->call('create')
            ->assertHasFormErrors(['leave_type']);

        $this->assertDatabaseCount('leave_requests', 0);

        $this->actingAs($this->makeEmployee(['has_annual_leave' => true])->user);

        Livewire::test(CreateLeaveRequest::class)
            ->assertFormSet(['leave_type' => LeaveRequest::TYPE_ANNUAL])
            ->fillForm(['leave_type' => LeaveRequest::TYPE_ANNUAL, 'start_date' => '2026-12-01', 'end_date' => '2026-12-01', 'reason' => 'Cuti'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('leave_requests', ['leave_type' => LeaveRequest::TYPE_ANNUAL, 'days_count' => 1]);
    }

    public function test_panel_edit_cannot_turn_a_leave_into_annual_leave_without_entitlement(): void
    {
        $employee = $this->makeEmployee();
        $leave = LeaveRequest::create(['employee_id' => $employee->id, 'leave_type' => 'permission', 'start_date' => '2026-12-01', 'end_date' => '2026-12-01', 'days_count' => 1, 'reason' => 'Izin', 'status' => 'pending']);

        $this->actingAs($this->makeSuperadmin());

        Livewire::test(EditLeaveRequest::class, ['record' => $leave->getKey()])
            ->fillForm(['leave_type' => LeaveRequest::TYPE_ANNUAL])
            ->call('save')
            ->assertHasFormErrors(['leave_type']);

        $this->assertSame('permission', $leave->fresh()->leave_type);
    }

    public function test_hr_can_grant_annual_leave_to_many_employees_at_once(): void
    {
        $first = $this->makeEmployee();
        $second = $this->makeEmployee();

        $this->actingAs($this->makeSuperadmin());

        Livewire::test(ListEmployees::class)
            ->selectTableRecords([$first->getKey(), $second->getKey()])
            ->callAction(TestAction::make('setAnnualLeave')->table()->bulk(), data: ['has_annual_leave' => true, 'annual_leave_quota' => 10])
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('employees', ['id' => $first->id, 'has_annual_leave' => true, 'annual_leave_quota' => 10]);
        $this->assertDatabaseHas('employees', ['id' => $second->id, 'has_annual_leave' => true, 'annual_leave_quota' => 10]);

        Livewire::test(ListEmployees::class)
            ->selectTableRecords([$first->getKey()])
            ->callAction(TestAction::make('setAnnualLeave')->table()->bulk(), data: ['has_annual_leave' => false])
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('employees', ['id' => $first->id, 'has_annual_leave' => false, 'annual_leave_quota' => null]);
    }

    public function test_panel_create_applies_the_remaining_quota(): void
    {
        $employee = $this->makeEmployee(['has_annual_leave' => true, 'annual_leave_quota' => 1]);
        LeaveRequest::create(['employee_id' => $employee->id, 'leave_type' => LeaveRequest::TYPE_ANNUAL, 'start_date' => now()->startOfYear(), 'end_date' => now()->startOfYear(), 'days_count' => 1, 'reason' => 'Cuti', 'status' => 'approved']);
        $this->actingAs($employee->user);

        $component = Livewire::test(CreateLeaveRequest::class)
            ->fillForm(['leave_type' => LeaveRequest::TYPE_ANNUAL, 'start_date' => '2026-12-01', 'end_date' => '2026-12-01', 'reason' => 'Cuti'])
            ->call('create')
            ->assertHasFormErrors(['leave_type']);

        $this->assertSame('Kuota cuti tahunan Anda tidak mencukupi (Sisa: 0 hari, Pengajuan: 1 hari).', $component->errors()->first('data.leave_type'));
    }

    public function test_panel_edit_of_an_approved_annual_leave_does_not_count_it_against_itself(): void
    {
        $employee = $this->makeEmployee(['has_annual_leave' => true, 'annual_leave_quota' => 12]);
        $leave = LeaveRequest::create(['employee_id' => $employee->id, 'leave_type' => LeaveRequest::TYPE_ANNUAL, 'start_date' => now()->startOfYear(), 'end_date' => now()->startOfYear()->addDays(9), 'days_count' => 10, 'reason' => 'Cuti panjang', 'status' => 'approved']);
        $this->actingAs($this->makeSuperadmin());

        Livewire::test(EditLeaveRequest::class, ['record' => $leave->getKey()])
            ->fillForm(['reason' => 'Cuti panjang, sudah diganti tugasnya'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Cuti panjang, sudah diganti tugasnya', $leave->fresh()->reason);
    }

    public function test_old_annual_leave_of_someone_without_entitlement_can_still_be_edited(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Intern');
        $intern = Intern::create(['user_id' => $user->id, 'company_id' => $this->company->id, 'division_id' => $this->division->id, 'nis' => 'MG-02', 'full_name' => 'Magang Lama', 'institution' => 'SMK', 'status' => 'active']);
        $leave = LeaveRequest::create(['intern_id' => $intern->id, 'leave_type' => LeaveRequest::TYPE_ANNUAL, 'start_date' => '2026-12-01', 'end_date' => '2026-12-01', 'days_count' => 1, 'reason' => 'Lama', 'status' => 'approved']);
        $this->actingAs($this->makeSuperadmin());

        Livewire::test(EditLeaveRequest::class, ['record' => $leave->getKey()])
            ->fillForm(['reason' => 'Catatan diperbaiki'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Catatan diperbaiki', $leave->fresh()->reason);
    }

    public function test_panel_edit_cannot_stretch_an_annual_leave_past_the_quota(): void
    {
        $employee = $this->makeEmployee(['has_annual_leave' => true, 'annual_leave_quota' => 3]);
        $leave = LeaveRequest::create(['employee_id' => $employee->id, 'leave_type' => LeaveRequest::TYPE_ANNUAL, 'start_date' => '2026-12-01', 'end_date' => '2026-12-01', 'days_count' => 1, 'reason' => 'Cuti', 'status' => 'pending']);
        $this->actingAs($this->makeSuperadmin());

        $component = Livewire::test(EditLeaveRequest::class, ['record' => $leave->getKey()])
            ->fillForm(['end_date' => '2026-12-05'])
            ->call('save')
            ->assertHasFormErrors(['leave_type']);

        $this->assertSame('Kuota cuti tahunan Anda tidak mencukupi (Sisa: 3 hari, Pengajuan: 5 hari).', $component->errors()->first('data.leave_type'));
        $this->assertSame(1, $leave->fresh()->days_count);
    }

    public function test_api_update_of_an_old_annual_leave_keeps_working_when_it_does_not_grow(): void
    {
        $employee = $this->makeEmployee();
        $leave = LeaveRequest::create(['employee_id' => $employee->id, 'leave_type' => LeaveRequest::TYPE_ANNUAL, 'start_date' => '2026-12-01', 'end_date' => '2026-12-02', 'days_count' => 2, 'reason' => 'Lama', 'status' => 'pending']);
        Sanctum::actingAs($employee->user);

        $this->putJson("/api/v1/leave-requests/{$leave->id}", ['reason' => 'Alasan diperbarui'])->assertOk();

        $this->putJson("/api/v1/leave-requests/{$leave->id}", ['end_date' => '2026-12-04'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.leave_type.0', 'Anda belum memiliki jatah cuti tahunan. Hubungi HR atau gunakan jenis Izin.');
    }

    public function test_final_approval_is_blocked_for_annual_leave_of_someone_without_entitlement(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Intern');
        $intern = Intern::create(['user_id' => $user->id, 'company_id' => $this->company->id, 'division_id' => $this->division->id, 'nis' => 'MG-03', 'full_name' => 'Magang Pending', 'institution' => 'SMK', 'status' => 'active']);
        $internLeave = $this->pendingLeave(['intern_id' => $intern->id]);
        $employeeLeave = $this->pendingLeave(['employee_id' => $this->makeEmployee()->id]);
        Sanctum::actingAs($this->makeSuperadmin());

        $this->putJson("/api/v1/approvals/leave/{$internLeave->id}/process", ['action' => 'approved'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.approve.0', 'Magang Pending adalah peserta magang dan tidak memiliki jatah cuti tahunan. Tolak pengajuan ini dan minta diajukan ulang sebagai Izin.');

        $this->putJson("/api/v1/approvals/leave/{$employeeLeave->id}/process", ['action' => 'approved'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('approve');

        $this->assertSame('pending', $internLeave->fresh()->status);
        $this->assertSame('pending', $employeeLeave->fresh()->status);
        $this->assertDatabaseMissing('approval_request_steps', ['approvable_id' => $internLeave->id, 'status' => 'approved']);

        $this->putJson("/api/v1/approvals/leave/{$internLeave->id}/process", ['action' => 'rejected', 'rejection_note' => 'Gunakan Izin'])->assertOk();
        $this->assertSame('rejected', $internLeave->fresh()->status);
    }

    public function test_final_approval_checks_the_remaining_quota_without_counting_the_request_itself(): void
    {
        $employee = $this->makeEmployee(['has_annual_leave' => true, 'annual_leave_quota' => 3]);
        LeaveRequest::create(['employee_id' => $employee->id, 'leave_type' => LeaveRequest::TYPE_ANNUAL, 'start_date' => now()->startOfYear(), 'end_date' => now()->startOfYear()->addDay(), 'days_count' => 2, 'reason' => 'Sudah dipakai', 'status' => 'approved']);
        $fits = $this->pendingLeave(['employee_id' => $employee->id]);
        $tooLong = $this->pendingLeave(['employee_id' => $employee->id, 'end_date' => '2026-12-03', 'days_count' => 3]);
        Sanctum::actingAs($this->makeSuperadmin());

        $this->putJson("/api/v1/approvals/leave/{$fits->id}/process", ['action' => 'approved'])->assertOk();
        $this->assertSame('approved', $fits->fresh()->status);

        $this->putJson("/api/v1/approvals/leave/{$tooLong->id}/process", ['action' => 'approved'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.approve.0', "Sisa kuota cuti tahunan {$employee->full_name} tidak mencukupi (Sisa: 0 hari, Pengajuan: 3 hari).");
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeEmployee(array $attributes = []): Employee
    {
        $user = User::factory()->create();
        $user->assignRole('Employee');

        return Employee::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Staff'])->id,
            'nip' => 'CUTI-'.$user->id,
            'full_name' => "Pekerja {$user->id}",
            'join_date' => '2024-01-02',
            'status' => 'active',
            ...$attributes,
        ])->load('user');
    }

    /**
     * @return array<string, string>
     */
    private function leavePayload(string $type, string $start = '2026-12-01', string $end = '2026-12-01'): array
    {
        return ['leave_type' => $type, 'start_date' => $start, 'end_date' => $end, 'reason' => 'Keperluan keluarga'];
    }

    private function makeSuperadmin(): User
    {
        $superadmin = User::factory()->create();
        $superadmin->assignRole('Superadmin');

        return $superadmin;
    }

    /**
     * Pending annual leave with its approval steps, as filed before the rule changed.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function pendingLeave(array $attributes): LeaveRequest
    {
        $leave = LeaveRequest::create([
            'leave_type' => LeaveRequest::TYPE_ANNUAL,
            'start_date' => '2026-12-01',
            'end_date' => '2026-12-01',
            'days_count' => 1,
            'reason' => 'Cuti',
            'status' => 'pending',
            ...$attributes,
        ]);

        app(ApprovalFlowService::class)->generateSteps($leave, 'leave');

        return $leave->fresh();
    }
}
