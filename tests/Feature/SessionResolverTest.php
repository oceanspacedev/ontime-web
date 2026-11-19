<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Holiday;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Services\Attendance\WorkSession;
use App\Services\SessionResolver;
use App\Services\WorkScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The read-only session resolver: which work sessions a worker has on a business date, the standing
 * hours without a dated schedule (also for companies where a blank day means not scheduled), and the
 * check-in and check-out windows of a session.
 *
 * Monday 16 Nov 2026; Saturday 21 Nov 2026.
 */
class SessionResolverTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-11-16';

    private const TUESDAY = '2026-11-17';

    private const SATURDAY = '2026-11-21';

    private Company $company;

    private Division $division;

    private Shift $morning;

    private Shift $split;

    private CompanyLocation $luuca;

    private CompanyLocation $jchicken;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->travelTo(Carbon::parse(self::MONDAY.' 07:00:00'));

        $this->company = Company::create(['name' => 'PT Franchise', 'code' => 'PFC', 'is_active' => true]);
        $this->company->policy->update(['work_start_time' => '09:00:00', 'work_end_time' => '18:00:00', 'late_tolerance_minutes' => 10]);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'LUUCA', 'is_active' => true]);
        $this->morning = Shift::create(['company_id' => $this->company->id, 'name' => 'Pagi', 'code' => 'P', 'start_time' => '08:00', 'end_time' => '15:00', 'late_tolerance_minutes' => 5, 'normal_minutes' => 400]);
        $this->split = Shift::create(['company_id' => $this->company->id, 'name' => 'Split', 'code' => 'SP', 'start_time' => '07:45', 'end_time' => '13:00', 'second_start_time' => '17:45', 'second_end_time' => '22:00']);
        $this->luuca = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $this->division->id, 'name' => 'Luuca Ciledug', 'latitude' => -6.2297, 'longitude' => 106.7112]);
        $this->jchicken = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $this->division->id, 'name' => 'JChicken Ciledug', 'latitude' => -6.2301, 'longitude' => 106.7120]);
    }

    // ── Sessions of a date ───────────────────────────────────────────────────

    public function test_a_single_rostered_shift_is_one_session_equal_to_the_resolved_hours(): void
    {
        $employee = $this->employee();
        $schedule = ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::MONDAY, 'shift_id' => $this->morning->id, 'company_location_id' => $this->luuca->id, 'section' => 'Kasir']);

        $sessions = $this->sessions($employee, self::MONDAY);

        $this->assertCount(1, $sessions);
        $session = $sessions[0];
        $this->assertSame($schedule->id, $session->scheduleId);
        $this->assertSame(1, $session->sequence);
        $this->assertSame('1:1', $session->key());
        $this->assertSame('schedule', $session->source);
        $this->assertSame(420, $session->scheduledMinutes);
        $this->assertSame(400, $session->normalMinutes);
        $this->assertTrue($session->isExpected);
        $this->assertFalse($session->crossesMidnight());
        $this->assertSameAsResolved($employee, self::MONDAY, $session);
    }

    public function test_a_split_shift_is_two_sessions_of_the_same_schedule(): void
    {
        $employee = $this->employee();
        $schedule = ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::MONDAY, 'shift_id' => $this->split->id, 'company_location_id' => $this->luuca->id]);

        $sessions = $this->sessions($employee, self::MONDAY);

        $this->assertSame(['1:1', '1:2'], array_map(fn (WorkSession $session): string => $session->key(), $sessions));
        $this->assertSame([self::MONDAY.' 07:45', self::MONDAY.' 17:45'], array_map(fn (WorkSession $session): string => $session->startAt->format('Y-m-d H:i'), $sessions));
        $this->assertSame([self::MONDAY.' 13:00', self::MONDAY.' 22:00'], array_map(fn (WorkSession $session): string => $session->endAt->format('Y-m-d H:i'), $sessions));

        foreach ($sessions as $session) {
            $this->assertSame($schedule->id, $session->scheduleId);
            $this->assertSame(2, $session->segmentCount);
            $this->assertSame(570, $session->scheduledMinutes);
            $this->assertNull($session->normalMinutes);
            $this->assertSameAsResolved($employee, self::MONDAY, $session);
        }
    }

    public function test_two_schedules_at_two_outlets_are_ordered_by_start(): void
    {
        $employee = $this->employee();
        $evening = Shift::create(['company_id' => $this->company->id, 'name' => 'Sore', 'code' => 'S', 'start_time' => '16:00', 'end_time' => '22:00']);
        // Stored out of order: the later shift carries the lower sequence.
        $second = ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::MONDAY, 'sequence' => 1, 'shift_id' => $evening->id, 'company_location_id' => $this->jchicken->id]);
        $first = ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::MONDAY, 'sequence' => 2, 'shift_id' => $this->morning->id, 'company_location_id' => $this->luuca->id]);

        $sessions = $this->sessions($employee, self::MONDAY);

        $this->assertCount(2, $sessions);
        $this->assertSame([$first->id, $second->id], [$sessions[0]->scheduleId, $sessions[1]->scheduleId]);
        $this->assertSame(['2:1', '1:1'], [$sessions[0]->key(), $sessions[1]->key()]);
        $this->assertSame(['Luuca Ciledug', 'JChicken Ciledug'], [$sessions[0]->location->name, $sessions[1]->location->name]);
        $this->assertSame(['08:00', '16:00'], [$sessions[0]->startAt->format('H:i'), $sessions[1]->startAt->format('H:i')]);
    }

    public function test_a_day_off_has_no_session(): void
    {
        $employee = $this->employee(['shift_id' => $this->morning->id]);
        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::MONDAY, 'is_day_off' => true]);

        $this->assertSame([], $this->sessions($employee, self::MONDAY));
    }

    public function test_an_overnight_shift_ends_on_the_next_day(): void
    {
        $employee = $this->employee();
        $night = Shift::create(['company_id' => $this->company->id, 'name' => 'Malam', 'code' => 'M', 'start_time' => '22:00', 'end_time' => '06:00']);
        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::MONDAY, 'shift_id' => $night->id]);

        $session = $this->sessions($employee, self::MONDAY)[0];

        $this->assertSame(self::MONDAY, $session->date->toDateString());
        $this->assertSame(self::MONDAY.' 22:00', $session->startAt->format('Y-m-d H:i'));
        $this->assertSame(self::TUESDAY.' 06:00', $session->endAt->format('Y-m-d H:i'));
        $this->assertTrue($session->crossesMidnight());
        $this->assertSameAsResolved($employee, self::MONDAY, $session);
    }

    // ── Standing hours ───────────────────────────────────────────────────────

    public function test_without_a_schedule_the_default_shift_is_the_standing_session(): void
    {
        $employee = $this->employee(['shift_id' => $this->morning->id]);

        $sessions = $this->sessions($employee, self::MONDAY);

        $this->assertCount(1, $sessions);
        $this->assertNull($sessions[0]->scheduleId);
        $this->assertSame('default', $sessions[0]->source);
        $this->assertTrue($sessions[0]->isExpected);
        $this->assertSameAsResolved($employee, self::MONDAY, $sessions[0]);
    }

    public function test_a_split_default_shift_gives_both_parts(): void
    {
        $employee = $this->employee(['shift_id' => $this->split->id]);

        $sessions = $this->sessions($employee, self::MONDAY);

        $this->assertSame(['1:1', '1:2'], array_map(fn (WorkSession $session): string => $session->key(), $sessions));

        foreach ($sessions as $session) {
            $this->assertSame('default', $session->source);
            $this->assertSameAsResolved($employee, self::MONDAY, $session);
        }
    }

    public function test_without_a_schedule_or_default_shift_the_company_hours_are_the_standing_session(): void
    {
        $employee = $this->employee();

        $sessions = $this->sessions($employee, self::MONDAY);

        $this->assertCount(1, $sessions);
        $this->assertSame('policy', $sessions[0]->source);
        $this->assertNull($sessions[0]->shift);
        $this->assertSame(540, $sessions[0]->scheduledMinutes);
        $this->assertNull($sessions[0]->normalMinutes);
        $this->assertSame(10, $sessions[0]->toleranceMinutes);
        $this->assertSameAsResolved($employee, self::MONDAY, $sessions[0]);
    }

    public function test_standing_hours_apply_on_a_weekend_or_holiday_but_are_not_expected(): void
    {
        $employee = $this->employee();
        Holiday::create(['name' => 'Libur Nasional', 'date' => self::TUESDAY, 'is_national' => true]);

        $saturday = $this->sessions($employee, self::SATURDAY);
        $holiday = $this->sessions($employee, self::TUESDAY);

        $this->assertCount(1, $saturday);
        $this->assertFalse($saturday[0]->isExpected);
        $this->assertSameAsResolved($employee, self::SATURDAY, $saturday[0]);
        $this->assertCount(1, $holiday);
        $this->assertFalse($holiday[0]->isExpected);
        $this->assertTrue($this->sessions($employee, self::MONDAY)[0]->isExpected);
    }

    public function test_a_freelancer_without_any_shift_has_no_session(): void
    {
        $freelancer = $this->freelancer();

        $this->assertSame([], app(SessionResolver::class)->sessionsFor($freelancer, Carbon::parse(self::MONDAY)));
        $this->assertNull(app(SessionResolver::class)->standingSession($freelancer, Carbon::parse(self::MONDAY)));
    }

    public function test_a_row_without_shift_or_day_off_counts_as_no_row_but_keeps_its_outlet(): void
    {
        $employee = $this->employee(['shift_id' => $this->morning->id]);
        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::MONDAY, 'company_location_id' => $this->luuca->id, 'section' => 'Bar']);

        $sessions = $this->sessions($employee, self::MONDAY);
        $resolved = app(WorkScheduleService::class)->resolve($employee->fresh(), Carbon::parse(self::MONDAY));

        $this->assertCount(1, $sessions);
        $this->assertNull($sessions[0]->scheduleId);
        $this->assertSame('default', $sessions[0]->source);
        $this->assertSame('Luuca Ciledug', $sessions[0]->location?->name);
        $this->assertSame('Bar', $sessions[0]->section);
        $this->assertNull($resolved['schedule_id'], 'A row that names no shift is not the schedule the hours come from.');
        $this->assertSame($this->luuca->id, $resolved['location']?->id);
    }

    // ── Companies that use shift rosters (blank = not scheduled) ─────────────

    public function test_in_a_roster_company_a_blank_day_has_no_session(): void
    {
        $this->company->policy->update(['uses_shift_roster' => true]);
        $rostered = $this->employee(['shift_id' => $this->morning->id]);
        ShiftSchedule::create(['employee_id' => $rostered->id, 'date' => self::MONDAY, 'shift_id' => $this->morning->id]);
        $withoutShift = $this->employee();
        $freelancer = $this->freelancer();

        $this->assertSame([], $this->sessions($rostered, self::TUESDAY), 'Rostered before: a blank day is not scheduled, default shift or not.');
        $this->assertCount(1, $this->sessions($rostered, self::MONDAY));
        $this->assertSame([], $this->sessions($withoutShift, self::MONDAY), 'The company hours are not standing hours in a roster company.');
        $this->assertSame([], app(SessionResolver::class)->sessionsFor($freelancer, Carbon::parse(self::MONDAY)));
    }

    public function test_in_a_roster_company_a_never_rostered_worker_on_a_default_shift_keeps_fixed_hours_on_working_days(): void
    {
        $this->company->policy->update(['uses_shift_roster' => true]);
        $employee = $this->employee(['shift_id' => $this->morning->id]);
        Holiday::create(['name' => 'Libur Nasional', 'date' => self::TUESDAY, 'is_national' => true]);

        $monday = $this->sessions($employee, self::MONDAY);

        $this->assertCount(1, $monday);
        $this->assertSame('default', $monday[0]->source);
        $this->assertTrue($monday[0]->isExpected);
        $this->assertSame(self::MONDAY.' 08:00', $monday[0]->startAt->format('Y-m-d H:i'));
        $this->assertSame([], $this->sessions($employee, self::SATURDAY));
        $this->assertSame([], $this->sessions($employee, self::TUESDAY));
    }

    /**
     * One resolver serves the whole request, so what it reads once (holidays, the first roster row) is not
     * read again by every service that asks; a schedule row written meanwhile is not missed.
     */
    public function test_the_resolver_is_shared_within_a_request_and_notices_a_first_schedule_row(): void
    {
        $this->company->policy->update(['uses_shift_roster' => true]);
        $employee = $this->employee(['shift_id' => $this->morning->id]);

        $this->assertSame(app(SessionResolver::class), app(SessionResolver::class));
        $this->assertCount(1, $this->sessions($employee, self::MONDAY), 'Never rostered: fixed hours.');

        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::MONDAY, 'shift_id' => $this->morning->id]);

        $this->assertSame([], $this->sessions($employee, self::TUESDAY), 'Rostered now: a blank day is not scheduled.');
    }

    // ── Windows ──────────────────────────────────────────────────────────────

    public function test_check_in_opens_an_hour_before_the_start_and_closes_at_the_end(): void
    {
        $employee = $this->employee();
        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::MONDAY, 'shift_id' => $this->morning->id]);

        $window = app(SessionResolver::class)->checkInWindow($this->sessions($employee, self::MONDAY)[0]);

        $this->assertSame(self::MONDAY.' 07:00:00', $window['opens_at']->format('Y-m-d H:i:s'));
        $this->assertSame(self::MONDAY.' 15:00:00', $window['closes_at']->format('Y-m-d H:i:s'));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function checkOutWindows(): array
    {
        return [
            'closing 14:00-22:00 closes at 04:00' => ['14:00', '22:00', self::TUESDAY.' 04:00:00'],
            'long shift until 22:30 closes at 04:30' => ['08:45', '22:30', self::TUESDAY.' 04:30:00'],
            'late shift until 23:30 closes at 05:30' => ['12:00', '23:30', self::TUESDAY.' 05:30:00'],
            'day shift 08:00-17:00 closes with its date' => ['08:00', '17:00', self::MONDAY.' 23:59:59'],
            'shift until 18:00 closes at midnight' => ['09:00', '18:00', self::TUESDAY.' 00:00:00'],
        ];
    }

    #[DataProvider('checkOutWindows')]
    public function test_check_out_closes_six_hours_after_the_scheduled_end_and_never_before_midnight(string $start, string $end, string $closesAt): void
    {
        $employee = $this->employee();
        $shift = Shift::create(['company_id' => $this->company->id, 'name' => 'Uji', 'code' => 'U', 'start_time' => $start, 'end_time' => $end]);
        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::MONDAY, 'shift_id' => $shift->id]);
        $sessions = app(SessionResolver::class);
        $session = $this->sessions($employee, self::MONDAY)[0];

        $this->assertSame($closesAt, $sessions->checkOutClosesAt($session)->format('Y-m-d H:i:s'));

        // The same for an attendance row checked in to it, read from its snapshot.
        $row = new Attendance(['employee_id' => $employee->id, 'date' => self::MONDAY, 'check_in' => self::MONDAY.' '.$start.':00', 'status' => AttendanceStatus::OnTime]);
        $row->fillSnapshot($sessions->snapshotFor($session))->save();

        $this->assertSame($closesAt, $sessions->checkOutClosesAt($row->fresh())->format('Y-m-d H:i:s'));
    }

    public function test_a_night_shift_stays_open_twenty_hours_after_its_check_in(): void
    {
        $employee = $this->employee();
        $night = Shift::create(['company_id' => $this->company->id, 'name' => 'Malam', 'code' => 'M', 'start_time' => '22:00', 'end_time' => '06:00']);
        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::MONDAY, 'shift_id' => $night->id]);
        $sessions = app(SessionResolver::class);
        $session = $this->sessions($employee, self::MONDAY)[0];

        $this->assertSame(self::TUESDAY.' 12:00:00', $sessions->checkOutClosesAt($session)->format('Y-m-d H:i:s'), 'Not checked in yet: the scheduled end plus six hours.');

        $row = new Attendance(['employee_id' => $employee->id, 'date' => self::MONDAY, 'check_in' => self::MONDAY.' 22:00:00', 'status' => AttendanceStatus::OnTime]);
        $row->fillSnapshot($sessions->snapshotFor($session))->save();
        $this->assertSame(self::TUESDAY.' 18:00:00', $sessions->checkOutClosesAt($row->fresh())->format('Y-m-d H:i:s'));

        // Checked in very early: the scheduled end plus six hours is the later moment.
        DB::table('attendances')->where('id', $row->id)->update(['check_in' => self::MONDAY.' 15:00:00']);
        $this->assertSame(self::TUESDAY.' 12:00:00', $sessions->checkOutClosesAt($row->fresh())->format('Y-m-d H:i:s'));
    }

    public function test_a_later_session_of_the_same_day_does_not_shorten_the_check_out_window(): void
    {
        $employee = $this->employee();
        $evening = Shift::create(['company_id' => $this->company->id, 'name' => 'Sore', 'code' => 'S', 'start_time' => '16:00', 'end_time' => '22:00']);
        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::MONDAY, 'sequence' => 1, 'shift_id' => $this->morning->id, 'company_location_id' => $this->luuca->id]);
        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::MONDAY, 'sequence' => 2, 'shift_id' => $evening->id, 'company_location_id' => $this->jchicken->id]);
        $sessions = app(SessionResolver::class);

        [$luuca, $jchicken] = $this->sessions($employee, self::MONDAY);

        $this->assertSame(self::MONDAY.' 15:00', $luuca->endAt->format('Y-m-d H:i'));
        $this->assertSame(self::MONDAY.' 23:59:59', $sessions->checkOutClosesAt($luuca)->format('Y-m-d H:i:s'), 'Still open after 16:00, when the JChicken session starts.');
        $this->assertSame(self::TUESDAY.' 04:00:00', $sessions->checkOutClosesAt($jchicken)->format('Y-m-d H:i:s'));
    }

    public function test_a_row_without_any_hours_closes_with_its_date(): void
    {
        $freelancer = $this->freelancer();
        $row = Attendance::create(['freelancer_id' => $freelancer->id, 'date' => self::MONDAY, 'check_in' => self::MONDAY.' 10:00:00', 'status' => AttendanceStatus::OnTime]);
        $sessions = app(SessionResolver::class);

        $this->assertNull($sessions->windowOf($row->fresh()));
        $this->assertSame(self::MONDAY.' 23:59:59', $sessions->checkOutClosesAt($row->fresh())->format('Y-m-d H:i:s'));
    }

    // ── Snapshot ─────────────────────────────────────────────────────────────

    public function test_the_snapshot_of_a_session_holds_the_columns_an_attendance_keeps(): void
    {
        $employee = $this->employee();
        $schedule = ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::MONDAY, 'sequence' => 2, 'shift_id' => $this->morning->id, 'company_location_id' => $this->luuca->id, 'section' => 'Kasir']);
        $sessions = app(SessionResolver::class);

        $snapshot = $sessions->snapshotFor($this->sessions($employee, self::MONDAY)[0]);

        $this->assertSame(Attendance::SNAPSHOT_COLUMNS, array_keys($snapshot));
        $this->assertSame($schedule->id, $snapshot['shift_schedule_id']);
        $this->assertSame(2, $snapshot['schedule_sequence']);
        $this->assertSame(self::MONDAY.' 08:00:00', $snapshot['sched_start_at']->format('Y-m-d H:i:s'));
        $this->assertSame(self::MONDAY.' 15:00:00', $snapshot['sched_end_at']->format('Y-m-d H:i:s'));
        $this->assertSame(5, $snapshot['sched_tolerance_minutes']);
        $this->assertSame(420, $snapshot['sched_scheduled_minutes']);
        $this->assertSame(400, $snapshot['sched_normal_minutes']);
        $this->assertSame(['Pagi', 'P', 'Luuca Ciledug', 'Kasir', 'schedule'], [$snapshot['sched_shift_name'], $snapshot['sched_shift_code'], $snapshot['sched_outlet_name'], $snapshot['sched_section'], $snapshot['sched_source']]);

        $none = $sessions->snapshotFor(null);
        $this->assertSame('none', $none['sched_source']);
        $this->assertNull($none['sched_start_at']);
        $this->assertNull($none['shift_schedule_id']);
        $this->assertSame(1, $none['schedule_sequence']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @return list<WorkSession>
     */
    private function sessions(Employee $employee, string $date): array
    {
        return app(SessionResolver::class)->sessionsFor($employee->fresh(), Carbon::parse($date));
    }

    /**
     * The session carries exactly what {@see WorkScheduleService::resolve()} answers for its segment.
     */
    private function assertSameAsResolved(Employee $employee, string $date, WorkSession $session): void
    {
        $resolved = app(WorkScheduleService::class)->resolve($employee->fresh(), Carbon::parse($date), $session->segment);
        $hours = $session->toWorkingHours();

        $this->assertEqualsCanonicalizing(array_keys($resolved), array_keys($hours));

        foreach (['is_day_off', 'late_tolerance_minutes', 'source', 'section', 'segment', 'segment_count', 'schedule_id', 'sequence', 'scheduled_minutes', 'normal_minutes'] as $key) {
            $this->assertSame($resolved[$key], $hours[$key], $key);
        }

        $this->assertSame($resolved['shift']?->id, $hours['shift']?->id);
        $this->assertSame($resolved['location']?->id, $hours['location']?->id);
        $this->assertSame(substr($resolved['start_time'], 0, 5), substr($hours['start_time'], 0, 5));
        $this->assertSame(substr($resolved['end_time'], 0, 5), substr($hours['end_time'], 0, 5));
        $this->assertTrue($resolved['start_at']->equalTo($hours['start_at']));
        $this->assertTrue($resolved['end_at']->equalTo($hours['end_at']));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function employee(array $attributes = []): Employee
    {
        $user = User::factory()->create(['is_active' => true]);

        return Employee::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_number' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Crew'])->id,
            'nip' => 'PF-'.$user->id,
            'full_name' => "Crew {$user->id}",
            'join_date' => '2026-01-01',
            'status' => 'permanent',
            ...$attributes,
        ]);
    }

    private function freelancer(): Freelancer
    {
        $user = User::factory()->create(['is_active' => true]);

        return Freelancer::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'full_name' => "Lepas {$user->id}",
            'start_date' => '2026-01-01',
            'status' => 'active',
        ]);
    }
}
