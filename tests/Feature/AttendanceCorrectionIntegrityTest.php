<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\DataScope;
use App\Filament\Resources\AttendanceCorrections\Pages\CreateAttendanceCorrection;
use App\Models\ApprovalFlow;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Services\ApprovalFlowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Attendance corrections: no future date or time (BUG-COR-4), no silent settling of an attendance
 * pending or refused by the geofence approver (BUG-COR-3 / BUG-08), lateness judged against the
 * business date of a night shift (BUG-COR-1), and early leave recomputed from the corrected check-out.
 *
 * The clock is frozen at 2026-11-16 10:00 (Asia/Jakarta).
 */
class AttendanceCorrectionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Division $division;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.onesignal.app_id' => null, 'services.whatsapp_gateway.url' => null]);
        Http::fake();
        $this->travelTo(Carbon::parse('2026-11-16 10:00:00'));

        Role::create(['name' => 'Employee']);
        Role::create(['name' => 'Approver']);

        $this->company = Company::create(['name' => 'PT Koreksi', 'code' => 'KRI', 'is_active' => true]);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Outlet', 'is_active' => true]);

        foreach (['correction', 'geofence'] as $type) {
            ApprovalFlow::create(['company_id' => $this->company->id, 'request_type' => $type, 'step_number' => 1, 'step_order' => 1, 'name' => 'Approver', 'approver_type' => 'role', 'approver_role' => 'Approver']);
        }

        $this->approver = User::factory()->create();
        $this->approver->assignRole('Approver');
        $this->approver->grantDataAccess(DataScope::All);
    }

    // ── Future dates and times (BUG-COR-4) ───────────────────────────────────

    public function test_api_refuses_a_future_date_and_accepts_today_and_the_past(): void
    {
        Sanctum::actingAs($this->makeWorker($this->shift('08:00', '17:00')));

        $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-17', 'corrected_check_in' => '08:00', 'reason' => 'Besok'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date' => 'tidak boleh melewati hari ini']);

        $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-16', 'corrected_check_in' => '08:00', 'reason' => 'Hari ini'])
            ->assertCreated();

        $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-15', 'corrected_check_in' => '08:00', 'corrected_check_out' => '17:00', 'reason' => 'Kemarin'])
            ->assertCreated();

        $this->assertSame(2, AttendanceCorrection::count());
    }

    public function test_api_refuses_times_later_than_now(): void
    {
        Sanctum::actingAs($this->makeWorker($this->shift('08:00', '17:00')));

        $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-16', 'corrected_check_in' => '10:30', 'reason' => 'Belum terjadi'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['corrected_check_in' => 'belum terjadi']);

        $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-16', 'corrected_check_in' => '08:00', 'corrected_check_out' => '17:00', 'reason' => 'Pulang belum terjadi'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['corrected_check_out' => 'belum terjadi']);

        $this->assertSame(0, AttendanceCorrection::count());
    }

    public function test_after_midnight_time_of_a_night_shift_is_judged_on_the_next_day(): void
    {
        Sanctum::actingAs($this->makeWorker($this->shift('22:00', '06:00')));

        // Tonight's 00:30 is tomorrow morning: not yet happened.
        $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-16', 'corrected_check_in' => '00:30', 'reason' => 'Malam ini'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['corrected_check_in' => 'belum terjadi']);

        // Last night's 00:30 and 06:00 already happened this morning.
        $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-15', 'corrected_check_in' => '00:30', 'corrected_check_out' => '06:00', 'reason' => 'Semalam'])
            ->assertCreated();
    }

    public function test_update_cannot_move_a_correction_to_a_future_date_or_time(): void
    {
        Sanctum::actingAs($this->makeWorker($this->shift('08:00', '17:00')));

        $id = $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-15', 'corrected_check_in' => '08:00', 'reason' => 'Kemarin'])
            ->assertCreated()
            ->json('data.id');

        $this->putJson("/api/v1/attendance-corrections/{$id}", ['date' => '2026-11-20'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date']);

        $this->putJson("/api/v1/attendance-corrections/{$id}", ['date' => '2026-11-16', 'corrected_check_in' => '11:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['corrected_check_in']);

        $this->assertSame('2026-11-15', AttendanceCorrection::findOrFail($id)->date->toDateString());
    }

    public function test_approving_a_future_dated_legacy_row_is_refused_and_creates_nothing(): void
    {
        $worker = $this->makeWorker($this->shift('08:00', '17:00'));
        $correction = $this->submit($worker, ['date' => '2026-11-20', 'corrected_check_in' => '08:00']);

        try {
            app(ApprovalFlowService::class)->approveStep($correction, $this->approver);
            $this->fail('A future-dated correction must not be approved.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('tidak boleh melewati hari ini', $exception->errors()['approve'][0]);
        }

        $this->assertSame(0, Attendance::count());
        $this->assertSame('pending', $correction->fresh()->status);
    }

    public function test_panel_create_refuses_a_future_date(): void
    {
        $this->actingAs($this->makeWorker($this->shift('08:00', '17:00')));

        Livewire::test(CreateAttendanceCorrection::class)
            ->fillForm(['date' => '2026-11-18', 'reason' => 'Besok', 'corrected_check_in' => '08:00'])
            ->call('create')
            ->assertHasFormErrors(['date']);

        $this->assertSame(0, AttendanceCorrection::count());
    }

    // ── Pending / rejected geofence attendance (BUG-COR-3 / BUG-08) ──────────

    public function test_api_refuses_a_correction_of_an_attendance_pending_geofence_approval(): void
    {
        $worker = $this->makeWorker($this->shift('08:00', '17:00'));
        $attendance = $this->attendance($worker, '2026-11-16 09:00:00', AttendanceStatus::PendingApproval, ['check_in_late_minutes' => 50]);
        Sanctum::actingAs($worker);

        $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-16', 'corrected_check_in' => '08:00', 'reason' => 'GPS'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date' => 'menunggu persetujuan atasan']);

        $this->assertSame(0, AttendanceCorrection::count());
        $this->assertSame(AttendanceStatus::PendingApproval, $attendance->fresh()->status);
    }

    public function test_api_refuses_a_correction_of_a_rejected_attendance(): void
    {
        $worker = $this->makeWorker($this->shift('08:00', '17:00'));
        $this->attendance($worker, '2026-11-15 08:05:00', AttendanceStatus::Rejected, ['rejection_reason' => 'Di luar area']);
        Sanctum::actingAs($worker);

        $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-15', 'corrected_check_out' => '17:00', 'reason' => 'Lupa pulang'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date' => 'sudah ditolak oleh atasan']);
    }

    public function test_update_cannot_move_a_correction_onto_a_pending_attendance(): void
    {
        $worker = $this->makeWorker($this->shift('08:00', '17:00'));
        $this->attendance($worker, '2026-11-14 09:00:00', AttendanceStatus::PendingApproval);
        Sanctum::actingAs($worker);

        $id = $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-15', 'corrected_check_in' => '08:00', 'reason' => 'Kemarin'])
            ->assertCreated()
            ->json('data.id');

        $this->putJson("/api/v1/attendance-corrections/{$id}", ['date' => '2026-11-14'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date' => 'menunggu persetujuan atasan']);
    }

    public function test_a_correction_of_a_settled_attendance_is_still_accepted(): void
    {
        $worker = $this->makeWorker($this->shift('08:00', '17:00'));
        $this->attendance($worker, '2026-11-15 09:00:00', AttendanceStatus::Late, ['late_minutes' => 60]);
        Sanctum::actingAs($worker);

        $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-15', 'corrected_check_in' => '08:00', 'reason' => 'Mesin error'])
            ->assertCreated();
    }

    public function test_panel_create_refuses_a_correction_of_a_pending_attendance(): void
    {
        $worker = $this->makeWorker($this->shift('08:00', '17:00'));
        $this->attendance($worker, '2026-11-15 09:00:00', AttendanceStatus::PendingApproval);
        $this->actingAs($worker);

        Livewire::test(CreateAttendanceCorrection::class)
            ->fillForm(['date' => '2026-11-15', 'reason' => 'GPS', 'corrected_check_in' => '08:00'])
            ->call('create')
            ->assertHasFormErrors(['date']);

        $this->assertSame(0, AttendanceCorrection::count());
    }

    public function test_attendance_turning_pending_after_submission_stays_pending_and_the_geofence_approver_judges_the_corrected_time(): void
    {
        $worker = $this->makeWorker($this->shift('08:00', '17:00'));
        $correction = $this->submit($worker, ['date' => '2026-11-16', 'corrected_check_in' => '08:30']);

        // Meanwhile the worker checks in out of area at 09:00 (50 minutes late after the tolerance).
        $attendance = $this->attendance($worker, '2026-11-16 09:00:00', AttendanceStatus::PendingApproval, ['check_in_late_minutes' => 50]);
        app(ApprovalFlowService::class)->generateSteps($attendance, 'geofence');

        app(ApprovalFlowService::class)->approveStep($correction, $this->approver);

        $attendance->refresh();
        $this->assertSame('approved', $correction->fresh()->status);
        $this->assertSame(AttendanceStatus::PendingApproval, $attendance->status);
        $this->assertSame('2026-11-16 08:30:00', $attendance->check_in->toDateTimeString());
        $this->assertSame(30, $attendance->check_in_late_minutes);
        $this->assertSame(30, $attendance->reportedLateMinutes());
        $this->assertTrue($attendance->is_corrected);
        $this->assertSame(1, $attendance->approvalSteps()->where('status', 'pending')->count());

        // The geofence approver settles it with the corrected lateness.
        app(ApprovalFlowService::class)->approveStep($attendance->fresh(), $this->approver);
        $attendance->refresh();
        $this->assertSame(AttendanceStatus::Late, $attendance->status);
        $this->assertSame(30, $attendance->late_minutes);
    }

    public function test_pending_lateness_survives_a_moved_check_in_with_the_same_lateness(): void
    {
        $worker = $this->makeWorker($this->shift('08:00', '17:00'));
        $correction = $this->submit($worker, ['date' => '2026-11-16', 'corrected_check_in' => '08:02']);
        $attendance = $this->attendance($worker, '2026-11-16 08:05:00', AttendanceStatus::PendingApproval, ['check_in_late_minutes' => 0]);

        $correction->applyCorrection();

        $attendance->refresh();
        $this->assertSame('2026-11-16 08:02:00', $attendance->check_in->toDateTimeString());
        $this->assertSame(0, $attendance->check_in_late_minutes);
        $this->assertSame(AttendanceStatus::PendingApproval, $attendance->status);
    }

    public function test_attendance_rejected_after_submission_stays_rejected_with_its_reason(): void
    {
        $worker = $this->makeWorker($this->shift('08:00', '17:00'));
        $correction = $this->submit($worker, ['date' => '2026-11-15', 'corrected_check_in' => '08:00', 'corrected_check_out' => '17:00']);
        $attendance = $this->attendance($worker, '2026-11-15 08:40:00', AttendanceStatus::Rejected, ['rejection_reason' => 'Di luar area']);

        app(ApprovalFlowService::class)->approveStep($correction, $this->approver);

        $attendance->refresh();
        $this->assertSame(AttendanceStatus::Rejected, $attendance->status);
        $this->assertSame('Di luar area', $attendance->rejection_reason);
        $this->assertSame('2026-11-15 08:00:00', $attendance->check_in->toDateTimeString());
        $this->assertSame('2026-11-15 17:00:00', $attendance->check_out->toDateTimeString());
    }

    // ── Night shift lateness on the business date (BUG-COR-1) ────────────────

    /**
     * @return array<string, array{string, string, int, string}>
     */
    public static function nightCheckIns(): array
    {
        return [
            'tepat mulai' => ['22:00', 'on_time', 0, '2026-11-14 22:00:00'],
            'datang awal' => ['21:40', 'on_time', 0, '2026-11-14 21:40:00'],
            'dalam toleransi' => ['22:10', 'on_time', 0, '2026-11-14 22:10:00'],
            'sebelum tengah malam' => ['23:59', 'late', 119, '2026-11-14 23:59:00'],
            'tepat tengah malam' => ['00:00', 'late', 120, '2026-11-15 00:00:00'],
            'lewat tengah malam' => ['00:30', 'late', 150, '2026-11-15 00:30:00'],
            'menjelang pulang' => ['05:00', 'late', 420, '2026-11-15 05:00:00'],
        ];
    }

    #[DataProvider('nightCheckIns')]
    public function test_night_shift_correction_is_late_against_the_evening_start(string $time, string $status, int $late, string $checkIn): void
    {
        $worker = $this->makeWorker($this->shift('22:00', '06:00'));

        $this->submit($worker, ['date' => '2026-11-14', 'corrected_check_in' => $time])->applyCorrection();

        $attendance = Attendance::where('employee_id', $worker->employee->id)->sole();
        $this->assertSame('2026-11-14', $attendance->date->toDateString());
        $this->assertSame($checkIn, $attendance->check_in->toDateTimeString());
        $this->assertSame($status, $attendance->status->value);
        $this->assertSame($late, (int) $attendance->late_minutes);
    }

    public function test_night_shift_correction_after_midnight_with_check_out_works_the_right_hours(): void
    {
        $worker = $this->makeWorker($this->shift('22:00', '06:00'));

        $this->submit($worker, ['date' => '2026-11-14', 'corrected_check_in' => '00:30', 'corrected_check_out' => '05:00'])->applyCorrection();

        $attendance = Attendance::where('employee_id', $worker->employee->id)->sole();
        $this->assertSame('2026-11-15 05:00:00', $attendance->check_out->toDateTimeString());
        $this->assertSame(270, $attendance->workedMinutes());
        $this->assertSame(60, $attendance->early_leave_minutes);
    }

    // ── Early leave recomputed (decision d) ──────────────────────────────────

    public function test_check_out_correction_recomputes_early_leave(): void
    {
        $shift = $this->shift('08:00', '17:00');
        $worker = $this->makeWorker($shift);
        $attendance = $this->attendance($worker, '2026-11-15 08:00:00', AttendanceStatus::OnTime, ['shift_id' => $shift->id, 'check_out' => '2026-11-15 17:00:00']);
        $this->assertSame(0, $attendance->early_leave_minutes);

        $this->submit($worker, ['date' => '2026-11-15', 'corrected_check_out' => '16:00'])->applyCorrection();

        $attendance->refresh();
        $this->assertSame('2026-11-15 16:00:00', $attendance->check_out->toDateTimeString());
        $this->assertSame(60, $attendance->early_leave_minutes);
        $this->assertSame(AttendanceStatus::OnTime, $attendance->status);
    }

    public function test_check_out_correction_to_the_scheduled_end_clears_early_leave(): void
    {
        $shift = $this->shift('08:00', '17:00');
        $worker = $this->makeWorker($shift);
        $attendance = $this->attendance($worker, '2026-11-15 08:00:00', AttendanceStatus::OnTime, ['shift_id' => $shift->id, 'check_out' => '2026-11-15 15:00:00']);
        $this->assertSame(120, $attendance->early_leave_minutes);

        $this->submit($worker, ['date' => '2026-11-15', 'corrected_check_out' => '17:05'])->applyCorrection();

        $this->assertSame(0, $attendance->fresh()->early_leave_minutes);
    }

    // ── Split shift ──────────────────────────────────────────────────────────

    public function test_split_shift_second_segment_correction_is_judged_on_its_own_hours(): void
    {
        $split = $this->splitShift();
        $worker = $this->makeWorker($split);
        $first = $this->attendance($worker, '2026-11-15 07:00:00', AttendanceStatus::OnTime, ['shift_id' => $split->id, 'check_out' => '2026-11-15 11:00:00']);

        $this->submit($worker, ['date' => '2026-11-15', 'corrected_check_in' => '17:30', 'corrected_check_out' => '20:00'])->applyCorrection();

        $second = Attendance::where('employee_id', $worker->employee->id)->where('segment', 2)->sole();
        $this->assertSame(AttendanceStatus::Late, $second->status);
        $this->assertSame(30, $second->late_minutes);
        $this->assertSame(60, $second->early_leave_minutes);
        $this->assertSame('2026-11-15 20:00:00', $second->check_out->toDateTimeString());

        $first->refresh();
        $this->assertSame(AttendanceStatus::OnTime, $first->status);
        $this->assertSame('2026-11-15 11:00:00', $first->check_out->toDateTimeString());
    }

    public function test_split_shift_pending_second_segment_refuses_only_that_segment(): void
    {
        $split = $this->splitShift();
        $worker = $this->makeWorker($split);
        $this->attendance($worker, '2026-11-15 07:20:00', AttendanceStatus::Late, ['shift_id' => $split->id, 'late_minutes' => 20, 'check_out' => '2026-11-15 11:00:00']);
        $this->attendance($worker, '2026-11-15 17:40:00', AttendanceStatus::PendingApproval, ['shift_id' => $split->id, 'segment' => 2]);
        Sanctum::actingAs($worker);

        $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-15', 'corrected_check_in' => '17:00', 'reason' => 'Segmen 2'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date' => 'menunggu persetujuan atasan']);

        $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-15', 'corrected_check_in' => '07:00', 'reason' => 'Segmen 1'])
            ->assertCreated();
    }

    // ── Check-out after check-in ─────────────────────────────────────────────

    public function test_swapped_times_on_a_day_shift_are_refused_and_not_read_as_an_overnight_stay(): void
    {
        $worker = $this->makeWorker($this->shift('08:00', '17:00'));
        Sanctum::actingAs($worker);

        $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-15', 'corrected_check_in' => '17:00', 'corrected_check_out' => '08:00', 'reason' => 'Tertukar'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['corrected_check_out' => 'Jam pulang harus setelah jam masuk']);

        $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-15', 'corrected_check_in' => '08:00', 'corrected_check_out' => '08:00', 'reason' => 'Sama'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['corrected_check_out' => 'Jam pulang harus setelah jam masuk']);

        $this->assertSame(0, AttendanceCorrection::count());
    }

    public function test_approving_a_legacy_row_with_swapped_day_times_is_refused_and_writes_nothing(): void
    {
        $worker = $this->makeWorker($this->shift('08:00', '17:00'));
        $correction = $this->submit($worker, ['date' => '2026-11-15', 'corrected_check_in' => '17:00', 'corrected_check_out' => '08:00']);

        $this->assertSame('2026-11-15 08:00:00', $correction->corrected_check_out->toDateTimeString());

        try {
            $correction->applyCorrection();
            $this->fail('A check-out before the check-in on a day shift must not be applied.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('tidak setelah jam masuk', $exception->errors()['approve'][0]);
        }

        $this->assertSame(0, Attendance::count());
        $this->assertSame('pending', $correction->fresh()->status);
    }

    public function test_check_in_correction_after_the_existing_check_out_is_refused(): void
    {
        $shift = $this->shift('08:00', '17:00');
        $worker = $this->makeWorker($shift);
        $this->attendance($worker, '2026-11-15 08:05:00', AttendanceStatus::OnTime, ['shift_id' => $shift->id, 'check_out' => '2026-11-15 12:00:00']);
        Sanctum::actingAs($worker);

        $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-15', 'corrected_check_in' => '13:00', 'reason' => 'Salah ketik'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['corrected_check_in' => 'sebelum jam pulang yang tercatat (12:00)']);

        $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-15', 'corrected_check_in' => '08:00', 'reason' => 'Benar'])
            ->assertCreated();
    }

    public function test_check_out_correction_before_the_existing_check_in_is_refused(): void
    {
        $shift = $this->shift('08:00', '17:00');
        $worker = $this->makeWorker($shift);
        $this->attendance($worker, '2026-11-15 08:05:00', AttendanceStatus::OnTime, ['shift_id' => $shift->id]);
        Sanctum::actingAs($worker);

        $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-15', 'corrected_check_out' => '07:00', 'reason' => 'Salah ketik'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['corrected_check_out' => 'setelah jam masuk yang tercatat (08:05)']);

        $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-15', 'corrected_check_out' => '17:00', 'reason' => 'Lupa pulang'])
            ->assertCreated();
    }

    public function test_night_shift_single_sided_corrections_are_compared_with_the_other_time_across_midnight(): void
    {
        $night = $this->shift('22:00', '06:00');
        $worker = $this->makeWorker($night);
        $this->attendance($worker, '2026-11-14 22:30:00', AttendanceStatus::Late, ['shift_id' => $night->id, 'late_minutes' => 30, 'check_out' => '2026-11-15 05:00:00']);
        Sanctum::actingAs($worker);

        // 05:30 is the next morning, after the recorded 05:00 check-out.
        $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-14', 'corrected_check_in' => '05:30', 'reason' => 'Salah ketik'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['corrected_check_in' => 'sebelum jam pulang yang tercatat (05:00)']);

        $id = $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-14', 'corrected_check_in' => '22:00', 'reason' => 'Datang tepat waktu'])
            ->assertCreated()
            ->json('data.id');

        AttendanceCorrection::findOrFail($id)->applyCorrection();

        $attendance = Attendance::where('employee_id', $worker->employee->id)->sole();
        $this->assertSame('2026-11-14 22:00:00', $attendance->check_in->toDateTimeString());
        $this->assertSame('2026-11-15 05:00:00', $attendance->check_out->toDateTimeString());
        $this->assertSame(AttendanceStatus::OnTime, $attendance->status);
    }

    public function test_night_shift_check_out_only_correction_lands_on_the_next_morning(): void
    {
        $night = $this->shift('22:00', '06:00');
        $worker = $this->makeWorker($night);
        $this->attendance($worker, '2026-11-14 22:00:00', AttendanceStatus::OnTime, ['shift_id' => $night->id]);
        Sanctum::actingAs($worker);

        $id = $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-14', 'corrected_check_out' => '06:00', 'reason' => 'Lupa pulang'])
            ->assertCreated()
            ->json('data.id');

        AttendanceCorrection::findOrFail($id)->applyCorrection();

        $this->assertSame('2026-11-15 06:00:00', Attendance::where('employee_id', $worker->employee->id)->sole()->check_out->toDateTimeString());
    }

    public function test_split_shift_second_segment_check_in_is_compared_with_that_segments_check_out(): void
    {
        $split = $this->splitShift();
        $worker = $this->makeWorker($split);
        $this->attendance($worker, '2026-11-15 07:00:00', AttendanceStatus::OnTime, ['shift_id' => $split->id, 'check_out' => '2026-11-15 11:00:00']);
        $this->attendance($worker, '2026-11-15 17:00:00', AttendanceStatus::OnTime, ['shift_id' => $split->id, 'segment' => 2, 'check_out' => '2026-11-15 19:00:00']);
        Sanctum::actingAs($worker);

        $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-15', 'corrected_check_in' => '19:30', 'reason' => 'Salah ketik'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['corrected_check_in' => 'sebelum jam pulang yang tercatat (19:00)']);

        $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-15', 'corrected_check_in' => '17:05', 'reason' => 'Benar'])
            ->assertCreated();
    }

    public function test_approval_refuses_a_check_in_correction_once_the_check_out_moved_before_it(): void
    {
        $shift = $this->shift('08:00', '17:00');
        $worker = $this->makeWorker($shift);
        $attendance = $this->attendance($worker, '2026-11-15 08:05:00', AttendanceStatus::OnTime, ['shift_id' => $shift->id, 'check_out' => '2026-11-15 17:00:00']);
        $correction = $this->submit($worker, ['date' => '2026-11-15', 'corrected_check_in' => '12:00']);
        $this->assertSame([], $correction->submissionErrors());

        // The admin moves the check-out earlier after the correction was submitted.
        $attendance->forceFill(['check_out' => '2026-11-15 11:00:00'])->save();

        $this->expectException(ValidationException::class);

        try {
            $correction->applyCorrection();
        } finally {
            $this->assertSame('2026-11-15 08:05:00', $attendance->fresh()->check_in->toDateTimeString());
        }
    }

    // ── Night shift check-in window ──────────────────────────────────────────

    /**
     * @return array<string, array{string}>
     */
    public static function implausibleNightCheckIns(): array
    {
        return [
            'siang (maksud 23:00)' => ['13:00'],
            'sore' => ['15:00'],
            'pagi setelah shift berakhir' => ['06:30'],
            'terlalu awal' => ['20:30'],
        ];
    }

    #[DataProvider('implausibleNightCheckIns')]
    public function test_night_shift_check_in_far_from_the_shift_is_refused(string $time): void
    {
        Sanctum::actingAs($this->makeWorker($this->shift('22:00', '06:00')));

        $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-14', 'corrected_check_in' => $time, 'reason' => 'Salah ketik'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['corrected_check_in' => 'Isi jam masuk antara 21:00 dan 06:00']);

        $this->assertSame(0, AttendanceCorrection::count());
    }

    public function test_day_shift_check_in_after_the_shift_end_is_refused(): void
    {
        Sanctum::actingAs($this->makeWorker($this->shift('08:00', '17:00')));

        $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-15', 'corrected_check_in' => '18:00', 'reason' => 'Salah ketik'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['corrected_check_in' => 'Isi jam masuk antara 07:00 dan 17:00']);
    }

    public function test_check_in_on_a_scheduled_day_off_is_not_judged_against_shift_hours(): void
    {
        $shift = $this->shift('08:00', '17:00');
        $worker = $this->makeWorker($shift);
        ShiftSchedule::create(['employee_id' => $worker->employee->id, 'date' => '2026-11-15', 'is_day_off' => true]);
        Sanctum::actingAs($worker);

        $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-15', 'corrected_check_in' => '19:00', 'reason' => 'Masuk saat libur'])
            ->assertCreated();
    }

    // ── Corrections of the other time of a day ───────────────────────────────

    public function test_check_out_correction_is_allowed_after_an_approved_check_in_correction(): void
    {
        $worker = $this->makeWorker($this->shift('08:00', '17:00'));
        Sanctum::actingAs($worker);

        $firstId = $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-15', 'corrected_check_in' => '08:00', 'reason' => 'Lupa masuk'])
            ->assertCreated()
            ->json('data.id');
        app(ApprovalFlowService::class)->approveStep(AttendanceCorrection::findOrFail($firstId), $this->approver);
        $this->assertSame('approved', AttendanceCorrection::findOrFail($firstId)->status);

        // A second check-in correction would overwrite the approved one.
        $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-15', 'corrected_check_in' => '08:10', 'reason' => 'Lagi'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.date.0', AttendanceCorrection::DUPLICATE_MESSAGE);

        $secondId = $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-15', 'corrected_check_out' => '17:00', 'reason' => 'Lupa pulang'])
            ->assertCreated()
            ->json('data.id');
        app(ApprovalFlowService::class)->approveStep(AttendanceCorrection::findOrFail($secondId), $this->approver);

        $this->assertSame('approved', AttendanceCorrection::findOrFail($secondId)->status);
        $attendance = Attendance::where('employee_id', $worker->employee->id)->sole();
        $this->assertSame('2026-11-15 08:00:00', $attendance->check_in->toDateTimeString());
        $this->assertSame('2026-11-15 17:00:00', $attendance->check_out->toDateTimeString());
    }

    public function test_approval_still_refuses_a_correction_that_overwrites_the_same_approved_time(): void
    {
        $worker = $this->makeWorker($this->shift('08:00', '17:00'));
        $first = $this->submit($worker, ['date' => '2026-11-15', 'corrected_check_in' => '08:00']);
        $second = $this->submit($worker, ['date' => '2026-11-15', 'corrected_check_in' => '08:30', 'corrected_check_out' => '17:00']);
        $first->applyCorrection();

        $this->expectException(ValidationException::class);

        $second->fresh()->applyCorrection();
    }

    // ── Update with explicit nulls ───────────────────────────────────────────

    public function test_update_clearing_the_check_in_is_checked_as_a_check_out_only_correction(): void
    {
        $split = $this->splitShift();
        $worker = $this->makeWorker($split);
        $correction = $this->submit($worker, ['date' => '2026-11-15', 'corrected_check_in' => '07:00', 'corrected_check_out' => '20:00']);
        // Segment 2 is open and still waits for the geofence approver.
        $this->attendance($worker, '2026-11-15 17:40:00', AttendanceStatus::PendingApproval, ['shift_id' => $split->id, 'segment' => 2]);
        Sanctum::actingAs($worker);

        $this->putJson("/api/v1/attendance-corrections/{$correction->id}", ['corrected_check_in' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date' => 'menunggu persetujuan atasan']);

        $this->assertSame('2026-11-15 07:00:00', $correction->fresh()->corrected_check_in->toDateTimeString());
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function shift(string $start, string $end): Shift
    {
        return Shift::create(['company_id' => $this->company->id, 'name' => "Shift {$start}", 'code' => 'S'.str_replace(':', '', $start), 'start_time' => $start, 'end_time' => $end, 'late_tolerance_minutes' => 10]);
    }

    private function splitShift(): Shift
    {
        return Shift::create(['company_id' => $this->company->id, 'name' => 'Split', 'code' => 'SP', 'start_time' => '07:00', 'end_time' => '11:00', 'second_start_time' => '17:00', 'second_end_time' => '21:00', 'late_tolerance_minutes' => 10]);
    }

    /**
     * A pending correction with its approval steps, as submitted before anything else happened.
     *
     * @param  array<string, string>  $times
     */
    private function submit(User $worker, array $times): AttendanceCorrection
    {
        $correction = AttendanceCorrection::create([
            'employee_id' => $worker->employee->id,
            'reason' => 'Koreksi',
            'status' => 'pending',
            ...$times,
        ]);
        app(ApprovalFlowService::class)->generateSteps($correction, 'correction');

        return $correction->fresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function attendance(User $worker, string $checkIn, AttendanceStatus $status, array $attributes = []): Attendance
    {
        $attendance = new Attendance;
        $attendance->forceFill([
            'employee_id' => $worker->employee->id,
            'date' => substr($checkIn, 0, 10),
            'segment' => 1,
            'check_in' => $checkIn,
            'status' => $status,
            'late_minutes' => 0,
            ...$attributes,
        ])->save();

        return $attendance->fresh();
    }

    private function makeWorker(Shift $shift): User
    {
        $user = User::factory()->create();
        $user->assignRole('Employee');

        Employee::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Staff Operasional'])->id,
            'nip' => 'KRI-'.$user->id,
            'full_name' => "Pekerja {$user->id}",
            'status' => 'active',
            'shift_id' => $shift->id,
        ]);

        return $user->fresh();
    }
}
