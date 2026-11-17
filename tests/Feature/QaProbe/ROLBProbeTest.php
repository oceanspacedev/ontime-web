<?php

namespace Tests\Feature\QaProbe;

use App\Enums\AttendanceStatus;
use App\Enums\DataScope;
use App\Models\ApprovalFlow;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ApprovalRequestedNotification;
use App\Services\AnnualLeaveService;
use App\Services\ApprovalFlowService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * QA-STAGING.md catalog ROL-021..ROL-035 (approval flows, eligibility, quota, leave vs attendance),
 * executed locally against the API and the approval service. Each test asserts the oracle of its case.
 */
class ROLBProbeTest extends TestCase
{
    use RefreshDatabase;

    private ApprovalFlowService $service;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Company $companyA1;

    private Company $companyB1;

    private Division $sales;

    private Division $finance;

    private Division $salesB;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp_gateway.url' => null,
            'services.whatsapp_gateway.token' => null,
            'services.onesignal.app_id' => 'qa-fake-app',
            'services.onesignal.rest_api_key' => 'qa-fake-key',
        ]);
        Http::fake();
        Queue::fake();
        Notification::fake();

        $this->travelTo(now()->setDate(2026, 11, 17)->setTime(9, 0));

        $this->seed(RoleSeeder::class);

        $this->service = app(ApprovalFlowService::class);
        $this->tenantA = Tenant::factory()->create(['name' => 'Klien QA', 'code' => 'QA']);
        $this->tenantB = Tenant::factory()->create(['name' => 'Klien QB', 'code' => 'QB']);

        $this->companyA1 = $this->company($this->tenantA, 'PT A1', 'A1');
        $this->companyB1 = $this->company($this->tenantB, 'PT B1', 'B1');

        $this->sales = Division::create(['company_id' => $this->companyA1->id, 'name' => 'Sales', 'is_active' => true]);
        $this->finance = Division::create(['company_id' => $this->companyA1->id, 'name' => 'Finance', 'is_active' => true]);
        $this->salesB = Division::create(['company_id' => $this->companyB1->id, 'name' => 'Sales B', 'is_active' => true]);
    }

    /**
     * Role only or scope only is not enough: the stage role and the worker scope must both hold, and
     * another Klien never sees or processes the request.
     *
     * @see ROL-022
     */
    public function test_rol_022_stage_role_and_worker_scope_are_both_required(): void
    {
        $this->flow($this->companyA1, ['Approver', 'BOD']);
        $approverA = $this->account('Approver', $this->tenantA, DataScope::Companies, companyIds: [$this->companyA1->id]);
        $approverFinance = $this->account('Approver', $this->tenantA, DataScope::Divisions, divisionIds: [$this->finance->id]);
        $bodA = $this->account('BOD', $this->tenantA, DataScope::All);
        $approverB = $this->account('Approver', $this->tenantB, DataScope::All);
        $bodB = $this->account('BOD', $this->tenantB, DataScope::All);
        $worker = $this->employee($this->companyA1, $this->sales, 'Pekerja A');
        $leave = $this->leave($worker, 'permission', '2026-11-23', '2026-11-23');

        $this->process($bodA, $leave, 'approved')->assertUnprocessable();
        $this->process($approverFinance, $leave, 'approved')->assertUnprocessable();
        $this->process($approverB, $leave, 'approved')->assertUnprocessable();
        $this->process($approverB, $leave, 'rejected', 'Tidak')->assertUnprocessable();

        Sanctum::actingAs($approverB);
        $this->getJson('/api/v1/approvals/pending')->assertOk()->assertJsonCount(0, 'leave_requests');
        $this->assertSame('pending', $leave->fresh()->status);
        $this->assertSame(1, (int) $leave->fresh()->current_step);

        $this->process($approverA, $leave, 'approved')->assertOk()->assertJsonPath('current_step', 2);
        $this->process($approverA, $leave, 'approved')->assertUnprocessable();
        $this->process($bodB, $leave, 'approved')->assertUnprocessable();
        $this->process($bodA, $leave, 'approved')->assertOk()->assertJsonPath('status', 'approved');
    }

    /**
     * Admin/Superadmin may override but one stage at a time; an Admin outside the worker's scope is
     * refused; the business effect lands only after the last stage; Superadmin is never notified.
     *
     * @see ROL-023
     */
    public function test_rol_023_admin_and_superadmin_override_one_stage_at_a_time_within_scope(): void
    {
        $this->flow($this->companyA1, ['Approver', 'BOD', 'Approver']);
        $adminSales = $this->account('Admin', $this->tenantA, DataScope::Divisions, divisionIds: [$this->sales->id]);
        $adminFinance = $this->account('Admin', $this->tenantA, DataScope::Divisions, divisionIds: [$this->finance->id]);
        $superadmin = $this->account('Superadmin', null, DataScope::All);
        $this->account('Approver', $this->tenantA, DataScope::Companies, companyIds: [$this->companyA1->id]);
        $worker = $this->employee($this->companyA1, $this->sales, 'Pekerja Override', annualQuota: 12);
        $leave = $this->leave($worker, LeaveRequest::TYPE_ANNUAL, '2026-11-23', '2026-11-24');

        $this->process($adminSales, $leave, 'approved')->assertOk()->assertJsonPath('current_step', 2);
        $this->assertSame('pending', $leave->fresh()->status);
        $this->assertSame(0, Attendance::query()->where('employee_id', $worker->id)->count(), 'No leave effect before the last stage.');

        $this->process($adminFinance, $leave, 'approved')->assertUnprocessable();
        $this->assertSame(2, (int) $leave->fresh()->current_step);

        $this->process($superadmin, $leave, 'approved')->assertOk()->assertJsonPath('current_step', 3);
        $this->assertSame('pending', $leave->fresh()->status);

        $this->process($adminSales, $leave, 'approved')->assertOk()->assertJsonPath('status', 'approved');
        $this->assertSame(2, Attendance::query()->where('employee_id', $worker->id)->where('status', AttendanceStatus::Leave->value)->count());

        Notification::assertNotSentTo($superadmin, ApprovalRequestedNotification::class);
    }

    /**
     * Koordinator-only closes every remaining stage; Koordinator+Admin stays one stage. The leave is
     * applied exactly once either way.
     *
     * @see ROL-024
     */
    public function test_rol_024_coordinator_closes_all_stages_but_coordinator_admin_acts_per_stage(): void
    {
        $this->flow($this->companyA1, ['Approver', 'BOD', 'Approver']);
        $coordinator = $this->account('Koordinator', $this->tenantA, DataScope::Divisions, divisionIds: [$this->sales->id]);
        $coordinatorAdmin = $this->account(['Koordinator', 'Admin'], $this->tenantA, DataScope::Divisions, divisionIds: [$this->sales->id]);
        $worker = $this->employee($this->companyA1, $this->sales, 'Crew Sales', annualQuota: 12);

        $first = $this->leave($worker, LeaveRequest::TYPE_ANNUAL, '2026-11-23', '2026-11-24');
        $this->process($coordinatorAdmin, $first, 'approved')->assertOk()->assertJsonPath('current_step', 2);
        $this->assertSame('pending', $first->fresh()->status);

        $second = $this->leave($worker, LeaveRequest::TYPE_ANNUAL, '2026-11-26', '2026-11-27');
        $this->process($coordinator, $second, 'approved')->assertOk()->assertJsonPath('status', 'approved');
        $this->assertSame(['approved', 'approved', 'approved'], $second->approvalSteps()->orderBy('step_order')->pluck('status')->all());
        $this->assertSame([$coordinator->id], $second->approvalSteps()->pluck('actioned_by')->unique()->values()->all());
        $this->assertSame(2, Attendance::query()->where('employee_id', $worker->id)->where('status', AttendanceStatus::Leave->value)->count());
        $this->assertSame(2, app(AnnualLeaveService::class)->usedDays($worker->fresh()));
    }

    /**
     * Admin/Koordinator never process their own request, not even an intermediate stage; a BOD may
     * pass a matching intermediate stage but never the final one, and someone else can finish it.
     *
     * @see ROL-025
     */
    public function test_rol_025_admin_and_coordinator_cannot_process_their_own_request(): void
    {
        $this->flow($this->companyA1, ['Admin', 'Koordinator', 'BOD']);
        $admin = $this->account('Admin', $this->tenantA, DataScope::All);
        $this->employeeFor($admin, $this->companyA1, $this->sales, 'Admin Pemohon');
        $coordinator = $this->account('Koordinator', $this->tenantA, DataScope::Divisions, divisionIds: [$this->sales->id]);
        $this->employeeFor($coordinator, $this->companyA1, $this->sales, 'Koordinator Pemohon');
        $otherBod = $this->account('BOD', $this->tenantA, DataScope::All);

        $adminLeave = $this->leave($admin->employee()->first(), 'permission', '2026-11-23', '2026-11-23');
        $this->process($admin, $adminLeave, 'approved')->assertUnprocessable();
        $this->assertSame(1, (int) $adminLeave->fresh()->current_step);

        $coordinatorLeave = $this->leave($coordinator->employee()->first(), 'permission', '2026-11-23', '2026-11-23');
        $this->process($admin, $coordinatorLeave, 'approved')->assertOk()->assertJsonPath('current_step', 2);
        $this->process($coordinator, $coordinatorLeave, 'approved')->assertUnprocessable();
        $this->assertSame('pending', $coordinatorLeave->fresh()->status);
        $this->assertSame(2, (int) $coordinatorLeave->fresh()->current_step);

        // BOD requester: may pass the BOD stage only when it is not final.
        ApprovalFlow::query()->where('company_id', $this->companyA1->id)->delete();
        $this->flow($this->companyA1, ['BOD', 'Approver']);
        $bod = $this->account('BOD', $this->tenantA, DataScope::All);
        $this->employeeFor($bod, $this->companyA1, $this->sales, 'BOD Pemohon');
        $approver = $this->account('Approver', $this->tenantA, DataScope::All);
        $bodLeave = $this->leave($bod->employee()->first(), 'permission', '2026-11-24', '2026-11-24');
        $this->process($bod, $bodLeave, 'approved')->assertOk()->assertJsonPath('current_step', 2);
        $this->process($bod, $bodLeave, 'approved')->assertUnprocessable();
        $this->process($approver, $bodLeave, 'approved')->assertOk()->assertJsonPath('status', 'approved');
        $this->assertNotNull($otherBod);
    }

    /**
     * A named approver who is deactivated leaves the stage unstaffed: it is reported, reminders go to
     * the Admins of the same Klien (not Superadmin, not another Klien), and nothing is auto-approved.
     *
     * @see ROL-029
     */
    public function test_rol_029_deactivated_named_approver_falls_back_to_tenant_admin_only(): void
    {
        $named = $this->account('BOD', $this->tenantA, DataScope::All);
        ApprovalFlow::create(['company_id' => $this->companyA1->id, 'request_type' => 'leave', 'step_number' => 1, 'step_order' => 1, 'name' => 'Direktur', 'approver_type' => 'user', 'user_id' => $named->id]);
        $adminA = $this->account('Admin', $this->tenantA, DataScope::Companies, companyIds: [$this->companyA1->id]);
        $adminB = $this->account('Admin', $this->tenantB, DataScope::All);
        $superadmin = $this->account('Superadmin', null, DataScope::All);
        $worker = $this->employee($this->companyA1, $this->sales, 'Pekerja Unstaffed');
        $leave = $this->leave($worker, 'permission', '2026-11-23', '2026-11-23');

        $named->forceFill(['is_active' => false])->save();
        $this->assertNotEmpty(ApprovalFlowService::unstaffedStages($this->companyA1->fresh()));

        Notification::fake();
        $leave->approvalSteps()->update(['created_at' => now()->subDays(3)]);
        $this->assertSame(1, $this->service->remindStalled(2));

        Notification::assertSentTo($adminA, ApprovalRequestedNotification::class);
        Notification::assertNotSentTo($adminB, ApprovalRequestedNotification::class);
        Notification::assertNotSentTo($superadmin, ApprovalRequestedNotification::class);
        Notification::assertNotSentTo($named, ApprovalRequestedNotification::class);
        $this->assertSame('pending', $leave->fresh()->status);

        $this->process($adminA, $leave, 'approved')->assertOk()->assertJsonPath('status', 'approved');
    }

    /**
     * A pending request keeps the steps it was created with after the flow is edited; a new request
     * uses the new flow.
     *
     * @see ROL-030
     */
    public function test_rol_030_pending_request_keeps_its_step_snapshot_after_the_flow_changes(): void
    {
        $this->flow($this->companyA1, ['Approver', 'BOD']);
        $approver = $this->account('Approver', $this->tenantA, DataScope::All);
        $bod = $this->account('BOD', $this->tenantA, DataScope::All);
        $worker = $this->employee($this->companyA1, $this->sales, 'Pekerja Snapshot');
        $r1 = $this->leave($worker, 'permission', '2026-11-23', '2026-11-23');

        ApprovalFlow::query()->where('company_id', $this->companyA1->id)->delete();
        $this->flow($this->companyA1, ['BOD']);
        $r2 = $this->leave($worker, 'permission', '2026-11-25', '2026-11-25');

        $this->assertSame(['Approver', 'BOD'], $r1->approvalSteps()->orderBy('step_order')->pluck('approver_role')->all());
        $this->assertSame(['BOD'], $r2->approvalSteps()->orderBy('step_order')->pluck('approver_role')->all());

        $this->process($bod, $r1, 'approved')->assertUnprocessable();
        $this->process($approver, $r1, 'approved')->assertOk()->assertJsonPath('current_step', 2);
        $this->process($bod, $r1, 'approved')->assertOk()->assertJsonPath('status', 'approved');
        $this->process($bod, $r2, 'approved')->assertOk()->assertJsonPath('status', 'approved');
    }

    /**
     * Legacy codes are normalised on the API; Freelancer and non-entitled Employee cannot file annual
     * leave even with the legacy code; an entitled Employee is held to the quota (boundary and +1).
     *
     * @see ROL-031
     */
    public function test_rol_031_legacy_annual_code_is_normalised_and_quota_bounded(): void
    {
        $this->flow($this->companyA1, ['Approver']);
        $approver = $this->account('Approver', $this->tenantA, DataScope::All);

        $freelancerUser = $this->account('Freelancer', $this->tenantA);
        Freelancer::create(['user_id' => $freelancerUser->id, 'company_id' => $this->companyA1->id, 'division_id' => $this->sales->id, 'full_name' => 'Freelancer', 'status' => 'active']);
        Sanctum::actingAs($freelancerUser);
        $this->postJson('/api/v1/leave-requests', $this->payload('annual', '2026-11-23', '2026-11-23'))->assertUnprocessable();

        $nonEntitled = $this->employee($this->companyA1, $this->sales, 'Tanpa Cuti');
        Sanctum::actingAs($nonEntitled->user);
        $this->postJson('/api/v1/leave-requests', $this->payload('annual', '2026-11-23', '2026-11-23'))->assertUnprocessable();
        $this->postJson('/api/v1/leave-requests', $this->payload('permitted', '2026-11-23', '2026-11-23'))
            ->assertCreated()->assertJsonPath('data.leave_type', 'permission');

        $entitled = $this->employee($this->companyA1, $this->sales, 'Berhak Cuti', annualQuota: 3);
        Sanctum::actingAs($entitled->user);
        $id = $this->postJson('/api/v1/leave-requests', $this->payload('annual', '2026-11-23', '2026-11-25'))
            ->assertCreated()->json('data.id');
        $this->assertSame(LeaveRequest::TYPE_ANNUAL, LeaveRequest::find($id)->leave_type);
        $this->process($approver, LeaveRequest::find($id), 'approved')->assertOk();

        Sanctum::actingAs($entitled->user);
        $this->postJson('/api/v1/leave-requests', $this->payload('annual', '2026-11-27', '2026-11-27'))->assertUnprocessable();
    }

    /**
     * Quota lowered between the first and the final stage: the final approval re-checks and fails
     * cleanly, without approving or writing leave days.
     *
     * @see ROL-032
     */
    public function test_rol_032_quota_lowered_before_final_stage_blocks_final_approval(): void
    {
        $this->flow($this->companyA1, ['Approver', 'BOD']);
        $approver = $this->account('Approver', $this->tenantA, DataScope::All);
        $bod = $this->account('BOD', $this->tenantA, DataScope::All);
        $worker = $this->employee($this->companyA1, $this->sales, 'Kuota Turun', annualQuota: 3);
        $leave = $this->leave($worker, LeaveRequest::TYPE_ANNUAL, '2026-11-23', '2026-11-25');

        $this->process($approver, $leave, 'approved')->assertOk()->assertJsonPath('current_step', 2);
        $worker->forceFill(['annual_leave_quota' => 2])->save();

        $this->process($bod, $leave, 'approved')->assertUnprocessable()->assertJsonValidationErrors('approve');
        $this->assertSame('pending', $leave->fresh()->status);
        $this->assertSame('pending', $leave->approvalSteps()->where('step_order', 2)->value('status'));
        $this->assertSame(0, Attendance::query()->where('employee_id', $worker->id)->count());
    }

    /**
     * Two final approvals of different annual requests (2 + 2 days, 3 left) that run at the same time:
     * at most one may become approved. The interleaving is reproduced deterministically: request B is
     * approved while request A's final approval is between its quota check and its status write, which
     * is exactly what a concurrent transaction sees (A's write is not committed yet).
     *
     * @see ROL-033
     */
    public function test_rol_033_concurrent_final_approvals_cannot_overspend_the_quota(): void
    {
        $this->flow($this->companyA1, ['Approver']);
        $approverOne = $this->account('Approver', $this->tenantA, DataScope::All);
        $approverTwo = $this->account('Approver', $this->tenantA, DataScope::All);
        $worker = $this->employee($this->companyA1, $this->sales, 'Kuota Paralel', annualQuota: 3);
        $requestA = $this->leave($worker, LeaveRequest::TYPE_ANNUAL, '2026-11-23', '2026-11-24');
        $requestB = $this->leave($worker, LeaveRequest::TYPE_ANNUAL, '2026-11-30', '2026-12-01');

        $interleaved = false;
        LeaveRequest::updating(function (LeaveRequest $leave) use (&$interleaved, $requestA, $requestB, $approverTwo): void {
            if ($interleaved || $leave->getKey() !== $requestA->getKey() || $leave->status !== 'approved') {
                return;
            }

            $interleaved = true;

            try {
                $this->service->approveStep(LeaveRequest::find($requestB->getKey()), $approverTwo);
            } catch (ValidationException) {
                // B refused: the quota held.
            }
        });

        try {
            $this->service->approveStep($requestA, $approverOne);
        } catch (ValidationException) {
            // A refused: the quota held.
        }

        $this->assertTrue($interleaved);
        $approvedDays = (int) LeaveRequest::query()->where('employee_id', $worker->id)->where('status', 'approved')->sum('days_count');
        $this->assertLessThanOrEqual(3, $approvedDays, "Quota 3 days, approved {$approvedDays} days.");
    }

    /**
     * Annual leave filed for the same days twice: once the first is approved, the second must not
     * consume the quota again for days already on leave.
     *
     * @see ROL-033
     */
    public function test_rol_033_overlapping_annual_leave_does_not_charge_the_same_days_twice(): void
    {
        $this->flow($this->companyA1, ['Approver']);
        $approver = $this->account('Approver', $this->tenantA, DataScope::All);
        $worker = $this->employee($this->companyA1, $this->sales, 'Cuti Ganda', annualQuota: 12);

        Sanctum::actingAs($worker->user);
        $firstId = $this->postJson('/api/v1/leave-requests', $this->payload(LeaveRequest::TYPE_ANNUAL, '2026-11-23', '2026-11-24'))->assertCreated()->json('data.id');
        $this->process($approver, LeaveRequest::find($firstId), 'approved')->assertOk();
        $this->normaliseAttendanceDates();

        Sanctum::actingAs($worker->user);
        $second = $this->postJson('/api/v1/leave-requests', $this->payload(LeaveRequest::TYPE_ANNUAL, '2026-11-23', '2026-11-24'));

        if ($second->status() === 201) {
            $outcome = $this->process($approver, LeaveRequest::find($second->json('data.id')), 'approved');
            $this->assertNotSame(500, $outcome->status());
        }

        $leaveDays = Attendance::query()->where('employee_id', $worker->id)->where('status', AttendanceStatus::Leave->value)->count();
        $this->assertSame($leaveDays, app(AnnualLeaveService::class)->usedDays($worker->fresh()), 'Quota used must match the calendar days actually on leave.');
    }

    /**
     * Whatever year a leave is allocated to, two requests in one future year cannot together exceed
     * one year's quota. The code counts used days by the start_date year but validates against the
     * current year.
     *
     * @see ROL-034
     */
    public function test_rol_034_next_year_requests_cannot_exceed_one_years_quota(): void
    {
        $this->flow($this->companyA1, ['Approver']);
        $approver = $this->account('Approver', $this->tenantA, DataScope::All);
        $worker = $this->employee($this->companyA1, $this->sales, 'Cuti Tahun Depan', annualQuota: 12);

        foreach ([['2027-02-01', '2027-02-10'], ['2027-03-01', '2027-03-10']] as [$start, $end]) {
            Sanctum::actingAs($worker->user);
            $response = $this->postJson('/api/v1/leave-requests', $this->payload(LeaveRequest::TYPE_ANNUAL, $start, $end));

            if ($response->status() === 201) {
                $this->process($approver, LeaveRequest::find($response->json('data.id')), 'approved');
            }
        }

        $used2027 = app(AnnualLeaveService::class)->usedDays($worker->fresh(), 2027);
        $this->assertLessThanOrEqual(12, $used2027, "Quota 12 days, 2027 approved {$used2027} days.");
    }

    /**
     * Approving leave on a day the worker already checked in and out must not leave a row that says
     * leave while still carrying the worked hours (silent mixed status).
     *
     * @see ROL-035
     */
    public function test_rol_035_leave_over_a_completed_attendance_is_not_a_silent_mixed_status(): void
    {
        $this->flow($this->companyA1, ['Approver']);
        $approver = $this->account('Approver', $this->tenantA, DataScope::All);
        $worker = $this->employee($this->companyA1, $this->sales, 'Sudah Hadir');
        $attendance = $this->attendance($worker, '2026-11-16', 1, '08:00', '17:00');
        $leave = $this->leave($worker, 'sick', '2026-11-16', '2026-11-16');

        $refused = $this->tryApprove($leave, $approver);
        $row = $attendance->fresh();

        if ($refused) {
            $this->assertSame(AttendanceStatus::OnTime, $row->status);
        } else {
            $this->assertFalse(
                $row->status === AttendanceStatus::Leave && $row->check_in !== null,
                'Attendance row is status leave but still holds check_in/check_out (worked hours).',
            );
        }
    }

    /**
     * Split shift: two attendance rows on the day. Approving leave on that day must not leave one
     * segment on leave and the other still present.
     *
     * @see ROL-035
     */
    public function test_rol_035_leave_over_split_shift_leaves_both_segments_consistent(): void
    {
        $this->flow($this->companyA1, ['Approver']);
        $approver = $this->account('Approver', $this->tenantA, DataScope::All);
        $worker = $this->employee($this->companyA1, $this->sales, 'Split Shift');
        $this->attendance($worker, '2026-11-16', 1, '07:45', '13:00');
        $this->attendance($worker, '2026-11-16', 2, '17:45', '22:00');
        $leave = $this->leave($worker, 'sick', '2026-11-16', '2026-11-16');

        $this->tryApprove($leave, $approver);

        $statuses = Attendance::query()->where('employee_id', $worker->id)->orderBy('segment')->get()
            ->map(fn (Attendance $row): string => $row->status->value)->unique()->values()->all();
        $this->assertCount(1, $statuses, 'Segments of one day ended with different statuses: '.implode(', ', $statuses));
    }

    /**
     * A check-in waiting for geofence approval on the day of a newly approved leave: the leave must
     * not silently bury that approval (record no longer pending while its step stays pending forever).
     *
     * @see ROL-035
     */
    public function test_rol_035_leave_does_not_strand_a_pending_geofence_approval(): void
    {
        $this->flow($this->companyA1, ['Approver']);
        $this->flow($this->companyA1, ['Approver'], 'geofence');
        $approver = $this->account('Approver', $this->tenantA, DataScope::All);
        $worker = $this->employee($this->companyA1, $this->sales, 'Luar Geofence');
        $attendance = $this->attendance($worker, '2026-11-16', 1, '08:00', null);
        $attendance->forceFill(['is_out_of_bounds' => true])->save();
        $this->service->generateSteps($attendance, 'geofence');
        $this->assertSame(AttendanceStatus::PendingApproval, $attendance->fresh()->status);

        $leave = $this->leave($worker, 'sick', '2026-11-16', '2026-11-16');
        $this->tryApprove($leave, $approver);

        $row = $attendance->fresh();
        $stuckSteps = $row->approvalSteps()->where('status', 'pending')->count();
        $this->assertFalse(
            $row->status !== AttendanceStatus::PendingApproval && $stuckSteps > 0,
            "Attendance is now {$row->status->value} but {$stuckSteps} geofence approval step(s) stay pending and out of every inbox.",
        );
    }

    /**
     * Keep attendance dates as plain Y-m-d, as the MySQL DATE column holds them; SQLite keeps the time
     * suffix written by the date cast, which the leave's `date = 'Y-m-d'` lookup never matches.
     */
    private function normaliseAttendanceDates(): void
    {
        DB::table('attendances')->update(['date' => DB::raw('substr(date, 1, 10)')]);
    }

    private function tryApprove(LeaveRequest $leave, User $approver): bool
    {
        try {
            $this->service->approveStep($leave, $approver);

            return false;
        } catch (ValidationException) {
            return true;
        }
    }

    private function process(User $actor, LeaveRequest $leave, string $action, ?string $note = null): TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->putJson("/api/v1/approvals/leave/{$leave->id}/process", array_filter([
            'action' => $action,
            'rejection_note' => $note,
        ]));
    }

    /**
     * @return array<string, string>
     */
    private function payload(string $type, string $start, string $end): array
    {
        return ['leave_type' => $type, 'start_date' => $start, 'end_date' => $end, 'reason' => 'Uji QA'];
    }

    private function company(Tenant $tenant, string $name, string $code): Company
    {
        $company = new Company(['name' => $name, 'code' => $code, 'is_active' => true]);
        $company->tenant_id = $tenant->id;
        $company->save();

        return $company;
    }

    /**
     * @param  string|list<string>  $roles
     * @param  list<int>  $companyIds
     * @param  list<int>  $divisionIds
     */
    private function account(string|array $roles, ?Tenant $tenant, ?DataScope $scope = null, array $companyIds = [], array $divisionIds = []): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['tenant_id' => $tenant?->id])->save();
        $user->assignRole($roles);

        if ($scope) {
            $user->grantDataAccess($scope, companyIds: $companyIds, divisionIds: $divisionIds);
        }

        return $user;
    }

    private function employee(Company $company, Division $division, string $name, ?int $annualQuota = null): Employee
    {
        $user = $this->account('Employee', $company->tenant_id === $this->tenantA->id ? $this->tenantA : $this->tenantB);

        return $this->employeeFor($user, $company, $division, $name, $annualQuota);
    }

    private function employeeFor(User $user, Company $company, Division $division, string $name, ?int $annualQuota = null): Employee
    {
        return Employee::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'division_id' => $division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Staff'])->id,
            'nip' => $company->code.'-'.$user->id,
            'full_name' => $name,
            'join_date' => '2024-01-02',
            'status' => 'active',
            'has_annual_leave' => $annualQuota !== null,
            'annual_leave_quota' => $annualQuota,
        ]);
    }

    /**
     * @param  list<string>  $roles
     */
    private function flow(Company $company, array $roles, string $type = 'leave'): void
    {
        foreach ($roles as $index => $role) {
            ApprovalFlow::create([
                'company_id' => $company->id,
                'request_type' => $type,
                'step_number' => $index + 1,
                'step_order' => $index + 1,
                'name' => "Tahap {$role}",
                'approver_type' => 'role',
                'approver_role' => $role,
            ]);
        }
    }

    private function leave(Employee $worker, string $type, string $start, string $end): LeaveRequest
    {
        $leave = LeaveRequest::create([
            'employee_id' => $worker->id,
            'leave_type' => $type,
            'start_date' => $start,
            'end_date' => $end,
            'days_count' => (int) Carbon::parse($start)->diffInDays(Carbon::parse($end)) + 1,
            'reason' => 'Uji QA',
            'status' => 'pending',
        ]);

        $this->service->generateSteps($leave, 'leave');

        return $leave->fresh();
    }

    private function attendance(Employee $worker, string $date, int $segment, string $in, ?string $out): Attendance
    {
        $attendance = Attendance::create([
            'employee_id' => $worker->id,
            'date' => $date,
            'segment' => $segment,
            'check_in' => "{$date} {$in}:00",
            'check_out' => $out ? "{$date} {$out}:00" : null,
            'status' => AttendanceStatus::OnTime->value,
            'late_minutes' => 0,
        ]);

        // Stored as a plain Y-m-d, as the MySQL DATE column holds it (SQLite would keep a time suffix
        // that the leave's `date = 'Y-m-d'` lookup never matches).
        DB::table('attendances')->where('id', $attendance->id)->update(['date' => $date]);

        return $attendance->fresh();
    }
}
