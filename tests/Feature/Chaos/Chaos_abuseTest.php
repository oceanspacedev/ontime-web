<?php

namespace Tests\Feature\Chaos;

use App\Enums\DataScope;
use App\Models\ApprovalFlow;
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
use App\Services\ApprovalFlowService;
use App\Services\ShiftSwapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Chaos lens "input / authz abuse": other-tenant ids, workers acting on colleagues' requests, malformed and
 * oversized payloads, replays and mass-assignment through the API. Every malformed payload must be a 4xx, never a 500.
 */
class Chaos_abuseTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-11-16';

    private const TUESDAY = '2026-11-17';

    private Tenant $tenant;

    private Company $company;

    private Division $division;

    private Shift $morning;

    private Shift $afternoon;

    private Tenant $otherTenant;

    private Company $otherCompany;

    private Division $otherDivision;

    private Shift $otherShift;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp_gateway.url' => null,
            'services.whatsapp_gateway.token' => null,
            'services.onesignal.app_id' => null,
            'services.onesignal.rest_api_key' => null,
        ]);
        Http::fake();
        Notification::fake();

        foreach (['Superadmin', 'Admin', 'PIC', 'Koordinator', 'Approver', 'BOD', 'Employee', 'Intern', 'Freelancer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->travelTo(Carbon::parse(self::MONDAY.' 06:00:00'));

        $this->tenant = Tenant::factory()->create(['name' => 'Klien A', 'code' => 'KA', 'is_active' => true]);
        $this->company = $this->makeCompany('PT Resto', 'RESTO', $this->tenant);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Dapur', 'code' => 'DPR', 'is_active' => true]);
        $this->morning = Shift::create(['company_id' => $this->company->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '16:00', 'late_tolerance_minutes' => 0, 'is_active' => true]);
        $this->afternoon = Shift::create(['company_id' => $this->company->id, 'name' => 'Siang', 'start_time' => '13:00', 'end_time' => '21:00', 'late_tolerance_minutes' => 0, 'is_active' => true]);

        $this->otherTenant = Tenant::factory()->create(['name' => 'Klien B', 'code' => 'KB', 'is_active' => true]);
        $this->otherCompany = $this->makeCompany('PT Lain', 'LAIN', $this->otherTenant);
        $this->otherDivision = Division::create(['company_id' => $this->otherCompany->id, 'name' => 'Bar', 'code' => 'BAR', 'is_active' => true]);
        $this->otherShift = Shift::create(['company_id' => $this->otherCompany->id, 'name' => 'Pagi B', 'start_time' => '08:00', 'end_time' => '16:00', 'late_tolerance_minutes' => 0, 'is_active' => true]);

        foreach ([$this->company, $this->otherCompany] as $company) {
            app(ApprovalFlowService::class)->createDefaultFlows($company);
            $company->policy()->update([
                'require_gps' => false,
                'require_photo' => false,
                'require_face_recognition' => false,
                'require_liveness' => false,
                'late_tolerance_minutes' => 15,
            ]);
        }

        ApprovalFlow::query()->firstOrCreate(
            ['company_id' => $this->company->id, 'request_type' => 'shift_swap', 'step_number' => 1],
            ['step_order' => 1, 'name' => 'Persetujuan Tukar Shift', 'approver_type' => 'role', 'approver_role' => 'Approver'],
        );
    }

    // ── Findings: malformed input answered with a 500 ─────────────────────────

    /**
     * A non-numeric or 64-bit-overflowing id on the worker request routes reaches an `int $id` controller
     * argument (the routes have no whereNumber), so PHP throws a TypeError and the API answers 500.
     */
    public function test_non_numeric_or_overflowing_request_ids_are_not_found_not_a_server_error(): void
    {
        Sanctum::actingAs($this->employee('Andi')->user);

        foreach (['leave-requests', 'overtime-requests', 'attendance-corrections', 'resignations', 'attendances'] as $resource) {
            foreach (['abc', '99999999999999999999'] as $id) {
                $status = $this->getJson("/api/v1/{$resource}/{$id}")->getStatusCode();
                $this->assertLessThan(500, $status, "GET /api/v1/{$resource}/{$id} answered {$status}");
            }
        }

        foreach (['leave-requests', 'overtime-requests', 'attendance-corrections', 'resignations'] as $resource) {
            $this->assertLessThan(500, $this->putJson("/api/v1/{$resource}/abc", ['reason' => 'x'])->getStatusCode(), "PUT {$resource}/abc");
            $this->assertLessThan(500, $this->deleteJson("/api/v1/{$resource}/abc")->getStatusCode(), "DELETE {$resource}/abc");
        }
    }

    /**
     * The approval endpoint has the same untyped route segment: /approvals/leave/abc/process is a 500.
     */
    public function test_approval_process_with_a_non_numeric_id_is_not_a_server_error(): void
    {
        Sanctum::actingAs($this->approverUser());

        foreach (['abc', '99999999999999999999', '1.5'] as $id) {
            $status = $this->putJson("/api/v1/approvals/leave/{$id}/process", ['action' => 'approved'])->getStatusCode();
            $this->assertLessThan(500, $status, "PUT /api/v1/approvals/leave/{$id}/process answered {$status}");
        }
    }

    /**
     * whereNumber() only checks digits: an id longer than PHP_INT_MAX still overflows the `int $id` argument.
     */
    public function test_where_number_routes_with_an_overflowing_id_are_not_a_server_error(): void
    {
        Sanctum::actingAs($this->employee('Andi')->user);
        $this->assertLessThan(500, $this->postJson('/api/v1/shift-swaps/99999999999999999999/accept')->getStatusCode(), 'shift-swaps accept');
        $this->assertLessThan(500, $this->postJson('/api/v1/shift-swaps/99999999999999999999/cancel')->getStatusCode(), 'shift-swaps cancel');

        Sanctum::actingAs($this->admin());
        $this->assertLessThan(500, $this->postJson('/api/v1/team/freelancers/99999999999999999999/end')->getStatusCode(), 'team freelancer end');
    }

    /**
     * `trim((string) $request->query('search'))` on ?search[]=x raises "Array to string conversion" → 500.
     */
    public function test_array_search_query_on_team_and_account_lists_is_not_a_server_error(): void
    {
        Sanctum::actingAs($this->admin());

        foreach (['/api/v1/team/workers?search[]=x', '/api/v1/team/freelancers?search[]=x', '/api/v1/client/accounts?search[]=x'] as $url) {
            $status = $this->getJson($url)->getStatusCode();
            $this->assertLessThan(500, $status, "GET {$url} answered {$status}");
        }
    }

    /**
     * `end_date => after_or_equal:start_date` parses the other field with Carbon; an array start_date makes the
     * validator itself throw a TypeError, so the leave form answers 500 instead of a 422 on start_date.
     */
    public function test_leave_request_with_an_array_start_date_is_a_validation_error(): void
    {
        $andi = $this->employee('Andi');
        Sanctum::actingAs($andi->user);

        $this->postJson('/api/v1/leave-requests', ['leave_type' => 'sick', 'start_date' => ['2026-11-20'], 'end_date' => '2026-11-20', 'reason' => 'Demam'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('start_date');

        $leaveId = $this->postJson('/api/v1/leave-requests', ['leave_type' => 'sick', 'start_date' => '2026-11-20', 'end_date' => '2026-11-20', 'reason' => 'Demam'])
            ->assertCreated()
            ->json('data.id');

        $this->putJson("/api/v1/leave-requests/{$leaveId}", ['start_date' => ['2026-11-21'], 'end_date' => '2026-11-21'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('start_date');
    }

    /**
     * The api-login rate limiter casts `email` to string before validation runs: an array email is a 500 on an
     * unauthenticated endpoint.
     */
    public function test_login_with_an_array_email_is_a_validation_error(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => ['a@example.com'], 'password' => 'secret'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    /**
     * The account grant rule runs `(string) $value` on data_scope even when it is an array → 500.
     */
    public function test_client_account_with_an_array_data_scope_is_a_validation_error(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/client/accounts', [
            'name' => 'Akun Baru',
            'email' => 'baru@example.com',
            'roles' => ['Employee'],
            'data_scope' => ['companies'],
            'company_ids' => [$this->company->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('data_scope');
    }

    // ── Findings: oversized values accepted ───────────────────────────────

    /**
     * Policy and outlet integers have no upper bound. 3,000,000,000 does not fit the INT columns of the
     * production MySQL database (a 500 there) and a tolerance of 5,700 years switches lateness off for good.
     */
    public function test_policy_and_outlet_integers_beyond_the_column_range_are_refused(): void
    {
        Sanctum::actingAs($this->admin());

        $this->putJson("/api/v1/client/companies/{$this->company->id}/policy", [
            'late_tolerance_minutes' => 3000000000,
            'require_gps' => false,
            'selfie_mode' => 'none',
            'annual_leave_quota' => 3000000000,
            'default_approval_stages' => 3000000000,
        ])->assertUnprocessable()->assertJsonValidationErrors(['late_tolerance_minutes', 'annual_leave_quota', 'default_approval_stages']);

        $this->postJson("/api/v1/client/companies/{$this->company->id}/locations", [
            'name' => 'Outlet Raksasa',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'radius_meters' => 3000000000,
        ])->assertUnprocessable()->assertJsonValidationErrors('radius_meters');
    }

    /**
     * A worker can file a sick leave from year 1 to year 9999 (3.6 million days): there is no bound on the leave
     * range, unlike overtime (±31 days) or corrections (not in the future).
     */
    public function test_leave_request_spanning_thousands_of_years_is_refused(): void
    {
        Sanctum::actingAs($this->employee('Andi')->user);

        $this->postJson('/api/v1/leave-requests', [
            'leave_type' => 'sick',
            'start_date' => '0001-01-01',
            'end_date' => '9999-12-31',
            'reason' => 'Sakit',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('leave_requests', 0);
    }

    // ── Survived: tenant and ownership boundaries ─────────────────────────────

    public function test_approver_of_another_klien_cannot_process_a_request_and_learns_nothing(): void
    {
        $andi = $this->employee('Andi');
        $leave = $this->fileLeave($andi->user);

        $outsider = $this->approverUser($this->otherCompany, $this->otherDivision);
        Sanctum::actingAs($outsider);

        $this->putJson("/api/v1/approvals/leave/{$leave->id}/process", ['action' => 'approved'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('approve');
        $this->putJson("/api/v1/approvals/leave/{$leave->id}/process", ['action' => 'rejected', 'rejection_note' => 'x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reject');

        $this->assertSame('pending', (string) $leave->fresh()->status);
        $this->getJson("/api/v1/leave-requests/{$leave->id}")->assertForbidden();
    }

    public function test_worker_holding_the_approver_role_cannot_approve_their_own_leave(): void
    {
        $self = $this->employee('Rina', role: 'Approver');
        $self->user->grantDataAccess(DataScope::Companies, companyIds: [$this->company->id]);
        $leave = $this->fileLeave($self->user);

        Sanctum::actingAs($self->user);
        $this->putJson("/api/v1/approvals/leave/{$leave->id}/process", ['action' => 'approved'])->assertUnprocessable();

        $this->assertSame('pending', (string) $leave->fresh()->status);
    }

    public function test_replaying_the_same_approval_is_refused_the_second_time(): void
    {
        $leave = $this->fileLeave($this->employee('Andi')->user);
        Sanctum::actingAs($this->approverUser());

        $this->putJson("/api/v1/approvals/leave/{$leave->id}/process", ['action' => 'approved'])->assertOk();
        $this->putJson("/api/v1/approvals/leave/{$leave->id}/process", ['action' => 'rejected', 'rejection_note' => 'balik'])->assertUnprocessable();

        $this->assertSame('approved', (string) $leave->fresh()->status);
    }

    public function test_workers_cannot_act_on_a_colleagues_shift_swap(): void
    {
        $andi = $this->employee('Andi');
        $budi = $this->employee('Budi');
        $citra = $this->employee('Citra');
        ShiftSchedule::create(['employee_id' => $andi->id, 'date' => self::TUESDAY, 'shift_id' => $this->morning->id, 'is_day_off' => false]);
        ShiftSchedule::create(['employee_id' => $budi->id, 'date' => self::TUESDAY, 'shift_id' => $this->afternoon->id, 'is_day_off' => false]);

        Sanctum::actingAs($andi->user);
        $swapId = $this->postJson('/api/v1/shift-swaps', [
            'type' => ShiftSwapRequest::TYPE_SWAP,
            'requester_date' => self::TUESDAY,
            'counterpart_date' => self::TUESDAY,
            'colleague_type' => 'employee',
            'colleague_id' => $budi->id,
        ])->assertCreated()->json('data.id');

        // The requester cannot accept on the colleague's behalf.
        $this->postJson("/api/v1/shift-swaps/{$swapId}/accept")->assertUnprocessable();

        // A third worker sees nothing.
        Sanctum::actingAs($citra->user);
        $this->postJson("/api/v1/shift-swaps/{$swapId}/accept")->assertNotFound();
        $this->postJson("/api/v1/shift-swaps/{$swapId}/cancel")->assertNotFound();
        $this->getJson("/api/v1/shift-swaps/{$swapId}")->assertNotFound();

        // The colleague cannot cancel the requester's swap.
        Sanctum::actingAs($budi->user);
        $this->postJson("/api/v1/shift-swaps/{$swapId}/cancel")->assertUnprocessable();

        $this->assertSame(ShiftSwapRequest::STATUS_AWAITING_COLLEAGUE, ShiftSwapRequest::findOrFail($swapId)->status);
    }

    public function test_shift_swap_with_a_worker_of_another_klien_is_answered_like_a_missing_one(): void
    {
        $andi = $this->employee('Andi');
        $foreign = $this->employee('Asing', company: $this->otherCompany, division: $this->otherDivision);
        ShiftSchedule::create(['employee_id' => $andi->id, 'date' => self::TUESDAY, 'shift_id' => $this->morning->id, 'is_day_off' => false]);

        Sanctum::actingAs($andi->user);
        $this->postJson('/api/v1/shift-swaps', [
            'type' => ShiftSwapRequest::TYPE_GIVE_AWAY,
            'requester_date' => self::TUESDAY,
            'colleague_type' => ShiftSwapService::workerType($foreign),
            'colleague_id' => $foreign->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('colleague_id');

        $this->assertDatabaseCount('shift_swap_requests', 0);
    }

    public function test_off_schedule_check_in_refuses_another_kliens_cover_shift_and_colleague(): void
    {
        $andi = $this->employee('Andi');
        ShiftSchedule::create(['employee_id' => $andi->id, 'date' => self::MONDAY, 'is_day_off' => true]);
        $foreign = $this->employee('Asing', company: $this->otherCompany, division: $this->otherDivision);
        ShiftSchedule::create(['employee_id' => $foreign->id, 'date' => self::MONDAY, 'shift_id' => $this->otherShift->id, 'is_day_off' => false]);
        $this->travelTo(Carbon::parse(self::MONDAY.' 08:05:00'));

        Sanctum::actingAs($andi->user);
        $this->postJson('/api/v1/attendance/check-in', ['cover_shift_id' => $this->otherShift->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cover_shift_id');

        $this->postJson('/api/v1/attendance/check-in', ['cover_shift_id' => $this->morning->id, 'replaces_worker' => 'employee:'.$foreign->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('replaces_worker');

        $this->postJson('/api/v1/attendance/check-in', ['cover_shift_id' => $this->morning->id, 'replaces_worker' => 'employee:1 OR 1=1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('replaces_worker');

        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_worker_cannot_edit_or_cancel_a_colleagues_overtime(): void
    {
        $andi = $this->employee('Andi');
        $budi = $this->employee('Budi');
        Sanctum::actingAs($andi->user);
        $overtimeId = $this->postJson('/api/v1/overtime-requests', ['date' => self::MONDAY, 'start_time' => '17:00', 'end_time' => '19:00', 'reason' => 'Stok'])
            ->assertCreated()->json('data.id');

        Sanctum::actingAs($budi->user);
        $this->putJson("/api/v1/overtime-requests/{$overtimeId}", ['reason' => 'diubah'])->assertNotFound();
        $this->deleteJson("/api/v1/overtime-requests/{$overtimeId}")->assertNotFound();

        $this->assertSame('Stok', OvertimeRequest::findOrFail($overtimeId)->reason);
    }

    // ── Survived: mass-assignment of protected fields ────────────────────────

    public function test_protected_fields_sent_with_overtime_and_correction_are_ignored(): void
    {
        $andi = $this->employee('Andi');
        Sanctum::actingAs($andi->user);

        $overtimeId = $this->postJson('/api/v1/overtime-requests', [
            'date' => self::MONDAY, 'start_time' => '17:00', 'end_time' => '19:00', 'reason' => 'Stok',
            'status' => 'approved', 'current_step' => 99, 'duration_minutes' => 9999, 'actual_duration_minutes' => 9999,
            'employee_id' => 999,
        ])->assertCreated()->json('data.id');

        $overtime = OvertimeRequest::findOrFail($overtimeId);
        $this->assertSame('pending', (string) $overtime->status);
        $this->assertSame(120, (int) $overtime->duration_minutes);
        $this->assertNull($overtime->actual_duration_minutes);
        $this->assertSame($andi->id, (int) $overtime->employee_id);

        $this->putJson("/api/v1/overtime-requests/{$overtimeId}", ['status' => 'approved', 'actual_duration_minutes' => 600, 'reason' => 'Stok opname'])->assertOk();
        $overtime->refresh();
        $this->assertSame('pending', (string) $overtime->status);
        $this->assertNull($overtime->actual_duration_minutes);

        // attendance_id is a real (optional) field since WP12: one that is not the worker's own row is refused.
        $this->postJson('/api/v1/attendance-corrections', [
            'date' => '2026-11-14', 'corrected_check_in' => '08:00', 'reason' => 'Lupa absen', 'attendance_id' => 999,
        ])->assertUnprocessable()->assertJsonValidationErrors('attendance_id');

        $correctionId = $this->postJson('/api/v1/attendance-corrections', [
            'date' => '2026-11-14', 'corrected_check_in' => '08:00', 'reason' => 'Lupa absen',
            'status' => 'approved', 'origin' => 'admin', 'before_values' => ['status' => 'x'], 'freelancer_id' => 1,
        ])->assertCreated()->json('data.id');

        $correction = AttendanceCorrection::findOrFail($correctionId);
        $this->assertSame('pending', (string) $correction->status);
        $this->assertSame('worker', $correction->origin);
        $this->assertNull($correction->before_values);
        $this->assertNull($correction->freelancer_id);
        $this->assertSame($andi->id, (int) $correction->employee_id);
    }

    public function test_admin_cannot_escalate_an_account_to_admin_pic_or_superadmin_or_grant_another_kliens_company(): void
    {
        Sanctum::actingAs($this->admin());

        foreach (['Superadmin', 'PIC', 'Admin'] as $role) {
            $this->postJson('/api/v1/client/accounts', [
                'name' => 'Naik Pangkat', 'email' => strtolower($role).'@example.com', 'roles' => [$role],
                'data_scope' => DataScope::Companies->value, 'company_ids' => [$this->company->id],
            ])->assertUnprocessable()->assertJsonValidationErrors('roles.0');
        }

        $this->postJson('/api/v1/client/accounts', [
            'name' => 'Lintas Klien', 'email' => 'lintas@example.com', 'roles' => ['Employee'],
            'data_scope' => DataScope::Companies->value, 'company_ids' => [$this->otherCompany->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('data_scope');

        $this->assertDatabaseMissing('users', ['email' => 'lintas@example.com']);
    }

    // ── Survived: malformed payloads already answered with 422 ────────────────

    public function test_malformed_payloads_on_worker_endpoints_are_validation_errors(): void
    {
        Sanctum::actingAs($this->employee('Andi')->user);

        $this->postJson('/api/v1/attendance/check-in', ['cover_shift_id' => ['x']])->assertUnprocessable()->assertJsonValidationErrors('cover_shift_id');
        $this->postJson('/api/v1/attendance/check-in', ['cover_shift_id' => '99999999999999999999'])->assertUnprocessable()->assertJsonValidationErrors('cover_shift_id');
        $this->postJson('/api/v1/attendance/check-in', ['cover_shift_id' => -1])->assertUnprocessable()->assertJsonValidationErrors('cover_shift_id');
        $this->postJson('/api/v1/attendance/check-in', ['latitude' => 'NaN', 'longitude' => 'INF'])->assertUnprocessable();
        $this->postJson('/api/v1/attendance/check-in', ['is_mocked' => ['x']])->assertUnprocessable()->assertJsonValidationErrors('is_mocked');
        $this->postJson('/api/v1/shift-swaps', ['type' => ['swap'], 'requester_date' => '2026-02-30', 'colleague_type' => 'employee', 'colleague_id' => '99999999999999999999'])
            ->assertUnprocessable()->assertJsonValidationErrors(['type', 'requester_date', 'colleague_id']);
        $this->postJson('/api/v1/overtime-requests', ['date' => self::MONDAY, 'start_time' => '17:00', 'end_time' => '19:00', 'reason' => str_repeat('x', 1001)])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->putJson('/api/v1/approvals/leave/1/process', ['action' => ['approved'], 'current_step' => ['x']])
            ->assertUnprocessable()->assertJsonValidationErrors(['action', 'current_step']);
        $this->getJson('/api/v1/shift-swaps/colleagues?date=2026-11-16&search[]=x')->assertUnprocessable()->assertJsonValidationErrors('search');
        $this->getJson('/api/v1/laporan-absensi?start_date=0001-01-01&end_date=9999-12-31')->assertUnprocessable();

        foreach (['/api/v1/attendances?status[]=x&page=-5', '/api/v1/attendances?month[]=1&year=2026', '/api/v1/attendances?page=99999999999999999999'] as $url) {
            $this->getJson($url)->assertOk();
        }

        // SQL-ish and multibyte text is stored verbatim.
        $sqlish = "'); DROP TABLE users; -- 🍜 ñandú";
        $leaveId = $this->postJson('/api/v1/leave-requests', ['leave_type' => 'sick', 'start_date' => '2026-11-20', 'end_date' => '2026-11-20', 'reason' => $sqlish])
            ->assertCreated()->json('data.id');
        $this->assertSame($sqlish, LeaveRequest::findOrFail($leaveId)->reason);
        $this->assertGreaterThan(0, User::query()->count());
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function fileLeave(User $worker): LeaveRequest
    {
        Sanctum::actingAs($worker);
        $id = $this->postJson('/api/v1/leave-requests', ['leave_type' => 'sick', 'start_date' => '2026-11-20', 'end_date' => '2026-11-20', 'reason' => 'Demam'])
            ->assertCreated()->json('data.id');

        return LeaveRequest::findOrFail($id);
    }

    private function makeCompany(string $name, string $code, Tenant $tenant): Company
    {
        $company = new Company(['name' => $name, 'code' => $code, 'is_active' => true]);
        $company->tenant_id = $tenant->id;
        $company->save();

        return $company;
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->forceFill(['tenant_id' => $this->tenant->id])->save();
        $admin->assignRole('Admin');
        $admin->grantDataAccess(DataScope::Companies, companyIds: [$this->company->id]);

        return $admin;
    }

    private function approverUser(?Company $company = null, ?Division $division = null): User
    {
        $company ??= $this->company;
        $division ??= $this->division;
        $approver = User::factory()->create(['is_active' => true]);
        $approver->forceFill(['tenant_id' => $company->tenant_id])->save();
        $approver->assignRole('Approver');
        $this->grantDataAccessTo($approver, $company->id, $division->id);

        return $approver;
    }

    private function employee(string $name, ?Company $company = null, ?Division $division = null, string $role = 'Employee'): Employee
    {
        $company ??= $this->company;
        $division ??= $this->division;
        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['tenant_id' => $company->tenant_id])->save();
        $user->assignRole($role);

        return Employee::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'division_id' => $division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_number' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Kasir'])->id,
            'nip' => 'EMP-'.$user->id,
            'full_name' => $name,
            'join_date' => '2026-01-01',
            'status' => 'permanent',
        ]);
    }
}
