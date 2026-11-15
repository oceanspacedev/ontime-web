<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\DataScope;
use App\Filament\Pages\ApprovalSaya;
use App\Models\ApprovalFlow;
use App\Models\ApprovalRequestStep;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\Resignation;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ApprovalRequestedNotification;
use App\Notifications\ApprovalResolvedNotification;
use App\Services\ApprovalFlowService;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Two approvers acting on the same request at the same time, requests changed after an approver
 * acted, who holds a stage of their own request, and the cost of building approval inboxes.
 */
class ApprovalConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private ApprovalFlowService $service;

    private Tenant $tenant;

    private Company $company;

    private Division $division;

    private User $admin;

    private User $approver;

    private User $secondApprover;

    private User $bod;

    private User $worker;

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
        Notification::fake();

        $this->travelTo(now()->setDate(2026, 11, 16)->setTime(7, 30));

        $this->seed(RoleSeeder::class);

        $this->service = app(ApprovalFlowService::class);
        $this->tenant = Tenant::factory()->create(['name' => 'Klien Uji', 'code' => 'KU']);

        $this->company = new Company(['name' => 'PT Uji', 'code' => 'UJI', 'is_active' => true]);
        $this->company->tenant_id = $this->tenant->id;
        $this->company->save();

        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Operasional', 'is_active' => true]);

        $this->admin = $this->account(['Admin', 'PIC'], 'PIC Uji');
        $this->approver = $this->worker('Approver', 'Andi Approver', DataScope::All);
        $this->secondApprover = $this->worker('Approver', 'Rina Approver', DataScope::All);
        $this->bod = $this->worker('BOD', 'Dewi Direktur', DataScope::All);
        $this->worker = $this->worker('Employee', 'Budi Operasional');
    }

    public function test_reject_lalu_approve_dari_halaman_lama_hanya_menghasilkan_penolakan_tanpa_hari_cuti(): void
    {
        $this->flow('leave', ['Approver']);
        $leave = $this->createLeave($this->worker, '2026-11-23', '2026-11-24');

        $seenByApprover = LeaveRequest::findOrFail($leave->id);
        $seenBySecondApprover = LeaveRequest::findOrFail($leave->id);

        $this->service->rejectStep($seenByApprover, $this->approver, 'Bentrok dengan audit');

        $this->assertAlreadyProcessed(fn () => $this->service->approveStep($seenBySecondApprover, $this->secondApprover));

        $leave->refresh();
        $this->assertSame('rejected', $leave->status);
        $this->assertSame('Bentrok dengan audit', $leave->rejection_reason);
        $this->assertSame(['rejected'], $leave->approvalSteps()->pluck('status')->all());
        $this->assertSame((int) $this->approver->id, (int) $leave->approvalSteps()->value('actioned_by'));
        $this->assertSame(0, Attendance::where('employee_id', $this->worker->employee->id)->where('status', AttendanceStatus::Leave->value)->count());
        Notification::assertSentToTimes($this->worker, ApprovalResolvedNotification::class, 1);
    }

    public function test_approve_lalu_reject_dari_halaman_lama_tetap_disetujui_dan_hari_cuti_tercatat_sekali(): void
    {
        $this->flow('leave', ['Approver']);
        $leave = $this->createLeave($this->worker, '2026-11-23', '2026-11-24');

        $seenByApprover = LeaveRequest::findOrFail($leave->id);
        $seenBySecondApprover = LeaveRequest::findOrFail($leave->id);

        $this->service->approveStep($seenByApprover, $this->approver);

        $this->assertAlreadyProcessed(fn () => $this->service->rejectStep($seenBySecondApprover, $this->secondApprover, 'Terlambat'));

        $leave->refresh();
        $this->assertSame('approved', $leave->status);
        $this->assertNull($leave->rejection_reason);
        $this->assertSame(['approved'], $leave->approvalSteps()->pluck('status')->all());
        $this->assertSame(2, Attendance::where('employee_id', $this->worker->employee->id)->where('status', AttendanceStatus::Leave->value)->count());
        $this->assertSame('approved', $seenByApprover->status, 'The instance the approver acted on is brought up to date.');
        Notification::assertSentToTimes($this->worker, ApprovalResolvedNotification::class, 1);
    }

    public function test_dua_approver_menyetujui_tahap_yang_sama_hanya_satu_yang_tercatat(): void
    {
        $this->flow('overtime', ['Approver', 'BOD']);
        $overtime = $this->createOvertime($this->worker);

        $seenByApprover = OvertimeRequest::findOrFail($overtime->id);
        $seenBySecondApprover = OvertimeRequest::findOrFail($overtime->id);

        $this->service->approveStep($seenByApprover, $this->approver);

        $this->assertAlreadyProcessed(fn () => $this->service->approveStep($seenBySecondApprover, $this->secondApprover));

        $overtime->refresh();
        $this->assertSame('pending', $overtime->status);
        $this->assertSame(2, (int) $overtime->current_step, 'The BOD stage is not skipped by the second approval.');
        $this->assertSame(['approved', 'pending'], $overtime->approvalSteps()->orderBy('step_order')->pluck('status')->all());
        $this->assertSame((int) $this->approver->id, (int) $overtime->approvalSteps()->where('step_order', 1)->value('actioned_by'));
        Notification::assertSentToTimes($this->bod, ApprovalRequestedNotification::class, 1);
    }

    public function test_tap_untuk_tahap_pertama_tidak_memutuskan_tahap_berikutnya(): void
    {
        $this->flow('overtime', ['Approver', 'BOD']);
        $overtime = $this->createOvertime($this->worker);

        // The approver approves stage 1; the PIC tapped on the same stage 1 screen a moment later.
        $this->service->approveStep(OvertimeRequest::findOrFail($overtime->id), $this->approver);

        Sanctum::actingAs($this->admin);
        $this->putJson("/api/v1/approvals/overtime/{$overtime->id}/process", ['action' => 'rejected', 'rejection_note' => 'Tidak perlu', 'current_step' => 1])
            ->assertUnprocessable()
            ->assertJsonPath('message', ApprovalFlowService::ALREADY_PROCESSED_MESSAGE);

        $overtime->refresh();
        $this->assertSame('pending', $overtime->status);
        $this->assertSame(['approved', 'pending'], $overtime->approvalSteps()->orderBy('step_order')->pluck('status')->all(), 'The BOD stage is left for the BOD.');

        // Acting on the stage actually shown still works.
        $this->putJson("/api/v1/approvals/overtime/{$overtime->id}/process", ['action' => 'approved', 'current_step' => 2])->assertOk();
        $this->assertSame('approved', $overtime->fresh()->status);
    }

    public function test_api_mengembalikan_422_saat_pengajuan_diproses_approver_lain_di_tengah_jalan(): void
    {
        $this->flow('leave', ['Approver']);
        $leave = $this->createLeave($this->worker);

        // The other approver rejects right after this request has loaded the leave, before it acts.
        $this->rejectRightAfterFirstLoad($leave);

        Sanctum::actingAs($this->secondApprover);
        $this->putJson("/api/v1/approvals/leave/{$leave->id}/process", ['action' => 'approved'])
            ->assertUnprocessable()
            ->assertJsonPath('message', ApprovalFlowService::ALREADY_PROCESSED_MESSAGE);

        $this->assertSame('rejected', $leave->fresh()->status);
        $this->assertSame(0, Attendance::where('employee_id', $this->worker->employee->id)->count());
    }

    public function test_panel_menampilkan_notifikasi_saat_pengajuan_sudah_diproses(): void
    {
        $this->flow('leave', ['Approver']);
        $leave = $this->createLeave($this->worker);

        $this->actingAs($this->secondApprover);
        $page = Livewire::test(ApprovalSaya::class)->assertSuccessful();

        $this->rejectRightAfterFirstLoad($leave);

        $page->callAction('approve', arguments: ['type' => 'leave', 'id' => $leave->id])
            ->assertNotified('Pengajuan tidak bisa disetujui');

        $this->assertSame('rejected', $leave->fresh()->status);
        $this->assertSame((int) $this->approver->id, (int) $leave->approvalSteps()->value('actioned_by'));
    }

    public function test_pengajuan_yang_sudah_mulai_diproses_tidak_bisa_diubah_atau_dibatalkan_pemohon(): void
    {
        foreach (['leave', 'overtime', 'correction', 'resignation'] as $requestType) {
            $this->flow($requestType, ['Approver', 'BOD']);
        }

        $requests = [
            'leave-requests' => [$this->createLeave($this->worker), ['reason' => 'Ganti alasan']],
            'overtime-requests' => [$this->createOvertime($this->worker), ['reason' => 'Ganti alasan']],
            'attendance-corrections' => [$this->createCorrection($this->worker), ['reason' => 'Ganti alasan']],
            'resignations' => [$this->createResignation($this->worker), ['reason' => 'Ganti alasan']],
        ];

        foreach ($requests as [$request]) {
            $this->service->approveStep($request, $this->approver);
        }

        Sanctum::actingAs($this->worker);

        foreach ($requests as $endpoint => [$request, $changes]) {
            $this->putJson("/api/v1/{$endpoint}/{$request->id}", $changes)
                ->assertUnprocessable()
                ->assertJsonPath('message', 'Pengajuan sudah mulai diproses, tidak bisa diubah.');

            $this->deleteJson("/api/v1/{$endpoint}/{$request->id}")
                ->assertUnprocessable()
                ->assertJsonPath('message', 'Pengajuan sudah mulai diproses, tidak bisa dibatalkan.');

            $this->assertModelExists($request);
            $this->assertNotSame('Ganti alasan', $request->fresh()->reason, "{$endpoint} must keep what the approver saw.");
            $this->assertSame(2, $request->approvalSteps()->count());
        }
    }

    public function test_pengajuan_yang_belum_disentuh_approver_masih_bisa_diubah_dan_dibatalkan(): void
    {
        $this->flow('leave', ['Approver', 'BOD']);
        $leave = $this->createLeave($this->worker);

        Sanctum::actingAs($this->worker);

        $this->putJson("/api/v1/leave-requests/{$leave->id}", ['reason' => 'Acara keluarga'])->assertOk();
        $this->assertSame('Acara keluarga', $leave->fresh()->reason);

        $this->deleteJson("/api/v1/leave-requests/{$leave->id}")->assertOk();
        $this->assertModelMissing($leave);
        $this->assertSame(0, ApprovalRequestStep::where('approvable_id', $leave->id)->where('approvable_type', $leave->getMorphClass())->count());
    }

    public function test_tahap_akhir_cuti_bod_sendiri_diberitahukan_ke_admin(): void
    {
        $this->flow('leave', ['Approver', 'BOD']);
        $leave = $this->createLeave($this->bod);

        $this->service->approveStep($leave, $this->approver);

        $this->assertSame(2, (int) $leave->fresh()->current_step);
        Notification::assertNotSentTo($this->bod, ApprovalRequestedNotification::class);
        Notification::assertSentTo($this->admin, ApprovalRequestedNotification::class);
    }

    public function test_tahap_user_yang_menyebut_pemohon_sendiri_dialihkan_ke_admin(): void
    {
        ApprovalFlow::create([
            'company_id' => $this->company->id,
            'request_type' => 'leave',
            'step_number' => 1,
            'step_order' => 1,
            'name' => 'Persetujuan Direktur',
            'approver_type' => 'user',
            'user_id' => $this->bod->id,
        ]);

        $this->createLeave($this->bod);

        Notification::assertNotSentTo($this->bod, ApprovalRequestedNotification::class);
        Notification::assertSentTo($this->admin, ApprovalRequestedNotification::class);
    }

    public function test_pengingat_tertunda_tanpa_lazy_loading_dengan_banyak_pengajuan(): void
    {
        $this->flow('leave', ['Approver']);
        $this->flow('overtime', ['Approver']);
        $secondWorker = $this->worker('Employee', 'Sari Operasional');

        $this->travelTo(now()->subDays(3));
        $this->createLeave($this->worker, '2026-11-23');
        $this->createLeave($secondWorker, '2026-11-24');
        $this->createOvertime($this->worker);
        $this->createOvertime($secondWorker);
        $this->travelBack();
        $this->travelTo(now()->setDate(2026, 11, 16)->setTime(8, 0));
        Notification::fake();

        Model::preventLazyLoading();

        try {
            $this->assertSame(4, $this->service->remindStalled());
        } finally {
            Model::preventLazyLoading(! app()->isProduction());
        }

        Notification::assertSentToTimes($this->approver, ApprovalRequestedNotification::class, 4);
        Notification::assertSentToTimes($this->secondApprover, ApprovalRequestedNotification::class, 4);
    }

    public function test_antrean_persetujuan_memakai_tahap_yang_sudah_dimuat_tanpa_query_per_pengajuan(): void
    {
        $this->flow('leave', ['Approver']);
        foreach (range(1, 3) as $index) {
            $this->createLeave($this->worker, "2026-11-2{$index}");
        }

        Model::preventLazyLoading();
        DB::enableQueryLog();

        try {
            $pending = $this->service->getPendingRequestsForUser($this->approver, LeaveRequest::class);
        } finally {
            Model::preventLazyLoading(! app()->isProduction());
        }

        $stepQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'approval_request_steps'));
        DB::disableQueryLog();

        $this->assertCount(3, $pending);
        $this->assertCount(1, $stepQueries, 'Steps are eager loaded once, not queried per request.');
    }

    public function test_antrean_kosong_tanpa_query_untuk_pengguna_tanpa_peran_approval(): void
    {
        $this->flow('leave', ['Approver']);
        $this->createLeave($this->worker);

        DB::enableQueryLog();
        $pending = $this->service->getPendingRequestsForUser($this->worker, LeaveRequest::class);
        $leaveQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'leave_requests'));
        DB::disableQueryLog();

        $this->assertCount(0, $pending);
        $this->assertCount(0, $leaveQueries);
    }

    public function test_pengguna_yang_disebut_langsung_di_tahap_tetap_melihat_pengajuannya(): void
    {
        $namedEmployee = $this->worker('Employee', 'Joko Supervisor');

        ApprovalFlow::create([
            'company_id' => $this->company->id,
            'request_type' => 'leave',
            'step_number' => 1,
            'step_order' => 1,
            'name' => 'Persetujuan Supervisor',
            'approver_type' => 'user',
            'user_id' => $namedEmployee->id,
        ]);

        $leave = $this->createLeave($this->worker);

        $this->assertSame([$leave->id], $this->service->getPendingRequestsForUser($namedEmployee, LeaveRequest::class)->pluck('id')->all());
    }

    public function test_antrean_hanya_memuat_pengajuan_badan_usaha_klien_sendiri(): void
    {
        $this->flow('leave', ['Approver']);
        $leave = $this->createLeave($this->worker);

        $otherTenant = Tenant::factory()->create(['name' => 'Klien Lain', 'code' => 'KL']);
        $otherCompany = new Company(['name' => 'PT Lain', 'code' => 'LAI', 'is_active' => true]);
        $otherCompany->tenant_id = $otherTenant->id;
        $otherCompany->save();
        ApprovalFlow::create(['company_id' => $otherCompany->id, 'request_type' => 'leave', 'step_number' => 1, 'step_order' => 1, 'name' => 'Approver', 'approver_type' => 'role', 'approver_role' => 'Approver']);
        $otherDivision = Division::create(['company_id' => $otherCompany->id, 'name' => 'Operasional', 'is_active' => true]);
        $otherWorker = $this->worker('Employee', 'Eko Lain', company: $otherCompany, division: $otherDivision);
        $otherLeave = $this->createLeave($otherWorker);

        DB::enableQueryLog();
        $this->service->getPendingRequestsForUser($this->approver, LeaveRequest::class);
        $leaveQuery = collect(DB::getQueryLog())->first(fn (array $query): bool => str_starts_with($query['query'], 'select * from "leave_requests"'));
        DB::disableQueryLog();

        $this->assertNotNull($leaveQuery);
        $this->assertStringContainsString('company_id', $leaveQuery['query'], 'The Klien filter runs in SQL.');
        $this->assertSame([$leave->id], $this->service->getPendingRequestsForUser($this->approver, LeaveRequest::class)->pluck('id')->all());

        $superadmin = User::factory()->create(['is_active' => true]);
        $superadmin->assignRole('Superadmin');
        $this->assertEqualsCanonicalizing([$leave->id, $otherLeave->id], $this->service->getPendingRequestsForUser($superadmin, LeaveRequest::class)->pluck('id')->all());
    }

    /**
     * The first time $leave is loaded after this call, another approver rejects it, as if they had
     * clicked a moment earlier.
     */
    private function rejectRightAfterFirstLoad(LeaveRequest $leave): void
    {
        $fired = false;

        LeaveRequest::retrieved(function (LeaveRequest $loaded) use ($leave, &$fired): void {
            if ($fired || $loaded->id !== $leave->id) {
                return;
            }

            $fired = true;
            $this->service->rejectStep(LeaveRequest::findOrFail($leave->id), $this->approver, 'Ditolak lebih dulu');
        });
    }

    private function assertAlreadyProcessed(callable $action): void
    {
        try {
            $action();
        } catch (ValidationException $exception) {
            $this->assertSame(ApprovalFlowService::ALREADY_PROCESSED_MESSAGE, collect($exception->errors())->flatten()->first());

            return;
        }

        $this->fail('The second processor must be refused.');
    }

    /**
     * @param  list<string>  $roles
     */
    private function flow(string $requestType, array $roles): void
    {
        foreach ($roles as $index => $role) {
            ApprovalFlow::create([
                'company_id' => $this->company->id,
                'request_type' => $requestType,
                'step_number' => $index + 1,
                'step_order' => $index + 1,
                'name' => "Persetujuan {$role}",
                'approver_type' => 'role',
                'approver_role' => $role,
            ]);
        }
    }

    /**
     * @param  list<string>  $roles
     */
    private function account(array $roles, string $name): User
    {
        $user = User::factory()->create(['name' => $name, 'is_active' => true]);
        $user->forceFill(['tenant_id' => $this->tenant->id])->save();
        $user->assignRole($roles);
        $user->grantDataAccess(DataScope::All);

        return $user;
    }

    private function worker(string $role, string $name, ?DataScope $scope = null, ?Company $company = null, ?Division $division = null): User
    {
        $company ??= $this->company;
        $division ??= $this->division;

        $user = User::factory()->create(['name' => $name, 'is_active' => true]);
        $user->forceFill(['tenant_id' => $company->tenant_id])->save();
        $user->assignRole($role);

        if ($scope) {
            $user->grantDataAccess($scope);
        }

        Employee::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'division_id' => $division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Staff Operasional'])->id,
            'nip' => $company->code.'-'.$user->id,
            'full_name' => $name,
            'join_date' => '2024-01-02',
            'status' => 'active',
        ]);

        return $user;
    }

    private function createLeave(User $worker, string $start = '2026-11-23', ?string $end = null): LeaveRequest
    {
        $end ??= $start;

        $leave = LeaveRequest::create([
            'employee_id' => $worker->employee->id,
            'leave_type' => 'permission',
            'start_date' => $start,
            'end_date' => $end,
            'days_count' => (int) now()->parse($start)->diffInDays(now()->parse($end)) + 1,
            'reason' => 'Izin keluarga',
            'status' => 'pending',
        ]);

        $this->service->generateSteps($leave, 'leave');

        return $leave->fresh();
    }

    private function createOvertime(User $worker): OvertimeRequest
    {
        $overtime = OvertimeRequest::create([
            'employee_id' => $worker->employee->id,
            'date' => '2026-11-16',
            'start_time' => '17:00',
            'end_time' => '20:00',
            'duration_minutes' => 180,
            'reason' => 'Stock opname',
            'status' => 'pending',
        ]);

        $this->service->generateSteps($overtime, 'overtime');

        return $overtime->fresh();
    }

    private function createCorrection(User $worker): AttendanceCorrection
    {
        $correction = AttendanceCorrection::create([
            'employee_id' => $worker->employee->id,
            'date' => '2026-11-13',
            'corrected_check_in' => '2026-11-13 08:00:00',
            'corrected_check_out' => '2026-11-13 17:00:00',
            'reason' => 'Lupa absen',
            'status' => 'pending',
        ]);

        $this->service->generateSteps($correction, 'correction');

        return $correction->fresh();
    }

    private function createResignation(User $worker): Resignation
    {
        $resignation = Resignation::create([
            'employee_id' => $worker->employee->id,
            'resignation_date' => '2026-11-16',
            'last_working_day' => '2026-12-16',
            'reason' => 'Melanjutkan studi',
            'status' => 'pending',
        ]);

        $this->service->generateSteps($resignation, 'resignation');

        return $resignation->fresh();
    }
}
