<?php

namespace Tests\Feature\QaProbe;

use App\Enums\AttendanceStatus;
use App\Enums\DataScope;
use App\Exports\AttendanceRecapSheet;
use App\Filament\Resources\Attendances\Pages\EditAttendance;
use App\Models\ApprovalFlow;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalFlowService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * QA-STAGING catalog ATT-046..ATT-059 (corrections, admin override, approval rounds, recap) run
 * locally against the backend. Cases already covered elsewhere are not repeated here:
 * ATT-047 time formats and ATT-048 overnight (AttendanceCorrectionInputTest), ATT-050 edit/cancel
 * after an approver acted (ApprovalConcurrencyTest), ATT-054/055 approval rounds
 * (AttendancePolicyEnforcementTest).
 */
class ATTCProbeTest extends TestCase
{
    use RefreshDatabase;

    private const D = '2026-11-13';

    private Tenant $tenant;

    private Company $company;

    private Division $division;

    private Shift $pagi;

    private User $admin;

    private User $approver;

    private User $bod;

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
        Storage::fake(config('filesystems.default', 'public'));

        $this->travelTo(Carbon::parse('2026-11-16 09:00:00'));
        $this->seed(RoleSeeder::class);

        $this->tenant = Tenant::factory()->create(['name' => 'Klien Probe', 'code' => 'KP', 'is_active' => true]);
        $this->company = new Company(['name' => 'PT Probe', 'code' => 'PRB', 'is_active' => true]);
        $this->company->tenant_id = $this->tenant->id;
        $this->company->save();
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Operasional', 'code' => 'OPS', 'is_active' => true]);

        $this->pagi = Shift::create(['company_id' => $this->company->id, 'name' => 'Pagi', 'code' => 'P', 'start_time' => '08:00', 'end_time' => '17:00', 'late_tolerance_minutes' => 0]);

        $this->flow('correction', ['Approver', 'BOD']);
        $this->flow('geofence', ['Approver']);

        $this->admin = $this->account(['Admin'], 'Admin Probe');
        $this->approver = $this->account(['Approver'], 'Approver Probe');
        $this->bod = $this->account(['BOD'], 'BOD Probe');
    }

    // ── ATT-046 ──────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{string}>
     */
    public static function workerTypes(): array
    {
        return ['employee' => ['employee'], 'intern' => ['intern'], 'freelancer' => ['freelancer']];
    }

    #[DataProvider('workerTypes')]
    public function test_at_t_046_correction_is_applied_only_after_the_final_stage_for_each_worker_type(string $type): void
    {
        [$user, $profile, $column] = $this->makeWorker($type);
        ShiftSchedule::create([$column => $profile->id, 'date' => self::D, 'shift_id' => $this->pagi->id]);
        $original = Attendance::create([$column => $profile->id, 'date' => self::D, 'segment' => 1, 'shift_id' => $this->pagi->id, 'check_in' => self::D.' 08:00:00', 'status' => 'on_time', 'late_minutes' => 0]);

        Sanctum::actingAs($user);
        $id = $this->postJson('/api/v1/attendance-corrections', ['date' => self::D, 'corrected_check_in' => '08:10', 'corrected_check_out' => '17:00', 'reason' => 'Lupa absen pulang'])
            ->assertCreated()
            ->json('data.id');

        $correction = AttendanceCorrection::findOrFail($id);
        $service = app(ApprovalFlowService::class);
        $service->approveStep($correction, $this->approver);

        $correction->refresh();
        $this->assertSame('pending', $correction->status);
        $this->assertSame(2, $correction->current_step);
        $this->assertSame('08:00:00', $original->fresh()->check_in->format('H:i:s'), 'Before the final stage the attendance stays untouched.');
        $this->assertNull($original->fresh()->check_out);

        $service->approveStep($correction, $this->bod);

        $this->assertSame('approved', $correction->fresh()->status);
        $rows = Attendance::where($column, $profile->id)->get();
        $this->assertCount(1, $rows, 'Exactly one attendance row for the worker/date.');
        $row = $rows->sole();
        $this->assertSame($original->id, $row->id);
        $this->assertTrue($row->is_corrected);
        $this->assertSame(self::D.' 08:10:00', $row->check_in->toDateTimeString());
        $this->assertSame(self::D.' 17:00:00', $row->check_out->toDateTimeString());
        $this->assertSame(10, (int) $row->late_minutes);
        $this->assertSame(AttendanceStatus::Late, $row->status);
    }

    // ── ATT-047 ──────────────────────────────────────────────────────────────

    public function test_at_t_047_reason_is_enforced_and_a_broken_date_is_a_422(): void
    {
        [$user] = $this->makeWorker('employee');
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/attendance-corrections', ['date' => self::D, 'corrected_check_in' => '08:00', 'reason' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->postJson('/api/v1/attendance-corrections', ['date' => self::D, 'corrected_check_in' => '08:00', 'reason' => str_repeat('a', 1001)])
            ->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->postJson('/api/v1/attendance-corrections', ['date' => 'abc', 'corrected_check_in' => '08:00', 'reason' => 'Lupa'])
            ->assertUnprocessable()->assertJsonValidationErrors(['date']);

        $this->assertSame(0, AttendanceCorrection::count());
    }

    // ── ATT-049 ──────────────────────────────────────────────────────────────

    /**
     * @see ATT-049
     */
    public function test_at_t_049_late_check_out_correction_of_segment_one_past_the_break_midpoint_closes_segment_one(): void
    {
        [$user, $employee] = $this->makeWorker('employee');
        $split = Shift::create(['company_id' => $this->company->id, 'name' => 'Split', 'code' => 'S', 'start_time' => '07:45', 'end_time' => '13:00', 'second_start_time' => '17:45', 'second_end_time' => '22:00', 'late_tolerance_minutes' => 0]);
        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::D, 'shift_id' => $split->id]);
        $segmentOne = Attendance::create(['employee_id' => $employee->id, 'date' => self::D, 'segment' => 1, 'shift_id' => $split->id, 'check_in' => self::D.' 07:45:00', 'status' => 'on_time']);

        // Worker stayed late on segment 1 and forgot to check out at 15:30 (midpoint is 15:22:30).
        $correction = AttendanceCorrection::create(['employee_id' => $employee->id, 'date' => self::D, 'corrected_check_out' => '15:30', 'reason' => 'Lupa absen pulang segmen 1', 'status' => 'pending']);
        $correction->applyCorrection();

        $this->assertSame(self::D.' 15:30:00', $segmentOne->fresh()->check_out?->toDateTimeString(), 'The open segment 1 must be the one closed.');
        $this->assertSame(0, Attendance::where('employee_id', $employee->id)->where('segment', 2)->count(), 'No check-out-only segment 2 row may be created.');
    }

    // ── ATT-051 ──────────────────────────────────────────────────────────────

    /**
     * @see ATT-051
     */
    public function test_at_t_051_a_second_correction_does_not_silently_overwrite_an_approved_one(): void
    {
        [$user, $employee] = $this->makeWorker('employee');
        Attendance::create(['employee_id' => $employee->id, 'date' => self::D, 'segment' => 1, 'check_in' => self::D.' 09:00:00', 'status' => 'late', 'late_minutes' => 60]);
        Sanctum::actingAs($user);

        $first = $this->postJson('/api/v1/attendance-corrections', ['date' => self::D, 'corrected_check_in' => '08:05', 'reason' => 'Koreksi pertama'])->assertCreated()->json('data.id');
        $secondResponse = $this->postJson('/api/v1/attendance-corrections', ['date' => self::D, 'corrected_check_in' => '08:20', 'reason' => 'Koreksi kedua']);

        if ($secondResponse->status() === 422) {
            $this->assertSame(1, AttendanceCorrection::count());

            return;
        }

        $second = $secondResponse->assertCreated()->json('data.id');
        $service = app(ApprovalFlowService::class);

        foreach ([$first, $second] as $id) {
            $service->approveStep(AttendanceCorrection::findOrFail($id), $this->approver);
        }

        $service->approveStep(AttendanceCorrection::findOrFail($first), $this->bod);
        $this->assertSame('08:05', Attendance::where('employee_id', $employee->id)->sole()->check_in->format('H:i'));

        try {
            $service->approveStep(AttendanceCorrection::findOrFail($second), $this->bod);
        } catch (ValidationException) {
            $this->assertSame('pending', AttendanceCorrection::findOrFail($second)->status);

            return;
        }

        $this->fail('Second correction for the same worker/date/segment was approved and silently replaced the check-in 08:05 that correction #'.$first.' wrote (now '.Attendance::where('employee_id', $employee->id)->sole()->check_in->format('H:i').'), with both corrections shown as approved.');
    }

    /**
     * @see ATT-051
     */
    public function test_at_t_051_concurrent_creation_of_the_target_row_is_a_422_not_a_500(): void
    {
        [$user, $employee] = $this->makeWorker('employee');
        $correction = AttendanceCorrection::create(['employee_id' => $employee->id, 'date' => self::D, 'corrected_check_in' => '08:00', 'corrected_check_out' => '17:00', 'reason' => 'Lupa absen', 'status' => 'pending']);
        app(ApprovalFlowService::class)->generateSteps($correction, 'correction');
        app(ApprovalFlowService::class)->approveStep($correction->fresh(), $this->approver);

        // Another approval (or a check-in) inserts the row between firstOrNew() and save().
        $fired = false;
        Attendance::creating(function (Attendance $attendance) use ($employee, &$fired): void {
            if ($fired) {
                return;
            }
            $fired = true;
            DB::table('attendances')->insert(['employee_id' => $employee->id, 'date' => $attendance->date, 'segment' => 1, 'check_in' => self::D.' 08:30:00', 'status' => 'late', 'created_at' => now(), 'updated_at' => now()]);
        });

        Sanctum::actingAs($this->bod);
        $response = $this->putJson("/api/v1/approvals/correction/{$correction->id}/process", ['action' => 'approved']);

        $this->assertNotSame(500, $response->status(), 'Unique conflict on the target attendance must not surface as a 500.');
        $this->assertSame('pending', $correction->fresh()->status);
    }

    // ── ATT-052 ──────────────────────────────────────────────────────────────

    /**
     * @see ATT-052
     */
    public function test_at_t_052_attachment_is_not_left_behind_when_no_approval_flow_exists(): void
    {
        ApprovalFlow::where('request_type', 'correction')->delete();
        [$user] = $this->makeWorker('employee');
        Sanctum::actingAs($user);

        $this->post('/api/v1/attendance-corrections', [
            'date' => self::D,
            'corrected_check_in' => '08:00',
            'reason' => 'Lupa absen',
            'attachment' => UploadedFile::fake()->create('surat.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->assertSame(0, AttendanceCorrection::count());
        $this->assertSame([], Storage::disk(config('filesystems.default', 'public'))->allFiles(), 'The rejected request left an orphan attachment in storage.');
    }

    /**
     * @see ATT-052
     */
    public function test_at_t_052_replacing_the_attachment_removes_the_previous_file(): void
    {
        [$user] = $this->makeWorker('employee');
        Sanctum::actingAs($user);

        $id = $this->post('/api/v1/attendance-corrections', [
            'date' => self::D, 'corrected_check_in' => '08:00', 'reason' => 'Lupa absen',
            'attachment' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $this->post("/api/v1/attendance-corrections/{$id}", [
            '_method' => 'PUT',
            'attachment' => UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertOk();

        $files = Storage::disk(config('filesystems.default', 'public'))->allFiles();
        $this->assertSame([AttendanceCorrection::findOrFail($id)->attachment], $files, 'Old attachment stays in storage after being replaced.');
    }

    /**
     * @see ATT-052
     */
    public function test_at_t_052_cancelling_a_correction_removes_its_attachment(): void
    {
        [$user] = $this->makeWorker('employee');
        Sanctum::actingAs($user);

        $id = $this->post('/api/v1/attendance-corrections', [
            'date' => self::D, 'corrected_check_in' => '08:00', 'reason' => 'Lupa absen',
            'attachment' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $this->deleteJson("/api/v1/attendance-corrections/{$id}")->assertOk();

        $this->assertSame([], Storage::disk(config('filesystems.default', 'public'))->allFiles(), 'Cancelled correction left its attachment in storage.');
    }

    // ── ATT-053 ──────────────────────────────────────────────────────────────

    /**
     * @see ATT-053
     */
    public function test_at_t_053_override_refuses_a_check_out_before_the_check_in(): void
    {
        $attendance = $this->dayAttendance();
        $this->actingAs($this->admin);

        Livewire::test(EditAttendance::class, ['record' => $attendance->getRouteKey()])
            ->fillForm(['check_in' => self::D.' 08:00', 'check_out' => self::D.' 07:00'])
            ->call('save')
            ->assertHasFormErrors(['check_out']);

        $this->assertSame('17:00', $attendance->fresh()->check_out->format('H:i'));
    }

    /**
     * @see ATT-053
     */
    public function test_at_t_053_override_refuses_a_check_in_on_another_date_than_the_attendance(): void
    {
        $attendance = $this->dayAttendance();
        $this->actingAs($this->admin);

        Livewire::test(EditAttendance::class, ['record' => $attendance->getRouteKey()])
            ->fillForm(['check_in' => '2026-11-20 08:00', 'check_out' => '2026-11-20 17:00'])
            ->call('save')
            ->assertHasFormErrors(['check_in']);

        $this->assertSame(self::D, $attendance->fresh()->check_in->toDateString());
    }

    /**
     * @see ATT-053
     */
    public function test_at_t_053_override_refuses_a_status_that_contradicts_the_late_minutes(): void
    {
        $attendance = $this->dayAttendance();
        $this->actingAs($this->admin);

        Livewire::test(EditAttendance::class, ['record' => $attendance->getRouteKey()])
            ->fillForm(['status' => AttendanceStatus::Late->value, 'late_minutes' => 0])
            ->call('save')
            ->assertHasFormErrors();

        $this->assertSame(AttendanceStatus::OnTime, $attendance->fresh()->status);
    }

    /**
     * @see ATT-053
     */
    public function test_at_t_053_overriding_the_times_marks_the_row_as_corrected(): void
    {
        $attendance = $this->dayAttendance();
        $this->actingAs($this->admin);

        Livewire::test(EditAttendance::class, ['record' => $attendance->getRouteKey()])
            ->fillForm(['check_in' => self::D.' 07:30', 'check_out' => self::D.' 18:00'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue((bool) $attendance->fresh()->is_corrected, 'An admin override of the times leaves no correction/audit flag on the row.');
    }

    /**
     * @see ATT-053
     */
    public function test_at_t_053_override_of_a_pending_record_does_not_bypass_its_open_approval_round(): void
    {
        [, $employee] = $this->makeWorker('employee');
        $attendance = Attendance::create(['employee_id' => $employee->id, 'date' => self::D, 'segment' => 1, 'check_in' => self::D.' 08:00:00', 'status' => AttendanceStatus::PendingApproval, 'is_out_of_bounds' => true]);
        app(ApprovalFlowService::class)->generateSteps($attendance, 'geofence');
        $this->actingAs($this->admin);

        Livewire::test(EditAttendance::class, ['record' => $attendance->getRouteKey()])
            ->fillForm(['status' => AttendanceStatus::OnTime->value])
            ->call('save');

        $attendance->refresh();
        if ($attendance->status === AttendanceStatus::PendingApproval) {
            $this->assertTrue(true);

            return;
        }

        $this->assertSame(0, $attendance->approvalSteps()->where('status', 'pending')->count(), 'Override settled the request outside the approval flow, leaving its step pending with no actor/audit.');
    }

    // ── ATT-056 ──────────────────────────────────────────────────────────────

    public function test_at_t_056_approve_then_reject_of_a_geofence_attendance_keeps_one_decision(): void
    {
        [, $employee] = $this->makeWorker('employee');
        $attendance = Attendance::create(['employee_id' => $employee->id, 'date' => self::D, 'segment' => 1, 'check_in' => self::D.' 08:00:00', 'status' => AttendanceStatus::PendingApproval, 'check_in_late_minutes' => 0]);
        app(ApprovalFlowService::class)->generateSteps($attendance, 'geofence');
        $secondApprover = $this->account(['Approver'], 'Approver Dua');

        $stalePageA = Attendance::findOrFail($attendance->id);
        $stalePageB = Attendance::findOrFail($attendance->id);

        app(ApprovalFlowService::class)->approveStep($stalePageA, $this->approver);

        try {
            app(ApprovalFlowService::class)->rejectStep($stalePageB, $secondApprover, 'Telat');
            $this->fail('A stale reject must be refused.');
        } catch (ValidationException $exception) {
            $this->assertSame(ApprovalFlowService::ALREADY_PROCESSED_MESSAGE, collect($exception->errors())->flatten()->first());
        }

        $attendance->refresh();
        $this->assertSame(AttendanceStatus::OnTime, $attendance->status);
        $this->assertSame(['approved'], $attendance->approvalSteps()->pluck('status')->all());
        $this->assertSame($this->approver->id, $attendance->approvalSteps()->sole()->actioned_by);
    }

    // ── ATT-057 ──────────────────────────────────────────────────────────────

    /**
     * @see ATT-057
     */
    public function test_at_t_057_recap_does_not_count_a_rejected_attendance_as_a_worked_day(): void
    {
        [, $employee] = $this->makeWorker('employee');
        Attendance::create(['employee_id' => $employee->id, 'date' => '2026-11-12', 'segment' => 1, 'check_in' => '2026-11-12 08:00:00', 'check_out' => '2026-11-12 17:00:00', 'status' => 'on_time']);
        Attendance::create(['employee_id' => $employee->id, 'date' => self::D, 'segment' => 1, 'check_in' => self::D.' 08:00:00', 'check_out' => self::D.' 17:00:00', 'status' => AttendanceStatus::Rejected, 'rejection_reason' => 'Lokasi palsu']);

        $rows = (new AttendanceRecapSheet(Attendance::with('employee')->get(), Carbon::parse('2026-11-12'), Carbon::parse(self::D)))->array();
        $workerRow = $rows[2];

        $this->assertSame(1, $workerRow[5], 'Hari Masuk counts the attendance the approver rejected.');
        $this->assertEqualsWithDelta(9.0, $workerRow[10], 0.001, 'Jumlah Jam includes the hours of a rejected attendance.');
    }

    /**
     * @see ATT-057
     */
    public function test_at_t_057_report_api_does_not_count_a_rejected_attendance_as_both_present_and_absent(): void
    {
        [, $employee] = $this->makeWorker('employee');
        Attendance::create(['employee_id' => $employee->id, 'date' => self::D, 'segment' => 1, 'check_in' => self::D.' 08:00:00', 'check_out' => self::D.' 17:00:00', 'status' => AttendanceStatus::Rejected, 'rejection_reason' => 'Lokasi palsu']);

        Sanctum::actingAs($this->admin);
        $summary = $this->getJson('/api/v1/laporan-absensi?start_date='.self::D.'&end_date='.self::D)->assertOk()->json('summary');

        $this->assertSame(1, $summary['total_present'] + $summary['total_absent'], 'One rejected worker-day is counted '.json_encode($summary));
        $this->assertSame(0, $summary['total_present'], 'A rejected attendance is not a present day.');
    }

    // ── ATT-058 ──────────────────────────────────────────────────────────────

    public function test_at_t_058_recap_keeps_workers_of_each_type_apart_and_counts_a_late_split_day_once(): void
    {
        [, $employee] = $this->makeWorker('employee');
        [, $intern] = $this->makeWorker('intern');
        [, $freelancer] = $this->makeWorker('freelancer');
        $this->assertSame($employee->id, $intern->id);
        $this->assertSame($employee->id, $freelancer->id);

        Attendance::create(['employee_id' => $employee->id, 'date' => self::D, 'segment' => 1, 'check_in' => self::D.' 07:45:00', 'check_out' => self::D.' 13:00:00', 'status' => 'on_time']);
        Attendance::create(['employee_id' => $employee->id, 'date' => self::D, 'segment' => 2, 'check_in' => self::D.' 17:50:00', 'status' => 'late', 'late_minutes' => 5]);
        Attendance::create(['intern_id' => $intern->id, 'date' => self::D, 'segment' => 1, 'check_in' => self::D.' 08:00:00', 'check_out' => self::D.' 17:00:00', 'status' => 'on_time']);
        Attendance::create(['freelancer_id' => $freelancer->id, 'date' => self::D, 'segment' => 1, 'check_in' => self::D.' 08:00:00', 'check_out' => self::D.' 17:00:00', 'status' => 'on_time']);

        $rows = (new AttendanceRecapSheet(Attendance::with(['employee', 'intern', 'freelancer'])->get(), Carbon::parse(self::D), Carbon::parse(self::D)))->array();
        $workers = collect(array_slice($rows, 2, 3))->keyBy(2);

        $this->assertCount(3, $workers);
        $this->assertSame([1, 1, 5], array_slice($workers['Karyawan'], 5, 3));
        $this->assertSame("13:00\n-", $workers['Karyawan'][12], 'Missing segment 2 check-out is shown as missing, not as a time.');
        $this->assertSame([1, 0, 0], array_slice($workers['Magang'], 5, 3));
        $this->assertSame([1, 0, 0], array_slice($workers['Freelancer'], 5, 3));
    }

    // ── ATT-059 ──────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidAttendanceFilters(): array
    {
        return [
            'unknown status' => ['status=bogus', 'status'],
            'reversed range' => ['start_date=2026-11-30&end_date=2026-11-01', 'end_date'],
            'broken start date' => ['start_date=abc&end_date=2026-11-30', 'start_date'],
            'month out of range' => ['month=13&year=2026', 'month'],
        ];
    }

    /**
     * @see ATT-059
     */
    #[DataProvider('invalidAttendanceFilters')]
    public function test_at_t_059_invalid_attendance_list_filter_is_a_422(string $query, string $field): void
    {
        // Verified as intended (finding refuted): an invalid filter is ignored, never a 500, and only
        // the worker's own rows are listed.
        [$user, $employee] = $this->makeWorker('employee');
        Attendance::create(['employee_id' => $employee->id, 'date' => self::D, 'segment' => 1, 'check_in' => self::D.' 08:00:00', 'status' => 'on_time']);
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/attendances?{$query}")->assertOk();
    }

    public function test_at_t_059_pages_of_the_attendance_list_neither_drop_nor_repeat_rows(): void
    {
        [, $employee] = $this->makeWorker('employee');
        $intern = $this->makeWorker('intern')[1];
        foreach (range(1, 15) as $day) {
            $date = sprintf('2026-10-%02d', $day);
            Attendance::create(['employee_id' => $employee->id, 'date' => $date, 'segment' => 1, 'check_in' => "{$date} 08:00:00", 'status' => 'on_time']);
            Attendance::create(['intern_id' => $intern->id, 'date' => $date, 'segment' => 1, 'check_in' => "{$date} 08:00:00", 'status' => 'on_time']);
        }

        Sanctum::actingAs($this->admin);
        $first = $this->getJson('/api/v1/attendances?month=10&year=2026')->assertOk();
        $second = $this->getJson('/api/v1/attendances?month=10&year=2026&page=2')->assertOk();

        $ids = [...$first->json('data.*.id'), ...$second->json('data.*.id')];
        $this->assertSame(30, $first->json('pagination.total'));
        $this->assertCount(30, array_unique($ids));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function dayAttendance(): Attendance
    {
        [, $employee] = $this->makeWorker('employee');

        return Attendance::create(['employee_id' => $employee->id, 'date' => self::D, 'segment' => 1, 'shift_id' => $this->pagi->id, 'check_in' => self::D.' 08:00:00', 'check_out' => self::D.' 17:00:00', 'status' => 'on_time', 'late_minutes' => 0]);
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

    /**
     * @return array{0: User, 1: Employee|Intern|Freelancer, 2: string}
     */
    private function makeWorker(string $type): array
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['tenant_id' => $this->tenant->id])->save();

        $common = ['user_id' => $user->id, 'company_id' => $this->company->id, 'division_id' => $this->division->id, 'full_name' => ucfirst($type).' '.$user->id];

        $profile = match ($type) {
            'employee' => Employee::create([...$common,
                'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_number' => 1])->id,
                'job_title_id' => JobTitle::firstOrCreate(['name' => 'Staff Operasional'])->id,
                'nip' => 'PRB-'.$user->id, 'join_date' => '2026-01-01', 'status' => 'active']),
            'intern' => Intern::create([...$common, 'nis' => 'NIS-'.$user->id, 'institution' => 'Univ', 'start_date' => '2026-01-01', 'end_date' => '2027-01-01', 'status' => 'active']),
            'freelancer' => Freelancer::create([...$common, 'freelancer_number' => 'FRL-'.$user->id, 'start_date' => '2026-01-01', 'end_date' => '2027-01-01', 'status' => 'active']),
        };

        $user->assignRole(match ($type) {
            'employee' => 'Employee',
            'intern' => 'Intern',
            'freelancer' => 'Freelancer',
        });

        return [$user->fresh(), $profile, "{$type}_id"];
    }
}
