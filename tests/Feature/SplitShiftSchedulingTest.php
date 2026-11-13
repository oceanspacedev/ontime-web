<?php

namespace Tests\Feature;

use App\Filament\Pages\JadwalMingguan;
use App\Filament\Resources\Shifts\Pages\ManageShifts;
use App\Filament\Resources\ShiftSchedules\Pages\ManageShiftSchedules;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Services\Attendance\AttendanceCheckInput;
use App\Services\Attendance\AttendanceRuleException;
use App\Services\AttendanceCheckService;
use App\Services\AttendanceReminderService;
use App\Services\OutletRosterService;
use App\Services\ScheduleCopyService;
use App\Services\ShiftAssignmentService;
use App\Services\TeamRecapService;
use App\Services\WorkScheduleService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Real Ciledug rosters (week 44, November 2026): split shifts, workers lent between brands,
 * Talenta codes, hours that differ by weekday, short part-time shifts and days off.
 */
class SplitShiftSchedulingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Division $momoyo;

    private Division $luuca;

    private Shift $split;

    private Shift $luucaNoon;

    private CompanyLocation $momoyoOutlet;

    private CompanyLocation $luucaOutlet;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        foreach (['Superadmin', 'Admin', 'Employee'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->company = Company::create(['name' => 'PT Franchise', 'code' => 'PFC', 'is_active' => true]);
        $this->momoyo = Division::create(['company_id' => $this->company->id, 'name' => 'MOMOYO', 'is_active' => true]);
        $this->luuca = Division::create(['company_id' => $this->company->id, 'name' => 'LUUCA', 'is_active' => true]);
        $this->split = Shift::create(['company_id' => $this->company->id, 'division_id' => $this->momoyo->id, 'name' => 'Split', 'code' => 'SP', 'talenta_code' => 'Regular 11', 'start_time' => '07:45', 'end_time' => '13:00', 'second_start_time' => '17:45', 'second_end_time' => '22:00']);
        $this->luucaNoon = Shift::create(['company_id' => $this->company->id, 'division_id' => $this->luuca->id, 'name' => 'Siang', 'code' => 'S', 'talenta_code' => 'LUUCA SHIFT 13.30', 'start_time' => '13:30', 'end_time' => '22:30']);
        $this->momoyoOutlet = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $this->momoyo->id, 'name' => 'Momoyo Ciledug', 'latitude' => -6.2297, 'longitude' => 106.7112]);
        $this->luucaOutlet = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $this->luuca->id, 'name' => 'Luuca Ciledug', 'latitude' => -6.2301, 'longitude' => 106.7120]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        $this->grantDataAccessTo($this->admin, $this->company->id);
    }

    public function test_split_shift_is_labelled_with_both_segments_and_counts_work_time_without_the_break(): void
    {
        $this->assertSame('MOMOYO · Split (07:45 - 13:00 & 17:45 - 22:00)', $this->split->label());
        $this->assertSame([['start' => '07:45', 'end' => '13:00'], ['start' => '17:45', 'end' => '22:00']], $this->split->segments());
        $this->assertSame(315 + 255, $this->split->scheduledMinutes());

        $partTime = Shift::create(['company_id' => $this->company->id, 'name' => 'Parttime 4,5 jam', 'start_time' => '08:00', 'end_time' => '12:30']);
        $this->assertSame(270, $partTime->scheduledMinutes());
        $this->assertFalse($partTime->hasSecondSegment());
    }

    public function test_each_segment_of_a_split_shift_is_checked_in_and_out_and_judged_late_on_its_own(): void
    {
        $worker = $this->makeEmployee();
        ShiftSchedule::create(['employee_id' => $worker->id, 'date' => today(), 'shift_id' => $this->split->id, 'company_location_id' => $this->momoyoOutlet->id, 'section' => 'K']);
        $service = app(AttendanceCheckService::class);
        $input = new AttendanceCheckInput;

        $this->travelTo(today()->setTime(7, 50));
        $service->checkIn($worker->user, $input);

        $this->travelTo(today()->setTime(13, 0));
        $service->checkOut($worker->user, $input);

        $this->travelTo(today()->setTime(18, 30));
        $second = $service->checkIn($worker->user, $input)->attendance;

        $this->assertSame(2, (int) $second->segment);
        $this->assertSame(45, $second->late_minutes);
        $this->assertSame('late', $second->status->value);

        $this->travelTo(today()->setTime(22, 0));
        $service->checkOut($worker->user, $input);

        $rows = Attendance::where('employee_id', $worker->id)->orderBy('segment')->get();
        $this->assertSame([1, 2], $rows->pluck('segment')->map(fn ($segment): int => (int) $segment)->all());
        $this->assertSame([0, 45], $rows->pluck('late_minutes')->map(fn ($minutes): int => (int) $minutes)->all());
        $this->assertNotNull($rows[0]->check_out);
        $this->assertNotNull($rows[1]->check_out);

        try {
            $service->checkIn($worker->user, $input);
            $this->fail('A third check-in must be refused.');
        } catch (AttendanceRuleException $exception) {
            $this->assertSame(409, $exception->status);
        }
    }

    public function test_second_segment_opens_an_hour_before_it_starts(): void
    {
        $worker = $this->makeEmployee();
        ShiftSchedule::create(['employee_id' => $worker->id, 'date' => today(), 'shift_id' => $this->split->id]);
        $service = app(AttendanceCheckService::class);

        $this->travelTo(today()->setTime(7, 45));
        $service->checkIn($worker->user, new AttendanceCheckInput);
        $this->travelTo(today()->setTime(13, 5));
        $service->checkOut($worker->user, new AttendanceCheckInput);

        $this->travelTo(today()->setTime(14, 0));

        try {
            $service->checkIn($worker->user, new AttendanceCheckInput);
            $this->fail('Too early for the second segment.');
        } catch (AttendanceRuleException $exception) {
            $this->assertStringContainsString('Check in dibuka pukul 16:45', $exception->getMessage());
        }

        $this->travelTo(today()->setTime(16, 45));
        $this->assertSame(2, (int) $service->checkIn($worker->user, new AttendanceCheckInput)->attendance->segment);
    }

    public function test_second_segment_cannot_start_while_the_first_is_still_open(): void
    {
        $worker = $this->makeEmployee();
        ShiftSchedule::create(['employee_id' => $worker->id, 'date' => today(), 'shift_id' => $this->split->id]);
        $service = app(AttendanceCheckService::class);

        $this->travelTo(today()->setTime(7, 50));
        $service->checkIn($worker->user, new AttendanceCheckInput);

        $this->travelTo(today()->setTime(17, 50));
        $this->expectException(AttendanceRuleException::class);
        $service->checkIn($worker->user, new AttendanceCheckInput);
    }

    public function test_a_single_segment_shift_still_allows_one_check_in_a_day(): void
    {
        $worker = $this->makeEmployee();
        ShiftSchedule::create(['employee_id' => $worker->id, 'date' => today(), 'shift_id' => $this->luucaNoon->id]);
        $service = app(AttendanceCheckService::class);

        $this->travelTo(today()->setTime(13, 30));
        $service->checkIn($worker->user, new AttendanceCheckInput);
        $service->checkOut($worker->user, new AttendanceCheckInput);

        $this->expectException(AttendanceRuleException::class);
        $service->checkIn($worker->user, new AttendanceCheckInput);
    }

    public function test_today_endpoint_moves_to_the_second_segment_after_the_first_check_out(): void
    {
        $worker = $this->makeEmployee();
        ShiftSchedule::create(['employee_id' => $worker->id, 'date' => today(), 'shift_id' => $this->split->id, 'company_location_id' => $this->momoyoOutlet->id]);
        $service = app(AttendanceCheckService::class);
        $this->actingAs($worker->user, 'sanctum');

        $this->travelTo(today()->setTime(7, 45));
        $this->getJson('/api/v1/attendance/today')
            ->assertOk()
            ->assertJsonPath('schedule.segment', 1)
            ->assertJsonPath('schedule.segment_count', 2)
            ->assertJsonPath('schedule.segments.1.start', '17:45')
            ->assertJsonPath('schedule.shift.talenta_code', 'Regular 11')
            ->assertJsonPath('has_checked_in', false);

        $service->checkIn($worker->user, new AttendanceCheckInput);
        $service->checkOut($worker->user, new AttendanceCheckInput);

        $this->travelTo(today()->setTime(14, 0));
        $this->getJson('/api/v1/attendance/today')
            ->assertOk()
            ->assertJsonPath('schedule.segment', 2)
            ->assertJsonPath('schedule.start_time', '17:45')
            ->assertJsonPath('schedule.end_time', '22:00')
            ->assertJsonPath('has_checked_in', false)
            ->assertJsonCount(1, 'attendances');

        $this->travelTo(today()->setTime(17, 45));
        $service->checkIn($worker->user, new AttendanceCheckInput);

        $this->getJson('/api/v1/attendance/today')
            ->assertOk()
            ->assertJsonPath('has_checked_in', true)
            ->assertJsonPath('has_checked_out', false)
            ->assertJsonPath('attendance.segment', 2)
            ->assertJsonCount(2, 'attendances');
    }

    public function test_worker_lent_to_another_brand_is_judged_by_that_brand_shift_and_outlet(): void
    {
        $worker = $this->makeEmployee(['division_id' => $this->momoyo->id]);

        $schedule = app(ShiftAssignmentService::class)->assignDay(
            $this->admin,
            $worker,
            today()->toDateString(),
            ShiftAssignmentService::MODE_SHIFT,
            $this->luucaNoon->id,
            $this->luucaOutlet->id,
            'Dining',
        );

        $this->assertSame($this->luucaNoon->id, $schedule->shift_id);

        $this->travelTo(today()->setTime(14, 0));
        $attendance = app(AttendanceCheckService::class)->checkIn($worker->user, new AttendanceCheckInput)->attendance;

        $this->assertSame($this->luucaOutlet->id, $attendance->company_location_id);
        $this->assertSame($this->luucaNoon->id, $attendance->shift_id);
        $this->assertSame(30, (int) $attendance->late_minutes);
        $this->assertSame(15, app(WorkScheduleService::class)->resolve($worker->fresh(), today())['late_tolerance_minutes']);
    }

    public function test_weekday_specific_hours_are_separate_shifts_that_share_a_talenta_code(): void
    {
        $this->actingAs($this->admin);
        $form = ['company_id' => $this->company->id, 'division_id' => $this->luuca->id, 'is_active' => true, 'talenta_code' => 'LUUCA SHIFT 14.30'];

        Livewire::test(ManageShifts::class)
            ->callAction('create', data: [...$form, 'name' => 'Siang Sabtu', 'code' => 'S', 'start_time' => '14:30', 'end_time' => '23:30'])
            ->assertHasNoFormErrors();

        $saturday = Shift::where('name', 'Siang Sabtu')->firstOrFail();
        $worker = $this->makeEmployee();
        ShiftSchedule::create(['employee_id' => $worker->id, 'date' => '2026-11-07', 'shift_id' => $saturday->id]);
        ShiftSchedule::create(['employee_id' => $worker->id, 'date' => '2026-11-06', 'shift_id' => $this->luucaNoon->id]);

        $service = app(WorkScheduleService::class);
        $this->assertSame('14:30', substr($service->resolve($worker, Carbon::parse('2026-11-07'))['start_time'], 0, 5));
        $this->assertSame('13:30', substr($service->resolve($worker, Carbon::parse('2026-11-06'))['start_time'], 0, 5));
        $this->assertSame('LUUCA SHIFT 14.30', $saturday->talenta_code);
    }

    public function test_shift_form_requires_a_complete_second_segment_that_starts_after_the_first(): void
    {
        $this->actingAs($this->admin);
        $form = ['company_id' => $this->company->id, 'division_id' => $this->momoyo->id, 'name' => 'SP GC', 'is_active' => true, 'start_time' => '07:45', 'end_time' => '13:00'];

        Livewire::test(ManageShifts::class)
            ->callAction('create', data: [...$form, 'second_start_time' => '20:00'])
            ->assertHasFormErrors(['second_end_time']);

        Livewire::test(ManageShifts::class)
            ->callAction('create', data: [...$form, 'second_start_time' => '12:00', 'second_end_time' => '23:00'])
            ->assertHasFormErrors(['second_start_time']);

        Livewire::test(ManageShifts::class)
            ->callAction('create', data: [...$form, 'second_start_time' => '20:00', 'second_end_time' => '23:00'])
            ->assertHasNoFormErrors();

        $this->assertSame('MOMOYO · SP GC (07:45 - 13:00 & 20:00 - 23:00)', Shift::where('name', 'SP GC')->firstOrFail()->label());
    }

    public function test_roster_and_recap_treat_a_split_day_as_one_day_of_work(): void
    {
        $worker = $this->makeEmployee(['division_id' => $this->momoyo->id]);
        ShiftSchedule::create(['employee_id' => $worker->id, 'date' => today(), 'shift_id' => $this->split->id, 'company_location_id' => $this->momoyoOutlet->id]);
        $service = app(AttendanceCheckService::class);

        $this->travelTo(today()->setTime(7, 45));
        $service->checkIn($worker->user, new AttendanceCheckInput);
        $this->travelTo(today()->setTime(13, 0));
        $service->checkOut($worker->user, new AttendanceCheckInput);

        $this->travelTo(today()->setTime(14, 0));
        $entry = app(OutletRosterService::class)->roster($this->admin, today())[0]['entries'][0];
        $this->assertSame(OutletRosterService::STATE_UPCOMING, $entry['state'], 'Segment 2 has not started.');
        $this->assertCount(2, $entry['segments']);

        $this->travelTo(today()->setTime(18, 0));
        $entry = app(OutletRosterService::class)->roster($this->admin, today())[0]['entries'][0];
        $this->assertSame(OutletRosterService::STATE_MISSING, $entry['state'], 'Segment 2 started and nobody checked in.');

        $service->checkIn($worker->user, new AttendanceCheckInput);
        $this->travelTo(today()->setTime(22, 0));
        $service->checkOut($worker->user, new AttendanceCheckInput);

        $entry = app(OutletRosterService::class)->roster($this->admin, today())[0]['entries'][0];
        $this->assertSame(OutletRosterService::STATE_DONE, $entry['state']);

        $row = app(TeamRecapService::class)->recap($this->admin, today())[0];
        $this->assertSame(1, $row['scheduled_days']);
        $this->assertSame(1, $row['present_days']);
        $this->assertSame(9.5, $row['scheduled_hours']);
        $this->assertSame(9.25, $row['worked_hours'], '5h15 and 4h worked, the break is not counted.');
    }

    public function test_second_segment_gets_its_own_reminders(): void
    {
        config(['services.onesignal.app_id' => 'app-id', 'services.onesignal.rest_api_key' => 'rest-key']);
        $worker = $this->makeEmployee();
        ShiftSchedule::create(['employee_id' => $worker->id, 'date' => '2026-11-06', 'shift_id' => $this->split->id]);
        Attendance::create(['employee_id' => $worker->id, 'date' => '2026-11-06', 'segment' => 1, 'check_in' => '2026-11-06 07:45:00', 'check_out' => '2026-11-06 13:00:00', 'status' => 'on_time']);
        $reminders = app(AttendanceReminderService::class);

        $this->travelTo(Carbon::parse('2026-11-06 17:30'));
        $this->assertSame(1, $reminders->sendDue(now())['shift_soon'], 'Segment 2 starts 17:45; segment 1 is done and sends nothing.');
        $this->assertSame(0, $reminders->sendDue(now())['shift_soon'], 'Not sent twice.');

        $this->travelTo(Carbon::parse('2026-11-06 18:02'));
        $this->assertSame(1, $reminders->sendDue(now())['not_checked_in']);
    }

    public function test_attendance_rows_stay_unique_per_worker_date_and_segment(): void
    {
        $worker = $this->makeEmployee();
        Attendance::create(['employee_id' => $worker->id, 'date' => today(), 'segment' => 1, 'status' => 'on_time']);
        Attendance::create(['employee_id' => $worker->id, 'date' => today(), 'segment' => 2, 'status' => 'on_time']);

        $this->expectException(QueryException::class);
        Attendance::create(['employee_id' => $worker->id, 'date' => today(), 'segment' => 2, 'status' => 'on_time']);
    }

    public function test_last_weeks_schedule_is_copied_without_overwriting_or_reviving_inactive_shifts(): void
    {
        $worker = $this->makeEmployee(['division_id' => $this->momoyo->id]);
        $other = $this->makeEmployee();
        $oldShift = Shift::create(['company_id' => $this->company->id, 'name' => 'Lama', 'start_time' => '08:00', 'end_time' => '12:00', 'is_active' => false]);

        // Week 44 of November 2026: Monday the 2nd.
        ShiftSchedule::create(['employee_id' => $worker->id, 'date' => '2026-11-02', 'shift_id' => $this->split->id, 'company_location_id' => $this->momoyoOutlet->id, 'section' => 'K']);
        ShiftSchedule::create(['employee_id' => $worker->id, 'date' => '2026-11-03', 'is_day_off' => true]);
        ShiftSchedule::create(['employee_id' => $worker->id, 'date' => '2026-11-04', 'shift_id' => $oldShift->id]);
        ShiftSchedule::create(['employee_id' => $other->id, 'date' => '2026-11-02', 'shift_id' => $this->luucaNoon->id]);
        ShiftSchedule::create(['employee_id' => $other->id, 'date' => '2026-11-09', 'is_day_off' => true]);

        $result = app(ScheduleCopyService::class)->copyWeek($this->admin, Carbon::parse('2026-11-05'), Carbon::parse('2026-11-12'));

        $this->assertSame(['copied' => 2, 'skipped_existing' => 1, 'skipped_unavailable' => 1], Arr::only($result, ['copied', 'skipped_existing', 'skipped_unavailable']));
        $copied = ShiftSchedule::where('employee_id', $worker->id)->whereDate('date', '2026-11-09')->firstOrFail();
        $this->assertSame([$this->split->id, $this->momoyoOutlet->id, 'K'], [$copied->shift_id, $copied->company_location_id, $copied->section]);
        $this->assertTrue(ShiftSchedule::where('employee_id', $worker->id)->whereDate('date', '2026-11-10')->firstOrFail()->is_day_off);
        $this->assertFalse(ShiftSchedule::where('employee_id', $worker->id)->whereDate('date', '2026-11-11')->exists());
        $this->assertTrue(ShiftSchedule::where('employee_id', $other->id)->whereDate('date', '2026-11-09')->firstOrFail()->is_day_off, 'The existing day off stays.');
    }

    public function test_panel_action_copies_a_week(): void
    {
        $worker = $this->makeEmployee();
        ShiftSchedule::create(['employee_id' => $worker->id, 'date' => '2026-11-02', 'shift_id' => $this->luucaNoon->id]);
        $this->actingAs($this->admin);

        Livewire::test(ManageShiftSchedules::class)
            ->callAction('copyWeek', data: ['from' => '2026-11-04', 'to' => '2026-11-11'])
            ->assertHasNoActionErrors()
            ->assertNotified('Jadwal disalin');

        $this->assertTrue(ShiftSchedule::where('employee_id', $worker->id)->whereDate('date', '2026-11-09')->exists());
    }

    public function test_week_endpoint_gives_a_grid_of_workers_and_days(): void
    {
        $worker = $this->makeEmployee(['division_id' => $this->momoyo->id]);
        ShiftSchedule::create(['employee_id' => $worker->id, 'date' => '2026-11-02', 'shift_id' => $this->split->id, 'company_location_id' => $this->momoyoOutlet->id, 'section' => 'K']);
        ShiftSchedule::create(['employee_id' => $worker->id, 'date' => '2026-11-03', 'is_day_off' => true]);
        ShiftSchedule::create(['employee_id' => $worker->id, 'date' => '2026-11-09', 'is_day_off' => true]);

        $this->actingAs($worker->user, 'sanctum');
        $this->getJson('/api/v1/team/week?date=2026-11-05')->assertForbidden();

        $this->actingAs($this->admin, 'sanctum');
        $this->getJson('/api/v1/team/week?date=2026-11-05')
            ->assertOk()
            ->assertJsonPath('start', '2026-11-02')
            ->assertJsonPath('end', '2026-11-08')
            ->assertJsonCount(7, 'days')
            ->assertJsonCount(1, 'workers')
            ->assertJsonPath('workers.0.days.2026-11-02.shift.code', 'SP')
            ->assertJsonPath('workers.0.days.2026-11-02.shift.segments.1.start', '17:45')
            ->assertJsonPath('workers.0.days.2026-11-02.outlet', 'Momoyo Ciledug')
            ->assertJsonPath('workers.0.days.2026-11-03.is_day_off', true)
            ->assertJsonMissingPath('workers.0.days.2026-11-09');
    }

    public function test_weekly_grid_page_shows_the_week_and_edits_a_cell(): void
    {
        $worker = $this->makeEmployee(['division_id' => $this->momoyo->id]);
        ShiftSchedule::create(['employee_id' => $worker->id, 'date' => '2026-11-02', 'shift_id' => $this->split->id, 'company_location_id' => $this->momoyoOutlet->id]);
        $this->actingAs($this->admin);

        $page = Livewire::test(JadwalMingguan::class)
            ->set('date', '2026-11-04')
            ->assertSee($worker->full_name)
            ->assertSee('SP')
            ->assertSee('Momoyo Ciledug');

        $page->mountAction('editCell', ['type' => 'employee', 'id' => $worker->id, 'company_id' => $this->company->id, 'name' => $worker->full_name, 'date' => '2026-11-03'])
            ->setActionData(['mode' => 'off'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertTrue(ShiftSchedule::where('employee_id', $worker->id)->whereDate('date', '2026-11-03')->firstOrFail()->is_day_off);

        $page->callAction('editCell', ['mode' => 'clear'], ['type' => 'employee', 'id' => $worker->id, 'company_id' => $this->company->id, 'name' => $worker->full_name, 'date' => '2026-11-03']);
        $this->assertFalse(ShiftSchedule::where('employee_id', $worker->id)->whereDate('date', '2026-11-03')->exists());
    }

    public function test_weekly_grid_page_is_closed_to_workers(): void
    {
        $this->actingAs($this->makeEmployee()->user);

        $this->assertFalse(JadwalMingguan::canAccess());
    }

    public function test_correction_of_the_evening_time_lands_on_segment_two_and_is_judged_late_from_its_start(): void
    {
        $worker = $this->makeEmployee(['division_id' => $this->momoyo->id]);
        ShiftSchedule::create(['employee_id' => $worker->id, 'date' => '2026-11-05', 'shift_id' => $this->split->id]);
        Attendance::create(['employee_id' => $worker->id, 'date' => '2026-11-05', 'segment' => 1, 'check_in' => '2026-11-05 07:45:00', 'check_out' => '2026-11-05 13:00:00', 'status' => 'on_time']);

        $correction = AttendanceCorrection::create([
            'employee_id' => $worker->id,
            'date' => '2026-11-05',
            'corrected_check_in' => '18:15',
            'corrected_check_out' => '22:00',
            'reason' => 'Lupa absen',
            'status' => 'pending',
        ]);
        $correction->applyCorrection();

        $rows = Attendance::where('employee_id', $worker->id)->orderBy('segment')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('on_time', $rows[0]->status->value, 'Segment 1 stays as it was.');
        $this->assertSame(2, (int) $rows[1]->segment);
        $this->assertSame(30, (int) $rows[1]->late_minutes);
        $this->assertSame('late', $rows[1]->status->value);
        $this->assertTrue($rows[1]->is_corrected);
    }

    public function test_correction_of_a_missed_morning_check_in_recomputes_lateness_on_segment_one(): void
    {
        $worker = $this->makeEmployee(['division_id' => $this->momoyo->id]);
        ShiftSchedule::create(['employee_id' => $worker->id, 'date' => '2026-11-05', 'shift_id' => $this->split->id]);
        Attendance::create(['employee_id' => $worker->id, 'date' => '2026-11-05', 'segment' => 1, 'status' => 'absent']);

        AttendanceCorrection::create(['employee_id' => $worker->id, 'date' => '2026-11-05', 'corrected_check_in' => '09:00', 'reason' => 'Mesin error', 'status' => 'pending'])->applyCorrection();

        $row = Attendance::where('employee_id', $worker->id)->firstOrFail();
        $this->assertSame(['late', 75], [$row->status->value, (int) $row->late_minutes]);
        $this->assertSame(1, Attendance::where('employee_id', $worker->id)->count());
    }

    public function test_attendance_report_counts_a_split_day_once(): void
    {
        $worker = $this->makeEmployee(['division_id' => $this->momoyo->id]);
        Attendance::create(['employee_id' => $worker->id, 'date' => '2026-11-05', 'segment' => 1, 'check_in' => '2026-11-05 07:45:00', 'check_out' => '2026-11-05 13:00:00', 'status' => 'on_time']);
        Attendance::create(['employee_id' => $worker->id, 'date' => '2026-11-05', 'segment' => 2, 'check_in' => '2026-11-05 18:15:00', 'check_out' => '2026-11-05 22:00:00', 'status' => 'late', 'late_minutes' => 30]);
        $this->actingAs($this->admin, 'sanctum');

        $this->getJson('/api/v1/laporan-absensi?start_date=2026-11-04&end_date=2026-11-06')
            ->assertOk()
            ->assertJsonPath('summary.total_present', 1)
            ->assertJsonPath('summary.total_late', 1);
    }

    public function test_copy_endpoint_is_for_managers_only(): void
    {
        $worker = $this->makeEmployee();
        ShiftSchedule::create(['employee_id' => $worker->id, 'date' => '2026-11-02', 'shift_id' => $this->luucaNoon->id]);

        $this->actingAs($worker->user, 'sanctum');
        $this->postJson('/api/v1/team/schedules/copy', ['from' => '2026-11-02', 'to' => '2026-11-09'])->assertForbidden();

        $this->actingAs($this->admin, 'sanctum');
        $this->postJson('/api/v1/team/schedules/copy', ['from' => '2026-11-02', 'to' => '2026-11-09'])
            ->assertOk()
            ->assertJsonPath('data.copied', 1);
        $this->postJson('/api/v1/team/schedules/copy', ['from' => 'nope', 'to' => '2026-11-09'])->assertUnprocessable();
    }

    private function makeEmployee(array $attributes = []): Employee
    {
        $user = User::factory()->create();
        $user->assignRole('Employee');

        return Employee::create($attributes + [
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->luuca->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Crew'])->id,
            'nip' => 'PF-'.$user->id,
            'full_name' => "Crew {$user->id}",
            'status' => 'active',
        ]);
    }
}
