<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\DataScope;
use App\Filament\Resources\AttendanceCorrections\Pages\EditAttendanceCorrection;
use App\Filament\Resources\Attendances\AttendanceResource;
use App\Filament\Resources\Attendances\Pages\EditAttendance;
use App\Filament\Resources\Attendances\Pages\ListAttendances;
use App\Models\ApprovalFlow;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\ShiftScheduleChange;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalFlowService;
use App\Services\Attendance\LedgerQuery;
use App\Services\AttendanceLedger;
use App\Services\RosterChangeContext;
use App\Services\SessionResolver;
use App\Services\WorkerAccountService;
use Database\Seeders\RoleSeeder;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * WP12: a correction names or safely infers the session it corrects (stored at submission), records a real
 * check-out after midnight up to six hours after the scheduled end, never creates a row without a check-in,
 * revives a rejected row only with the real check-in, works on an unscheduled day, can carry the shift and
 * outlet that really applied (the only way to change a locked roster row), and every admin edit or
 * deletion of an attendance leaves an approved admin correction with the values it replaced.
 *
 * The clock is frozen on Wednesday 18 November 2026, 12:00.
 */
class CorrectionSessionTargetTest extends TestCase
{
    use RefreshDatabase;

    private const D = '2026-11-10';

    private Tenant $tenant;

    private Company $company;

    private Division $division;

    private CompanyLocation $luuca;

    private CompanyLocation $jchicken;

    private Shift $closing;

    private Shift $pagi;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.onesignal.app_id' => null, 'services.whatsapp_gateway.url' => null]);
        Http::fake();
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-11-18 12:00:00'));
        $this->seed(RoleSeeder::class);

        $this->tenant = Tenant::factory()->create(['name' => 'Klien Koreksi', 'code' => 'KKS', 'is_active' => true]);
        $this->company = new Company(['name' => 'PT Franchise', 'code' => 'PFR', 'is_active' => true]);
        $this->company->tenant_id = $this->tenant->id;
        $this->company->save();
        $this->company->policy()->update(['require_gps' => false, 'require_photo' => false, 'require_face_recognition' => false, 'require_liveness' => false, 'late_tolerance_minutes' => 5]);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Outlet', 'code' => 'OUT', 'is_active' => true]);
        $this->luuca = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $this->division->id, 'name' => 'Luuca Ciledug', 'latitude' => -6.2, 'longitude' => 106.7]);
        $this->jchicken = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $this->division->id, 'name' => 'JChicken Ciledug', 'latitude' => -6.2, 'longitude' => 106.7]);
        $this->closing = Shift::create(['company_id' => $this->company->id, 'name' => 'Closing', 'code' => 'C', 'start_time' => '14:00', 'end_time' => '22:00', 'late_tolerance_minutes' => 5]);
        $this->pagi = Shift::create(['company_id' => $this->company->id, 'name' => 'Pagi', 'code' => 'P', 'start_time' => '08:00', 'end_time' => '17:00', 'late_tolerance_minutes' => 5]);

        foreach (['correction', 'geofence'] as $type) {
            ApprovalFlow::create(['company_id' => $this->company->id, 'request_type' => $type, 'step_number' => 1, 'step_order' => 1, 'name' => 'Approver', 'approver_type' => 'role', 'approver_role' => 'Approver']);
        }

        $this->approver = $this->account(['Approver']);
    }

    // ── After midnight ───────────────────────────────────────────────────────

    public function test_an_old_payload_check_out_after_midnight_of_a_closing_shift_lands_on_the_next_day(): void
    {
        $worker = $this->employee($this->closing);
        $this->attend($worker, self::D, '14:00');
        Sanctum::actingAs($worker->user);

        $id = $this->correct(['date' => self::D, 'corrected_check_out' => '00:10'])->assertCreated()->json('data.id');

        $correction = AttendanceCorrection::findOrFail($id);
        $this->assertSame('2026-11-11 00:10:00', $correction->corrected_check_out->toDateTimeString());
        $this->assertTrue($correction->check_out_next_day);

        $this->approve($correction);

        $this->assertSame('2026-11-11 00:10:00', $this->rowOf($worker)->check_out->toDateTimeString());
        $this->assertSame(610, $this->rowOf($worker)->workedMinutes());
    }

    public function test_a_check_out_more_than_six_hours_after_the_scheduled_end_is_refused(): void
    {
        $worker = $this->employee($this->closing);
        $this->attend($worker, self::D, '14:00');
        Sanctum::actingAs($worker->user);

        $this->correct(['date' => self::D, 'corrected_check_out' => '05:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['corrected_check_out' => 'sampai 6 jam setelah jadwal selesai (11/11 04:00)']);

        $this->correct(['date' => self::D, 'corrected_check_out' => '04:00'])->assertCreated();
    }

    public function test_a_day_shift_check_out_on_the_next_morning_stays_refused_and_no_longer_says_night_shift_only(): void
    {
        Sanctum::actingAs($this->employee($this->pagi)->user);

        $this->correct(['date' => self::D, 'corrected_check_in' => '17:00', 'corrected_check_out' => '08:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['corrected_check_out' => 'Jam pulang harus setelah jam masuk'])
            ->assertJsonMissing(['hanya berlaku untuk shift malam']);
    }

    public function test_a_full_next_day_datetime_and_the_next_day_flag_are_honoured_inside_the_window(): void
    {
        $worker = $this->employee($this->closing);
        $this->attend($worker, self::D, '14:00');
        Sanctum::actingAs($worker->user);

        $fullDate = AttendanceCorrection::findOrFail($this->correct(['date' => self::D, 'corrected_check_out' => '2026-11-11 01:30'])->assertCreated()->json('data.id'));
        $this->assertSame('2026-11-11 01:30:00', $fullDate->corrected_check_out->toDateTimeString());
        $fullDate->delete();

        // 23:30 the same evening would be a valid time too; the flag says it was the next day... and that is
        // past the window (04:00), so it is refused instead of silently moved.
        $this->correct(['date' => self::D, 'corrected_check_out' => '23:30', 'check_out_next_day' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('corrected_check_out');

        $flagged = AttendanceCorrection::findOrFail($this->correct(['date' => self::D, 'corrected_check_out' => '03:00', 'check_out_next_day' => true])->assertCreated()->json('data.id'));
        $this->approve($flagged);

        $this->assertSame('2026-11-11 03:00:00', $this->rowOf($worker)->check_out->toDateTimeString());
        $this->assertTrue($flagged->fresh()->check_out_next_day);
    }

    // ── Which session ────────────────────────────────────────────────────────

    public function test_on_a_two_schedule_day_the_check_in_time_picks_the_session_and_an_ambiguous_time_asks_for_the_schedule(): void
    {
        $worker = $this->employee();
        $evening = Shift::create(['company_id' => $this->company->id, 'name' => 'Sore', 'code' => 'S', 'start_time' => '16:00', 'end_time' => '22:00', 'late_tolerance_minutes' => 5]);
        $morning = Shift::create(['company_id' => $this->company->id, 'name' => 'Siang', 'code' => 'SG', 'start_time' => '08:00', 'end_time' => '15:00', 'late_tolerance_minutes' => 5]);
        ShiftSchedule::create(['employee_id' => $worker->id, 'date' => self::D, 'sequence' => 1, 'shift_id' => $morning->id, 'company_location_id' => $this->luuca->id]);
        $second = ShiftSchedule::create(['employee_id' => $worker->id, 'date' => self::D, 'sequence' => 2, 'shift_id' => $evening->id, 'company_location_id' => $this->jchicken->id]);
        Sanctum::actingAs($worker->user);

        // 15:00 is both the end of the first and inside the opening hour of the second.
        $this->correct(['date' => self::D, 'corrected_check_in' => '15:00', 'corrected_check_out' => '22:00'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.date.0', AttendanceCorrection::AMBIGUOUS_TARGET_MESSAGE);

        $explicit = $this->correct(['date' => self::D, 'schedule_id' => $second->id, 'corrected_check_in' => '15:00', 'corrected_check_out' => '22:00'])
            ->assertCreated()
            ->assertJsonPath('data.schedule_id', $second->id)
            ->assertJsonPath('data.sequence', 2)
            ->json('data.id');
        AttendanceCorrection::findOrFail($explicit)->delete();

        $id = $this->correct(['date' => self::D, 'corrected_check_in' => '16:20', 'corrected_check_out' => '22:00'])
            ->assertCreated()
            ->assertJsonPath('data.sequence', 2)
            ->assertJsonPath('data.segment', 1)
            ->json('data.id');

        $this->approve(AttendanceCorrection::findOrFail($id));

        $row = $this->rowOf($worker);
        $this->assertSame(2, $row->schedule_sequence);
        $this->assertSame($second->id, $row->shift_schedule_id);
        $this->assertSame($this->jchicken->id, $row->company_location_id);
        $this->assertSame('JChicken Ciledug', $row->sched_outlet_name);
        $this->assertSame(AttendanceStatus::Late, $row->status);
        $this->assertSame(20, $row->late_minutes);
    }

    public function test_a_check_out_alone_on_a_day_without_a_check_in_is_refused_and_a_stale_one_is_refused_at_approval(): void
    {
        $worker = $this->employee($this->pagi);
        Sanctum::actingAs($worker->user);

        $this->correct(['date' => self::D, 'corrected_check_out' => '17:00'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.corrected_check_in.0', AttendanceCorrection::CHECK_IN_REQUIRED_MESSAGE);

        $row = $this->attend($worker, self::D, '08:00');
        $correction = AttendanceCorrection::findOrFail($this->correct(['date' => self::D, 'corrected_check_out' => '17:00'])->assertCreated()->json('data.id'));
        $this->assertSame($row->id, $correction->attendance_id);

        // The check-in is cleared meanwhile (an admin found it was not the worker's).
        $row->forceFill(['check_in' => null])->saveQuietly();

        try {
            $this->approve($correction);
            $this->fail('A check-out without a check-in must not be approved.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Isi juga jam masuk', $exception->errors()['approve'][0]);
        }

        $this->assertNull($row->fresh()->check_out);
        $this->assertSame('pending', $correction->fresh()->status);
    }

    public function test_a_live_check_in_reusing_a_legacy_half_row_clears_its_stale_check_out(): void
    {
        $worker = $this->employee();
        ShiftSchedule::create(['employee_id' => $worker->id, 'date' => '2026-11-18', 'shift_id' => $this->pagi->id, 'company_location_id' => $this->luuca->id]);
        $half = Attendance::create(['employee_id' => $worker->id, 'date' => '2026-11-18', 'segment' => 1, 'check_out' => '2026-11-18 17:00:00', 'status' => 'on_time', 'is_corrected' => true]);

        $this->travelTo(Carbon::parse('2026-11-18 07:58:00'));
        Sanctum::actingAs($worker->user);
        $this->postJson('/api/v1/attendance/check-in')->assertOk();

        $half->refresh();
        $this->assertSame('2026-11-18 07:58:00', $half->check_in->toDateTimeString());
        $this->assertNull($half->check_out);
        $this->assertSame(1, Attendance::where('employee_id', $worker->id)->count());
    }

    // ── Unscheduled days ─────────────────────────────────────────────────────

    public function test_a_freelancer_without_a_schedule_corrects_an_unscheduled_day_from_the_app(): void
    {
        $account = $this->account(['Freelancer']);
        $freelancer = Freelancer::create(['user_id' => $account->id, 'company_id' => $this->company->id, 'division_id' => $this->division->id, 'full_name' => 'Fani', 'status' => 'active']);
        Sanctum::actingAs($account);

        $id = $this->correct(['date' => self::D, 'corrected_check_in' => '10:00', 'corrected_check_out' => '18:00', 'corrected_company_location_id' => $this->jchicken->id])
            ->assertCreated()
            ->json('data.id');
        $this->approve(AttendanceCorrection::findOrFail($id));

        $row = Attendance::where('freelancer_id', $freelancer->id)->sole();
        $this->assertSame([AttendanceStatus::OnTime, 0, true, $this->jchicken->id, 'none'], [$row->status, (int) $row->late_minutes, $row->is_off_schedule, $row->company_location_id, $row->sched_source]);
        $this->assertSame(480, $row->workedMinutes());
    }

    public function test_an_employee_of_a_roster_company_corrects_an_unrostered_day_and_may_name_the_shift_covered(): void
    {
        $this->company->policy()->update(['uses_shift_roster' => true]);
        $worker = $this->employee();
        ShiftSchedule::create(['employee_id' => $worker->id, 'date' => '2026-11-09', 'shift_id' => $this->pagi->id, 'company_location_id' => $this->luuca->id]);
        Sanctum::actingAs($worker->user);

        $id = $this->correct(['date' => self::D, 'corrected_check_in' => '09:00', 'corrected_check_out' => '17:00', 'corrected_company_location_id' => $this->luuca->id])
            ->assertCreated()
            ->json('data.id');
        $this->approve(AttendanceCorrection::findOrFail($id));

        $row = $this->rowOf($worker);
        $this->assertSame([AttendanceStatus::OnTime, 0, true, $this->luuca->id, 'none'], [$row->status, (int) $row->late_minutes, $row->is_off_schedule, $row->company_location_id, $row->sched_source]);

        // Naming the shift covered judges the day against it.
        $id = $this->correct(['date' => '2026-11-11', 'corrected_check_in' => '08:30', 'corrected_check_out' => '17:00', 'corrected_shift_id' => $this->pagi->id, 'corrected_company_location_id' => $this->luuca->id])
            ->assertCreated()
            ->json('data.id');
        $this->approve(AttendanceCorrection::findOrFail($id));

        $covered = Attendance::where('employee_id', $worker->id)->whereDate('date', '2026-11-11')->sole();
        $this->assertSame([AttendanceStatus::Late, 30, true, $this->pagi->id], [$covered->status, (int) $covered->late_minutes, $covered->is_off_schedule, $covered->shift_id]);

        // A shift of another company is refused.
        $other = Company::create(['name' => 'PT Lain', 'code' => 'LAI', 'is_active' => true]);
        $foreign = Shift::create(['company_id' => $other->id, 'name' => 'Asing', 'start_time' => '08:00', 'end_time' => '17:00']);
        $this->correct(['date' => '2026-11-12', 'corrected_check_in' => '08:00', 'corrected_shift_id' => $foreign->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('corrected_shift_id');
    }

    // ── Rejected rows ────────────────────────────────────────────────────────

    public function test_a_rejected_row_is_present_after_a_correction_with_its_check_in_and_a_check_out_alone_is_refused(): void
    {
        $worker = $this->employee();
        $schedule = ShiftSchedule::create(['employee_id' => $worker->id, 'date' => self::D, 'shift_id' => $this->pagi->id, 'company_location_id' => $this->luuca->id]);
        $row = $this->attend($worker, self::D, '08:40', [
            'status' => 'rejected', 'rejection_reason' => 'Di luar area', 'shift_id' => $this->pagi->id, 'shift_schedule_id' => $schedule->id, 'company_location_id' => $this->luuca->id,
            'sched_start_at' => self::D.' 08:00:00', 'sched_end_at' => self::D.' 17:00:00', 'sched_tolerance_minutes' => 5, 'sched_scheduled_minutes' => 540,
            'sched_shift_name' => 'Pagi', 'sched_outlet_name' => 'Luuca Ciledug', 'sched_source' => 'schedule',
        ]);
        Sanctum::actingAs($worker->user);

        $this->correct(['date' => self::D, 'corrected_check_out' => '17:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date' => 'sudah ditolak oleh atasan']);

        $id = $this->correct(['date' => self::D, 'corrected_check_in' => '08:40', 'corrected_check_out' => '17:00'])->assertCreated()->json('data.id');
        $this->approve(AttendanceCorrection::findOrFail($id));

        $row->refresh();
        $this->assertSame([AttendanceStatus::Late, 40, null], [$row->status, (int) $row->late_minutes, $row->rejection_reason]);
        $this->assertStringContainsString('Di luar area', (string) $row->notes);

        $admin = $this->account(['Admin']);
        Sanctum::actingAs($admin);
        $line = collect($this->getJson('/api/v1/team/recap?month=2026-11')->assertOk()->json('data'))->firstWhere('worker.name', $worker->full_name);
        $this->assertSame([1, 1], [$line['present_days'], $line['late_days']], 'The revived day is present (and late).');
    }

    // ── One stretch of time is worked once ───────────────────────────────────

    public function test_a_next_day_check_out_over_the_next_days_session_is_refused_at_submission_and_at_approval(): void
    {
        $worker = $this->employee($this->closing);
        $this->attend($worker, self::D, '14:00');
        Sanctum::actingAs($worker->user);

        // Filed while nothing else was worked: accepted; a session checked in at 00:05 meanwhile refuses the approval.
        $id = $this->correct(['date' => self::D, 'corrected_check_out' => '00:30'])->assertCreated()->json('data.id');
        $this->attend($worker, '2026-11-11', '00:05', ['check_out' => '2026-11-11 08:00:00']);

        $this->correct(['date' => self::D, 'corrected_check_out' => '00:20'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['corrected_check_out' => 'Jam koreksi bertabrakan dengan absensi lain pada 11/11/2026 (00:05-08:00).']);

        $this->expectApprovalRefused(AttendanceCorrection::findOrFail($id), 'Jam koreksi bertabrakan dengan absensi lain pada 11/11/2026 (00:05-08:00). Tolak pengajuan ini.');
        $this->assertNull(Attendance::where('employee_id', $worker->id)->whereDate('date', self::D)->sole()->check_out);
    }

    public function test_reviving_a_rejected_row_over_a_session_worked_later_that_day_is_refused(): void
    {
        [$worker, $second] = $this->twoScheduleDay();
        $rejected = $this->attend($worker, self::D, '08:40', ['status' => 'rejected', 'rejection_reason' => 'Di luar area']);
        $this->attend($worker, self::D, '15:55', ['schedule_sequence' => 2, 'shift_schedule_id' => $second->id, 'check_out' => self::D.' 22:00:00']);
        Sanctum::actingAs($worker->user);

        $this->correct(['date' => self::D, 'attendance_id' => $rejected->id, 'corrected_check_in' => '08:40', 'corrected_check_out' => '22:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['corrected_check_in' => 'Jam koreksi bertabrakan dengan absensi lain pada 10/11/2026 (15:55-22:00).']);

        $this->correct(['date' => self::D, 'attendance_id' => $rejected->id, 'corrected_check_in' => '08:40', 'corrected_check_out' => '15:00'])->assertCreated();
    }

    public function test_a_schedule_correction_of_one_part_of_a_split_worked_in_both_parts_is_refused(): void
    {
        $worker = $this->employee();
        $split = Shift::create(['company_id' => $this->company->id, 'name' => 'Split', 'code' => 'SP', 'start_time' => '07:45', 'end_time' => '13:00', 'second_start_time' => '17:45', 'second_end_time' => '22:00', 'late_tolerance_minutes' => 5]);
        $schedule = ShiftSchedule::create(['employee_id' => $worker->id, 'date' => self::D, 'shift_id' => $split->id, 'company_location_id' => $this->luuca->id]);
        $first = $this->attend($worker, self::D, '07:45', ['check_out' => self::D.' 13:00:00', 'shift_id' => $split->id, 'shift_schedule_id' => $schedule->id, 'company_location_id' => $this->luuca->id]);
        $this->attend($worker, self::D, '17:45', ['segment' => 2, 'check_out' => self::D.' 22:00:00', 'shift_id' => $split->id, 'shift_schedule_id' => $schedule->id, 'company_location_id' => $this->luuca->id]);

        $this->actingAs($this->account(['Admin']));
        $correction = AttendanceResource::fileScheduleCorrection($first, ['corrected_shift_id' => $this->pagi->id, 'corrected_company_location_id' => $this->luuca->id, 'reason' => 'Salah shift']);

        $this->expectApprovalRefused($correction, AttendanceCorrection::SPLIT_SIBLING_MESSAGE.' Tolak pengajuan ini.');
        $this->assertSame($split->id, $schedule->fresh()->shift_id);
    }

    public function test_a_correction_whose_schedule_was_replaced_while_pending_is_refused_not_moved_to_the_new_schedule(): void
    {
        $worker = $this->employee();
        $schedule = ShiftSchedule::create(['employee_id' => $worker->id, 'date' => self::D, 'shift_id' => $this->closing->id, 'company_location_id' => $this->luuca->id]);
        Sanctum::actingAs($worker->user);

        $id = $this->correct(['date' => self::D, 'corrected_check_in' => '14:00', 'corrected_check_out' => '22:00'])->assertCreated()->json('data.id');
        $this->assertSame($schedule->id, (int) AttendanceCorrection::findOrFail($id)->shift_schedule_id);

        $schedule->delete();
        ShiftSchedule::create(['employee_id' => $worker->id, 'date' => self::D, 'shift_id' => $this->pagi->id, 'company_location_id' => $this->luuca->id]);

        $this->expectApprovalRefused(AttendanceCorrection::findOrFail($id), AttendanceCorrection::SCHEDULE_GONE_MESSAGE.' Tolak pengajuan ini dan minta pekerja mengajukan ulang.');
        $this->assertSame(0, Attendance::where('employee_id', $worker->id)->count());
    }

    // ── Schedule corrections and the lock ────────────────────────────────────

    public function test_an_approved_schedule_correction_changes_the_locked_row_rejudges_it_and_logs_it_as_a_correction(): void
    {
        $worker = $this->employee();
        $siang = Shift::create(['company_id' => $this->company->id, 'name' => 'Siang', 'code' => 'SG', 'start_time' => '09:30', 'end_time' => '18:00', 'late_tolerance_minutes' => 5]);
        $schedule = ShiftSchedule::create(['employee_id' => $worker->id, 'date' => self::D, 'shift_id' => $this->pagi->id, 'company_location_id' => $this->luuca->id]);
        $row = $this->attend($worker, self::D, '09:30', [
            'status' => 'late', 'late_minutes' => 90, 'check_out' => self::D.' 18:00:00', 'shift_id' => $this->pagi->id, 'shift_schedule_id' => $schedule->id, 'company_location_id' => $this->luuca->id,
            'sched_start_at' => self::D.' 08:00:00', 'sched_end_at' => self::D.' 17:00:00', 'sched_tolerance_minutes' => 5, 'sched_scheduled_minutes' => 540,
            'sched_shift_name' => 'Pagi', 'sched_outlet_name' => 'Luuca Ciledug', 'sched_source' => 'schedule',
        ]);
        $this->assertTrue($schedule->fresh()->isLocked());

        // A swap (or any roster writer other than a correction) cannot change the locked row.
        try {
            RosterChangeContext::run('swap', null, fn () => $schedule->fresh()->update(['shift_id' => $siang->id]));
            $this->fail('A swap must not change a schedule that already has attendance.');
        } catch (ValidationException $exception) {
            $this->assertSame(ShiftSchedule::LOCKED_MESSAGE, $exception->errors()['date'][0]);
        }

        // The admin files "Koreksi jadwal"; it waits for approval like any correction.
        $this->actingAs($this->account(['Admin']));
        $correction = AttendanceResource::fileScheduleCorrection($row, ['corrected_shift_id' => $siang->id, 'corrected_company_location_id' => $this->jchicken->id, 'reason' => 'Dipindah ke JChicken shift siang']);
        $this->assertSame('pending', $correction->status);
        $this->assertSame($this->pagi->id, $schedule->fresh()->shift_id, 'Nothing changes before the approval.');

        $this->approve($correction);

        $schedule->refresh();
        $row->refresh();
        $this->assertSame([$siang->id, $this->jchicken->id], [$schedule->shift_id, $schedule->company_location_id]);
        $this->assertSame([$siang->id, $this->jchicken->id, $schedule->id], [$row->shift_id, $row->company_location_id, $row->shift_schedule_id]);
        $this->assertSame(['2026-11-10 09:30:00', 'Siang', 'JChicken Ciledug'], [$row->sched_start_at->toDateTimeString(), $row->sched_shift_name, $row->sched_outlet_name]);
        $this->assertSame([AttendanceStatus::OnTime, 0, 0], [$row->status, (int) $row->late_minutes, (int) $row->early_leave_minutes]);

        $log = ShiftScheduleChange::query()->where('shift_schedule_id', $schedule->id)->latest('id')->first();
        $this->assertSame(['updated', 'correction'], [$log->action, $log->source]);
        $this->assertSame(90, $correction->fresh()->before_values['late_minutes']);
        $this->assertSame($this->pagi->id, $correction->fresh()->before_values['shift_id']);
    }

    public function test_the_attendance_table_files_a_schedule_correction_through_the_approval_flow(): void
    {
        $worker = $this->employee($this->pagi);
        $row = $this->attend($worker, self::D, '08:00', ['shift_id' => $this->pagi->id, 'company_location_id' => $this->luuca->id, 'check_out' => self::D.' 17:00:00']);
        $this->actingAs($this->account(['Admin']));

        Livewire::test(ListAttendances::class)
            ->callAction(TestAction::make('koreksiJadwal')->table($row), ['corrected_shift_id' => $this->closing->id, 'corrected_company_location_id' => $this->jchicken->id, 'reason' => 'Shift sebenarnya closing'])
            ->assertNotified('Koreksi jadwal diajukan');

        $correction = AttendanceCorrection::sole();
        $this->assertSame(['pending', 'admin', $row->id, $this->closing->id, $this->jchicken->id], [$correction->status, $correction->origin, $correction->attendance_id, $correction->corrected_shift_id, $correction->corrected_company_location_id]);
        $this->assertSame(1, $correction->approvalSteps()->where('status', 'pending')->count());
        $this->assertSame($this->pagi->id, $row->fresh()->shift_id);
    }

    // ── Admin edits leave a record ───────────────────────────────────────────

    public function test_an_admin_edit_and_an_admin_delete_each_leave_an_admin_correction_with_the_values_before(): void
    {
        $worker = $this->employee($this->pagi);
        $row = $this->attend($worker, self::D, '08:30', ['status' => 'late', 'late_minutes' => 30, 'check_out' => self::D.' 17:00:00', 'shift_id' => $this->pagi->id]);
        $admin = $this->account(['Admin']);
        $this->actingAs($admin);

        Livewire::test(EditAttendance::class, ['record' => $row->getRouteKey()])
            ->fillForm(['check_in' => self::D.' 08:00'])
            ->call('save')
            ->assertHasNoFormErrors();

        $row->refresh();
        $this->assertSame([AttendanceStatus::OnTime, 0], [$row->status, (int) $row->late_minutes], 'The moved check-in is judged again.');

        $edit = AttendanceCorrection::query()->where('origin', 'admin')->sole();
        $this->assertSame(['approved', $row->id, '2026-11-10 08:30:00', 'late', 30], [$edit->status, $edit->attendance_id, $edit->before_values['check_in'], $edit->before_values['status'], $edit->before_values['late_minutes']]);
        $this->assertSame('2026-11-10 08:00:00', $edit->corrected_check_in->toDateTimeString());
        $this->assertSame([$admin->id, 'approved'], [$edit->approvalSteps()->sole()->actioned_by, $edit->approvalSteps()->sole()->status]);

        // Saving only the notes changes no time, status or lateness: no record.
        Livewire::test(EditAttendance::class, ['record' => $row->getRouteKey()])
            ->fillForm(['notes' => 'Dicek ulang'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(1, AttendanceCorrection::query()->where('origin', 'admin')->count());

        // The record of an edit is history: the worker may still file a correction of that check-in.
        Sanctum::actingAs($worker->user);
        $this->correct(['date' => self::D, 'corrected_check_in' => '07:55'])->assertCreated();

        $this->actingAs($admin);
        Livewire::test(EditAttendance::class, ['record' => $row->getRouteKey()])
            ->callAction(DeleteAction::class);

        $this->assertNull(Attendance::find($row->id));
        $deletion = AttendanceCorrection::query()->where('origin', 'admin')->latest('id')->first();
        $this->assertNotSame($edit->id, $deletion->id);
        $this->assertSame(['approved', null, '2026-11-10 08:00:00', 'on_time'], [$deletion->status, $deletion->attendance_id, $deletion->before_values['check_in'], $deletion->before_values['status']]);
        $this->assertSame('Dihapus admin', $deletion->approvalSteps()->sole()->step_name);
    }

    public function test_the_panel_refuses_changing_the_times_of_a_correction_already_decided(): void
    {
        $worker = $this->employee($this->pagi);
        $correction = AttendanceCorrection::create(['employee_id' => $worker->id, 'date' => self::D, 'corrected_check_in' => '08:00', 'corrected_check_out' => '17:00', 'reason' => 'Lupa absen', 'status' => 'pending']);
        $correction->applyCorrection();
        $this->actingAs($this->account(['Admin']));

        Livewire::test(EditAttendanceCorrection::class, ['record' => $correction->getRouteKey()])
            ->fillForm(['corrected_check_in' => '07:00'])
            ->call('save')
            ->assertHasFormErrors(['corrected_check_in']);

        $this->assertSame('08:00', $correction->fresh()->corrected_check_in->format('H:i'));
    }

    // ── Changing a pending correction keeps its target ───────────────────────

    public function test_a_reason_only_update_from_the_app_or_the_panel_keeps_the_schedule_named_at_submission(): void
    {
        [$worker, $second] = $this->twoScheduleDay();
        Sanctum::actingAs($worker->user);

        // 15:00 fits both sessions, so only the named schedule tells them apart.
        $id = $this->correct(['date' => self::D, 'schedule_id' => $second->id, 'corrected_check_in' => '15:00', 'corrected_check_out' => '22:00'])
            ->assertCreated()
            ->json('data.id');

        $this->putJson("/api/v1/attendance-corrections/{$id}", ['reason' => 'Alasan baru'])
            ->assertOk()
            ->assertJsonPath('data.schedule_id', $second->id)
            ->assertJsonPath('data.sequence', 2);

        $correction = AttendanceCorrection::findOrFail($id);
        $this->assertSame([$second->id, 2, 1, 'Alasan baru'], [$correction->shift_schedule_id, $correction->target_sequence, $correction->target_segment, $correction->reason]);

        // Changed times that still fit both sessions are settled by the schedule named before.
        $this->putJson("/api/v1/attendance-corrections/{$id}", ['corrected_check_in' => '15:05'])
            ->assertOk()
            ->assertJsonPath('data.sequence', 2);

        $this->actingAs($this->account(['Admin']));
        Livewire::test(EditAttendanceCorrection::class, ['record' => $correction->getRouteKey()])
            ->fillForm(['reason' => 'Diperbaiki admin'])
            ->call('save')
            ->assertHasNoFormErrors();

        $correction->refresh();
        $this->assertSame([$second->id, 2, 1, 'Diperbaiki admin'], [$correction->shift_schedule_id, $correction->target_sequence, $correction->target_segment, $correction->reason]);
    }

    public function test_a_panel_edit_of_a_split_shift_schedule_correction_keeps_its_second_part(): void
    {
        $split = Shift::create(['company_id' => $this->company->id, 'name' => 'Split', 'code' => 'SP', 'start_time' => '08:00', 'end_time' => '13:00', 'second_start_time' => '17:00', 'second_end_time' => '22:00', 'late_tolerance_minutes' => 5]);
        $worker = $this->employee($split);
        $schedule = ShiftSchedule::create(['employee_id' => $worker->id, 'date' => self::D, 'sequence' => 1, 'shift_id' => $split->id, 'company_location_id' => $this->luuca->id]);
        $this->attend($worker, self::D, '08:00', ['shift_schedule_id' => $schedule->id, 'check_out' => self::D.' 13:00:00']);
        $this->attend($worker, self::D, '17:00', ['shift_schedule_id' => $schedule->id, 'segment' => 2, 'check_out' => self::D.' 22:00:00']);
        $correction = AttendanceCorrection::create(['employee_id' => $worker->id, 'date' => self::D, 'shift_schedule_id' => $schedule->id, 'target_sequence' => 1, 'target_segment' => 2, 'corrected_company_location_id' => $this->jchicken->id, 'reason' => 'Outlet sebenarnya', 'status' => 'pending', 'origin' => AttendanceCorrection::ORIGIN_ADMIN]);
        $this->actingAs($this->account(['Admin']));

        Livewire::test(EditAttendanceCorrection::class, ['record' => $correction->getRouteKey()])
            ->fillForm(['reason' => 'Outlet sebenarnya JChicken'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([2, 'Outlet sebenarnya JChicken'], [$correction->fresh()->target_segment, $correction->fresh()->reason]);
    }

    public function test_a_panel_edit_of_a_next_day_check_out_back_to_the_same_evening_clears_the_next_day_flag(): void
    {
        $worker = $this->employee($this->closing);
        $this->attend($worker, self::D, '14:00');
        Sanctum::actingAs($worker->user);
        $id = $this->correct(['date' => self::D, 'corrected_check_out' => '00:10'])->assertCreated()->json('data.id');
        $this->assertTrue(AttendanceCorrection::findOrFail($id)->check_out_next_day);

        $this->actingAs($this->account(['Admin']));
        Livewire::test(EditAttendanceCorrection::class, ['record' => $id])
            ->fillForm(['corrected_check_out' => '21:30'])
            ->call('save')
            ->assertHasNoFormErrors();

        $correction = AttendanceCorrection::findOrFail($id);
        $this->assertFalse($correction->check_out_next_day);
        $this->assertSame('2026-11-10 21:30:00', $correction->corrected_check_out->toDateTimeString());

        // Saving the same next-day check-out again keeps it on the next day.
        Livewire::test(EditAttendanceCorrection::class, ['record' => $id])
            ->fillForm(['corrected_check_out' => '00:20'])
            ->call('save')
            ->assertHasNoFormErrors();
        Livewire::test(EditAttendanceCorrection::class, ['record' => $id])
            ->fillForm(['reason' => 'Alasan lain'])
            ->call('save')
            ->assertHasNoFormErrors();

        $correction->refresh();
        $this->assertTrue($correction->check_out_next_day);
        $this->assertSame('2026-11-11 00:20:00', $correction->corrected_check_out->toDateTimeString());
    }

    public function test_the_panel_saves_a_schedule_correction_and_an_admin_record_without_times(): void
    {
        $worker = $this->employee($this->pagi);
        $row = $this->attend($worker, self::D, '08:00', ['shift_id' => $this->pagi->id, 'check_out' => self::D.' 17:00:00']);
        $this->actingAs($admin = $this->account(['Admin']));
        $scheduleOnly = AttendanceCorrection::create(['employee_id' => $worker->id, 'date' => self::D, 'attendance_id' => $row->id, 'corrected_shift_id' => $this->closing->id, 'reason' => 'Shift sebenarnya', 'status' => 'pending', 'origin' => AttendanceCorrection::ORIGIN_ADMIN]);

        Livewire::test(EditAttendanceCorrection::class, ['record' => $scheduleOnly->getRouteKey()])
            ->fillForm(['reason' => 'Shift sebenarnya closing'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('Shift sebenarnya closing', $scheduleOnly->fresh()->reason);

        $scheduleOnly->delete();
        $deletion = AttendanceCorrection::recordAdminChange($row, $row->auditValues(), $admin, 'Dihapus admin di halaman Data Absensi.', deleted: true);

        Livewire::test(EditAttendanceCorrection::class, ['record' => $deletion->getRouteKey()])
            ->fillForm(['reason' => 'Dihapus karena ganda'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('Dihapus karena ganda', $deletion->fresh()->reason);

        // A worker's own correction still needs a time, a shift or an outlet.
        $own = AttendanceCorrection::create(['employee_id' => $worker->id, 'date' => self::D, 'corrected_check_in' => '07:50', 'reason' => 'Lupa', 'status' => 'pending']);
        Livewire::test(EditAttendanceCorrection::class, ['record' => $own->getRouteKey()])
            ->fillForm(['corrected_check_in' => null, 'corrected_check_out' => null])
            ->call('save')
            ->assertHasFormErrors(['corrected_check_in' => 'required_without']);
    }

    public function test_the_worker_list_and_detail_leave_out_the_records_of_admin_edits(): void
    {
        $worker = $this->employee($this->pagi);
        $row = $this->attend($worker, self::D, '08:30', ['check_out' => self::D.' 17:00:00']);
        $admin = $this->account(['Admin']);
        $edit = AttendanceCorrection::recordAdminChange($row, $row->auditValues(), $admin, 'Diubah admin di halaman Data Absensi.');
        $schedule = AttendanceCorrection::create(['employee_id' => $worker->id, 'date' => self::D, 'attendance_id' => $row->id, 'corrected_shift_id' => $this->closing->id, 'reason' => 'Shift sebenarnya', 'status' => 'pending', 'origin' => AttendanceCorrection::ORIGIN_ADMIN]);
        Sanctum::actingAs($worker->user);
        $own = $this->correct(['date' => self::D, 'corrected_check_in' => '08:00'])->assertCreated()->json('data.id');

        $listed = collect($this->getJson('/api/v1/attendance-corrections')->assertOk()->assertJsonPath('pagination.total', 2)->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame(collect([$own, $schedule->id])->sort()->values()->all(), $listed);

        $this->getJson("/api/v1/attendance-corrections/{$edit->id}")->assertNotFound();
        $this->getJson("/api/v1/attendance-corrections/{$own}")->assertOk();

        Sanctum::actingAs($admin);
        $this->getJson("/api/v1/attendance-corrections/{$edit->id}")->assertOk();
    }

    // ── Review fixes ─────────────────────────────────────────────────────────

    /**
     * @return array<string, array{bool}>
     */
    public static function rosterFlags(): array
    {
        return ['fixed-hours company' => [false], 'roster company' => [true]];
    }

    #[DataProvider('rosterFlags')]
    public function test_an_approved_schedule_correction_of_standing_hours_keeps_the_worker_off_the_roster(bool $usesShiftRoster): void
    {
        $this->company->policy()->update(['uses_shift_roster' => $usesShiftRoster]);
        $worker = $this->employee($this->pagi);
        $row = $this->attend($worker, self::D, '08:00', ['shift_id' => $this->pagi->id, 'company_location_id' => $this->luuca->id, 'check_out' => self::D.' 17:00:00']);
        $this->actingAs($this->account(['Admin']));

        Livewire::test(ListAttendances::class)
            ->callAction(TestAction::make('koreksiJadwal')->table($row), ['corrected_company_location_id' => $this->jchicken->id, 'reason' => 'Outlet sebenarnya JChicken'])
            ->assertNotified('Koreksi jadwal diajukan');
        $this->approve(AttendanceCorrection::sole());

        $row->refresh();
        $this->assertSame('approved', AttendanceCorrection::sole()->status);
        $this->assertSame(0, ShiftSchedule::query()->where('employee_id', $worker->id)->count(), 'Standing hours are not turned into a roster.');
        $this->assertSame([$this->pagi->id, $this->jchicken->id, null], [(int) $row->shift_id, (int) $row->company_location_id, $row->shift_schedule_id]);
        $this->assertSame(['Pagi', 'JChicken Ciledug', 'default', false], [$row->sched_shift_name, $row->sched_outlet_name, $row->sched_source, (bool) $row->is_off_schedule]);

        // A later weekday (and an earlier one of the same week) still has the standing session, expected.
        foreach (['2026-11-09', '2026-11-17'] as $date) {
            $session = app(SessionResolver::class)->standingSession($worker->fresh(), Carbon::parse($date));
            $this->assertNotNull($session, $date);
            $this->assertTrue($session->isExpected, $date);
        }

        $day = app(AttendanceLedger::class)->days(LedgerQuery::forWorkers(['employee:'.$worker->id], '2026-11-17', '2026-11-17'))->collect()->keyBy('date')->get('2026-11-17');
        $this->assertTrue($day['expected']);
        $this->assertSame('absent', $day['status']);
    }

    public function test_a_schedule_correction_before_a_mid_week_first_roster_row_writes_no_roster_row(): void
    {
        $this->company->policy()->update(['uses_shift_roster' => true]);
        $worker = $this->employee($this->pagi);
        // The first roster row is Thursday: Tuesday and Wednesday of that week are on fixed hours.
        ShiftSchedule::create(['employee_id' => $worker->id, 'date' => '2026-11-12', 'shift_id' => $this->pagi->id, 'company_location_id' => $this->luuca->id]);
        $row = $this->attend($worker, self::D, '08:00', ['shift_id' => $this->pagi->id, 'company_location_id' => $this->luuca->id, 'check_out' => self::D.' 17:00:00']);
        $this->actingAs($this->account(['Admin']));

        $this->approve(AttendanceResource::fileScheduleCorrection($row, ['corrected_company_location_id' => $this->jchicken->id, 'reason' => 'Outlet sebenarnya JChicken']));

        $this->assertSame('approved', AttendanceCorrection::sole()->status);
        $this->assertFalse(ShiftSchedule::query()->where('employee_id', $worker->id)->whereDate('date', self::D)->exists(), 'The roster still starts on Thursday.');
        $this->assertSame([$this->jchicken->id, null, 'default'], [(int) $row->fresh()->company_location_id, $row->fresh()->shift_schedule_id, $row->fresh()->sched_source]);

        $wednesday = app(SessionResolver::class)->standingSession($worker->fresh(), Carbon::parse('2026-11-11'));
        $this->assertNotNull($wednesday);
        $this->assertTrue($wednesday->isExpected);
    }

    public function test_a_schedule_correction_of_a_worker_whose_only_row_is_a_cover_writes_no_roster_row(): void
    {
        $this->company->policy()->update(['uses_shift_roster' => true]);
        $worker = $this->employee($this->pagi);
        // An approved Saturday cover the week before: its own row never ends the fixed hours.
        $cover = RosterChangeContext::run('cover', null, fn (): ShiftSchedule => ShiftSchedule::create(['employee_id' => $worker->id, 'date' => '2026-11-07', 'shift_id' => $this->pagi->id, 'company_location_id' => $this->luuca->id]));
        $this->attend($worker, '2026-11-07', '08:00', ['shift_id' => $this->pagi->id, 'shift_schedule_id' => $cover->id, 'is_off_schedule' => true, 'check_out' => '2026-11-07 17:00:00']);
        $row = $this->attend($worker, self::D, '08:00', ['shift_id' => $this->pagi->id, 'company_location_id' => $this->luuca->id, 'check_out' => self::D.' 17:00:00']);
        $this->actingAs($this->account(['Admin']));

        $this->approve(AttendanceResource::fileScheduleCorrection($row, ['corrected_company_location_id' => $this->jchicken->id, 'reason' => 'Outlet sebenarnya JChicken']));

        $this->assertSame('approved', AttendanceCorrection::sole()->status);
        $this->assertSame([$cover->id], ShiftSchedule::query()->where('employee_id', $worker->id)->pluck('id')->all(), 'No roster row is written.');
        $this->assertTrue(app(SessionResolver::class)->standingSession($worker->fresh(), Carbon::parse('2026-11-11'))?->isExpected);
    }

    public function test_a_later_schedule_correction_supersedes_an_approved_one_on_a_locked_row(): void
    {
        $worker = $this->employee();
        $schedule = ShiftSchedule::create(['employee_id' => $worker->id, 'date' => self::D, 'shift_id' => $this->pagi->id, 'company_location_id' => $this->luuca->id]);
        $row = $this->attend($worker, self::D, '08:00', ['check_out' => self::D.' 17:00:00', 'shift_id' => $this->pagi->id, 'shift_schedule_id' => $schedule->id, 'company_location_id' => $this->luuca->id]);
        $this->actingAs($this->account(['Admin']));

        $wrong = AttendanceResource::fileScheduleCorrection($row, ['corrected_company_location_id' => $this->jchicken->id, 'reason' => 'Salah pilih outlet']);
        $this->approve($wrong);
        $this->assertSame($this->jchicken->id, (int) $schedule->fresh()->company_location_id);

        $fix = AttendanceResource::fileScheduleCorrection($row->fresh(), ['corrected_company_location_id' => $this->luuca->id, 'reason' => 'Outlet yang benar Luuca']);
        $this->assertSame('pending', $fix->status);
        $this->approve($fix);

        $this->assertSame('approved', $fix->fresh()->status);
        $this->assertSame($this->luuca->id, (int) $schedule->fresh()->company_location_id);
        $this->assertSame([$this->luuca->id, 'Luuca Ciledug'], [(int) $row->fresh()->company_location_id, $row->fresh()->sched_outlet_name]);
        $this->assertSame($this->jchicken->id, (int) $fix->fresh()->before_values['company_location_id']);

        // Two schedule corrections still waiting at the same time stay a duplicate.
        $first = AttendanceResource::fileScheduleCorrection($row->fresh(), ['corrected_shift_id' => $this->closing->id, 'reason' => 'Shift closing']);
        $this->assertSame('pending', $first->status);
        try {
            AttendanceResource::fileScheduleCorrection($row->fresh(), ['corrected_shift_id' => $this->pagi->id, 'reason' => 'Lagi']);
            $this->fail('A second schedule correction while one is pending must be refused.');
        } catch (ValidationException $exception) {
            $this->assertSame(AttendanceCorrection::DUPLICATE_MESSAGE, collect($exception->errors())->flatten()->first());
        }
    }

    public function test_a_check_out_alone_from_the_old_app_closes_the_forgotten_session_not_a_rejected_one(): void
    {
        [$worker, $second] = $this->twoScheduleDay();
        $first = ShiftSchedule::query()->where('employee_id', $worker->id)->where('sequence', 1)->sole();
        $this->attend($worker, self::D, '08:00', ['schedule_sequence' => 1, 'shift_schedule_id' => $first->id]);
        $this->attend($worker, self::D, '16:00', ['schedule_sequence' => 2, 'shift_schedule_id' => $second->id, 'status' => 'rejected']);
        Sanctum::actingAs($worker->user);

        $id = $this->correct(['date' => self::D, 'corrected_check_out' => '15:05'])->assertCreated()->json('data.id');

        $correction = AttendanceCorrection::findOrFail($id);
        $this->assertSame([1, $first->id], [(int) $correction->target_sequence, (int) $correction->shift_schedule_id]);

        $this->approve($correction);
        $closed = Attendance::query()->where('employee_id', $worker->id)->where('schedule_sequence', 1)->sole();
        $this->assertSame('2026-11-10 15:05:00', $closed->check_out->toDateTimeString());
        $this->assertNull(Attendance::query()->where('employee_id', $worker->id)->where('schedule_sequence', 2)->sole()->check_out);
    }

    public function test_the_worker_cannot_cancel_or_change_a_correction_an_admin_filed(): void
    {
        $worker = $this->employee($this->pagi);
        $row = $this->attend($worker, self::D, '08:00', ['shift_id' => $this->pagi->id, 'check_out' => self::D.' 17:00:00']);
        $this->actingAs($this->account(['Admin']));
        $correction = AttendanceResource::fileScheduleCorrection($row, ['corrected_shift_id' => $this->closing->id, 'reason' => 'Shift sebenarnya closing']);
        Sanctum::actingAs($worker->user);

        $this->putJson("/api/v1/attendance-corrections/{$correction->id}", ['corrected_check_in' => '07:00', 'reason' => 'Diubah pekerja'])
            ->assertUnprocessable()
            ->assertJsonPath('message', AttendanceCorrection::ADMIN_FILED_MESSAGE);
        $this->deleteJson("/api/v1/attendance-corrections/{$correction->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', AttendanceCorrection::ADMIN_FILED_MESSAGE);

        $correction->refresh();
        $this->assertSame(['pending', null, 'Shift sebenarnya closing', $this->closing->id], [$correction->status, $correction->corrected_check_in, $correction->reason, (int) $correction->corrected_shift_id]);
        $this->assertSame(1, $correction->approvalSteps()->count());

        // The worker's own pending request can still be cancelled.
        $own = $this->correct(['date' => '2026-11-11', 'corrected_check_in' => '08:00', 'corrected_check_out' => '17:00'])->assertCreated()->json('data.id');
        $this->deleteJson("/api/v1/attendance-corrections/{$own}")->assertOk();
    }

    public function test_the_record_of_an_admin_deletion_is_not_work_history(): void
    {
        $worker = $this->employee($this->pagi);
        $row = $this->attend($worker, self::D, '08:00', ['shift_id' => $this->pagi->id]);
        $this->actingAs($this->account(['Admin']));
        $accounts = app(WorkerAccountService::class);
        $this->assertTrue($accounts->hasWorkHistory($worker));

        Livewire::test(EditAttendance::class, ['record' => $row->getRouteKey()])->callAction(DeleteAction::class);

        $this->assertSame(1, AttendanceCorrection::query()->where('origin', 'admin')->count());
        $this->assertFalse($accounts->hasWorkHistory($worker));
        $this->assertFalse((bool) $accounts->withWorkHistoryFlag(Employee::query()->whereKey($worker->id))->sole()->{WorkerAccountService::WORK_HISTORY_FLAG});

        // A worker's own correction (or an admin's schedule correction) still is.
        AttendanceCorrection::create(['employee_id' => $worker->id, 'date' => self::D, 'corrected_check_in' => self::D.' 08:00:00', 'reason' => 'Lupa absen', 'status' => 'pending']);
        $this->assertTrue($accounts->hasWorkHistory($worker));
        $this->assertTrue((bool) $accounts->withWorkHistoryFlag(Employee::query()->whereKey($worker->id))->sole()->{WorkerAccountService::WORK_HISTORY_FLAG});
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * A worker with two schedules on the date: 08:00-15:00 at Luuca and 16:00-22:00 at JChicken.
     *
     * @return array{0: Employee, 1: ShiftSchedule}
     */
    private function twoScheduleDay(): array
    {
        $worker = $this->employee();
        $evening = Shift::create(['company_id' => $this->company->id, 'name' => 'Sore', 'code' => 'S', 'start_time' => '16:00', 'end_time' => '22:00', 'late_tolerance_minutes' => 5]);
        $morning = Shift::create(['company_id' => $this->company->id, 'name' => 'Siang', 'code' => 'SG', 'start_time' => '08:00', 'end_time' => '15:00', 'late_tolerance_minutes' => 5]);
        ShiftSchedule::create(['employee_id' => $worker->id, 'date' => self::D, 'sequence' => 1, 'shift_id' => $morning->id, 'company_location_id' => $this->luuca->id]);
        $second = ShiftSchedule::create(['employee_id' => $worker->id, 'date' => self::D, 'sequence' => 2, 'shift_id' => $evening->id, 'company_location_id' => $this->jchicken->id]);

        return [$worker, $second];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function correct(array $payload): TestResponse
    {
        return $this->postJson('/api/v1/attendance-corrections', ['reason' => 'Koreksi', ...$payload]);
    }

    private function expectApprovalRefused(AttendanceCorrection $correction, string $message): void
    {
        try {
            $this->approve($correction);
            $this->fail('The approval should have been refused.');
        } catch (ValidationException $exception) {
            $this->assertSame($message, $exception->errors()['approve'][0]);
        }

        $this->assertSame('pending', $correction->fresh()->status);
    }

    private function approve(AttendanceCorrection $correction): void
    {
        app(ApprovalFlowService::class)->approveStep($correction->fresh(), $this->approver);
    }

    private function rowOf(Employee $worker): Attendance
    {
        return Attendance::where('employee_id', $worker->id)->sole();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function attend(Employee $worker, string $date, string $checkIn, array $attributes = []): Attendance
    {
        $row = new Attendance;
        $row->forceFill([
            'employee_id' => $worker->id,
            'date' => $date,
            'segment' => 1,
            'check_in' => "{$date} {$checkIn}:00",
            'status' => 'on_time',
            'late_minutes' => 0,
            ...$attributes,
        ])->save();

        return $row->fresh();
    }

    /**
     * @param  list<string>  $roles
     */
    private function account(array $roles): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['tenant_id' => $this->tenant->id])->save();
        $user->assignRole($roles);
        $user->grantDataAccess(DataScope::All);

        return $user;
    }

    private function employee(?Shift $shift = null): Employee
    {
        $user = $this->account(['Employee']);

        return Employee::create([
            'user_id' => $user->id, 'company_id' => $this->company->id, 'division_id' => $this->division->id, 'shift_id' => $shift?->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_number' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Crew'])->id,
            'nip' => 'PFR-'.$user->id, 'full_name' => 'Pekerja '.$user->id, 'join_date' => '2026-01-01', 'status' => 'active',
        ]);
    }
}
