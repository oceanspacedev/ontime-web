<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\DataScope;
use App\Models\ApprovalFlow;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ApprovalRequestedNotification;
use App\Services\AnnualLeaveService;
use App\Services\ApprovalFlowService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Annual leave quota per calendar year, no overlapping leave, and the final leave approval against
 * attendance already recorded on the leave days (QA cluster B, ROL-033..035 and OPS-001).
 */
class QaBLeaveQuotaTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Division $division;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 11, 17, 9));

        foreach (['Superadmin', 'Approver', 'Employee'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->company = Company::create(['name' => 'PT Kuota', 'code' => 'KUOTA', 'is_active' => true]);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'UMUM', 'is_active' => true]);

        ApprovalFlow::create(['company_id' => $this->company->id, 'request_type' => 'leave', 'step_number' => 1, 'step_order' => 1, 'name' => 'Approver', 'approver_type' => 'role', 'approver_role' => 'Approver']);
    }

    public function test_a_request_crossing_the_new_year_charges_each_year_its_own_days(): void
    {
        $employee = $this->makeEmployee(quota: 12);
        $this->approvedLeave($employee, '2026-12-28', '2027-01-03');

        $service = app(AnnualLeaveService::class);

        $this->assertSame(4, $service->usedDays($employee, 2026));
        $this->assertSame(3, $service->usedDays($employee, 2027));
        $this->assertSame([2026 => 4, 2027 => 3], $service->daysPerYear(Carbon::parse('2026-12-28'), Carbon::parse('2027-01-03')));
    }

    public function test_filing_is_checked_against_the_quota_of_the_year_the_days_fall_in(): void
    {
        $employee = $this->makeEmployee(quota: 12);
        $this->approvedLeave($employee, '2027-02-01', '2027-02-10');
        Sanctum::actingAs($employee->user);

        $this->postJson('/api/v1/leave-requests', $this->payload('2027-03-01', '2027-03-03'))
            ->assertUnprocessable()
            ->assertJsonPath('errors.leave_type.0', 'Kuota cuti tahunan 2027 Anda tidak mencukupi (Sisa: 2 hari, Pengajuan: 3 hari).');

        $this->postJson('/api/v1/leave-requests', $this->payload('2026-12-28', '2027-01-03'))
            ->assertUnprocessable()
            ->assertJsonPath('errors.leave_type.0', 'Kuota cuti tahunan 2027 Anda tidak mencukupi (Sisa: 2 hari, Pengajuan: 3 hari).');

        $this->postJson('/api/v1/leave-requests', $this->payload('2027-03-01', '2027-03-02'))->assertCreated();
    }

    public function test_final_approval_rechecks_the_quota_of_the_leave_year(): void
    {
        $employee = $this->makeEmployee(quota: 12);
        $this->approvedLeave($employee, '2027-02-01', '2027-02-10');
        $leave = $this->pendingLeave($employee, '2027-03-01', '2027-03-03');

        try {
            app(ApprovalFlowService::class)->approveStep($leave, $this->makeSuperadmin());
            $this->fail('The approval should exceed the 2027 quota.');
        } catch (ValidationException $exception) {
            $this->assertSame("Sisa kuota cuti tahunan 2027 {$employee->full_name} tidak mencukupi (Sisa: 2 hari, Pengajuan: 3 hari).", $exception->errors()['approve'][0]);
        }

        $this->assertSame('pending', $leave->fresh()->status);
    }

    public function test_annual_leave_overlapping_another_pending_or_approved_leave_is_refused(): void
    {
        $employee = $this->makeEmployee(quota: 12);
        $this->pendingLeave($employee, '2026-11-23', '2026-11-24', 'sick');
        Sanctum::actingAs($employee->user);

        $this->postJson('/api/v1/leave-requests', $this->payload('2026-11-24', '2026-11-25'))
            ->assertUnprocessable()
            ->assertJsonPath('errors.leave_type.0', 'Tanggal cuti bertabrakan dengan pengajuan Sakit Anda pada 23/11/2026 - 24/11/2026. Pilih tanggal lain.');

        $id = $this->postJson('/api/v1/leave-requests', $this->payload('2026-11-26', '2026-11-26'))->assertCreated()->json('data.id');

        $this->putJson("/api/v1/leave-requests/{$id}", ['start_date' => '2026-11-24', 'end_date' => '2026-11-26'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('leave_type');
        $this->putJson("/api/v1/leave-requests/{$id}", ['reason' => 'Alasan baru'])->assertOk();
    }

    public function test_final_approval_refuses_annual_leave_overlapping_an_approved_one(): void
    {
        $employee = $this->makeEmployee(quota: 12);
        $this->approvedLeave($employee, '2026-11-23', '2026-11-24');
        $duplicate = $this->pendingLeave($employee, '2026-11-23', '2026-11-24');

        $this->expectApprovalRefused($duplicate, "{$employee->full_name} sudah memiliki pengajuan Cuti Tahunan pada 23/11/2026 - 24/11/2026 yang bertabrakan dengan cuti ini. Tolak pengajuan ini.");
        $this->assertSame(2, app(AnnualLeaveService::class)->usedDays($employee));
    }

    public function test_final_approval_is_refused_on_days_the_worker_already_checked_in(): void
    {
        $employee = $this->makeEmployee();
        $first = $this->attendance($employee, '2026-11-16', 1, AttendanceStatus::OnTime, '08:00');
        $this->attendance($employee, '2026-11-17', 1, AttendanceStatus::PendingApproval, '08:00');
        $leave = $this->pendingLeave($employee, '2026-11-15', '2026-11-17', 'sick');

        $this->expectApprovalRefused($leave, "{$employee->full_name} sudah melakukan presensi pada tanggal 16/11/2026, 17/11/2026. Tolak pengajuan ini, atau minta pekerja mengubah tanggal cuti/izinnya.");
        $this->assertSame(AttendanceStatus::OnTime, $first->fresh()->status);
        $this->assertSame(0, Attendance::query()->where('status', AttendanceStatus::Leave->value)->count());
    }

    public function test_approved_leave_marks_every_segment_of_a_split_shift_day(): void
    {
        $employee = $this->makeEmployee();
        $this->attendance($employee, '2026-11-16', 1, AttendanceStatus::Absent, null);
        $this->attendance($employee, '2026-11-16', 2, AttendanceStatus::Absent, null);
        $leave = $this->pendingLeave($employee, '2026-11-16', '2026-11-17', 'sick');

        app(ApprovalFlowService::class)->approveStep($leave, $this->makeSuperadmin());

        $rows = Attendance::query()->where('employee_id', $employee->id)->orderBy('date')->orderBy('segment')->get();
        $this->assertCount(3, $rows);
        $this->assertSame([AttendanceStatus::Leave], $rows->pluck('status')->unique()->values()->all());
    }

    public function test_new_request_push_is_sent_only_after_the_commit(): void
    {
        config(['services.onesignal.app_id' => 'qa-fake-app', 'services.onesignal.rest_api_key' => 'qa-fake-key']);
        Notification::fake();
        $tenant = Tenant::factory()->create();
        $this->company->forceFill(['tenant_id' => $tenant->id])->save();
        $employee = $this->makeEmployee();
        $approver = User::factory()->create(['is_active' => true]);
        $approver->forceFill(['tenant_id' => $tenant->id])->save();
        $approver->assignRole('Approver');
        $approver->grantDataAccess(DataScope::All);

        DB::transaction(function () use ($employee, $approver): void {
            $this->pendingLeave($employee, '2026-11-23', '2026-11-23', 'sick');

            Notification::assertNotSentTo($approver, ApprovalRequestedNotification::class);
        });

        Notification::assertSentTo($approver, ApprovalRequestedNotification::class);
    }

    private function expectApprovalRefused(LeaveRequest $leave, string $message): void
    {
        try {
            app(ApprovalFlowService::class)->approveStep($leave, $this->makeSuperadmin());
            $this->fail('The final approval should have been refused.');
        } catch (ValidationException $exception) {
            $this->assertSame($message, $exception->errors()['approve'][0]);
        }

        $this->assertSame('pending', $leave->fresh()->status);
    }

    private function makeEmployee(?int $quota = null): Employee
    {
        $user = User::factory()->create();
        $user->assignRole('Employee');

        return Employee::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Staff'])->id,
            'nip' => 'KUOTA-'.$user->id,
            'full_name' => "Pekerja {$user->id}",
            'join_date' => '2024-01-02',
            'status' => 'active',
            'has_annual_leave' => $quota !== null,
            'annual_leave_quota' => $quota,
        ])->load('user');
    }

    private function makeSuperadmin(): User
    {
        $superadmin = User::factory()->create();
        $superadmin->assignRole('Superadmin');

        return $superadmin;
    }

    /**
     * @return array<string, string>
     */
    private function payload(string $start, string $end): array
    {
        return ['leave_type' => LeaveRequest::TYPE_ANNUAL, 'start_date' => $start, 'end_date' => $end, 'reason' => 'Liburan'];
    }

    private function approvedLeave(Employee $employee, string $start, string $end): LeaveRequest
    {
        return LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type' => LeaveRequest::TYPE_ANNUAL,
            'start_date' => $start,
            'end_date' => $end,
            'days_count' => (int) Carbon::parse($start)->diffInDays(Carbon::parse($end)) + 1,
            'reason' => 'Disetujui',
            'status' => 'approved',
        ]);
    }

    private function pendingLeave(Employee $employee, string $start, string $end, string $type = LeaveRequest::TYPE_ANNUAL): LeaveRequest
    {
        $leave = LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type' => $type,
            'start_date' => $start,
            'end_date' => $end,
            'days_count' => (int) Carbon::parse($start)->diffInDays(Carbon::parse($end)) + 1,
            'reason' => 'Menunggu',
            'status' => 'pending',
        ]);

        app(ApprovalFlowService::class)->generateSteps($leave, 'leave');

        return $leave->fresh();
    }

    /**
     * Stored as a plain Y-m-d, as the MySQL DATE column holds it.
     */
    private function attendance(Employee $employee, string $date, int $segment, AttendanceStatus $status, ?string $checkIn): Attendance
    {
        $attendance = Attendance::create([
            'employee_id' => $employee->id,
            'date' => $date,
            'segment' => $segment,
            'check_in' => $checkIn ? "{$date} {$checkIn}:00" : null,
            'status' => $status->value,
        ]);

        DB::table('attendances')->where('id', $attendance->id)->update(['date' => $date]);

        return $attendance->fresh();
    }
}
