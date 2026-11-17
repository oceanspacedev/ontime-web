<?php

namespace Tests\Feature\Chaos;

use App\Enums\AttendanceStatus;
use App\Enums\DataScope;
use App\Filament\Resources\Attendances\Pages\EditAttendance;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalFlowService;
use App\Services\AttendanceCheckService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Chaos lens: concurrency and retries. SQLite cannot run two requests at once, so the competing
 * request is written between the read and the write of the one under test (a model "retrieved"
 * hook or a query listener), as in AttendanceCheckRaceAndLeaveTest.
 *
 * Methods named test_finding_* fail on the current code and prove a defect; test_survives_* are the
 * scenarios the app already handles, kept as regression tests.
 */
class Chaos_concurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-11-16';

    private const FRIDAY = '2026-11-13';

    private Company $company;

    private Division $division;

    private Shift $morning;

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

        $this->seed(RoleSeeder::class);
        $this->travelTo(Carbon::parse(self::MONDAY.' 06:00:00'));

        $tenant = Tenant::factory()->create(['name' => 'Klien Chaos', 'code' => 'KC', 'is_active' => true]);
        $this->company = new Company(['name' => 'PT Chaos', 'code' => 'CHAOS', 'is_active' => true]);
        $this->company->tenant_id = $tenant->id;
        $this->company->save();
        app(ApprovalFlowService::class)->createDefaultFlows($this->company);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Operasional', 'code' => 'OPS', 'is_active' => true]);

        $this->company->policy()->update([
            'require_gps' => false,
            'require_photo' => false,
            'require_face_recognition' => false,
            'require_liveness' => false,
            'work_start_time' => '08:00:00',
            'work_end_time' => '17:00:00',
            'late_tolerance_minutes' => 0,
        ]);
        $this->company->unsetRelation('policy');

        $this->morning = $this->shift('Pagi', '08:00', '16:00');
    }

    // ── Findings ────────────────────────────────────────────────────────────

    /**
     * Leave approval vs check-in: the final approval checks the leave days for a check-in, then a
     * check-in commits, then applyLeave() writes status "leave" over the checked-in row.
     *
     * On MySQL the check reads the leave days with a locking read, so a check-in either commits before
     * it (and is seen) or waits for the approval. SQLite has one connection, so the simulated check-in
     * runs inside the approval's own transaction and is rolled back with the refused approval: the test
     * asserts the refusal itself (applyLeave() re-reads the rows under a lock and refuses a check-in)
     * and that no row is left with the leave status.
     */
    public function test_finding_leave_approval_buries_a_check_in_that_lands_after_its_check(): void
    {
        $employee = $this->employee('Andi');
        $leave = $this->leave($employee, self::MONDAY, self::MONDAY);
        $this->at('07:58:00');

        // The worker's check-in commits right after the approval looked for check-ins on the leave days.
        $done = false;
        DB::listen(function ($query) use ($employee, &$done): void {
            if (! $done && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'from "attendances"') && str_contains($query->sql, '"check_in" is not null')) {
                $done = true;
                Attendance::create([
                    'employee_id' => $employee->id,
                    'date' => self::MONDAY,
                    'segment' => 1,
                    'shift_id' => $this->morning->id,
                    'check_in' => now(),
                    'status' => AttendanceStatus::OnTime,
                    'late_minutes' => 0,
                ]);
            }
        });

        $refusal = null;

        try {
            app(ApprovalFlowService::class)->approveStep($leave->fresh(), $this->approver());
        } catch (ValidationException $exception) {
            $refusal = $exception;
        }

        $this->assertTrue($done, 'The simulated check-in never ran.');
        $this->assertNotNull($refusal, 'The leave was approved over a day the worker checked in.');
        $this->assertStringContainsString('sudah melakukan presensi', $refusal->errors()['approve'][0]);
        $this->assertSame('pending', $leave->fresh()->status, 'The leave was approved over a day the worker checked in.');
        $this->assertFalse(
            Attendance::query()->where('employee_id', $employee->id)->where('status', AttendanceStatus::Leave->value)->exists(),
            'A check-in was overwritten with the leave status.',
        );
    }

    /**
     * Correction approval vs leave approval on the same past date: the leave lands first (no check-in
     * yet, so it passes), then the correction is approved and turns the approved leave day into a worked day.
     * In the other order the leave is refused (checkedInDates), so the outcome depends on who clicks first.
     */
    public function test_finding_correction_approved_after_a_leave_turns_the_leave_day_into_a_worked_day(): void
    {
        $this->at('10:00:00');
        $employee = $this->employee('Budi');
        $correction = AttendanceCorrection::create([
            'employee_id' => $employee->id,
            'date' => self::FRIDAY,
            'corrected_check_in' => '08:00',
            'corrected_check_out' => '17:00',
            'reason' => 'Lupa absen',
            'status' => 'pending',
        ]);
        app(ApprovalFlowService::class)->generateSteps($correction, 'correction');
        $leave = $this->leave($employee, self::FRIDAY, self::FRIDAY);

        // Two approvers, two inbox items for the same day: the leave is approved first.
        app(ApprovalFlowService::class)->approveStep($leave->fresh(), $this->approver());
        $this->assertSame(AttendanceStatus::Leave, Attendance::query()->where('employee_id', $employee->id)->sole()->status);

        try {
            app(ApprovalFlowService::class)->approveStep($correction->fresh(), $this->approver());
        } catch (ValidationException) {
            // Refusing the correction on an approved leave day is the expected outcome.
        }

        $row = Attendance::query()->where('employee_id', $employee->id)->sole();
        $this->assertSame('approved', $leave->fresh()->status);
        $this->assertSame(AttendanceStatus::Leave, $row->status, 'The approved leave day became '.$row->status->value.' while the leave stays approved.');
        $this->assertNull($row->check_in);
    }

    /**
     * Roster edit vs check-in: the check-in resolves the morning shift, the PIC moves the worker to the
     * late shift and commits (its lateness recompute finds no row yet), then the check-in is saved. The
     * check-in re-reads the roster under a lock but only refuses a day off, so it is stored against the
     * shift the roster no longer has, late by the old start, and nothing recomputes it.
     */
    public function test_finding_check_in_is_judged_against_a_roster_changed_while_it_runs(): void
    {
        $late = $this->shift('Siang', '10:00', '18:00');
        $employee = $this->employee('Citra');
        $schedule = ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::MONDAY, 'shift_id' => $this->morning->id]);
        $this->at('09:30:00');

        $done = false;
        ShiftSchedule::retrieved(function (ShiftSchedule $retrieved) use ($schedule, $late, &$done): void {
            if (! $done && $retrieved->is($schedule)) {
                $done = true;
                DB::table('shift_schedules')->where('id', $schedule->id)->update(['shift_id' => $late->id]);
            }
        });

        Sanctum::actingAs($employee->user);
        $response = $this->postJson('/api/v1/attendance/check-in');

        $this->assertTrue($done);
        $this->assertSame($late->id, (int) $schedule->fresh()->shift_id);
        $attendance = Attendance::query()->where('employee_id', $employee->id)->first();

        $this->assertTrue(
            $attendance === null || ((int) $attendance->shift_id === $late->id && (int) $attendance->late_minutes === 0),
            sprintf(
                'Check-in (HTTP %d) stored against shift %s with %s late minutes, while the roster of the day is Siang 10:00 (0 late).',
                $response->status(),
                $attendance?->shift_id,
                $attendance?->late_minutes,
            ),
        );
    }

    /**
     * Roster edit vs off-schedule approval: the approval reads the backup's day off, the PIC gives the
     * backup a working shift and commits, then the approval writes the cover shift over the PIC's roster.
     * Done one after the other, the PIC's roster is kept (OffScheduleCheckInTest::
     * test_roster_given_by_the_pic_while_pending_is_not_overwritten_by_the_approval).
     */
    public function test_finding_off_schedule_approval_overwrites_a_roster_the_pic_set_while_it_runs(): void
    {
        $evening = $this->shift('Sore', '09:00', '17:00');
        $employee = $this->employee('Dewi');
        $dayOff = ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::MONDAY, 'is_day_off' => true]);
        $this->at('08:00:00');
        Sanctum::actingAs($employee->user);
        $this->postJson('/api/v1/attendance/check-in', ['cover_shift_id' => $this->morning->id])
            ->assertOk()
            ->assertJsonPath('requires_approval', true);
        $row = Attendance::query()->where('employee_id', $employee->id)->sole();

        $done = false;
        ShiftSchedule::retrieved(function (ShiftSchedule $retrieved) use ($dayOff, $evening, &$done): void {
            $insideApply = collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS))->contains(fn (array $frame): bool => ($frame['function'] ?? null) === 'applyApproval');

            if (! $done && $insideApply && $retrieved->is($dayOff)) {
                $done = true;
                DB::table('shift_schedules')->where('id', $dayOff->id)->update(['is_day_off' => false, 'shift_id' => $evening->id, 'notes' => 'Dijadwalkan PIC']);
            }
        });

        app(ApprovalFlowService::class)->approveStep($row->fresh(), $this->approver());

        $this->assertTrue($done);
        $schedule = $dayOff->fresh();
        $this->assertSame($evening->id, (int) $schedule->shift_id, 'The roster the PIC set meanwhile was overwritten by the approval.');
        $this->assertSame('Dijadwalkan PIC', $schedule->notes);
    }

    /**
     * Admin edit vs worker check-out: the admin opens the attendance to fix a note, the worker checks out,
     * then the admin saves the form opened before the check-out. The stale empty check-out is saved as a
     * "moved" time: the check-out is erased and the row marked corrected. The overtime edit page refuses a
     * form older than an approver's action; the attendance edit page has no such guard.
     */
    public function test_finding_admin_edit_from_a_stale_form_erases_a_check_out_made_meanwhile(): void
    {
        $employee = $this->employee('Eka');
        $attendance = Attendance::create([
            'employee_id' => $employee->id,
            'date' => self::MONDAY,
            'segment' => 1,
            'shift_id' => $this->morning->id,
            'check_in' => self::MONDAY.' 08:00:00',
            'status' => AttendanceStatus::OnTime,
            'late_minutes' => 0,
        ]);
        $this->at('15:59:00');
        $this->actingAs($this->account(['Admin']));

        $page = Livewire::test(EditAttendance::class, ['record' => $attendance->getRouteKey()]);

        // The worker checks out while the admin's form is open (what AttendanceCheckService::checkOut() writes).
        $this->at('16:00:00');
        DB::table('attendances')->where('id', $attendance->id)->update(['check_out' => self::MONDAY.' 16:00:00', 'early_leave_minutes' => 0]);
        $this->assertNotNull($attendance->fresh()->check_out);

        $page->fillForm(['notes' => 'Dicek PIC'])->call('save');

        $fresh = $attendance->fresh();
        $this->assertNotNull($fresh->check_out, 'Saving a note from a form opened before the check-out erased the check-out.');
        $this->assertFalse((bool) $fresh->is_corrected);
    }

    // ── Survived ─────────────────────────────────────────────────────────────

    public function test_survives_double_tap_check_in_without_a_row_keeps_one_check_in(): void
    {
        $employee = $this->employee('Fajar');
        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::MONDAY, 'shift_id' => $this->morning->id]);
        $this->at('07:55:00');
        $first = now()->copy()->subSeconds(2);

        // The first tap inserts its row right after the second one found none.
        $done = false;
        DB::listen(function ($query) use ($employee, $first, &$done): void {
            if (! $done && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'from "attendances"')) {
                $done = true;
                Attendance::create(['employee_id' => $employee->id, 'date' => self::MONDAY, 'segment' => 1, 'shift_id' => $this->morning->id, 'check_in' => $first, 'status' => AttendanceStatus::OnTime]);
            }
        });

        Sanctum::actingAs($employee->user);
        $this->postJson('/api/v1/attendance/check-in')
            ->assertStatus(409)
            ->assertJsonPath('message', AttendanceCheckService::ALREADY_CHECKED_IN_MESSAGE);

        $row = Attendance::query()->where('employee_id', $employee->id)->sole();
        $this->assertTrue($row->check_in->equalTo($first));
    }

    public function test_survives_check_in_retried_after_a_timeout_keeps_the_first_time(): void
    {
        $employee = $this->employee('Gita');
        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::MONDAY, 'shift_id' => $this->morning->id]);
        Sanctum::actingAs($employee->user);

        $this->at('07:59:00');
        $this->postJson('/api/v1/attendance/check-in')->assertOk();
        $this->at('08:00:40');
        $this->postJson('/api/v1/attendance/check-in')->assertStatus(409);

        $row = Attendance::query()->where('employee_id', $employee->id)->sole();
        $this->assertSame('07:59', $row->check_in->format('H:i'));
        $this->assertSame(AttendanceStatus::OnTime, $row->status);
    }

    public function test_survives_check_out_retried_after_a_timeout_keeps_the_first_time(): void
    {
        $employee = $this->employee('Hadi');
        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::MONDAY, 'shift_id' => $this->morning->id]);
        Sanctum::actingAs($employee->user);
        $this->at('07:59:00');
        $this->postJson('/api/v1/attendance/check-in')->assertOk();

        $this->at('16:00:00');
        $this->postJson('/api/v1/attendance/check-out')->assertOk();
        $this->at('16:00:45');
        $this->postJson('/api/v1/attendance/check-out')->assertStatus(409);

        $this->assertSame('16:00:00', Attendance::query()->where('employee_id', $employee->id)->sole()->check_out->format('H:i:s'));
    }

    public function test_survives_two_approvers_approving_the_same_geofence_step(): void
    {
        $row = $this->pendingGeofenceRow('Indra');
        $seenByFirst = Attendance::findOrFail($row->id);
        $seenBySecond = Attendance::findOrFail($row->id);

        app(ApprovalFlowService::class)->approveStep($seenByFirst, $this->approver());

        $this->expectAlreadyProcessed(fn () => app(ApprovalFlowService::class)->approveStep($seenBySecond, $this->approver()));
        $this->assertSame(AttendanceStatus::OnTime, $row->fresh()->status);
        $this->assertSame(['approved'], $row->approvalSteps()->pluck('status')->all());
    }

    public function test_survives_reject_and_approve_at_once_on_a_geofence_step(): void
    {
        $row = $this->pendingGeofenceRow('Joko');
        $seenByFirst = Attendance::findOrFail($row->id);
        $seenBySecond = Attendance::findOrFail($row->id);

        app(ApprovalFlowService::class)->rejectStep($seenByFirst, $this->approver(), 'Di luar outlet');

        $this->expectAlreadyProcessed(fn () => app(ApprovalFlowService::class)->approveStep($seenBySecond, $this->approver()));
        $this->assertSame(AttendanceStatus::Rejected, $row->fresh()->status);
        $this->assertSame(['rejected'], $row->approvalSteps()->pluck('status')->all());
    }

    public function test_survives_correction_and_geofence_approval_on_the_same_row(): void
    {
        $row = $this->pendingGeofenceRow('Kiki', '08:30:00');
        $this->assertSame(30, $row->check_in_late_minutes);
        $correction = AttendanceCorrection::create([
            'employee_id' => $row->employee_id,
            'date' => self::MONDAY,
            'corrected_check_in' => '08:00',
            'reason' => 'Jam HP salah',
            'status' => 'pending',
        ]);
        app(ApprovalFlowService::class)->generateSteps($correction, 'correction');
        $staleGeofence = Attendance::findOrFail($row->id);

        // The correction lands first; the geofence approver acts on a copy loaded before it.
        app(ApprovalFlowService::class)->approveStep($correction->fresh(), $this->approver());
        $this->assertSame(AttendanceStatus::PendingApproval, $row->fresh()->status);
        app(ApprovalFlowService::class)->approveStep($staleGeofence, $this->approver());

        $fresh = $row->fresh();
        $this->assertSame('08:00', $fresh->check_in->format('H:i'));
        $this->assertSame(AttendanceStatus::OnTime, $fresh->status);
        $this->assertSame(0, $fresh->late_minutes);
    }

    public function test_survives_overtime_submitted_twice(): void
    {
        $employee = $this->employee('Lina');
        Sanctum::actingAs($employee->user);
        $payload = ['date' => self::MONDAY, 'start_time' => '17:00', 'end_time' => '20:00', 'reason' => 'Stok opname'];

        $this->postJson('/api/v1/overtime-requests', $payload)->assertCreated();
        $this->postJson('/api/v1/overtime-requests', $payload)->assertStatus(422);

        $this->assertSame(1, OvertimeRequest::query()->where('employee_id', $employee->id)->count());
    }

    public function test_survives_overtime_duplicate_inserted_while_the_second_submit_runs(): void
    {
        $employee = $this->employee('Maya');
        Sanctum::actingAs($employee->user);
        $payload = ['date' => self::MONDAY, 'start_time' => '17:00', 'end_time' => '20:00', 'reason' => 'Stok opname'];

        // The first submit commits right after the second one locked the worker row (MySQL would make
        // it wait; the overlap read that follows sees the first request either way).
        $done = false;
        DB::listen(function ($query) use ($employee, &$done): void {
            if (! $done && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'from "employees"') && str_contains($query->sql, '"employees"."id" =')) {
                $done = true;
                OvertimeRequest::create(['employee_id' => $employee->id, 'date' => self::MONDAY, 'start_time' => '17:00', 'end_time' => '20:00', 'duration_minutes' => 180, 'reason' => 'Stok opname', 'status' => 'pending']);
            }
        });

        // The simulated first request runs inside this request's transaction and is rolled back with it, so
        // only the refusal is asserted: the second submit never stores an overlapping request.
        $this->postJson('/api/v1/overtime-requests', $payload)->assertStatus(422);

        $this->assertTrue($done);
    }

    public function test_survives_check_in_after_the_contract_ended(): void
    {
        $freelancer = $this->freelancer('Nina');
        ShiftSchedule::create(['freelancer_id' => $freelancer->id, 'date' => self::MONDAY, 'shift_id' => $this->morning->id]);
        $freelancer->update(['status' => 'completed', 'end_date' => '2026-11-15']);
        $this->at('07:59:00');

        Sanctum::actingAs($freelancer->user);
        $this->postJson('/api/v1/attendance/check-in')->assertStatus(403);

        $this->assertSame(0, Attendance::query()->where('freelancer_id', $freelancer->id)->count());
    }

    public function test_survives_leave_approval_after_the_check_in_landed(): void
    {
        $employee = $this->employee('Oki');
        $leave = $this->leave($employee, self::MONDAY, self::MONDAY);
        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::MONDAY, 'shift_id' => $this->morning->id]);
        $this->at('07:58:00');
        Sanctum::actingAs($employee->user);
        $this->postJson('/api/v1/attendance/check-in')->assertOk();

        try {
            app(ApprovalFlowService::class)->approveStep($leave->fresh(), $this->approver());
            $this->fail('The leave was approved over a check-in.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('approve', $exception->errors());
        }

        $this->assertSame('pending', $leave->fresh()->status);
        $this->assertSame(AttendanceStatus::OnTime, Attendance::query()->where('employee_id', $employee->id)->sole()->status);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function at(string $time): void
    {
        $this->travelTo(Carbon::parse(self::MONDAY.' '.$time));
    }

    private function expectAlreadyProcessed(callable $action): void
    {
        try {
            $action();
            $this->fail('A request already processed was processed again.');
        } catch (ValidationException $exception) {
            $this->assertSame(ApprovalFlowService::ALREADY_PROCESSED_MESSAGE, collect($exception->errors())->flatten()->first());
        }
    }

    /**
     * A check-in outside the outlet, waiting for the geofence approver.
     */
    private function pendingGeofenceRow(string $name, string $checkIn = '08:00:00'): Attendance
    {
        $employee = $this->employee($name);
        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::MONDAY, 'shift_id' => $this->morning->id]);
        $row = Attendance::create([
            'employee_id' => $employee->id,
            'date' => self::MONDAY,
            'segment' => 1,
            'shift_id' => $this->morning->id,
            'check_in' => self::MONDAY.' '.$checkIn,
            'status' => AttendanceStatus::PendingApproval,
            'late_minutes' => 0,
            'check_in_late_minutes' => (int) Carbon::parse(self::MONDAY.' 08:00:00')->diffInMinutes(Carbon::parse(self::MONDAY.' '.$checkIn)),
            'is_out_of_bounds' => true,
        ]);
        app(ApprovalFlowService::class)->generateSteps($row, 'geofence');
        $this->at('09:00:00');

        return $row->fresh();
    }

    private function leave(Employee $employee, string $start, string $end): LeaveRequest
    {
        $leave = LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type' => 'sick',
            'start_date' => $start,
            'end_date' => $end,
            'days_count' => Carbon::parse($start)->diffInDays(Carbon::parse($end)) + 1,
            'reason' => 'Demam',
            'status' => 'pending',
        ]);
        app(ApprovalFlowService::class)->generateSteps($leave, 'leave');

        return $leave->fresh();
    }

    private function approver(): User
    {
        $approver = User::factory()->create(['is_active' => true]);
        $approver->forceFill(['tenant_id' => $this->company->tenant_id])->save();
        $approver->assignRole('Approver');
        $this->grantDataAccessTo($approver, $this->company->id, $this->division->id);

        return $approver;
    }

    /**
     * @param  list<string>  $roles
     */
    private function account(array $roles): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['tenant_id' => $this->company->tenant_id])->save();
        $user->assignRole($roles);
        $user->grantDataAccess(DataScope::All);

        return $user;
    }

    private function shift(string $name, string $start, string $end): Shift
    {
        return Shift::create(['company_id' => $this->company->id, 'name' => $name, 'start_time' => $start, 'end_time' => $end, 'late_tolerance_minutes' => 0, 'is_active' => true]);
    }

    private function employee(string $name): Employee
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['tenant_id' => $this->company->tenant_id])->save();
        $user->assignRole('Employee');

        return Employee::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_number' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Kasir'])->id,
            'nip' => 'CHS-'.$user->id,
            'full_name' => $name,
            'join_date' => '2026-01-01',
            'status' => 'permanent',
        ]);
    }

    private function freelancer(string $name): Freelancer
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['tenant_id' => $this->company->tenant_id])->save();
        $user->assignRole('Freelancer');

        return Freelancer::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'full_name' => $name,
            'start_date' => '2026-10-01',
            'end_date' => '2026-12-31',
            'status' => 'active',
        ]);
    }
}
