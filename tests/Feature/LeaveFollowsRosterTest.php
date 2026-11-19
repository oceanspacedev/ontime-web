<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\DataScope;
use App\Filament\Pages\JadwalMingguan;
use App\Filament\Resources\LeaveRequests\Pages\CreateLeaveRequest;
use App\Filament\Resources\LeaveRequests\Pages\EditLeaveRequest;
use App\Models\ApprovalFlow;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Services\AnnualLeaveService;
use App\Services\ApprovalFlowService;
use App\Services\RosterChangeContext;
use App\Services\ScheduleCopyService;
use App\Services\ShiftAssignmentService;
use App\Services\ShiftScheduleSpreadsheet;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Leave follows the roster (WP13): in a company that plans with shift rosters a leave is charged only for
 * the days the worker would have worked. The list of charged dates is computed when the leave is filed,
 * recounted and frozen at the final approval, and from then on feeds days_count, the quota, the leave rows
 * and every report. The week grid shows the leave, and no working schedule can be put on a charged date.
 *
 * Tuesday 17 November 2026; the leave week is Monday 9 - Sunday 15 November.
 */
class LeaveFollowsRosterTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Division $division;

    private CompanyLocation $outlet;

    private Shift $morning;

    private User $admin;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-17 09:00'));

        foreach (['Superadmin', 'Admin', 'Approver', 'Employee'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->company = Company::create(['name' => 'PT Franchise', 'code' => 'PFC', 'is_active' => true]);
        $this->company->policy()->update(['uses_shift_roster' => true]);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'LUUCA', 'is_active' => true]);
        $this->outlet = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $this->division->id, 'name' => 'Luuca Ciledug', 'latitude' => -6.2, 'longitude' => 106.7]);
        $this->morning = Shift::create(['company_id' => $this->company->id, 'name' => 'Pagi', 'code' => 'P', 'start_time' => '08:00', 'end_time' => '17:00']);
        ApprovalFlow::create(['company_id' => $this->company->id, 'request_type' => 'leave', 'step_number' => 1, 'step_order' => 1, 'name' => 'Approver', 'approver_type' => 'role', 'approver_role' => 'Approver']);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('Admin');
        $this->admin->grantDataAccess(DataScope::All);
        $this->superadmin = User::factory()->create(['is_active' => true]);
        $this->superadmin->assignRole('Superadmin');
    }

    public function test_a_rostered_day_off_inside_the_leave_is_not_charged_and_every_figure_follows(): void
    {
        $employee = $this->employee();
        $this->rosterWeek($employee, '2026-11-09', dayOff: ['2026-11-11']);
        Sanctum::actingAs($employee->user);

        $id = $this->postJson('/api/v1/leave-requests', $this->payload('2026-11-09', '2026-11-15'))
            ->assertCreated()
            ->assertJsonPath('data.days_count', 6)
            ->json('data.id');

        $leave = $this->approve(LeaveRequest::findOrFail($id));

        $this->assertSame(6, $leave->days_count);
        $this->assertSame(['2026-11-09', '2026-11-10', '2026-11-12', '2026-11-13', '2026-11-14', '2026-11-15'], $leave->charged_dates);
        $this->assertSame(['annual_leave_quota' => 12, 'used_days' => 6, 'remaining_days' => 6], array_intersect_key(app(AnnualLeaveService::class)->summary($employee), array_flip(['annual_leave_quota', 'used_days', 'remaining_days'])));

        $rows = Attendance::where('employee_id', $employee->id)->orderBy('date')->get();
        $this->assertCount(6, $rows);
        $this->assertSame([AttendanceStatus::Leave], $rows->pluck('status')->unique()->values()->all());
        $this->assertNotContains('2026-11-11', $rows->map(fn (Attendance $row): string => $row->date->toDateString())->all());

        $this->assertReportedLeaveDays(6);
    }

    public function test_a_blank_date_of_a_published_week_is_not_charged_and_an_unpublished_week_is(): void
    {
        $employee = $this->employee();
        $service = app(AnnualLeaveService::class);

        // Monday to Friday rostered, the weekend left blank: the week is published, the weekend not scheduled.
        foreach (['2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13'] as $date) {
            $this->schedule($employee, $date);
        }

        $this->assertCount(5, $service->chargedDates($employee, Carbon::parse('2026-11-09'), Carbon::parse('2026-11-15')));
        // The week of 16 November has no roster row yet: every calendar day is charged.
        $this->assertSame(['2026-11-16', '2026-11-17', '2026-11-18'], $service->chargedDates($employee, Carbon::parse('2026-11-16'), Carbon::parse('2026-11-18')));

        Sanctum::actingAs($employee->user);
        $this->postJson('/api/v1/leave-requests', $this->payload('2026-11-12', '2026-11-18'))
            ->assertCreated()
            ->assertJsonPath('data.days_count', 5);
    }

    public function test_the_blank_days_before_a_mid_week_first_roster_row_and_of_a_cover_only_week_are_charged(): void
    {
        $employee = $this->employee();
        $service = app(AnnualLeaveService::class);

        // The first roster row is Thursday: Monday to Wednesday are still on fixed hours, Friday is a blank day of the roster.
        $this->schedule($employee, '2026-11-19');

        $this->assertSame(['2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19'], $service->chargedDates($employee, Carbon::parse('2026-11-16'), Carbon::parse('2026-11-20')));

        Sanctum::actingAs($employee->user);
        $this->postJson('/api/v1/leave-requests', $this->payload('2026-11-17', '2026-11-18'))
            ->assertCreated()
            ->assertJsonPath('data.days_count', 2);

        // A worker whose only row of the week is an approved Saturday cover is charged every blank day of it.
        $other = $this->employee();
        $cover = RosterChangeContext::run('cover', null, fn (): ShiftSchedule => $this->schedule($other, '2026-11-14'));
        Attendance::query()->create([
            'employee_id' => $other->id,
            'date' => '2026-11-14',
            'check_in' => '2026-11-14 08:00:00',
            'status' => AttendanceStatus::OnTime,
            'shift_id' => $this->morning->id,
            'shift_schedule_id' => $cover->id,
            'is_off_schedule' => true,
        ]);

        $this->assertSame(['2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13'], $service->chargedDates($other, Carbon::parse('2026-11-09'), Carbon::parse('2026-11-13')));
    }

    public function test_the_days_are_recounted_at_approval_when_the_roster_changed_after_filing(): void
    {
        $employee = $this->employee();
        $schedules = $this->rosterWeek($employee, '2026-11-09');
        Sanctum::actingAs($employee->user);
        $id = $this->postJson('/api/v1/leave-requests', $this->payload('2026-11-09', '2026-11-15'))->assertCreated()->assertJsonPath('data.days_count', 7)->json('data.id');

        // Thursday becomes a day off before the approver gets to it.
        $schedules['2026-11-12']->update(['is_day_off' => true, 'shift_id' => null, 'company_location_id' => null]);

        $leave = $this->approve(LeaveRequest::findOrFail($id));

        $this->assertSame(6, $leave->days_count);
        $this->assertNotContains('2026-11-12', $leave->charged_dates);
        $this->assertFalse(Attendance::where('employee_id', $employee->id)->whereDate('date', '2026-11-12')->exists());
        $this->assertSame(6, app(AnnualLeaveService::class)->usedDays($employee, 2026));
    }

    public function test_the_quota_is_rechecked_with_the_recount_at_approval(): void
    {
        $employee = $this->employee(quota: 6);
        $schedules = $this->rosterWeek($employee, '2026-11-09', dayOff: ['2026-11-11']);
        Sanctum::actingAs($employee->user);
        $id = $this->postJson('/api/v1/leave-requests', $this->payload('2026-11-09', '2026-11-15'))->assertCreated()->assertJsonPath('data.days_count', 6)->json('data.id');

        // Wednesday turns into a working day: seven days no longer fit a quota of six.
        $schedules['2026-11-11']->update(['is_day_off' => false, 'shift_id' => $this->morning->id, 'company_location_id' => $this->outlet->id]);

        try {
            $this->approve(LeaveRequest::findOrFail($id));
            $this->fail('The recount should exceed the quota.');
        } catch (ValidationException $exception) {
            $this->assertSame("Sisa kuota cuti tahunan {$employee->full_name} tidak mencukupi (Sisa: 6 hari, Pengajuan: 7 hari).", $exception->errors()['approve'][0]);
        }

        $leave = LeaveRequest::findOrFail($id);
        $this->assertSame(['pending', 6, null], [$leave->status, $leave->days_count, $leave->charged_dates]);
        $this->assertSame(0, Attendance::where('employee_id', $employee->id)->count());
    }

    public function test_a_day_off_added_after_approval_changes_neither_the_days_nor_the_reports(): void
    {
        $employee = $this->employee();
        $schedules = $this->rosterWeek($employee, '2026-11-09');
        $leave = $this->approve($this->pendingLeave($employee, '2026-11-09', '2026-11-15'));
        $this->assertSame(7, $leave->days_count);

        $schedules['2026-11-12']->update(['is_day_off' => true, 'shift_id' => null, 'company_location_id' => null]);

        $this->assertSame(7, $leave->fresh()->days_count);
        $this->assertSame(7, app(AnnualLeaveService::class)->usedDays($employee, 2026));
        $this->assertReportedLeaveDays(7);
    }

    public function test_a_period_of_only_days_off_is_refused(): void
    {
        $employee = $this->employee();
        $this->rosterWeek($employee, '2026-11-23', dayOff: ['2026-11-28', '2026-11-29']);
        Sanctum::actingAs($employee->user);

        $this->postJson('/api/v1/leave-requests', $this->payload('2026-11-28', '2026-11-29'))
            ->assertUnprocessable()
            ->assertJsonPath('errors.start_date.0', AnnualLeaveService::NO_WORKDAY_MESSAGE.'.');
        $this->assertSame(0, LeaveRequest::count());

        // The panel refuses it the same way.
        $this->actingAs($employee->user);
        Livewire::test(CreateLeaveRequest::class)
            ->fillForm(['leave_type' => LeaveRequest::TYPE_ANNUAL, 'start_date' => '2026-11-28', 'end_date' => '2026-11-29', 'reason' => 'Liburan'])
            ->call('create')
            ->assertHasFormErrors(['start_date']);
        $this->assertSame(0, LeaveRequest::count());

        // A request whose working days all turned into days off before approval cannot be approved either.
        $pending = $this->pendingLeave($employee, '2026-11-26', '2026-11-26');
        ShiftSchedule::where('employee_id', $employee->id)->whereDate('date', '2026-11-26')->sole()->update(['is_day_off' => true, 'shift_id' => null, 'company_location_id' => null]);

        try {
            $this->approve($pending);
            $this->fail('A leave without a working day should not be approved.');
        } catch (ValidationException $exception) {
            $this->assertStringStartsWith(AnnualLeaveService::NO_WORKDAY_MESSAGE, $exception->errors()['approve'][0]);
        }

        $this->assertSame('pending', $pending->fresh()->status);
    }

    public function test_a_company_without_rosters_still_charges_every_calendar_day(): void
    {
        $this->company->policy()->update(['uses_shift_roster' => false]);
        $employee = $this->employee();
        $this->rosterWeek($employee, '2026-11-09', dayOff: ['2026-11-11']);
        Sanctum::actingAs($employee->user);

        $id = $this->postJson('/api/v1/leave-requests', $this->payload('2026-11-09', '2026-11-15'))->assertCreated()->assertJsonPath('data.days_count', 7)->json('data.id');
        $leave = $this->approve(LeaveRequest::findOrFail($id));

        $this->assertSame(7, $leave->days_count);
        $this->assertCount(7, $leave->charged_dates);
        $this->assertSame(7, Attendance::where('employee_id', $employee->id)->count());
    }

    public function test_an_old_approved_leave_without_charged_dates_keeps_its_days(): void
    {
        $employee = $this->employee();
        $this->rosterWeek($employee, '2026-11-09', dayOff: ['2026-11-11']);
        $old = LeaveRequest::create(['employee_id' => $employee->id, 'leave_type' => LeaveRequest::TYPE_ANNUAL, 'start_date' => '2026-11-10', 'end_date' => '2026-11-12', 'days_count' => 3, 'reason' => 'Lama', 'status' => 'approved']);

        $this->assertNull($old->charged_dates);
        $this->assertSame(['2026-11-10', '2026-11-11', '2026-11-12'], $old->chargedDateStrings());
        $this->assertSame(3, app(AnnualLeaveService::class)->usedDays($employee, 2026));
        $this->assertReportedLeaveDays(3);
    }

    public function test_re_dating_an_approved_leave_moves_its_rows_and_deleting_it_removes_them(): void
    {
        $employee = $this->employee();
        $this->rosterWeek($employee, '2026-11-09', dayOff: ['2026-11-11']);
        $leave = $this->approve($this->pendingLeave($employee, '2026-11-09', '2026-11-10'));
        $this->assertSame(['2026-11-09', '2026-11-10'], $this->leaveRowDates($employee));
        $this->actingAs($this->superadmin);

        // Moved to Wednesday (day off) - Friday: two days charged, Wednesday left alone.
        Livewire::test(EditLeaveRequest::class, ['record' => $leave->getKey()])
            ->fillForm(['start_date' => '2026-11-11', 'end_date' => '2026-11-13'])
            ->call('save')
            ->assertHasNoFormErrors();

        $leave->refresh();
        $this->assertSame([2, ['2026-11-12', '2026-11-13']], [$leave->days_count, $leave->charged_dates]);
        $this->assertSame(['2026-11-12', '2026-11-13'], $this->leaveRowDates($employee));

        // A new date that already holds a check-in refuses the move and keeps everything as it was.
        Attendance::create(['employee_id' => $employee->id, 'date' => '2026-11-14', 'check_in' => '2026-11-14 08:00:00', 'check_out' => '2026-11-14 17:00:00', 'status' => 'on_time', 'late_minutes' => 0]);

        Livewire::test(EditLeaveRequest::class, ['record' => $leave->getKey()])
            ->fillForm(['start_date' => '2026-11-13', 'end_date' => '2026-11-14'])
            ->call('save')
            ->assertHasFormErrors(['start_date']);

        $leave->refresh();
        $this->assertSame(['2026-11-11', '2026-11-13', ['2026-11-12', '2026-11-13']], [$leave->start_date->toDateString(), $leave->end_date->toDateString(), $leave->charged_dates]);
        $this->assertSame(['2026-11-12', '2026-11-13'], $this->leaveRowDates($employee));

        Livewire::test(EditLeaveRequest::class, ['record' => $leave->getKey()])
            ->callAction(DeleteAction::class);

        $this->assertModelMissing($leave);
        $this->assertSame([], $this->leaveRowDates($employee));
        $this->assertTrue(Attendance::where('employee_id', $employee->id)->whereDate('date', '2026-11-14')->exists(), 'The worked day stays.');
    }

    public function test_re_dating_an_approved_leave_onto_a_day_with_approved_overtime_is_refused(): void
    {
        $employee = $this->employee();
        $this->rosterWeek($employee, '2026-11-09');
        $leave = $this->approve($this->pendingLeave($employee, '2026-11-10', '2026-11-11'));
        OvertimeRequest::create([
            'employee_id' => $employee->id, 'date' => '2026-11-12', 'start_time' => '17:00', 'end_time' => '19:00',
            'duration_minutes' => 120, 'reason' => 'Stok opname', 'status' => 'approved',
        ]);
        $this->actingAs($this->superadmin);

        // Thursday holds approved overtime: moving the leave onto it is refused, as at the approval.
        Livewire::test(EditLeaveRequest::class, ['record' => $leave->getKey()])
            ->fillForm(['start_date' => '2026-11-11', 'end_date' => '2026-11-12'])
            ->call('save')
            ->assertHasFormErrors(['start_date' => "{$employee->full_name} sudah memiliki lembur yang disetujui pada tanggal 12/11/2026. Tolak pengajuan ini, atau batalkan lemburnya terlebih dahulu."]);

        $leave->refresh();
        $this->assertSame(['2026-11-10', '2026-11-11', ['2026-11-10', '2026-11-11']], [$leave->start_date->toDateString(), $leave->end_date->toDateString(), $leave->charged_dates]);
        $this->assertSame(['2026-11-10', '2026-11-11'], $this->leaveRowDates($employee));

        // The same guard holds for any path that charges dates, not only the panel.
        try {
            DB::transaction(function () use ($leave): void {
                $leave->forceFill(['start_date' => '2026-11-12', 'end_date' => '2026-11-12'])->save();
                $leave->syncAttendanceRows(['2026-11-10', '2026-11-11']);
            });
            $this->fail('The leave was moved onto a day with approved overtime.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('sudah memiliki lembur yang disetujui pada tanggal 12/11/2026', $exception->errors()['approve'][0]);
        }

        $this->assertSame(['2026-11-10', '2026-11-11'], $this->leaveRowDates($employee));
        $this->assertFalse(Attendance::where('employee_id', $employee->id)->whereDate('date', '2026-11-12')->exists());
    }

    public function test_the_week_payload_and_the_panel_grid_mark_the_leave(): void
    {
        $employee = $this->employee();
        $this->rosterWeek($employee, '2026-11-09', dayOff: ['2026-11-11']);
        $this->approve($this->pendingLeave($employee, '2026-11-10', '2026-11-12'));
        Sanctum::actingAs($this->admin);

        $worker = $this->getJson('/api/v1/team/week?date=2026-11-09')->assertOk()->json('workers.0');

        $this->assertSame(
            ['2026-11-09' => false, '2026-11-10' => true, '2026-11-11' => false, '2026-11-12' => true, '2026-11-13' => false, '2026-11-14' => false, '2026-11-15' => false],
            array_map(fn (array $cell): bool => $cell['on_leave'], $worker['days']),
        );
        $this->assertTrue($worker['day_schedules']['2026-11-10'][0]['on_leave']);

        $this->actingAs($this->admin);
        Livewire::test(JadwalMingguan::class)
            ->set('date', '2026-11-09')
            ->assertSeeHtml("data-on-leave=\"employee-{$employee->id}-2026-11-10\"")
            ->assertSeeHtml("data-on-leave=\"employee-{$employee->id}-2026-11-12\"")
            ->assertDontSeeHtml("data-on-leave=\"employee-{$employee->id}-2026-11-11\"");
    }

    /**
     * A charged leave date without a schedule cell (every date of a week not rostered yet) shows as leave
     * too: in leave_dates of the week payload and as a "Cuti" badge in the panel grid; a worker with leave
     * but no schedule that week is listed.
     */
    public function test_the_week_marks_leave_on_dates_without_a_schedule_and_lists_a_worker_without_any(): void
    {
        $rostered = $this->employee();

        foreach (['2026-11-23', '2026-11-24', '2026-11-25'] as $date) {
            $this->schedule($rostered, $date);
        }

        $this->approve($this->pendingLeave($rostered, '2026-11-24', '2026-11-25'));
        $blank = $this->employee();
        // No roster at all that week: every date is charged.
        $this->approve($this->pendingLeave($blank, '2026-11-23', '2026-11-24'));
        Sanctum::actingAs($this->admin);

        $workers = collect($this->getJson('/api/v1/team/week?date=2026-11-23')->assertOk()->json('workers'))->keyBy('worker.id');

        $this->assertSame(['2026-11-24', '2026-11-25'], $workers[$rostered->id]['leave_dates']);
        $this->assertSame([false, true, true], [$workers[$rostered->id]['days']['2026-11-23']['on_leave'], $workers[$rostered->id]['days']['2026-11-24']['on_leave'], $workers[$rostered->id]['days']['2026-11-25']['on_leave']]);
        $this->assertSame(['2026-11-23', '2026-11-24'], $workers[$blank->id]['leave_dates']);
        $this->assertSame([], $workers[$blank->id]['days']);
        $this->assertTrue($workers[$blank->id]['can_edit']);
        $this->assertStringContainsString('"days":{}', $this->getJson('/api/v1/team/week?date=2026-11-23')->getContent(), 'A worker without cells keeps days as an object.');

        $this->actingAs($this->admin);
        Livewire::test(JadwalMingguan::class)
            ->set('date', '2026-11-23')
            ->assertSeeHtml("data-on-leave=\"employee-{$rostered->id}-2026-11-24\"")
            ->assertSeeHtml("data-on-leave=\"employee-{$blank->id}-2026-11-23\"")
            ->assertSeeHtml("data-on-leave=\"employee-{$blank->id}-2026-11-24\"")
            ->assertDontSeeHtml("data-on-leave=\"employee-{$rostered->id}-2026-11-23\"");
    }

    /**
     * An attendance row that existed before the approval and was only turned into a leave row gets its
     * status and notes back when the leave is shortened or deleted; only rows the leave created are removed.
     */
    public function test_undoing_a_leave_puts_back_a_row_that_existed_before_it(): void
    {
        $employee = $this->employee();
        $this->rosterWeek($employee, '2026-11-09');
        $placeholder = Attendance::create(['employee_id' => $employee->id, 'date' => '2026-11-10', 'status' => 'absent', 'notes' => 'Tidak datang', 'late_minutes' => 0]);
        $leave = $this->approve($this->pendingLeave($employee, '2026-11-09', '2026-11-12'));

        $this->assertSame(AttendanceStatus::Leave, $placeholder->fresh()->status);
        $this->assertSame(['status' => 'absent', 'notes' => 'Tidak datang'], $placeholder->fresh()->state_before_leave);
        $this->actingAs($this->superadmin);

        // Shortened to Thursday only: the Monday and Wednesday rows the leave created go, Tuesday's comes back.
        Livewire::test(EditLeaveRequest::class, ['record' => $leave->getKey()])
            ->fillForm(['start_date' => '2026-11-12', 'end_date' => '2026-11-12'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['2026-11-12'], $this->leaveRowDates($employee));
        $placeholder->refresh();
        $this->assertSame([AttendanceStatus::Absent, 'Tidak datang', null], [$placeholder->status, $placeholder->notes, $placeholder->state_before_leave]);

        // Moved back over Tuesday and then deleted: the row is put back again, not deleted.
        Livewire::test(EditLeaveRequest::class, ['record' => $leave->getKey()])
            ->fillForm(['start_date' => '2026-11-10', 'end_date' => '2026-11-10'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(AttendanceStatus::Leave, $placeholder->fresh()->status);

        Livewire::test(EditLeaveRequest::class, ['record' => $leave->getKey()])
            ->callAction(DeleteAction::class);

        $this->assertSame([], $this->leaveRowDates($employee));
        $this->assertSame([AttendanceStatus::Absent, 'Tidak datang'], [$placeholder->fresh()->status, $placeholder->fresh()->notes]);
    }

    /**
     * A worker without schedules is added to the week only for a leave that charges a date of that week,
     * and only while active: a leave from Saturday that charges nothing before Monday adds no empty row.
     */
    public function test_the_week_lists_a_worker_for_leave_only_when_it_charges_a_date_of_that_week(): void
    {
        $weekendStart = $this->employee();
        LeaveRequest::create(['employee_id' => $weekendStart->id, 'leave_type' => LeaveRequest::TYPE_ANNUAL, 'start_date' => '2026-11-28', 'end_date' => '2026-12-02', 'days_count' => 3, 'charged_dates' => ['2026-11-30', '2026-12-01', '2026-12-02'], 'reason' => 'Liburan', 'status' => 'approved']);
        $inactive = $this->employee();
        $inactive->update(['status' => 'inactive']);
        LeaveRequest::create(['employee_id' => $inactive->id, 'leave_type' => LeaveRequest::TYPE_ANNUAL, 'start_date' => '2026-11-24', 'end_date' => '2026-11-24', 'days_count' => 1, 'charged_dates' => ['2026-11-24'], 'reason' => 'Liburan', 'status' => 'approved']);
        Sanctum::actingAs($this->admin);

        $listed = fn (string $date): array => collect($this->getJson('/api/v1/team/week?date='.$date)->assertOk()->json('workers'))->pluck('worker.id')->all();

        $this->assertNotContains($weekendStart->id, $listed('2026-11-23'));
        $this->assertNotContains($inactive->id, $listed('2026-11-23'));
        $this->assertContains($weekendStart->id, $listed('2026-11-30'));
    }

    /**
     * Two approved leaves charging the same date: undoing one leaves the date a leave day, since the other
     * still charges it; undoing the second then removes the row.
     */
    public function test_undoing_one_of_two_overlapping_leaves_keeps_the_date_on_leave(): void
    {
        $employee = $this->employee();
        $this->rosterWeek($employee, '2026-11-16');
        $first = LeaveRequest::create(['employee_id' => $employee->id, 'leave_type' => LeaveRequest::TYPE_ANNUAL, 'start_date' => '2026-11-20', 'end_date' => '2026-11-20', 'days_count' => 1, 'charged_dates' => ['2026-11-20'], 'reason' => 'Keluarga', 'status' => 'approved']);
        $first->applyLeave();
        $second = LeaveRequest::create(['employee_id' => $employee->id, 'leave_type' => LeaveRequest::TYPE_ANNUAL, 'start_date' => '2026-11-19', 'end_date' => '2026-11-20', 'days_count' => 2, 'charged_dates' => ['2026-11-19', '2026-11-20'], 'reason' => 'Sakit', 'status' => 'approved']);
        $second->applyLeave();
        $this->assertSame(['2026-11-19', '2026-11-20'], $this->leaveRowDates($employee));

        $first->delete();
        $this->assertSame(['2026-11-19', '2026-11-20'], $this->leaveRowDates($employee));

        // Shortening the second to the 19th now frees the 20th, which no approved leave charges any more.
        DB::transaction(function () use ($second): void {
            $second->forceFill(['end_date' => '2026-11-19']);
            $second->syncAttendanceRows(['2026-11-19', '2026-11-20']);
        });
        $this->assertSame(['2026-11-19'], $this->leaveRowDates($employee));
    }

    public function test_a_shift_on_a_leave_day_is_refused_while_a_day_off_or_clearing_is_not(): void
    {
        $employee = $this->employee();
        // Next week, no roster yet: every day is charged.
        $this->approve($this->pendingLeave($employee, '2026-11-23', '2026-11-25'));
        Sanctum::actingAs($this->admin);
        $put = fn (array $data, string $date = '2026-11-23') => $this->putJson('/api/v1/team/schedules', ['worker_type' => 'employee', 'worker_id' => $employee->id, 'date' => $date] + $data);

        $put(['mode' => 'shift', 'shift_id' => $this->morning->id, 'company_location_id' => $this->outlet->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.date.0', ShiftAssignmentService::ON_LEAVE_MESSAGE);
        $put(['mode' => 'off'])->assertOk();
        $put(['mode' => 'clear'])->assertOk();
        $put(['mode' => 'shift', 'shift_id' => $this->morning->id, 'company_location_id' => $this->outlet->id], '2026-11-26')->assertOk();

        $this->actingAs($this->admin);
        Livewire::test(JadwalMingguan::class)
            ->set('date', '2026-11-23')
            ->callAction('editCell', ['mode' => 'shift', 'shift_id' => $this->morning->id, 'company_location_id' => $this->outlet->id], ['type' => 'employee', 'id' => $employee->id, 'company_id' => $this->company->id, 'name' => $employee->full_name, 'date' => '2026-11-24'])
            ->assertNotified(FilamentNotification::make()->title('Jadwal tidak disimpan')->body(ShiftAssignmentService::ON_LEAVE_MESSAGE)->danger());

        $this->assertFalse(ShiftSchedule::where('employee_id', $employee->id)->whereDate('date', '2026-11-24')->exists());
    }

    public function test_copy_week_and_import_skip_a_shift_on_a_leave_day(): void
    {
        $employee = $this->employee();
        $this->rosterWeek($employee, '2026-11-16', dayOff: ['2026-11-21']);
        $this->approve($this->pendingLeave($employee, '2026-11-24', '2026-11-25'));
        $this->actingAs($this->admin);

        $copies = app(ScheduleCopyService::class);
        $result = $copies->copyWeek($this->admin, Carbon::parse('2026-11-16'), Carbon::parse('2026-11-23'));

        // Tuesday and Wednesday are on leave; the five other days (the Saturday day off included) are copied.
        $this->assertSame([5, 2], [$result['copied'], $result['skipped_leave']]);
        $this->assertStringContainsString('2 dilewati karena pekerja sedang cuti.', $copies->summary($result));
        $this->assertFalse(ShiftSchedule::where('employee_id', $employee->id)->whereDate('date', '2026-11-24')->exists());

        $path = tempnam(sys_get_temp_dir(), 'leave').'.xlsx';
        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Luuca Ciledug');
        $writer->addRow(Row::fromValues(['ID Pekerja', 'Nama', 'Jenis', '2026-11-24', '2026-11-25']));
        $writer->addRow(Row::fromValues([$employee->nip, $employee->full_name, '', 'P', 'OFF']));
        $writer->close();

        try {
            $import = app(ShiftScheduleSpreadsheet::class)->import($this->admin, $this->company, $path);
        } finally {
            @unlink($path);
        }

        $this->assertFalse(ShiftSchedule::where('employee_id', $employee->id)->whereDate('date', '2026-11-24')->exists());
        $this->assertTrue(ShiftSchedule::where('employee_id', $employee->id)->whereDate('date', '2026-11-25')->sole()->is_day_off);
        $this->assertStringContainsString('2026-11-24: '.ShiftAssignmentService::ON_LEAVE_MESSAGE.' Sel dilewati.', $import['issues'][0]);
    }

    private function assertReportedLeaveDays(int $expected): void
    {
        Sanctum::actingAs($this->admin);

        $recap = $this->getJson('/api/v1/team/recap?month=2026-11')->assertOk();
        $this->assertSame($expected, (int) collect($recap->json('data'))->sum('leave_days'));
        $this->assertSame($expected, (int) $recap->json('total.leave_days'));
        $this->assertSame($expected, $this->getJson('/api/v1/laporan-absensi?start_date=2026-11-01&end_date=2026-11-30')->assertOk()->json('summary.total_leave'));
    }

    /**
     * A working schedule on every day of the week starting at $monday, except the given days off.
     *
     * @param  list<string>  $dayOff
     * @return array<string, ShiftSchedule>
     */
    private function rosterWeek(Employee $employee, string $monday, array $dayOff = []): array
    {
        $schedules = [];

        for ($offset = 0; $offset < 7; $offset++) {
            $date = Carbon::parse($monday)->addDays($offset)->toDateString();
            $schedules[$date] = in_array($date, $dayOff, true)
                ? ShiftSchedule::create(['employee_id' => $employee->id, 'date' => $date, 'is_day_off' => true])
                : $this->schedule($employee, $date);
        }

        return $schedules;
    }

    private function schedule(Employee $employee, string $date): ShiftSchedule
    {
        return ShiftSchedule::create(['employee_id' => $employee->id, 'date' => $date, 'shift_id' => $this->morning->id, 'company_location_id' => $this->outlet->id]);
    }

    private function employee(int $quota = 12): Employee
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('Employee');

        return Employee::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Staff'])->id,
            'nip' => 'PFC-'.$user->id,
            'full_name' => "Pekerja {$user->id}",
            'join_date' => '2024-01-02',
            'status' => 'active',
            'has_annual_leave' => true,
            'annual_leave_quota' => $quota,
        ])->load('user');
    }

    /**
     * @return array<string, string>
     */
    private function payload(string $start, string $end): array
    {
        return ['leave_type' => LeaveRequest::TYPE_ANNUAL, 'start_date' => $start, 'end_date' => $end, 'reason' => 'Liburan'];
    }

    private function pendingLeave(Employee $employee, string $start, string $end): LeaveRequest
    {
        $leave = LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type' => LeaveRequest::TYPE_ANNUAL,
            'start_date' => $start,
            'end_date' => $end,
            'days_count' => count(app(AnnualLeaveService::class)->chargedDates($employee, Carbon::parse($start), Carbon::parse($end))),
            'reason' => 'Liburan',
            'status' => 'pending',
        ]);

        app(ApprovalFlowService::class)->generateSteps($leave, 'leave');

        return $leave->fresh();
    }

    private function approve(LeaveRequest $leave): LeaveRequest
    {
        app(ApprovalFlowService::class)->approveStep($leave, $this->superadmin);

        return $leave->fresh();
    }

    /**
     * @return list<string>
     */
    private function leaveRowDates(Employee $employee): array
    {
        return Attendance::where('employee_id', $employee->id)
            ->where('status', AttendanceStatus::Leave->value)
            ->orderBy('date')
            ->get()
            ->map(fn (Attendance $row): string => $row->date->toDateString())
            ->all();
    }
}
