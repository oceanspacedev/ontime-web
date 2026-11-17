<?php

namespace Tests\Feature\Chaos;

use App\Enums\DataScope;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\ShiftSwapRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ApprovalResolvedNotification;
use App\Notifications\ShiftSwapUpdatedNotification;
use App\Services\ApprovalFlowService;
use App\Services\AttendanceCheckService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Mobile client chaos, server side: what the app gets back when a submit is sent twice because the
 * first answer was lost (timeout mid-submit, then "Coba lagi"), and the push payloads the new screens
 * rely on.
 */
class Chaos_mobileTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-11-16';

    private const TUESDAY = '2026-11-17';

    private Company $company;

    private Division $division;

    private Shift $morning;

    private Shift $afternoon;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp_gateway.url' => null,
            'services.whatsapp_gateway.token' => null,
            'services.onesignal.app_id' => 'chaos-fake-app',
            'services.onesignal.rest_api_key' => 'chaos-fake-key',
        ]);
        Http::fake();
        Notification::fake();

        $this->travelTo(Carbon::parse(self::MONDAY.' 07:30:00'));
        $this->seed(RoleSeeder::class);

        $tenant = Tenant::factory()->create(['name' => 'Klien Chaos', 'code' => 'KC']);
        $this->company = new Company(['name' => 'PT Chaos', 'code' => 'CHS', 'is_active' => true]);
        $this->company->tenant_id = $tenant->id;
        $this->company->save();
        app(ApprovalFlowService::class)->createDefaultFlows($this->company);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Outlet', 'is_active' => true]);
        $this->company->policy()->update([
            'require_gps' => false,
            'require_photo' => false,
            'require_face_recognition' => false,
            'require_liveness' => false,
            'work_start_time' => '08:00:00',
            'work_end_time' => '16:00:00',
            'late_tolerance_minutes' => 0,
        ]);
        $this->company->unsetRelation('policy');

        $this->morning = Shift::create(['company_id' => $this->company->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '16:00']);
        $this->afternoon = Shift::create(['company_id' => $this->company->id, 'name' => 'Siang', 'start_time' => '13:00', 'end_time' => '21:00']);

        $this->approver = $this->employeeAccount('Approver', 'Rina Approver', DataScope::All);
    }

    // ── Findings ──────────────────────────────────────────────────────────

    /**
     * The leave form uploads an attachment with a 90s timeout. When the answer is lost after the server
     * saved the request, the worker taps "Kirim pengajuan" again and the same Izin is filed twice: only
     * annual leave is checked for overlap, so the approver gets two identical requests for the same days.
     */
    public function test_identical_izin_resubmitted_after_a_lost_response_is_not_filed_twice(): void
    {
        $worker = $this->employeeAccount('Employee', 'Andi Kasir');
        Sanctum::actingAs($worker);

        $payload = ['leave_type' => 'permission', 'start_date' => '2026-11-20', 'end_date' => '2026-11-20', 'reason' => 'Urusan keluarga'];

        $this->postJson('/api/v1/leave-requests', $payload)->assertCreated();

        // Same tap again after the timeout: the server must recognise the duplicate.
        $this->postJson('/api/v1/leave-requests', $payload)->assertUnprocessable();

        $this->assertSame(1, LeaveRequest::query()->where('employee_id', $worker->employee->id)->where('status', 'pending')->count());
    }

    // ── Survived: retries after a lost answer are refused with an Indonesian message ──

    public function test_check_in_retried_after_a_lost_response_is_refused_with_409_and_keeps_one_row(): void
    {
        $worker = $this->employeeAccount('Employee', 'Andi Kasir', shift: $this->morning);
        $this->at('07:55:00');
        Sanctum::actingAs($worker);

        $this->postJson('/api/v1/attendance/check-in')->assertOk();
        $this->postJson('/api/v1/attendance/check-in')
            ->assertStatus(409)
            ->assertJsonPath('message', AttendanceCheckService::ALREADY_CHECKED_IN_MESSAGE);

        $this->assertSame(1, Attendance::query()->where('employee_id', $worker->employee->id)->count());
    }

    public function test_check_out_retried_after_a_lost_response_keeps_the_first_check_out_time(): void
    {
        $worker = $this->employeeAccount('Employee', 'Andi Kasir', shift: $this->morning);
        Sanctum::actingAs($worker);
        $this->at('07:55:00');
        $this->postJson('/api/v1/attendance/check-in')->assertOk();

        $this->at('16:05:00');
        $this->postJson('/api/v1/attendance/check-out')->assertOk();
        $this->at('16:07:30');
        $this->postJson('/api/v1/attendance/check-out')
            ->assertStatus(409)
            ->assertJsonPath('message', AttendanceCheckService::ALREADY_CHECKED_OUT_MESSAGE);

        $row = Attendance::query()->where('employee_id', $worker->employee->id)->sole();
        $this->assertSame('16:05', $row->check_out->format('H:i'));
    }

    public function test_shift_swap_request_retried_after_a_lost_response_is_refused_with_a_message_the_form_shows(): void
    {
        $andi = $this->employeeAccount('Employee', 'Andi Kasir');
        $budi = $this->employeeAccount('Employee', 'Budi Dapur');
        $this->schedule($andi, self::TUESDAY, $this->morning);
        $this->schedule($budi, self::TUESDAY, $this->afternoon);
        Sanctum::actingAs($andi);

        $payload = ['type' => 'swap', 'requester_date' => self::TUESDAY, 'counterpart_date' => self::TUESDAY, 'colleague_type' => 'employee', 'colleague_id' => $budi->employee->id];

        $this->postJson('/api/v1/shift-swaps', $payload)->assertCreated();
        // The mobile form shows any key it has no field for (here `shift_swap`) in its footer notice.
        $this->postJson('/api/v1/shift-swaps', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('shift_swap');

        $this->assertSame(1, ShiftSwapRequest::query()->count());
    }

    public function test_overtime_retried_after_a_lost_response_is_refused_as_overlapping(): void
    {
        $worker = $this->employeeAccount('Employee', 'Andi Kasir');
        Sanctum::actingAs($worker);
        $payload = ['date' => self::MONDAY, 'start_time' => '17:00', 'end_time' => '19:00', 'reason' => 'Stok opname'];

        $this->postJson('/api/v1/overtime-requests', $payload)->assertCreated();
        $this->postJson('/api/v1/overtime-requests', $payload)->assertUnprocessable();

        $this->assertSame(1, OvertimeRequest::query()->count());
    }

    public function test_correction_retried_after_a_lost_response_is_refused_as_a_duplicate(): void
    {
        $worker = $this->employeeAccount('Employee', 'Andi Kasir', shift: $this->morning);
        Sanctum::actingAs($worker);
        $payload = ['date' => '2026-11-14', 'corrected_check_in' => '08:00', 'corrected_check_out' => '16:00', 'reason' => 'Lupa absen'];

        $this->postJson('/api/v1/attendance-corrections', $payload)->assertCreated();
        $this->postJson('/api/v1/attendance-corrections', $payload)->assertUnprocessable();

        $this->assertSame(1, AttendanceCorrection::query()->count());
    }

    public function test_approval_replayed_after_a_lost_response_is_refused_as_already_processed(): void
    {
        $worker = $this->employeeAccount('Employee', 'Andi Kasir');
        Sanctum::actingAs($worker);
        $leaveId = $this->postJson('/api/v1/leave-requests', ['leave_type' => 'sick', 'start_date' => '2026-11-18', 'end_date' => '2026-11-18', 'reason' => 'Demam'])
            ->assertCreated()
            ->json('data.id');

        Sanctum::actingAs($this->approver);
        $this->putJson("/api/v1/approvals/leave/{$leaveId}/process", ['action' => 'approved', 'current_step' => 1])->assertOk();

        // The app's stale check matches this exact message and sends the approver back.
        $this->putJson("/api/v1/approvals/leave/{$leaveId}/process", ['action' => 'approved', 'current_step' => 1])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Pengajuan ini sudah tidak berstatus pending.');
    }

    /**
     * Contract the app's push routing relies on: when a Tukar Shift is approved, the requester hears it
     * only through `approval_resolved` with `request_type: shift_swap` (the colleague gets `shift_swap`).
     * The mobile pushTarget() must route that payload; see chaos_mobile.test.ts.
     */
    public function test_requester_of_an_approved_shift_swap_is_told_through_approval_resolved_with_type_shift_swap(): void
    {
        $andi = $this->employeeAccount('Employee', 'Andi Kasir');
        $budi = $this->employeeAccount('Employee', 'Budi Dapur');
        $this->schedule($andi, self::TUESDAY, $this->morning);
        $this->schedule($budi, self::TUESDAY, $this->afternoon);

        Sanctum::actingAs($andi);
        $swapId = $this->postJson('/api/v1/shift-swaps', ['type' => 'swap', 'requester_date' => self::TUESDAY, 'counterpart_date' => self::TUESDAY, 'colleague_type' => 'employee', 'colleague_id' => $budi->employee->id])
            ->assertCreated()
            ->json('data.id');
        Sanctum::actingAs($budi);
        $this->postJson("/api/v1/shift-swaps/{$swapId}/accept")->assertOk();
        Sanctum::actingAs($this->approver);
        $this->putJson("/api/v1/approvals/shift_swap/{$swapId}/process", ['action' => 'approved'])->assertOk();

        Notification::assertSentTo($andi, ApprovalResolvedNotification::class, function (ApprovalResolvedNotification $notification) use ($andi): bool {
            $data = $notification->toOneSignal($andi)->toArray('chaos-fake-app', [(string) $andi->id])['data'];

            return $data['kind'] === 'approval_resolved' && $data['request_type'] === 'shift_swap' && $data['status'] === 'approved';
        });
        Notification::assertNotSentTo($andi, ShiftSwapUpdatedNotification::class, fn (ShiftSwapUpdatedNotification $notification): bool => $notification->event === 'approved');
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function at(string $time): void
    {
        $this->travelTo(Carbon::parse(self::MONDAY.' '.$time));
    }

    private function schedule(User $worker, string $date, ?Shift $shift): ShiftSchedule
    {
        return ShiftSchedule::create([
            'date' => $date,
            'employee_id' => $worker->employee->id,
            'shift_id' => $shift?->id,
            'is_day_off' => $shift === null,
        ]);
    }

    private function employeeAccount(string $role, string $name, ?DataScope $scope = null, ?Shift $shift = null): User
    {
        $user = User::factory()->create(['name' => $name, 'is_active' => true]);
        $user->forceFill(['tenant_id' => $this->company->tenant_id])->save();
        $user->assignRole($role);

        if ($scope) {
            $user->grantDataAccess($scope);
        }

        Employee::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Staff Operasional'])->id,
            'nip' => $this->company->code.'-'.$user->id,
            'full_name' => $name,
            'join_date' => '2024-01-02',
            'status' => 'active',
            'shift_id' => $shift?->id,
        ]);

        return $user->fresh();
    }
}
