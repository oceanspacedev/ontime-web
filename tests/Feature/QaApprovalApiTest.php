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
use App\Services\ApprovalFlowService;
use App\Services\AttendanceCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Regression tests for the approval and request API findings (cluster A): detail visibility for a named
 * approver, Indonesian 403/404 answers, the stale-stage guard for geofence, process reach before status,
 * the approved-leave day on the today endpoint, leave attachments and stable attendance paging.
 */
class QaApprovalApiTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-11-16';

    private Tenant $tenant;

    private Company $company;

    private Division $operasional;

    private Division $gudang;

    private User $namedApprover;

    private Employee $worker;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp_gateway.url' => null,
            'services.whatsapp_gateway.token' => null,
            'services.face.driver' => 'basic',
            'services.onesignal.app_id' => null,
            'services.onesignal.rest_api_key' => null,
        ]);
        Http::fake();
        Notification::fake();

        foreach (['Superadmin', 'Admin', 'PIC', 'Koordinator', 'Approver', 'BOD', 'Employee', 'Intern', 'Freelancer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->travelTo(Carbon::parse(self::MONDAY.' 08:00:00'));

        $this->tenant = Tenant::factory()->create(['name' => 'Klien A', 'code' => 'KA', 'is_active' => true]);
        $this->company = $this->company($this->tenant, 'PT Uji', 'UJI');
        app(ApprovalFlowService::class)->createDefaultFlows($this->company);
        $this->operasional = Division::create(['company_id' => $this->company->id, 'name' => 'Operasional', 'code' => 'OPS', 'is_active' => true]);
        $this->gudang = Division::create(['company_id' => $this->company->id, 'name' => 'Gudang', 'code' => 'GDG', 'is_active' => true]);

        $this->company->policy()->update([
            'require_gps' => false,
            'require_photo' => false,
            'require_face_recognition' => false,
            'require_liveness' => false,
        ]);
        $this->company->unsetRelation('policy');

        // Divisions-scoped on Operasional, named on the company-wide flows: the worker in Gudang is outside their data access.
        $this->namedApprover = $this->employee('BOD', 'Dewi Direktur', $this->operasional)->user;
        $this->namedApprover->grantDataAccess(DataScope::Divisions, divisionIds: [$this->operasional->id]);
        $this->worker = $this->employee('Employee', 'Budi Gudang', $this->gudang);

        foreach (['leave', 'geofence'] as $type) {
            ApprovalFlow::where('company_id', $this->company->id)->where('request_type', $type)->delete();
            ApprovalFlow::create([
                'company_id' => $this->company->id,
                'request_type' => $type,
                'step_number' => 1,
                'step_order' => 1,
                'name' => 'Persetujuan Direktur',
                'approver_type' => 'user',
                'user_id' => $this->namedApprover->id,
            ]);
        }
    }

    // ── Detail visibility for the named approver ─────────────────────────────

    public function test_named_approver_outside_data_access_can_open_the_leave_they_decide(): void
    {
        $leave = $this->pendingLeave();

        Sanctum::actingAs($this->namedApprover);
        $this->getJson('/api/v1/approvals/pending')->assertOk()->assertJsonPath('leave_requests.0.id', $leave->id);
        $this->getJson("/api/v1/leave-requests/{$leave->id}")->assertOk()->assertJsonPath('data.id', $leave->id);

        $this->putJson("/api/v1/approvals/leave/{$leave->id}/process", ['action' => 'approved', 'current_step' => 1])->assertOk();

        // The screen reloads after the decision; whoever decided a stage may still open it.
        $this->getJson("/api/v1/leave-requests/{$leave->id}")->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_named_approver_outside_data_access_can_open_the_geofence_attendance_they_decide(): void
    {
        $attendance = $this->pendingGeofence();

        Sanctum::actingAs($this->namedApprover);
        $this->getJson("/api/v1/attendances/{$attendance->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $attendance->id)
            ->assertJsonPath('data.current_step', 1)
            ->assertJsonPath('data.total_steps', 1);
    }

    public function test_a_user_without_reach_still_gets_an_indonesian_403_on_the_detail(): void
    {
        $leave = $this->pendingLeave();
        $attendance = $this->pendingGeofence();
        $colleague = $this->employee('Employee', 'Sari Gudang', $this->gudang)->user;

        Sanctum::actingAs($colleague);
        $this->getJson("/api/v1/leave-requests/{$leave->id}")->assertForbidden()->assertJsonPath('message', 'Anda tidak memiliki akses ke pengajuan ini.');
        $this->getJson("/api/v1/attendances/{$attendance->id}")->assertForbidden()->assertJsonPath('message', 'Anda tidak memiliki akses ke presensi ini.');
    }

    public function test_a_missing_or_cancelled_request_answers_an_indonesian_404_without_the_model_name(): void
    {
        Sanctum::actingAs($this->worker->user);

        $answers = [
            '/api/v1/leave-requests/999999' => 'Pengajuan cuti tidak ditemukan atau sudah dibatalkan.',
            '/api/v1/overtime-requests/999999' => 'Pengajuan lembur tidak ditemukan atau sudah dibatalkan.',
            '/api/v1/attendance-corrections/999999' => 'Pengajuan koreksi absensi tidak ditemukan atau sudah dibatalkan.',
            '/api/v1/attendances/999999' => 'Data presensi tidak ditemukan.',
        ];

        foreach ($answers as $url => $message) {
            $response = $this->getJson($url)->assertNotFound()->assertJsonPath('message', $message);
            $this->assertStringNotContainsString('App\\Models', $response->getContent());
        }
    }

    // ── Attendance resource: stage and rejection reason ──────────────────────

    public function test_geofence_process_refuses_a_stale_stage_taken_from_the_detail_payload(): void
    {
        $secondApprover = $this->employee('BOD', 'Rudi Direktur', $this->operasional)->user;
        ApprovalFlow::create([
            'company_id' => $this->company->id,
            'request_type' => 'geofence',
            'step_number' => 2,
            'step_order' => 2,
            'name' => 'Persetujuan Kedua',
            'approver_type' => 'user',
            'user_id' => $secondApprover->id,
        ]);
        $admin = $this->employee('Admin', 'Ani Admin', $this->operasional)->user;
        $admin->grantDataAccess(DataScope::All);
        $attendance = $this->pendingGeofence();

        // The Admin opens the detail at stage 1.
        Sanctum::actingAs($admin);
        $seenStep = $this->getJson("/api/v1/attendances/{$attendance->id}")->assertOk()->json('data.current_step');
        $this->assertSame(1, $seenStep);

        // Meanwhile the stage-1 approver approves it.
        Sanctum::actingAs($this->namedApprover);
        $this->putJson("/api/v1/approvals/geofence/{$attendance->id}/process", ['action' => 'approved', 'current_step' => 1])->assertOk();

        Sanctum::actingAs($admin);
        $this->putJson("/api/v1/approvals/geofence/{$attendance->id}/process", ['action' => 'approved', 'current_step' => $seenStep])
            ->assertUnprocessable()
            ->assertJsonPath('message', ApprovalFlowService::ALREADY_PROCESSED_MESSAGE);

        $this->assertSame(AttendanceStatus::PendingApproval, $attendance->fresh()->status);
        $this->assertSame(2, (int) $attendance->fresh()->current_step);
    }

    public function test_the_worker_sees_why_their_attendance_was_rejected(): void
    {
        $attendance = $this->pendingGeofence();

        Sanctum::actingAs($this->namedApprover);
        $this->putJson("/api/v1/approvals/geofence/{$attendance->id}/process", ['action' => 'rejected', 'rejection_note' => 'Foto tidak jelas'])->assertOk();

        Sanctum::actingAs($this->worker->user);
        $this->getJson("/api/v1/attendances/{$attendance->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'Foto tidak jelas');
    }

    // ── Process: reach before status ─────────────────────────────────────────

    public function test_process_does_not_reveal_status_or_stage_to_a_user_of_another_klien(): void
    {
        $leave = $this->pendingLeave();
        Sanctum::actingAs($this->namedApprover);
        $this->putJson("/api/v1/approvals/leave/{$leave->id}/process", ['action' => 'approved'])->assertOk();
        $pending = $this->pendingLeave();

        $otherTenant = Tenant::factory()->create(['name' => 'Klien B', 'code' => 'KB', 'is_active' => true]);
        $outsider = User::factory()->create(['is_active' => true]);
        $outsider->forceFill(['tenant_id' => $otherTenant->id])->save();
        $outsider->assignRole('Approver');
        $outsider->grantDataAccess(DataScope::All);

        Sanctum::actingAs($outsider);
        // A decided request answers the same as a pending one: no "sudah tidak berstatus pending".
        $this->putJson("/api/v1/approvals/leave/{$leave->id}/process", ['action' => 'approved'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('approve')
            ->assertJsonMissing(['message' => 'Pengajuan ini sudah tidak berstatus pending.']);
        // A wrong stage answers the same as the right one: no "sudah diproses".
        $this->putJson("/api/v1/approvals/leave/{$pending->id}/process", ['action' => 'rejected', 'rejection_note' => 'x', 'current_step' => 5])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reject')
            ->assertJsonMissing(['message' => ApprovalFlowService::ALREADY_PROCESSED_MESSAGE]);

        $this->assertSame('pending', $pending->fresh()->status);
    }

    // ── Today on an approved-leave day ───────────────────────────────────────

    public function test_today_marks_an_approved_leave_day_and_the_liveness_challenge_is_refused(): void
    {
        $this->approvedLeaveToday();

        Sanctum::actingAs($this->worker->user);
        $this->getJson('/api/v1/attendance/today')
            ->assertOk()
            ->assertJsonPath('is_on_leave', true)
            ->assertJsonPath('has_checked_in', false);

        $this->postJson('/api/v1/attendance/liveness-challenge', ['purpose' => 'check_in'])
            ->assertStatus(409)
            ->assertJsonPath('message', AttendanceCheckService::ON_LEAVE_MESSAGE);
    }

    public function test_today_is_not_a_leave_day_when_the_leave_row_lost_its_leave(): void
    {
        Attendance::create([
            'employee_id' => $this->worker->id,
            'date' => today(),
            'status' => AttendanceStatus::Leave,
        ]);

        Sanctum::actingAs($this->worker->user);
        $this->getJson('/api/v1/attendance/today')->assertOk()->assertJsonPath('is_on_leave', false);
    }

    // ── Leave attachments ────────────────────────────────────────────────────

    public function test_leave_attachment_is_not_left_behind_when_no_approval_flow_exists(): void
    {
        $disk = Storage::fake(config('filesystems.default'));
        ApprovalFlow::where('company_id', $this->company->id)->where('request_type', 'leave')->delete();

        Sanctum::actingAs($this->worker->user);
        $this->post('/api/v1/leave-requests', $this->leavePayload(['attachment' => UploadedFile::fake()->create('surat.pdf', 10, 'application/pdf')]), ['Accept' => 'application/json'])
            ->assertUnprocessable();

        $this->assertSame(0, LeaveRequest::count());
        $this->assertSame([], $disk->allFiles());
    }

    public function test_leave_attachment_replaced_or_cancelled_is_removed_and_a_refused_update_keeps_no_new_file(): void
    {
        $disk = Storage::fake(config('filesystems.default'));
        Sanctum::actingAs($this->worker->user);

        $id = $this->post('/api/v1/leave-requests', $this->leavePayload(['attachment' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]), ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data.id');

        $this->post("/api/v1/leave-requests/{$id}", ['_method' => 'PUT', 'attachment' => UploadedFile::fake()->create('b.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertOk();
        $this->assertSame([LeaveRequest::findOrFail($id)->attachment], $disk->allFiles());

        // Refused after the upload was received: the new file is not kept.
        $this->post("/api/v1/leave-requests/{$id}", ['_method' => 'PUT', 'start_date' => '2026-11-25', 'end_date' => '2026-11-24', 'attachment' => UploadedFile::fake()->create('c.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertUnprocessable();
        $this->assertSame([LeaveRequest::findOrFail($id)->attachment], $disk->allFiles());

        $this->deleteJson("/api/v1/leave-requests/{$id}")->assertOk();
        $this->assertSame([], $disk->allFiles());
    }

    // ── Attendance paging ────────────────────────────────────────────────────

    public function test_attendance_list_orders_rows_of_the_same_date_by_segment_then_newest_id(): void
    {
        $admin = $this->employee('Admin', 'Ani Admin', $this->operasional)->user;
        $admin->grantDataAccess(DataScope::All);

        $row = fn (string $name, int $segment): int => Attendance::create([
            'employee_id' => $this->employee('Employee', $name, $this->gudang)->id,
            'date' => today(),
            'segment' => $segment,
            'check_in' => today()->setTime(8, 0),
            'status' => AttendanceStatus::OnTime,
        ])->id;
        $secondSegment = $row('Pekerja Split', 2);
        $older = $row('Pekerja Satu', 1);
        $newer = $row('Pekerja Dua', 1);

        Sanctum::actingAs($admin);
        $listed = $this->getJson('/api/v1/attendances')->assertOk()->json('data.*.id');

        $this->assertSame([$secondSegment, $newer, $older], $listed);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function company(Tenant $tenant, string $name, string $code): Company
    {
        $company = new Company(['name' => $name, 'code' => $code, 'is_active' => true]);
        $company->tenant_id = $tenant->id;
        $company->save();

        return $company;
    }

    private function employee(string $role, string $name, Division $division): Employee
    {
        $user = User::factory()->create(['name' => $name, 'is_active' => true]);
        $user->forceFill(['tenant_id' => $this->tenant->id])->save();
        $user->assignRole($role);
        $user->grantDataAccess(DataScope::Own);

        return Employee::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_number' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Staff Operasional'])->id,
            'nip' => 'UJI-'.$user->id,
            'full_name' => $name,
            'join_date' => '2024-01-02',
            'status' => 'permanent',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function leavePayload(array $overrides = []): array
    {
        return [
            'leave_type' => 'sick',
            'start_date' => '2026-11-20',
            'end_date' => '2026-11-20',
            'reason' => 'Sakit',
            ...$overrides,
        ];
    }

    private function pendingLeave(): LeaveRequest
    {
        $leave = LeaveRequest::create([
            'employee_id' => $this->worker->id,
            'leave_type' => 'sick',
            'start_date' => '2026-11-20',
            'end_date' => '2026-11-20',
            'days_count' => 1,
            'reason' => 'Sakit',
            'status' => 'pending',
        ]);
        app(ApprovalFlowService::class)->generateSteps($leave, 'leave');

        return $leave->fresh();
    }

    private function pendingGeofence(): Attendance
    {
        $attendance = Attendance::create([
            'employee_id' => $this->worker->id,
            'date' => today(),
            'check_in' => today()->setTime(7, 55),
            'is_out_of_bounds' => true,
            'status' => AttendanceStatus::PendingApproval,
            'current_step' => 1,
        ]);
        app(ApprovalFlowService::class)->generateSteps($attendance, 'geofence');

        return $attendance->fresh();
    }

    private function approvedLeaveToday(): void
    {
        LeaveRequest::create([
            'employee_id' => $this->worker->id,
            'leave_type' => 'sick',
            'start_date' => self::MONDAY,
            'end_date' => self::MONDAY,
            'days_count' => 1,
            'reason' => 'Sakit',
            'status' => 'approved',
        ]);
        Attendance::create([
            'employee_id' => $this->worker->id,
            'date' => today(),
            'status' => AttendanceStatus::Leave,
        ]);
    }
}
