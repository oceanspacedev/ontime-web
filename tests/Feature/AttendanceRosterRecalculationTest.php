<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Filament\Resources\Attendances\Pages\EditAttendance;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalFlowService;
use App\Services\AttendanceLatenessService;
use App\Services\ShiftAssignmentService;
use App\Services\WorkScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * D4: an attendance result is locked to the schedule it was checked in to. Changing or removing a schedule
 * that has a checked-in attendance is refused (only an approved correction may), and a schedule created or
 * changed around a check-in it is not linked to leaves that attendance as it is. An admin moving the times
 * in the panel judges the row again against its own schedule (BUG-02). Lateness is counted to the second
 * (BUG-06).
 */
class AttendanceRosterRecalculationTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-11-16';

    private Company $company;

    private Division $division;

    private Shift $morning;

    private Shift $late;

    private Employee $employee;

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

        foreach (['Superadmin', 'Admin', 'PIC', 'Koordinator', 'Approver', 'BOD', 'Employee', 'Intern', 'Freelancer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->travelTo(Carbon::parse(self::MONDAY.' 18:00:00'));

        $tenant = Tenant::factory()->create(['name' => 'Klien A', 'code' => 'KA', 'is_active' => true]);
        $this->company = new Company(['name' => 'PT Resto', 'code' => 'RESTO', 'is_active' => true]);
        $this->company->tenant_id = $tenant->id;
        $this->company->save();
        app(ApprovalFlowService::class)->createDefaultFlows($this->company);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Dapur', 'code' => 'DPR', 'is_active' => true]);

        $this->morning = $this->shift('Pagi', '08:00', '16:00');
        $this->late = $this->shift('Siang', '10:00', '18:00');

        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['tenant_id' => $tenant->id])->save();
        $user->assignRole('Employee');
        $this->employee = Employee::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'job_level_id' => JobLevel::create(['name' => 'Staff', 'level_number' => 1])->id,
            'job_title_id' => JobTitle::create(['name' => 'Kasir'])->id,
            'nip' => 'EMP-1',
            'full_name' => 'Andi',
            'join_date' => '2026-01-01',
            'status' => 'permanent',
        ]);
    }

    public function test_roster_edit_of_a_checked_in_schedule_is_refused_and_the_row_keeps_its_verdict(): void
    {
        $schedule = ShiftSchedule::create(['employee_id' => $this->employee->id, 'date' => self::MONDAY, 'shift_id' => $this->morning->id]);
        $row = $this->row(['check_in' => '08:30:00', 'check_out' => '16:00:00', 'status' => AttendanceStatus::Late, 'late_minutes' => 30, 'shift_id' => $this->morning->id, 'shift_schedule_id' => $schedule->id]);

        $this->assertRefused(fn () => $schedule->update(['shift_id' => $this->late->id]));

        $row->refresh();
        $this->assertSame($this->morning->id, $schedule->fresh()->shift_id);
        $this->assertSame(AttendanceStatus::Late, $row->status);
        $this->assertSame(30, $row->late_minutes);
        $this->assertSame($this->morning->id, $row->shift_id);
        $this->assertSame(0, $row->early_leave_minutes);
    }

    public function test_schedule_created_after_the_check_in_leaves_the_row_unchanged(): void
    {
        $row = $this->row(['check_in' => '10:20:00', 'status' => AttendanceStatus::Late, 'late_minutes' => 140]);

        ShiftSchedule::create(['employee_id' => $this->employee->id, 'date' => self::MONDAY, 'shift_id' => $this->late->id]);

        $row->refresh();
        $this->assertSame(140, $row->late_minutes);
        $this->assertNull($row->shift_id);
    }

    public function test_deleting_a_checked_in_schedule_is_refused(): void
    {
        $this->employee->update(['shift_id' => $this->morning->id]);
        $schedule = ShiftSchedule::create(['employee_id' => $this->employee->id, 'date' => self::MONDAY, 'shift_id' => $this->late->id]);
        $row = $this->row(['check_in' => '09:00:00', 'status' => AttendanceStatus::OnTime, 'late_minutes' => 0, 'shift_id' => $this->late->id, 'shift_schedule_id' => $schedule->id]);

        $this->assertRefused(fn () => $schedule->delete());

        $this->assertNotNull($schedule->fresh());
        $row->refresh();
        $this->assertSame(AttendanceStatus::OnTime, $row->status);
        $this->assertSame(0, $row->late_minutes);
        $this->assertSame($this->late->id, $row->shift_id);
    }

    public function test_pending_row_keeps_its_verdict_when_its_schedule_cannot_change(): void
    {
        $schedule = ShiftSchedule::create(['employee_id' => $this->employee->id, 'date' => self::MONDAY, 'shift_id' => $this->late->id]);
        $row = $this->row(['check_in' => '10:30:00', 'status' => AttendanceStatus::PendingApproval, 'late_minutes' => 0, 'check_in_late_minutes' => 30, 'shift_id' => $this->late->id, 'shift_schedule_id' => $schedule->id]);

        $this->assertRefused(fn () => $schedule->update(['shift_id' => $this->morning->id]));

        $row->refresh();
        $this->assertSame(AttendanceStatus::PendingApproval, $row->status);
        $this->assertSame(0, $row->late_minutes);
        $this->assertSame(30, $row->check_in_late_minutes);
        $this->assertSame(30, $row->reportedLateMinutes());
    }

    public function test_rejected_and_leave_rows_are_never_touched(): void
    {
        $schedule = ShiftSchedule::create(['employee_id' => $this->employee->id, 'date' => self::MONDAY, 'shift_id' => $this->late->id]);
        $rejected = $this->row(['check_in' => '09:00:00', 'status' => AttendanceStatus::Rejected, 'late_minutes' => 0, 'shift_id' => $this->late->id]);

        $schedule->update(['shift_id' => $this->morning->id]);

        $this->assertSame(AttendanceStatus::Rejected, $rejected->fresh()->status);
        $this->assertSame(0, $rejected->fresh()->late_minutes);
        $this->assertSame($this->late->id, $rejected->fresh()->shift_id);

        $rejected->delete();
        $leave = $this->row(['check_in' => null, 'status' => AttendanceStatus::Leave, 'late_minutes' => 0]);
        $schedule->update(['shift_id' => $this->late->id]);
        $this->assertSame(AttendanceStatus::Leave, $leave->fresh()->status);
    }

    public function test_day_off_set_after_the_check_in_keeps_the_shift_worked(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('Superadmin');
        ShiftSchedule::create(['employee_id' => $this->employee->id, 'date' => self::MONDAY, 'shift_id' => $this->morning->id]);
        $row = $this->row(['check_in' => '08:10:00', 'status' => AttendanceStatus::Late, 'late_minutes' => 10, 'shift_id' => $this->morning->id]);

        app(ShiftAssignmentService::class)->assignDay($user, $this->employee, self::MONDAY, ShiftAssignmentService::MODE_DAY_OFF);

        $row->refresh();
        $this->assertSame(AttendanceStatus::Late, $row->status);
        $this->assertSame(10, $row->late_minutes);
        $this->assertSame($this->morning->id, $row->shift_id);
    }

    public function test_assign_day_through_the_service_is_refused_over_an_unlinked_check_in(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('Superadmin');
        $row = $this->row(['check_in' => '10:05:00', 'status' => AttendanceStatus::Late, 'late_minutes' => 125]);

        try {
            app(ShiftAssignmentService::class)->assignDay($user, $this->employee, self::MONDAY, ShiftAssignmentService::MODE_SHIFT, $this->late->id);
            $this->fail('A schedule over a check-in without a schedule must be refused.');
        } catch (ValidationException $exception) {
            $this->assertSame([ShiftAssignmentService::UNLINKED_ATTENDANCE_MESSAGE], $exception->errors()['date']);
        }

        $this->assertSame(0, ShiftSchedule::query()->count());
        $this->assertSame(125, $row->fresh()->late_minutes);
    }

    public function test_split_shift_schedule_with_attendance_is_refused_for_both_parts(): void
    {
        $split = Shift::create(['company_id' => $this->company->id, 'name' => 'Split', 'start_time' => '08:00', 'end_time' => '12:00', 'second_start_time' => '17:00', 'second_end_time' => '21:00', 'late_tolerance_minutes' => 0, 'is_active' => true]);
        $splitLater = Shift::create(['company_id' => $this->company->id, 'name' => 'Split 2', 'start_time' => '09:00', 'end_time' => '13:00', 'second_start_time' => '18:00', 'second_end_time' => '22:00', 'late_tolerance_minutes' => 0, 'is_active' => true]);
        $schedule = ShiftSchedule::create(['employee_id' => $this->employee->id, 'date' => self::MONDAY, 'shift_id' => $split->id]);
        $first = $this->row(['check_in' => '08:30:00', 'check_out' => '12:00:00', 'status' => AttendanceStatus::Late, 'late_minutes' => 30, 'shift_id' => $split->id, 'shift_schedule_id' => $schedule->id]);
        $second = $this->row(['segment' => 2, 'check_in' => '18:10:00', 'check_out' => '21:00:00', 'status' => AttendanceStatus::Late, 'late_minutes' => 70, 'shift_id' => $split->id, 'shift_schedule_id' => $schedule->id]);

        $this->assertRefused(fn () => $schedule->update(['shift_id' => $splitLater->id]));

        $this->assertSame(AttendanceStatus::Late, $first->fresh()->status);
        $this->assertSame(0, $first->fresh()->early_leave_minutes);
        $this->assertSame(70, $second->fresh()->late_minutes);
        $this->assertSame(0, $second->fresh()->early_leave_minutes);
    }

    public function test_overnight_schedule_with_attendance_is_refused(): void
    {
        $night = $this->shift('Malam', '22:00', '06:00');
        $later = $this->shift('Malam Akhir', '23:00', '07:00');
        $schedule = ShiftSchedule::create(['employee_id' => $this->employee->id, 'date' => self::MONDAY, 'shift_id' => $night->id]);
        $row = Attendance::create([
            'employee_id' => $this->employee->id, 'date' => self::MONDAY, 'segment' => 1, 'shift_id' => $night->id, 'shift_schedule_id' => $schedule->id,
            'check_in' => '2026-11-17 00:30:00', 'check_out' => '2026-11-17 06:00:00', 'status' => AttendanceStatus::Late, 'late_minutes' => 150,
        ]);

        $this->assertRefused(fn () => $schedule->update(['shift_id' => $later->id]));

        $row->refresh();
        $this->assertSame(150, $row->late_minutes);
        $this->assertSame(0, $row->early_leave_minutes);
    }

    public function test_moving_a_checked_in_schedule_to_another_date_is_refused(): void
    {
        $schedule = ShiftSchedule::create(['employee_id' => $this->employee->id, 'date' => self::MONDAY, 'shift_id' => $this->late->id]);
        $row = $this->row(['check_in' => '09:00:00', 'status' => AttendanceStatus::OnTime, 'late_minutes' => 0, 'shift_id' => $this->late->id, 'shift_schedule_id' => $schedule->id]);

        $this->assertRefused(fn () => $schedule->update(['date' => '2026-11-17']));

        $this->assertSame(self::MONDAY, $schedule->fresh()->date->toDateString());
        $this->assertSame(0, $row->fresh()->late_minutes);
    }

    public function test_approval_follows_the_snapshot_whatever_the_roster_does(): void
    {
        $approver = User::factory()->create(['is_active' => true]);
        $approver->forceFill(['tenant_id' => $this->company->tenant_id])->save();
        $approver->assignRole('Approver');
        $this->grantDataAccessTo($approver, $this->company->id, $this->division->id);

        // A roster edit while the check-in waits is refused, and the approval applies the verdict of check-in.
        $schedule = ShiftSchedule::create(['employee_id' => $this->employee->id, 'date' => self::MONDAY, 'shift_id' => $this->morning->id]);
        $row = $this->row(['check_in' => '08:30:00', 'status' => AttendanceStatus::PendingApproval, 'late_minutes' => 0, 'check_in_late_minutes' => 30, 'shift_id' => $this->morning->id, 'is_out_of_bounds' => true, 'shift_schedule_id' => $schedule->id]);
        app(ApprovalFlowService::class)->generateSteps($row, 'geofence');
        $this->assertRefused(fn () => $schedule->update(['shift_id' => $this->late->id]));
        app(ApprovalFlowService::class)->approveStep($row->fresh(), $approver);
        $this->assertSame(AttendanceStatus::Late, $row->fresh()->status);
        $this->assertSame(30, $row->fresh()->late_minutes);

        // After the approval the roster stays locked too.
        $this->assertRefused(fn () => $schedule->fresh()->update(['shift_id' => $this->late->id]));
        $this->assertSame(AttendanceStatus::Late, $row->fresh()->status);
        $this->assertSame(30, $row->fresh()->late_minutes);
    }

    public function test_roster_edit_during_an_approval_is_refused_and_does_not_overwrite_it(): void
    {
        $approver = User::factory()->create(['is_active' => true]);
        $approver->forceFill(['tenant_id' => $this->company->tenant_id])->save();
        $approver->assignRole('Approver');
        $this->grantDataAccessTo($approver, $this->company->id, $this->division->id);
        $schedule = ShiftSchedule::create(['employee_id' => $this->employee->id, 'date' => self::MONDAY, 'shift_id' => $this->morning->id]);
        $row = $this->row(['check_in' => '08:30:00', 'status' => AttendanceStatus::PendingApproval, 'late_minutes' => 0, 'check_in_late_minutes' => 30, 'shift_id' => $this->morning->id, 'is_out_of_bounds' => true, 'shift_schedule_id' => $schedule->id]);
        app(ApprovalFlowService::class)->generateSteps($row, 'geofence');

        $request = $row->fresh();

        // The roster is edited right after the approval reads the row under its lock (a concurrent request).
        $done = false;
        $refused = false;
        Attendance::retrieved(function (Attendance $attendance) use ($row, $schedule, &$done, &$refused): void {
            if (! $done && $attendance->is($row)) {
                $done = true;

                try {
                    $schedule->update(['shift_id' => $this->late->id]);
                } catch (ValidationException) {
                    $refused = true;
                }
            }
        });

        app(ApprovalFlowService::class)->approveStep($request, $approver);
        $this->assertTrue($done);
        $this->assertTrue($refused, 'The roster edit of a checked-in schedule is refused.');

        $row->refresh();
        $this->assertSame(AttendanceStatus::Late, $row->status);
        $this->assertSame($this->morning->id, $row->shift_id);
        $this->assertSame(30, $row->late_minutes, 'The final row follows the schedule it was checked in to.');
        $this->assertSame($this->morning->id, $schedule->fresh()->shift_id);
    }

    public function test_recalculation_is_paused_inside_without_roster_recalculation(): void
    {
        $row = $this->row(['check_in' => '10:30:00', 'status' => AttendanceStatus::Late, 'late_minutes' => 150]);

        AttendanceLatenessService::withoutRosterRecalculation(function (): void {
            ShiftSchedule::create(['employee_id' => $this->employee->id, 'date' => self::MONDAY, 'shift_id' => $this->late->id]);
        });

        $this->assertSame(150, $row->fresh()->late_minutes);
        $this->assertFalse(AttendanceLatenessService::rosterRecalculationPaused());
    }

    // ── BUG-02: admin edit in the panel ─────────────────────────────────────

    public function test_admin_moving_the_check_in_marks_the_row_late(): void
    {
        ShiftSchedule::create(['employee_id' => $this->employee->id, 'date' => self::MONDAY, 'shift_id' => $this->morning->id]);
        $row = $this->row(['check_in' => '07:55:00', 'status' => AttendanceStatus::OnTime, 'late_minutes' => 0, 'shift_id' => $this->morning->id]);
        $this->actingAsAdmin();

        Livewire::test(EditAttendance::class, ['record' => $row->getRouteKey()])
            ->fillForm(['check_in' => self::MONDAY.' 09:30'])
            ->call('save')
            ->assertHasNoFormErrors();

        $row->refresh();
        $this->assertSame(AttendanceStatus::Late, $row->status);
        $this->assertSame(90, $row->late_minutes);
        $this->assertTrue($row->is_corrected);

        Livewire::test(EditAttendance::class, ['record' => $row->getRouteKey()])
            ->fillForm(['check_in' => self::MONDAY.' 07:50', 'status' => AttendanceStatus::Late->value, 'late_minutes' => 90])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(AttendanceStatus::OnTime, $row->fresh()->status);
        $this->assertSame(0, $row->fresh()->late_minutes);
    }

    public function test_admin_moving_the_check_out_recalculates_early_leave(): void
    {
        ShiftSchedule::create(['employee_id' => $this->employee->id, 'date' => self::MONDAY, 'shift_id' => $this->morning->id]);
        $row = $this->row(['check_in' => '08:00:00', 'check_out' => '16:00:00', 'status' => AttendanceStatus::OnTime, 'late_minutes' => 0, 'shift_id' => $this->morning->id]);
        $this->actingAsAdmin();

        Livewire::test(EditAttendance::class, ['record' => $row->getRouteKey()])
            ->fillForm(['check_out' => self::MONDAY.' 14:30'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(90, $row->fresh()->early_leave_minutes);
    }

    public function test_admin_saving_status_only_keeps_the_manual_verdict(): void
    {
        ShiftSchedule::create(['employee_id' => $this->employee->id, 'date' => self::MONDAY, 'shift_id' => $this->morning->id]);
        $row = $this->row(['check_in' => '08:00:00', 'status' => AttendanceStatus::OnTime, 'late_minutes' => 0, 'shift_id' => $this->morning->id]);
        $this->actingAsAdmin();

        Livewire::test(EditAttendance::class, ['record' => $row->getRouteKey()])
            ->fillForm(['status' => AttendanceStatus::Late->value, 'late_minutes' => 5])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(AttendanceStatus::Late, $row->fresh()->status);
        $this->assertSame(5, $row->fresh()->late_minutes);
    }

    public function test_manual_verdict_survives_a_later_roster_change(): void
    {
        ShiftSchedule::create(['employee_id' => $this->employee->id, 'date' => self::MONDAY, 'shift_id' => $this->morning->id]);
        $row = $this->row(['check_in' => '08:20:00', 'status' => AttendanceStatus::Late, 'late_minutes' => 20, 'shift_id' => $this->morning->id]);
        $this->actingAsAdmin();

        // Excused (car accident): the admin sets the row on time by hand.
        Livewire::test(EditAttendance::class, ['record' => $row->getRouteKey()])
            ->fillForm(['status' => AttendanceStatus::OnTime->value, 'late_minutes' => 0])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertTrue($row->fresh()->status_overridden);

        // The PIC later moves the Monday roster for an unrelated reason: the manual verdict stays.
        $later = $this->shift('Pagi 2', '08:10', '16:10');
        ShiftSchedule::query()->where('employee_id', $this->employee->id)->sole()->update(['shift_id' => $later->id]);

        $row->refresh();
        $this->assertSame(AttendanceStatus::OnTime, $row->status);
        $this->assertSame(0, $row->late_minutes);
        $this->assertTrue($row->status_overridden);
    }

    public function test_admin_moving_times_judges_an_overridden_row_again(): void
    {
        ShiftSchedule::create(['employee_id' => $this->employee->id, 'date' => self::MONDAY, 'shift_id' => $this->morning->id]);
        $row = $this->row(['check_in' => '08:20:00', 'status' => AttendanceStatus::OnTime, 'late_minutes' => 0, 'shift_id' => $this->morning->id, 'status_overridden' => true]);
        $this->actingAsAdmin();

        Livewire::test(EditAttendance::class, ['record' => $row->getRouteKey()])
            ->fillForm(['check_in' => self::MONDAY.' 08:30'])
            ->call('save')
            ->assertHasNoFormErrors();

        $row->refresh();
        $this->assertSame(AttendanceStatus::Late, $row->status);
        $this->assertSame(30, $row->late_minutes);
        $this->assertFalse($row->status_overridden);
    }

    public function test_admin_saving_without_changing_the_verdict_does_not_freeze_the_row(): void
    {
        ShiftSchedule::create(['employee_id' => $this->employee->id, 'date' => self::MONDAY, 'shift_id' => $this->morning->id]);
        $row = $this->row(['check_in' => '08:20:00', 'status' => AttendanceStatus::Late, 'late_minutes' => 20, 'shift_id' => $this->morning->id]);
        $this->actingAsAdmin();

        Livewire::test(EditAttendance::class, ['record' => $row->getRouteKey()])
            ->fillForm(['notes' => 'Dicek'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertFalse($row->fresh()->status_overridden);

        // The row has no schedule link, so the roster change is allowed and leaves it as it is (D4).
        ShiftSchedule::query()->where('employee_id', $this->employee->id)->sole()->update(['shift_id' => $this->late->id]);
        $this->assertSame(AttendanceStatus::Late, $row->fresh()->status);
        $this->assertSame(20, $row->fresh()->late_minutes);
    }

    public function test_admin_moving_a_pending_check_in_updates_only_the_snapshot(): void
    {
        ShiftSchedule::create(['employee_id' => $this->employee->id, 'date' => self::MONDAY, 'shift_id' => $this->morning->id]);
        $row = $this->row(['check_in' => '08:00:00', 'status' => AttendanceStatus::PendingApproval, 'late_minutes' => 0, 'check_in_late_minutes' => 0, 'shift_id' => $this->morning->id]);
        $this->actingAsAdmin();

        Livewire::test(EditAttendance::class, ['record' => $row->getRouteKey()])
            ->fillForm(['check_in' => self::MONDAY.' 08:40'])
            ->call('save')
            ->assertHasNoFormErrors();

        $row->refresh();
        $this->assertSame(AttendanceStatus::PendingApproval, $row->status);
        $this->assertSame(0, $row->late_minutes);
        $this->assertSame(40, $row->check_in_late_minutes);
    }

    // ── BUG-06: lateness to the second ──────────────────────────────────────

    public function test_lateness_is_judged_to_the_second_and_rounded_down_to_whole_minutes(): void
    {
        $service = app(WorkScheduleService::class);
        $hours = fn (int $tolerance): array => ['is_day_off' => false, 'start_time' => '08:00:00', 'late_tolerance_minutes' => $tolerance];
        $at = fn (string $time): Carbon => Carbon::parse(self::MONDAY.' '.$time);

        $this->assertSame(0, $service->lateMinutes($hours(0), $at('08:00:00')));
        $this->assertSame(1, $service->lateMinutes($hours(0), $at('08:00:01')));
        $this->assertSame(1, $service->lateMinutes($hours(0), $at('08:00:45')));
        $this->assertSame(1, $service->lateMinutes($hours(0), $at('08:01:59')));
        $this->assertSame(2, $service->lateMinutes($hours(0), $at('08:02:00')));
        $this->assertSame(0, $service->lateMinutes($hours(15), $at('08:15:00')));
        $this->assertSame(15, $service->lateMinutes($hours(15), $at('08:15:01')));
        $this->assertSame(15, $service->lateMinutes($hours(15), $at('08:15:30')));
        $this->assertSame(16, $service->lateMinutes($hours(15), $at('08:16:00')));
        $this->assertSame(0, $service->lateMinutes(['is_day_off' => true] + $hours(0), $at('11:00:00')));
        $this->assertSame(150, $service->lateMinutes(['is_day_off' => false, 'start_time' => '22:00:00', 'late_tolerance_minutes' => 0], Carbon::parse('2026-11-17 00:30:00'), Carbon::parse(self::MONDAY)));
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $attributes  Times are of the Monday unless a full datetime is given.
     */
    private function row(array $attributes): Attendance
    {
        foreach (['check_in', 'check_out'] as $column) {
            if (isset($attributes[$column]) && strlen((string) $attributes[$column]) === 8) {
                $attributes[$column] = self::MONDAY.' '.$attributes[$column];
            }
        }

        return Attendance::create(['employee_id' => $this->employee->id, 'date' => self::MONDAY, 'segment' => 1, ...$attributes]);
    }

    /**
     * @param  callable(): mixed  $change
     */
    private function assertRefused(callable $change): void
    {
        try {
            $change();
            $this->fail('A change of a schedule that has attendance must be refused.');
        } catch (ValidationException $exception) {
            $this->assertSame([ShiftSchedule::LOCKED_MESSAGE], $exception->errors()['date']);
        }
    }

    private function shift(string $name, string $start, string $end): Shift
    {
        return Shift::create(['company_id' => $this->company->id, 'name' => $name, 'start_time' => $start, 'end_time' => $end, 'late_tolerance_minutes' => 0, 'is_active' => true]);
    }

    private function actingAsAdmin(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('Superadmin');
        $this->actingAs($admin);
    }
}
