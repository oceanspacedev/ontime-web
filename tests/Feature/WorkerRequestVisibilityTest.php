<?php

namespace Tests\Feature;

use App\Filament\Pages\KalenderCuti;
use App\Filament\Pages\LaporanAbsensi;
use App\Filament\Resources\AttendanceCorrections\AttendanceCorrectionResource;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Resources\OvertimeRequests\OvertimeRequestResource;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Intern;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WorkerRequestVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Company $top;

    private Company $cs;

    private Division $topSales;

    private Division $topFinance;

    private Division $csSales;

    private int $jobLevelId;

    private int $jobTitleId;

    private User $employeeTop;

    private User $employeeCs;

    private User $internTop;

    private LeaveRequest $leaveTop;

    private LeaveRequest $leaveCs;

    private LeaveRequest $leaveIntern;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Superadmin', 'Approver', 'BOD', 'Employee', 'Intern'] as $role) {
            Role::create(['name' => $role]);
        }

        $this->top = Company::create(['name' => 'CV Top', 'code' => 'TOP', 'is_active' => true]);
        $this->cs = Company::create(['name' => 'CV CS', 'code' => 'CS', 'is_active' => true]);
        $this->topSales = Division::create(['company_id' => $this->top->id, 'name' => 'Sales Top', 'is_active' => true]);
        $this->topFinance = Division::create(['company_id' => $this->top->id, 'name' => 'Finance Top', 'is_active' => true]);
        $this->csSales = Division::create(['company_id' => $this->cs->id, 'name' => 'Sales CS', 'is_active' => true]);
        $this->jobLevelId = JobLevel::create(['name' => 'Staff', 'level_order' => 1])->id;
        $this->jobTitleId = JobTitle::create(['name' => 'Staff'])->id;

        $this->employeeTop = $this->makeEmployee($this->topSales, 'Employee');
        $this->employeeCs = $this->makeEmployee($this->csSales, 'Employee');

        $this->internTop = User::factory()->create();
        $this->internTop->assignRole('Intern');
        Intern::create([
            'user_id' => $this->internTop->id,
            'company_id' => $this->top->id,
            'division_id' => $this->topSales->id,
            'nis' => 'MG-TOP-01',
            'full_name' => 'Magang Top',
            'institution' => 'SMK Test',
            'status' => 'active',
        ]);

        $this->leaveTop = $this->makeLeave(['employee_id' => $this->employeeTop->employee->id]);
        $this->leaveCs = $this->makeLeave(['employee_id' => $this->employeeCs->employee->id]);
        $this->leaveIntern = $this->makeLeave(['intern_id' => $this->internTop->intern->id]);
    }

    public function test_approver_without_mapping_only_sees_own_requests(): void
    {
        $approver = $this->makeEmployee($this->topFinance, 'Approver');
        $ownLeave = $this->makeLeave(['employee_id' => $approver->employee->id]);

        $this->assertVisibleLeaves($approver, [$ownLeave]);
    }

    public function test_bod_without_mapping_or_employee_profile_sees_nothing(): void
    {
        $bod = User::factory()->create();
        $bod->assignRole('BOD');

        $this->assertVisibleLeaves($bod, []);

        $this->actingAs($bod);
        $this->assertSame(0, OvertimeRequestResource::getEloquentQuery()->count());
        $this->assertSame(0, AttendanceCorrectionResource::getEloquentQuery()->count());
    }

    public function test_company_level_bod_sees_workers_of_that_company_including_interns(): void
    {
        $bod = $this->makeEmployee($this->topFinance, 'BOD');
        $this->grantDataAccessTo($bod, $this->top->id);

        $this->assertVisibleLeaves($bod, [$this->leaveTop, $this->leaveIntern]);
    }

    public function test_division_approver_sees_employees_and_interns_of_that_division(): void
    {
        $approver = $this->makeEmployee($this->topFinance, 'Approver');
        $this->grantDataAccessTo($approver, $this->top->id, $this->topSales->id);

        $this->assertVisibleLeaves($approver, [$this->leaveTop, $this->leaveIntern]);
    }

    public function test_corrections_and_overtime_follow_the_same_scope(): void
    {
        $bod = $this->makeEmployee($this->topFinance, 'BOD');
        $this->grantDataAccessTo($bod, $this->top->id);

        $correctionTop = AttendanceCorrection::create(['intern_id' => $this->internTop->intern->id, 'date' => '2026-11-03', 'reason' => 'Lupa absen', 'status' => 'pending']);
        AttendanceCorrection::create(['employee_id' => $this->employeeCs->employee->id, 'date' => '2026-11-03', 'reason' => 'Lupa absen', 'status' => 'pending']);
        $overtimeTop = OvertimeRequest::create(['employee_id' => $this->employeeTop->employee->id, 'date' => '2026-11-04', 'start_time' => '18:00', 'end_time' => '20:00', 'duration_minutes' => 120, 'reason' => 'Stock opname', 'status' => 'pending']);
        OvertimeRequest::create(['employee_id' => $this->employeeCs->employee->id, 'date' => '2026-11-04', 'start_time' => '18:00', 'end_time' => '20:00', 'duration_minutes' => 120, 'reason' => 'Stock opname', 'status' => 'pending']);

        $this->actingAs($bod);

        $this->assertSame([$correctionTop->id], AttendanceCorrectionResource::getEloquentQuery()->pluck('id')->all());
        $this->assertSame([$overtimeTop->id], OvertimeRequestResource::getEloquentQuery()->pluck('id')->all());
    }

    public function test_employee_only_sees_own_requests(): void
    {
        $this->assertVisibleLeaves($this->employeeTop, [$this->leaveTop]);
    }

    public function test_attendance_report_is_limited_to_the_approver_scope(): void
    {
        $bod = $this->makeEmployee($this->topFinance, 'BOD');
        $this->grantDataAccessTo($bod, $this->top->id);

        $attendanceTop = Attendance::create(['employee_id' => $this->employeeTop->employee->id, 'date' => today(), 'status' => 'on_time']);
        $attendanceIntern = Attendance::create(['intern_id' => $this->internTop->intern->id, 'date' => today(), 'status' => 'on_time']);
        Attendance::create(['employee_id' => $this->employeeCs->employee->id, 'date' => today(), 'status' => 'on_time']);

        $this->actingAs($bod);

        $report = Livewire::test(LaporanAbsensi::class)->instance();

        $this->assertEqualsCanonicalizing(
            [$attendanceTop->id, $attendanceIntern->id],
            $report->getReportQuery()->pluck('id')->all(),
        );
    }

    public function test_approver_without_mapping_sees_no_other_attendance_in_report(): void
    {
        $approver = $this->makeEmployee($this->topFinance, 'Approver');

        Attendance::create(['intern_id' => $this->internTop->intern->id, 'date' => today(), 'status' => 'on_time']);
        Attendance::create(['employee_id' => $this->employeeCs->employee->id, 'date' => today(), 'status' => 'on_time']);

        $this->actingAs($approver);

        $this->assertSame([], Livewire::test(LaporanAbsensi::class)->instance()->getReportQuery()->pluck('id')->all());
    }

    public function test_leave_calendar_only_shows_the_viewers_company(): void
    {
        $this->actingAs($this->employeeCs);

        $calendar = Livewire::test(KalenderCuti::class)->assertSuccessful();

        $this->assertSame([$this->leaveCs->id], $calendar->instance()->leaveRequests->pluck('id')->all());
    }

    public function test_leave_calendar_api_only_returns_the_viewers_company(): void
    {
        Sanctum::actingAs($this->employeeCs);

        $response = $this->getJson('/api/v1/kalender-cuti?month='.now()->month.'&year='.now()->year);

        $response->assertOk();
        $this->assertSame([$this->leaveCs->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_leave_api_detail_is_forbidden_for_other_companies(): void
    {
        Sanctum::actingAs($this->employeeTop);

        $this->getJson('/api/v1/leave-requests/'.$this->leaveCs->id)->assertForbidden();
        $this->getJson('/api/v1/leave-requests/'.$this->leaveTop->id)->assertOk();
    }

    public function test_user_without_profile_cannot_read_leave_details_through_the_api(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/leave-requests/'.$this->leaveTop->id)->assertForbidden();
    }

    private function makeEmployee(Division $division, string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        Employee::create([
            'user_id' => $user->id,
            'company_id' => $division->company_id,
            'division_id' => $division->id,
            'job_level_id' => $this->jobLevelId,
            'job_title_id' => $this->jobTitleId,
            'nip' => 'NIP-'.$user->id,
            'full_name' => "Pekerja {$user->id}",
            'status' => 'active',
        ]);

        return $user->fresh();
    }

    /**
     * @param  array<string, int>  $owner
     */
    private function makeLeave(array $owner): LeaveRequest
    {
        return LeaveRequest::create($owner + [
            'leave_type' => 'permission',
            'start_date' => now()->startOfMonth()->addDays(2)->toDateString(),
            'end_date' => now()->startOfMonth()->addDays(2)->toDateString(),
            'days_count' => 1,
            'reason' => 'Keperluan keluarga',
            'status' => 'pending',
        ]);
    }

    /**
     * @param  list<LeaveRequest>  $expected
     */
    private function assertVisibleLeaves(User $user, array $expected): void
    {
        $this->actingAs($user);

        $this->assertEqualsCanonicalizing(
            collect($expected)->pluck('id')->all(),
            LeaveRequestResource::getEloquentQuery()->pluck('id')->all(),
        );
    }
}
