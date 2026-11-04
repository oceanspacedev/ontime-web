<?php

namespace Tests\Feature;

use App\Filament\Resources\AttendanceCorrections\Pages\ListAttendanceCorrections;
use App\Filament\Resources\LeaveRequests\Pages\ListLeaveRequests;
use App\Filament\Resources\OvertimeRequests\Pages\ListOvertimeRequests;
use App\Filament\Resources\ResignationResource\Pages\ListResignations;
use App\Models\ApprovalFlow;
use App\Models\Approver;
use App\Models\AttendanceCorrection;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\Resignation;
use App\Models\User;
use App\Services\ApprovalFlowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RequestListRenderingTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    private User $approver;

    /** @var array{employee: Employee, intern: Intern, freelancer: Freelancer} */
    private array $workers;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Superadmin', 'Approver', 'Employee'] as $role) {
            Role::create(['name' => $role]);
        }

        $company = Company::create(['name' => 'PT Daftar', 'code' => 'DFT', 'is_active' => true]);
        $division = Division::create(['company_id' => $company->id, 'name' => 'Toko', 'is_active' => true]);

        foreach (['leave', 'overtime', 'correction', 'resignation'] as $requestType) {
            ApprovalFlow::create(['company_id' => $company->id, 'request_type' => $requestType, 'step_number' => 1, 'step_order' => 1, 'name' => 'Approver', 'approver_type' => 'role', 'approver_role' => 'Approver']);
        }

        $this->superadmin = User::factory()->create();
        $this->superadmin->assignRole('Superadmin');

        $this->approver = User::factory()->create();
        $this->approver->assignRole('Approver');
        Approver::create(['user_id' => $this->approver->id, 'company_id' => $company->id, 'division_id' => $division->id, 'level' => 1]);

        $this->workers = [
            'employee' => Employee::create([
                'company_id' => $company->id,
                'division_id' => $division->id,
                'job_level_id' => JobLevel::create(['name' => 'Staff', 'level_order' => 1])->id,
                'job_title_id' => JobTitle::create(['name' => 'Kasir'])->id,
                'nip' => 'DFT-001',
                'full_name' => 'Kasir Satu',
                'status' => 'active',
            ]),
            'intern' => Intern::create(['company_id' => $company->id, 'division_id' => $division->id, 'nis' => 'MG-DFT', 'full_name' => 'Magang Toko', 'institution' => 'SMK', 'status' => 'active']),
            'freelancer' => Freelancer::create(['company_id' => $company->id, 'division_id' => $division->id, 'freelancer_number' => 'FL-DFT', 'full_name' => 'Freelance Toko', 'status' => 'active']),
        ];

        $service = app(ApprovalFlowService::class);

        foreach ($this->workers as $relation => $worker) {
            $owner = ["{$relation}_id" => $worker->id];

            $service->generateSteps(LeaveRequest::create($owner + ['leave_type' => 'permission', 'start_date' => '2026-11-09', 'end_date' => '2026-11-09', 'days_count' => 1, 'reason' => 'Izin', 'status' => 'pending']), 'leave');
            $service->generateSteps(OvertimeRequest::create($owner + ['date' => '2026-11-04', 'start_time' => '18:00', 'end_time' => '20:00', 'duration_minutes' => 120, 'reason' => 'Lembur', 'status' => 'pending']), 'overtime');
            $service->generateSteps(AttendanceCorrection::create($owner + ['date' => '2026-11-03', 'reason' => 'Lupa absen', 'status' => 'pending']), 'correction');
        }

        foreach (['DFT-002', 'DFT-003'] as $nip) {
            $employee = $this->workers['employee']->replicate()->fill(['nip' => $nip]);
            $employee->save();
            $service->generateSteps(Resignation::create(['employee_id' => $employee->id, 'resignation_date' => '2026-11-04', 'last_working_day' => '2026-12-04', 'reason' => 'Resign', 'status' => 'pending']), 'resignation');
        }
    }

    public function test_request_lists_render_many_rows_for_superadmin(): void
    {
        $this->actingAs($this->superadmin);

        Livewire::test(ListLeaveRequests::class)->assertSuccessful()->assertSee(['Kasir Satu', 'Magang Toko', 'Freelance Toko']);
        Livewire::test(ListOvertimeRequests::class)->assertSuccessful()->assertCanSeeTableRecords(OvertimeRequest::all());
        Livewire::test(ListAttendanceCorrections::class)->assertSuccessful()->assertSee(['Kasir Satu', 'Magang Toko', 'Freelance Toko']);
        Livewire::test(ListResignations::class)->assertSuccessful()->assertCanSeeTableRecords(Resignation::all());
    }

    public function test_request_lists_render_many_rows_for_a_mapped_approver(): void
    {
        $this->actingAs($this->approver);

        Livewire::test(ListLeaveRequests::class)->assertSuccessful()->assertCanSeeTableRecords(LeaveRequest::all());
        Livewire::test(ListOvertimeRequests::class)->assertSuccessful()->assertCanSeeTableRecords(OvertimeRequest::all());
        Livewire::test(ListAttendanceCorrections::class)->assertSuccessful()->assertCanSeeTableRecords(AttendanceCorrection::all());
        Livewire::test(ListResignations::class)->assertSuccessful()->assertCanSeeTableRecords(Resignation::all());
    }
}
