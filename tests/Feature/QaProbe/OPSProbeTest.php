<?php

namespace Tests\Feature\QaProbe;

use App\Enums\AttendanceStatus;
use App\Enums\DataScope;
use App\Models\ApprovalFlow;
use App\Models\ApprovalRequestStep;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ApprovalRequestedNotification;
use App\Notifications\AttendanceReminderNotification;
use App\Services\ApprovalFlowService;
use App\Services\AttendanceReminderService;
use App\Services\ShiftScheduleSpreadsheet;
use App\Support\SelfieUploadToken;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use RuntimeException;
use Tests\TestCase;

/**
 * Executable probes for the QA-STAGING.md catalog cases OPS-001..OPS-008 that can run locally.
 */
class OPSProbeTest extends TestCase
{
    use RefreshDatabase;

    private const HQ_LAT = -6.1753924;

    private const HQ_LNG = 106.8271528;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Company $companyA;

    private Company $companyB;

    private Division $divisionA;

    private Division $divisionB;

    /** @var list<string> */
    private array $files = [];

    private ?string $readOnlyRoot = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.onesignal.app_id' => 'qa-fake-app',
            'services.onesignal.rest_api_key' => 'qa-fake-key',
            'services.whatsapp_gateway.url' => null,
            'services.whatsapp_gateway.token' => null,
            'filesystems.default' => 'public',
        ]);
        Http::preventStrayRequests();
        Notification::fake();
        Storage::fake('public');

        $this->travelTo(Carbon::parse('2026-11-16 07:30:00'));
        $this->seed(RoleSeeder::class);

        $this->tenantA = Tenant::factory()->create(['name' => 'Klien QA', 'code' => 'QA']);
        $this->tenantB = Tenant::factory()->create(['name' => 'Klien QB', 'code' => 'QB']);
        $this->companyA = $this->company($this->tenantA, 'PT QA', 'QAC');
        $this->companyB = $this->company($this->tenantB, 'PT QB', 'QBC');
        $this->divisionA = Division::create(['company_id' => $this->companyA->id, 'name' => 'OPS-A', 'is_active' => true]);
        $this->divisionB = Division::create(['company_id' => $this->companyB->id, 'name' => 'OPS-B', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        if ($this->readOnlyRoot !== null) {
            exec('chmod -R u+w '.escapeshellarg($this->readOnlyRoot).' && rm -rf '.escapeshellarg($this->readOnlyRoot));
        }

        parent::tearDown();
    }

    // --- OPS-001 Approval notifications, retry and idempotency -----------------------------------

    /**
     * A request whose transaction rolls back must not push "new request" to the approvers: the job would
     * point at a request that does not exist (and, on Redis with after_commit=false, can even run before
     * the commit of a request that does).
     *
     * @see OPS-001
     */
    public function test_ops_001_new_request_notification_waits_for_the_commit(): void
    {
        $this->flow($this->companyA, ['Approver']);
        $approver = $this->staff('Approver', $this->tenantA, DataScope::All);
        [, $worker] = $this->employee($this->companyA, $this->divisionA, 'Pekerja QA');

        try {
            DB::transaction(function () use ($worker): void {
                $leave = LeaveRequest::create($this->leaveData($worker));
                app(ApprovalFlowService::class)->generateSteps($leave, 'leave');

                throw new RuntimeException('rollback after the steps were generated');
            });
        } catch (RuntimeException) {
            // Expected: the request is rolled back.
        }

        $this->assertSame(0, LeaveRequest::query()->count());
        $this->assertSame(0, ApprovalRequestStep::query()->count());
        Notification::assertNotSentTo($approver, ApprovalRequestedNotification::class);
    }

    /**
     * @see OPS-001
     */
    public function test_ops_001_only_the_active_stage_in_scope_is_notified_and_a_retry_does_not_duplicate_steps(): void
    {
        $this->flow($this->companyA, ['Approver', 'BOD']);
        $approverA = $this->staff('Approver', $this->tenantA, DataScope::All);
        $bodA = $this->staff('BOD', $this->tenantA, DataScope::All);
        $approverB = $this->staff('Approver', $this->tenantB, DataScope::All);
        $bodB = $this->staff('BOD', $this->tenantB, DataScope::All);
        [$workerUser, $worker] = $this->employee($this->companyA, $this->divisionA, 'Pekerja QA');

        Sanctum::actingAs($workerUser);
        $id = $this->postJson('/api/v1/leave-requests', [
            'leave_type' => 'sick',
            'start_date' => '2026-11-18',
            'end_date' => '2026-11-18',
            'reason' => 'Sakit',
        ])->assertCreated()->json('data.id');

        $leave = LeaveRequest::query()->findOrFail($id);
        $this->assertSame('pending', $leave->status);
        $this->assertSame(2, $leave->approvalSteps()->count());
        Notification::assertSentToTimes($approverA, ApprovalRequestedNotification::class, 1);
        Notification::assertNotSentTo($bodA, ApprovalRequestedNotification::class);
        Notification::assertNotSentTo($approverB, ApprovalRequestedNotification::class);
        Notification::assertNotSentTo($bodB, ApprovalRequestedNotification::class);

        Sanctum::actingAs($approverA);
        $this->putJson("/api/v1/approvals/leave/{$id}/process", ['action' => 'approved'])->assertOk();
        // A retried request (double tap, network retry) must not settle another stage.
        $this->putJson("/api/v1/approvals/leave/{$id}/process", ['action' => 'approved'])->assertUnprocessable();

        $leave->refresh();
        $this->assertSame('pending', $leave->status);
        $this->assertSame(2, (int) $leave->current_step);
        $this->assertSame(2, $leave->approvalSteps()->count());
        $this->assertSame(['approved', 'pending'], $leave->approvalSteps()->orderBy('step_order')->pluck('status')->all());
        Notification::assertSentToTimes($bodA, ApprovalRequestedNotification::class, 1);
        Notification::assertNotSentTo($bodB, ApprovalRequestedNotification::class);

        Sanctum::actingAs($bodA);
        $this->putJson("/api/v1/approvals/leave/{$id}/process", ['action' => 'approved'])->assertOk();
        $this->assertSame('approved', $leave->fresh()->status);
        $this->assertSame(2, $leave->approvalSteps()->count());
    }

    // --- OPS-002 Reminders --------------------------------------------------------------------------

    /**
     * Accounts of an inactive Klien cannot sign in (User::canSignIn), so they must not be told to check in.
     *
     * @see OPS-002
     */
    public function test_ops_002_workers_of_an_inactive_klien_get_no_attendance_reminders(): void
    {
        $shift = $this->shift($this->companyA, $this->divisionA);
        [$user] = $this->freelancer($this->companyA, $this->divisionA, 'Crew Nonaktif', ['shift_id' => $shift->id]);
        $coordinator = $this->staff('Koordinator', $this->tenantA, DataScope::All);
        $this->tenantA->forceFill(['is_active' => false])->save();
        $this->assertFalse($user->fresh()->canSignIn());

        $this->travelTo(Carbon::parse('2026-11-16 08:11:00'));
        app(AttendanceReminderService::class)->sendDue(now());

        Notification::assertNotSentTo($user, AttendanceReminderNotification::class);
        Notification::assertNotSentTo($coordinator, AttendanceReminderNotification::class);
    }

    /**
     * @see OPS-002
     */
    public function test_ops_002_reminders_stay_inside_the_klien_do_not_repeat_and_never_write_attendance(): void
    {
        $shiftA = $this->shift($this->companyA, $this->divisionA);
        $shiftB = $this->shift($this->companyB, $this->divisionB);
        [$crewA] = $this->freelancer($this->companyA, $this->divisionA, 'Crew QA', ['shift_id' => $shiftA->id]);
        [$crewB] = $this->freelancer($this->companyB, $this->divisionB, 'Crew QB', ['shift_id' => $shiftB->id]);
        [$checkedIn, $checkedInWorker] = $this->freelancer($this->companyA, $this->divisionA, 'Sudah Absen', ['shift_id' => $shiftA->id]);
        [$dayOff, $dayOffWorker] = $this->freelancer($this->companyA, $this->divisionA, 'Libur', ['shift_id' => $shiftA->id]);
        [$ended] = $this->freelancer($this->companyA, $this->divisionA, 'Kontrak Selesai', ['shift_id' => $shiftA->id, 'status' => 'completed']);
        Attendance::create(['freelancer_id' => $checkedInWorker->id, 'date' => '2026-11-16', 'check_in' => '2026-11-16 07:55:00', 'status' => AttendanceStatus::OnTime]);
        ShiftSchedule::create(['freelancer_id' => $dayOffWorker->id, 'date' => '2026-11-16', 'is_day_off' => true]);
        $coordinatorA = $this->staff('Koordinator', $this->tenantA, DataScope::All);
        $coordinatorB = $this->staff('Koordinator', $this->tenantB, DataScope::All);
        $attendanceRows = Attendance::query()->count();

        $this->travelTo(Carbon::parse('2026-11-16 08:11:00'));
        app(AttendanceReminderService::class)->sendDue(now());
        $again = app(AttendanceReminderService::class)->sendDue(now());

        $this->assertSame(0, array_sum($again), 'A second cycle in the same window sends nothing.');
        Notification::assertSentToTimes($crewA, AttendanceReminderNotification::class, 1);
        Notification::assertSentToTimes($crewB, AttendanceReminderNotification::class, 1);
        Notification::assertNotSentTo($checkedIn, AttendanceReminderNotification::class);
        Notification::assertNotSentTo($dayOff, AttendanceReminderNotification::class);
        Notification::assertNotSentTo($ended, AttendanceReminderNotification::class);
        Notification::assertSentTo($coordinatorA, AttendanceReminderNotification::class, fn (AttendanceReminderNotification $n): bool => str_contains($n->body, 'Crew QA') && ! str_contains($n->body, 'Crew QB'));
        Notification::assertSentTo($coordinatorB, AttendanceReminderNotification::class, fn (AttendanceReminderNotification $n): bool => str_contains($n->body, 'Crew QB') && ! str_contains($n->body, 'Crew QA'));
        $this->assertSame($attendanceRows, Attendance::query()->count(), 'Reminders never create attendance.');
    }

    // --- OPS-003 Storage failure ------------------------------------------------------------------

    /**
     * The web selfie upload writes base64 photos with Storage::put() and ignores its result: on a disk that
     * refuses the write without throwing it answers success with a token for a file that does not exist,
     * and the check-in that uses the token is saved with a missing mandatory photo.
     *
     * @see OPS-003
     */
    public function test_ops_003_web_selfie_upload_does_not_report_success_when_storage_refuses_the_write(): void
    {
        $this->useBrokenDisk(throw: false);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('upload.selfie'), [
            'photo_base64' => 'data:image/png;base64,'.base64_encode($this->pngBytes()),
        ]);

        if ($response->json('success') === true) {
            $path = SelfieUploadToken::resolve((string) $response->json('token'), $user);
            $this->assertTrue(Storage::disk('broken')->exists($path), "Upload answered success but {$path} was never written.");
        }

        $this->assertNotSame(200, $response->status(), 'A refused write must not answer success.');
    }

    /**
     * @see OPS-003
     */
    public function test_ops_003_web_multipart_selfie_upload_fails_cleanly_when_storage_refuses_the_write(): void
    {
        $this->useBrokenDisk(throw: false);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('upload.selfie'), [
            'photo' => UploadedFile::fake()->image('selfie.jpg', 200, 200),
        ], ['Accept' => 'application/json'])
            ->assertStatus(500)
            ->assertJsonPath('success', false);
    }

    /**
     * @see OPS-003
     */
    public function test_ops_003_leave_attachment_storage_failure_leaves_no_request_and_no_approval_push(): void
    {
        $this->flow($this->companyA, ['Approver']);
        $approver = $this->staff('Approver', $this->tenantA, DataScope::All);
        [$workerUser] = $this->employee($this->companyA, $this->divisionA, 'Pekerja QA');
        $this->useBrokenDisk(throw: false);

        Sanctum::actingAs($workerUser);
        $this->post('/api/v1/leave-requests', [
            'leave_type' => 'sick',
            'start_date' => '2026-11-18',
            'end_date' => '2026-11-18',
            'reason' => 'Sakit',
            'attachment' => UploadedFile::fake()->image('surat.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(503);

        $this->assertSame(0, LeaveRequest::query()->count());
        Notification::assertNotSentTo($approver, ApprovalRequestedNotification::class);
    }

    /**
     * @see OPS-003
     */
    public function test_ops_003_check_out_storage_failure_keeps_the_record_open_and_a_retry_does_not_duplicate(): void
    {
        $company = Company::create(['name' => 'PT Foto', 'code' => 'FTO', 'is_active' => true, 'latitude' => self::HQ_LAT, 'longitude' => self::HQ_LNG]);
        $company->forceFill(['tenant_id' => $this->tenantA->id])->save();
        $company->policy()->update(['require_gps' => true, 'require_photo' => true, 'require_face_recognition' => false, 'require_liveness' => false]);
        $division = Division::create(['company_id' => $company->id, 'name' => 'Foto', 'is_active' => true]);
        [$user, $employee] = $this->employee($company, $division, 'Pekerja Foto');

        $this->travelTo(Carbon::parse('2026-11-16 17:05:00'));
        $attendance = Attendance::create(['employee_id' => $employee->id, 'date' => '2026-11-16', 'check_in' => '2026-11-16 08:00:00', 'status' => AttendanceStatus::OnTime, 'late_minutes' => 0]);
        $form = fn (): array => [
            'latitude' => (string) self::HQ_LAT,
            'longitude' => (string) self::HQ_LNG,
            'accuracy' => '10',
            'is_mocked' => '0',
            'photo' => UploadedFile::fake()->image('check-out.jpg', 640, 640),
        ];

        $this->useBrokenDisk(throw: false);
        Sanctum::actingAs($user);
        $this->post('/api/v1/attendance/check-out', $form(), ['Accept' => 'application/json'])->assertStatus(503);
        $this->assertNull($attendance->fresh()->check_out);
        $this->assertNull($attendance->fresh()->check_out_photo);

        config(['filesystems.default' => 'public']);
        $this->post('/api/v1/attendance/check-out', $form(), ['Accept' => 'application/json'])->assertOk();

        $this->assertSame(1, Attendance::query()->where('employee_id', $employee->id)->count());
        $saved = $attendance->fresh();
        $this->assertNotNull($saved->check_out);
        Storage::disk('public')->assertExists($saved->check_out_photo);
    }

    // --- OPS-006 Schedule import ------------------------------------------------------------------

    /**
     * A date column the importer cannot read is dropped without a word: the cells under it are never saved
     * and the import reports "Jadwal diimpor" with no issue.
     *
     * @see OPS-006
     */
    public function test_ops_006_an_unreadable_date_column_is_reported_not_silently_skipped(): void
    {
        // D4: an import never changes a day already gone, so the imported week lies ahead.
        $this->travelTo(Carbon::parse('2026-11-01 07:30:00'));
        $admin = $this->staff('Admin', $this->tenantA, DataScope::All);
        $shift = $this->shift($this->companyA, $this->divisionA, ['code' => 'P']);
        [, $crew] = $this->freelancer($this->companyA, $this->divisionA, 'Crew QA', ['freelancer_number' => 'FL-01']);

        $path = $this->workbook(['Tanpa Outlet' => [
            ['ID Pekerja', 'Nama', 'Jenis', '2026-11-02', '30-02-2026'],
            ['FL-01', 'Crew QA', 'Freelancer', 'P', 'P'],
        ]]);

        $result = app(ShiftScheduleSpreadsheet::class)->import($admin, $this->companyA, $path);

        $this->assertSame(1, ShiftSchedule::query()->where('freelancer_id', $crew->id)->where('shift_id', $shift->id)->count());
        $this->assertGreaterThan(0, $result['issue_count'], 'The "30-02-2026" column with a filled cell was skipped without any issue.');
    }

    /**
     * @see OPS-006
     */
    public function test_ops_006_mixed_rows_import_partially_with_a_reason_per_row_and_a_retry_does_not_duplicate(): void
    {
        // D4: an import never changes a day already gone, so the imported week lies ahead.
        $this->travelTo(Carbon::parse('2026-11-01 07:30:00'));
        $admin = $this->staff('Admin', $this->tenantA, DataScope::All);
        $shiftA = $this->shift($this->companyA, $this->divisionA, ['code' => 'P']);
        $this->shift($this->companyB, $this->divisionB, ['code' => 'QB', 'name' => 'Shift QB']);
        [, $crewA] = $this->freelancer($this->companyA, $this->divisionA, 'Crew QA', ['freelancer_number' => 'FL-01']);
        [, $crewB] = $this->freelancer($this->companyB, $this->divisionB, 'Crew QB', ['freelancer_number' => 'FL-QB']);

        $path = $this->workbook(['Tanpa Outlet' => [
            ['ID Pekerja', 'Nama', 'Jenis', '2026-11-02', '2026-11-03'],
            ['FL-01', 'Crew QA', 'Freelancer', 'P', 'QB'],
            ['FL-QB', 'Crew QB', 'Freelancer', 'P', 'P'],
            ['FL-01', 'Crew QA', 'Freelancer', 'OFF', ''],
        ]]);

        $first = app(ShiftScheduleSpreadsheet::class)->import($admin, $this->companyA, $path);

        $this->assertSame(1, $first['created']);
        $this->assertSame(3, $first['issue_count'], implode(' | ', $first['issues']));
        $this->assertSame([[$shiftA->id, false]], ShiftSchedule::query()->where('freelancer_id', $crewA->id)->get()->map(fn (ShiftSchedule $s): array => [$s->shift_id, $s->is_day_off])->all());
        $this->assertFalse(ShiftSchedule::query()->where('freelancer_id', $crewB->id)->exists(), 'Another Klien\'s worker is never scheduled.');

        $second = app(ShiftScheduleSpreadsheet::class)->import($admin, $this->companyA, $path);

        $this->assertSame([0, 0, 0, 1], [$second['created'], $second['updated'], $second['deleted'], $second['unchanged']]);
        $this->assertSame(1, ShiftSchedule::query()->count());
    }

    // --- OPS-008 Secrets in technical evidence -----------------------------------------------------

    /**
     * @see OPS-008
     */
    public function test_ops_008_failed_logins_and_gateway_failures_never_log_the_password_or_the_code(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.' '.json_encode($event->context);
        });

        $user = User::factory()->create(['email' => 'qa-login@contoh.test', 'password' => bcrypt('Rahasia-Benar-123')]);
        $user->assignRole('Employee');
        $this->postJson('/api/v1/auth/login', ['email' => 'qa-login@contoh.test', 'password' => 'Rahasia-Salah-987'])->assertUnauthorized();

        config(['services.whatsapp_gateway.url' => 'https://wag-mati.contoh.test', 'services.whatsapp_gateway.token' => 'qa-gateway-token']);
        Http::fake(['https://wag-mati.contoh.test/*' => Http::response(['error' => 'down'], 500)]);
        $this->employee($this->companyA, $this->divisionA, 'Pekerja OTP', ['phone' => '081234567890']);
        $this->postJson('/api/v1/auth/otp/request', ['channel' => 'whatsapp', 'phone' => '081234567890'])->assertStatus(503);

        $sent = collect(Http::recorded())->last()[0] ?? null;
        preg_match('/\*(\d{6})\*/', (string) ($sent['message']['text'] ?? ''), $matches);
        $code = $matches[1] ?? null;
        $this->assertNotNull($code, 'The gateway request carried a code.');

        $all = implode("\n", $logged);
        $this->assertStringNotContainsString('Rahasia-Salah-987', $all);
        $this->assertStringNotContainsString('Rahasia-Benar-123', $all);
        $this->assertStringNotContainsString($code, $all);
        $this->assertStringNotContainsString('qa-gateway-token', $all);
    }

    // --- Helpers ----------------------------------------------------------------------------------

    private function company(Tenant $tenant, string $name, string $code): Company
    {
        $company = new Company(['name' => $name, 'code' => $code, 'is_active' => true]);
        $company->tenant_id = $tenant->id;
        $company->save();

        return $company;
    }

    private function staff(string $role, Tenant $tenant, DataScope $scope): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['tenant_id' => $tenant->id])->save();
        $user->assignRole($role);
        $user->grantDataAccess($scope);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: User, 1: Employee}
     */
    private function employee(Company $company, Division $division, string $name, array $attributes = []): array
    {
        $user = User::factory()->create(['name' => $name, 'is_active' => true]);
        $user->forceFill(['tenant_id' => $company->tenant_id])->save();
        $user->assignRole('Employee');

        $employee = Employee::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'division_id' => $division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Staff Operasional'])->id,
            'nip' => $company->code.'-'.$user->id,
            'full_name' => $name,
            'join_date' => '2024-01-02',
            'status' => 'active',
            ...$attributes,
        ]);

        return [$user, $employee];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: User, 1: Freelancer}
     */
    private function freelancer(Company $company, Division $division, string $name, array $attributes = []): array
    {
        $user = User::factory()->create(['name' => $name, 'is_active' => true]);
        $user->forceFill(['tenant_id' => $company->tenant_id])->save();
        $user->assignRole('Freelancer');

        $freelancer = Freelancer::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'division_id' => $division->id,
            'full_name' => $name,
            'status' => 'active',
            ...$attributes,
        ]);

        return [$user, $freelancer];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function shift(Company $company, Division $division, array $attributes = []): Shift
    {
        return Shift::create([
            'company_id' => $company->id,
            'division_id' => $division->id,
            'name' => 'Pagi',
            'start_time' => '08:00',
            'end_time' => '17:00',
            'late_tolerance_minutes' => 10,
            ...$attributes,
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
                'name' => $role,
                'approver_type' => 'role',
                'approver_role' => $role,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function leaveData(Employee $worker): array
    {
        return [
            'employee_id' => $worker->id,
            'leave_type' => 'sick',
            'start_date' => '2026-11-18',
            'end_date' => '2026-11-18',
            'days_count' => 1,
            'reason' => 'Sakit',
            'status' => 'pending',
        ];
    }

    /**
     * A disk that refuses every write, like a full volume or an object store rejecting the PUT: a local
     * disk on a read-only directory, so the adapter is built fine and only the write fails.
     */
    private function useBrokenDisk(bool $throw): void
    {
        $this->readOnlyRoot ??= sys_get_temp_dir().'/ops-probe-ro-'.uniqid();

        // The upload directories exist but refuse new files, so Flysystem reports UnableToWriteFile (what a
        // full disk or a rejected object PUT gives) instead of failing to create the directory.
        foreach (['attendance-photos', 'attendance/photos', 'attendance/liveness', 'leave-requests/attachments'] as $directory) {
            if (! is_dir("{$this->readOnlyRoot}/{$directory}")) {
                mkdir("{$this->readOnlyRoot}/{$directory}", 0755, true);
            }

            chmod("{$this->readOnlyRoot}/{$directory}", 0555);
        }

        config([
            'filesystems.disks.broken' => ['driver' => 'local', 'root' => $this->readOnlyRoot, 'throw' => $throw, 'url' => 'http://localhost/broken', 'visibility' => 'public'],
            'filesystems.default' => 'broken',
        ]);
    }

    /**
     * @param  array<string, list<list<string>>>  $sheets
     */
    private function workbook(array $sheets): string
    {
        $path = $this->files[] = tempnam(sys_get_temp_dir(), 'opsgrid').'.xlsx';
        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $first = true;

        foreach ($sheets as $name => $rows) {
            $sheet = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName($name);
            $first = false;

            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues($row));
            }
        }

        $writer->close();

        return $path;
    }

    private function pngBytes(): string
    {
        $image = imagecreatetruecolor(8, 8);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
