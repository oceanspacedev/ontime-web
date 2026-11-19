<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Http\Resources\Api\V1\AttendanceResource;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Services\Attendance\LedgerQuery;
use App\Services\AttendanceFigures;
use App\Services\AttendanceLedger;
use App\Services\DataAccessService;
use App\Services\RosterChangeContext;
use App\Services\SessionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The attendance ledger, one case per rule: what a worker-day is, what a session is worth in hours and
 * overtime, where a line belongs and who sees it.
 */
class AttendanceLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Division $luucaBrand;

    private Division $jchickenBrand;

    private CompanyLocation $luuca;

    private CompanyLocation $jchicken;

    private Shift $morning;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Tuesday 17 November 2026, noon.
        $this->travelTo(Carbon::parse('2026-11-17 12:00'));

        foreach (['Admin', 'Koordinator', 'Freelancer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->company = Company::create(['name' => 'PT Franchise', 'code' => 'PFC', 'is_active' => true]);
        $this->luucaBrand = Division::create(['company_id' => $this->company->id, 'name' => 'LUUCA', 'is_active' => true]);
        $this->jchickenBrand = Division::create(['company_id' => $this->company->id, 'name' => 'JCHICKEN', 'is_active' => true]);
        $this->luuca = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $this->luucaBrand->id, 'name' => 'Luuca Ciledug', 'latitude' => -6.2, 'longitude' => 106.7]);
        $this->jchicken = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $this->jchickenBrand->id, 'name' => 'JChicken Ciledug', 'latitude' => -6.2, 'longitude' => 106.7]);
        $this->morning = $this->shift('Pagi', '08:00', '17:00');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        $this->admin->grantDataAccess(DataScope::All);
    }

    // ── What a worker-day is ─────────────────────────────────────────────────

    public function test_each_worker_day_is_exactly_one_of_present_leave_absent_off_or_upcoming(): void
    {
        $ani = $this->freelancer('Ani');

        foreach (['2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12', '2026-11-15', '2026-11-17', '2026-11-18'] as $date) {
            $this->schedule($ani, $date);
        }

        ShiftSchedule::create(['freelancer_id' => $ani->id, 'date' => '2026-11-13', 'is_day_off' => true]);
        ShiftSchedule::create(['freelancer_id' => $ani->id, 'date' => '2026-11-14', 'is_day_off' => true]);

        $this->attend($ani, '2026-11-09', '08:00', '17:00');
        $this->attend($ani, '2026-11-10', '08:12', '17:00', ['status' => 'pending_approval', 'check_in_late_minutes' => 12]);
        $this->attend($ani, '2026-11-11', '08:00', null, ['status' => 'rejected']);
        $this->attend($ani, '2026-11-14', '09:00', '13:00');
        LeaveRequest::create(['freelancer_id' => $ani->id, 'leave_type' => 'annual', 'start_date' => '2026-11-15', 'end_date' => '2026-11-16', 'days_count' => 2, 'reason' => 'Keperluan keluarga', 'status' => 'approved']);

        $days = $this->ledger()->days(LedgerQuery::between($this->admin, '2026-11-09', '2026-11-19'))->collect()->keyBy('date');

        $this->assertSame(
            [
                '2026-11-09' => 'present',   // worked
                '2026-11-10' => 'present',   // waiting for approval counts as worked
                '2026-11-11' => 'absent',    // a rejected check-in is not work
                '2026-11-12' => 'absent',    // rostered, nothing recorded
                '2026-11-13' => 'off',       // day off
                '2026-11-14' => 'present',   // a day off that was worked is present only
                '2026-11-15' => 'leave',     // leave beats absent on a rostered day
                '2026-11-16' => 'leave',     // leave on a day without a roster
                '2026-11-17' => 'upcoming',  // today is never absent
                '2026-11-18' => 'upcoming',
            ],
            $days->map(fn (array $day): string => $day['status'])->all(),
            'A day without schedule or record (the 19th) is not in the ledger at all.',
        );

        $this->assertSame(['pending', true, 12], [$days['2026-11-10']['lines'][0]['status'], $days['2026-11-10']['is_pending'], $days['2026-11-10']['lines'][0]['late_minutes']]);
        $this->assertSame(['rejected', false, 0], [$days['2026-11-11']['lines'][0]['status'], $days['2026-11-11']['lines'][0]['is_worked'], $days['2026-11-11']['lines'][0]['worked_minutes']]);
        $this->assertSame(['unscheduled', 240], [$days['2026-11-14']['lines'][0]['kind'], $days['2026-11-14']['lines'][0]['worked_minutes']]);
        $this->assertSame(['work', 'leave'], [$days['2026-11-15']['lines'][0]['kind'], $days['2026-11-15']['lines'][0]['status']]);
        $this->assertSame(['leave', 'leave'], [$days['2026-11-16']['lines'][0]['kind'], $days['2026-11-16']['lines'][0]['status']]);
        $this->assertSame(['off', 'off'], [$days['2026-11-13']['lines'][0]['kind'], $days['2026-11-13']['lines'][0]['status']]);

        $summary = $this->ledger()->summary(LedgerQuery::between($this->admin, '2026-11-09', '2026-11-19'));

        $this->assertEquals(
            ['present_days' => 3, 'pending_days' => 1, 'late_days' => 1, 'late_minutes' => 12, 'absent_days' => 2, 'off_days' => 1, 'leave_days' => 2, 'scheduled_days' => 7, 'scheduled_days_to_date' => 5],
            array_intersect_key($summary, array_flip(['present_days', 'pending_days', 'late_days', 'late_minutes', 'absent_days', 'off_days', 'leave_days', 'scheduled_days', 'scheduled_days_to_date'])),
        );
    }

    public function test_days_outside_the_employment_window_are_never_absent(): void
    {
        $ani = $this->freelancer('Ani', ['start_date' => '2026-11-10', 'end_date' => '2026-11-11']);

        foreach (['2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12'] as $date) {
            $this->schedule($ani, $date);
        }

        $days = $this->ledger()->days(LedgerQuery::between($this->admin, '2026-11-09', '2026-11-12'))->collect()->keyBy('date');

        $this->assertSame(
            ['2026-11-09' => 'not_scheduled', '2026-11-10' => 'absent', '2026-11-11' => 'absent', '2026-11-12' => 'not_scheduled'],
            $days->map(fn (array $day): string => $day['status'])->all(),
        );
        $this->assertSame(2, $this->ledger()->summary(LedgerQuery::between($this->admin, '2026-11-09', '2026-11-12'))['absent_days']);
    }

    public function test_a_split_shift_is_two_lines_and_one_present_day(): void
    {
        $split = Shift::create(['company_id' => $this->company->id, 'name' => 'Split', 'start_time' => '07:45', 'end_time' => '13:00', 'second_start_time' => '17:45', 'second_end_time' => '22:00']);
        $ani = $this->freelancer('Ani');
        $this->schedule($ani, '2026-11-09', $split);
        $this->attend($ani, '2026-11-09', '07:45', '13:00');
        $this->attend($ani, '2026-11-09', '17:50', '22:00', ['segment' => 2, 'status' => 'late', 'late_minutes' => 5]);

        $query = LedgerQuery::between($this->admin, '2026-11-09', '2026-11-09');
        $lines = $this->ledger()->lines($query)->all();

        $this->assertSame([[1, 1, 315, 315], [1, 2, 255, 250]], array_map(fn (array $line): array => [$line['sequence'], $line['segment'], $line['scheduled_minutes'], $line['worked_minutes']], $lines));
        $this->assertSame(['2026-11-09 07:45:00', '2026-11-09 13:00:00', '2026-11-09 17:45:00', '2026-11-09 22:00:00'], [$lines[0]['scheduled_start'], $lines[0]['scheduled_end'], $lines[1]['scheduled_start'], $lines[1]['scheduled_end']]);

        $summary = $this->ledger()->summary($query);

        $this->assertSame(
            ['present_days' => 1, 'late_days' => 1, 'late_minutes' => 5, 'sessions_scheduled' => 2, 'sessions_worked' => 2, 'scheduled_minutes' => 570, 'worked_minutes' => 565],
            array_intersect_key($summary, array_flip(['present_days', 'late_days', 'late_minutes', 'sessions_scheduled', 'sessions_worked', 'scheduled_minutes', 'worked_minutes'])),
        );
    }

    public function test_a_day_at_two_outlets_counts_once_in_each_outlet_row_and_once_in_the_total(): void
    {
        $evening = $this->shift('Sore', '18:00', '22:00');
        $ani = $this->freelancer('Ani');
        $first = $this->schedule($ani, '2026-11-09');
        $second = ShiftSchedule::create(['freelancer_id' => $ani->id, 'date' => '2026-11-09', 'sequence' => 2, 'shift_id' => $evening->id, 'company_location_id' => $this->jchicken->id]);
        $this->attend($ani, '2026-11-09', '08:00', '17:00', ['shift_schedule_id' => $first->id, 'company_location_id' => $this->luuca->id]);
        $this->attend($ani, '2026-11-09', '18:00', '22:00', ['shift_schedule_id' => $second->id, 'schedule_sequence' => 2, 'company_location_id' => $this->jchicken->id]);
        // The next day both sessions were missed.
        $this->schedule($ani, '2026-11-10');
        ShiftSchedule::create(['freelancer_id' => $ani->id, 'date' => '2026-11-10', 'sequence' => 2, 'shift_id' => $evening->id, 'company_location_id' => $this->jchicken->id]);

        $report = $this->ledger()->outletRows(LedgerQuery::between($this->admin, '2026-11-09', '2026-11-10'));

        $this->assertSame(
            [['JChicken Ciledug', 1, 1, 240], ['Luuca Ciledug', 1, 1, 540]],
            array_map(fn (array $row): array => [$row['outlet']['name'], $row['present_days'], $row['absent_days'], $row['worked_minutes']], $report['rows']),
        );
        $this->assertSame([1, 1, 2, 780, 4], [$report['total']['present_days'], $report['total']['absent_days'], $report['total']['scheduled_days'], $report['total']['worked_minutes'], $report['total']['sessions_scheduled']]);
    }

    public function test_a_roster_company_does_not_expect_blank_days_except_of_a_fixed_hours_worker(): void
    {
        $this->company->policy()->update(['uses_shift_roster' => true]);
        $office = $this->shift('Kantor', '09:00', '17:00');
        $rostered = $this->freelancer('Berjadwal', ['shift_id' => $office->id]);
        $this->freelancer('Tanpa Jadwal');
        $this->freelancer('Jam Tetap', ['shift_id' => $office->id]);
        $this->schedule($rostered, '2026-11-10');
        // Neither a day off nor a shift: no row in a roster company.
        ShiftSchedule::create(['freelancer_id' => $rostered->id, 'date' => '2026-11-11', 'shift_id' => null]);

        $absent = $this->ledger()->days(LedgerQuery::between($this->admin, '2026-11-02', '2026-11-15'))
            ->collect()
            ->filter(fn (array $day): bool => $day['status'] === 'absent')
            ->groupBy(fn (array $day): string => $day['worker']['name'])
            ->map(fn ($days): array => $days->pluck('date')->all())
            ->all();

        $this->assertSame(
            ['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06', '2026-11-09', '2026-11-10'],
            $absent['Berjadwal'],
            'Fixed hours until the date of the first row (the Monday before it included), then only the rostered day; blank days are not scheduled.',
        );
        $this->assertArrayNotHasKey('Tanpa Jadwal', $absent);
        $this->assertCount(10, $absent['Jam Tetap'], 'A default shift and never a dated schedule: Monday to Friday is expected.');
    }

    // ── Hours ────────────────────────────────────────────────────────────────

    public function test_a_forgotten_check_out_is_present_with_no_hours_and_a_flag(): void
    {
        $ani = $this->freelancer('Ani');
        $this->schedule($ani, '2026-11-16');
        $this->schedule($ani, '2026-11-17');
        $this->attend($ani, '2026-11-16', '08:00', null);
        $open = $this->attend($ani, '2026-11-17', '08:00', null);

        $days = $this->ledger()->days(LedgerQuery::between($this->admin, '2026-11-16', '2026-11-17'))->collect()->keyBy('date');

        $this->assertSame(['present', true, true, 0], [$days['2026-11-16']['status'], $days['2026-11-16']['lines'][0]['is_missing_check_out'], $days['2026-11-16']['lines'][0]['is_incomplete'], $days['2026-11-16']['lines'][0]['worked_minutes']]);
        $this->assertSame(['present', false, false], [$days['2026-11-17']['status'], $days['2026-11-17']['lines'][0]['is_missing_check_out'], $days['2026-11-17']['lines'][0]['is_incomplete']], 'A row still open today can be closed yet.');
        $this->assertFalse(AttendanceFigures::forAttendance($open)['is_missing_check_out']);

        // A check-in to a later session makes the open row a missing check-out at once.
        $evening = $this->shift('Sore', '18:00', '22:00');
        $later = ShiftSchedule::create(['freelancer_id' => $ani->id, 'date' => '2026-11-17', 'sequence' => 2, 'shift_id' => $evening->id, 'company_location_id' => $this->jchicken->id]);
        $this->attend($ani, '2026-11-17', '11:30', null, ['shift_schedule_id' => $later->id, 'schedule_sequence' => 2]);

        $lines = $this->ledger()->lines(LedgerQuery::between($this->admin, '2026-11-17', '2026-11-17'))->all();

        $this->assertSame([[1, true], [2, false]], array_map(fn (array $line): array => [$line['sequence'], $line['is_missing_check_out']], $lines));
        $this->assertTrue(AttendanceFigures::forAttendance($open->fresh())['is_missing_check_out']);

        $summary = $this->ledger()->summary(LedgerQuery::between($this->admin, '2026-11-16', '2026-11-17'));
        $this->assertSame([2, 2, 0], [$summary['present_days'], $summary['missing_check_out_days'], $summary['worked_minutes']]);
    }

    public function test_a_day_worked_for_less_than_half_its_hours_is_incomplete_but_present(): void
    {
        $ani = $this->freelancer('Ani');
        $this->schedule($ani, '2026-11-09');
        $this->schedule($ani, '2026-11-10');
        $this->attend($ani, '2026-11-09', '08:00', '12:29');
        $this->attend($ani, '2026-11-10', '08:00', '12:30');

        $summary = $this->ledger()->summary(LedgerQuery::between($this->admin, '2026-11-09', '2026-11-10'));

        $this->assertSame([2, 1, 0], [$summary['present_days'], $summary['incomplete_days'], $summary['missing_check_out_days']], '269 of 540 minutes is incomplete, 270 is not.');
    }

    /**
     * @return array<string, array{0: list<array{0: int, 1: string, 2: string}>, 1: int|null, 2: int}>
     */
    public static function automaticOvertimeCases(): array
    {
        return [
            'whole long shift' => [[[1, '08:40', '22:35']], 480, 345],
            'left at 21:00' => [[[1, '08:40', '21:00']], 480, 255],
            'arrived two hours late' => [[[1, '10:45', '22:30']], 480, 225],
            'left at 19:00' => [[[1, '08:45', '19:00']], 480, 135],
            'worked only the normal hours' => [[[1, '08:45', '16:45']], 480, 0],
            'no normal hours on the shift' => [[[1, '08:40', '22:35']], null, 0],
        ];
    }

    /**
     * @param  list<array{0: int, 1: string, 2: string}>  $punches
     */
    #[DataProvider('automaticOvertimeCases')]
    public function test_time_inside_a_long_shift_above_its_normal_hours_is_automatic_overtime(array $punches, ?int $normalMinutes, int $expected): void
    {
        $long = $this->shift('Long Shift', '08:45', '22:30');
        $ani = $this->freelancer('Ani');
        $row = $this->schedule($ani, '2026-11-09', $long);

        foreach ($punches as [$segment, $in, $out]) {
            $attendance = $this->attend($ani, '2026-11-09', $in, $out, [
                'segment' => $segment, 'shift_id' => $long->id, 'shift_schedule_id' => $row->id,
                'sched_start_at' => '2026-11-09 08:45:00', 'sched_end_at' => '2026-11-09 22:30:00',
                'sched_scheduled_minutes' => 825, 'sched_normal_minutes' => $normalMinutes, 'sched_shift_name' => 'Long Shift', 'sched_source' => 'schedule',
            ]);
        }

        $summary = $this->ledger()->summary(LedgerQuery::between($this->admin, '2026-11-09', '2026-11-09'));

        $this->assertSame($expected, $summary['auto_overtime_minutes']);
        $this->assertSame($expected, $summary['overtime_minutes']);
        $this->assertSame([825, $normalMinutes ?? 825], [$summary['scheduled_minutes'], $summary['normal_minutes']]);
        $this->assertSame($expected, AttendanceFigures::forAttendance($attendance)['overtime_minutes'], 'The attendance row shows the same number.');
    }

    public function test_overtime_of_a_split_shift_belongs_to_the_whole_schedule_and_is_shown_on_its_last_worked_part(): void
    {
        $split = Shift::create(['company_id' => $this->company->id, 'name' => 'Split', 'start_time' => '07:45', 'end_time' => '13:00', 'second_start_time' => '17:45', 'second_end_time' => '22:00']);
        $ani = $this->freelancer('Ani');
        $snapshot = fn (int $segment, ShiftSchedule $row): array => [
            'segment' => $segment, 'shift_id' => $split->id, 'shift_schedule_id' => $row->id,
            'sched_start_at' => $row->date->toDateString().($segment === 1 ? ' 07:45:00' : ' 17:45:00'), 'sched_end_at' => $row->date->toDateString().($segment === 1 ? ' 13:00:00' : ' 22:00:00'),
            'sched_scheduled_minutes' => 570, 'sched_normal_minutes' => 480, 'sched_shift_name' => 'Split', 'sched_source' => 'schedule',
        ];

        // Monday: both parts, 570 minutes inside the windows. Tuesday: only the second part, 255 minutes.
        $monday = $this->schedule($ani, '2026-11-09', $split);
        $first = $this->attend($ani, '2026-11-09', '07:45', '13:00', $snapshot(1, $monday));
        $second = $this->attend($ani, '2026-11-09', '17:45', '22:00', $snapshot(2, $monday));
        $tuesday = $this->schedule($ani, '2026-11-10', $split);
        $this->attend($ani, '2026-11-10', '17:45', '22:00', $snapshot(2, $tuesday));

        $lines = $this->ledger()->lines(LedgerQuery::between($this->admin, '2026-11-09', '2026-11-10'))->all();

        $this->assertSame(
            [['2026-11-09', 1, 0, 315], ['2026-11-09', 2, 90, 165], ['2026-11-10', 1, 0, 315], ['2026-11-10', 2, 0, 165]],
            array_map(fn (array $line): array => [$line['date'], $line['segment'], $line['auto_overtime_minutes'], $line['normal_minutes']], $lines),
            'Working only part 2 stays below the normal hours: no overtime.',
        );

        $resources = AttendanceResource::collection(Attendance::query()->whereKey([$first->id, $second->id])->with(Attendance::workerEagerLoads())->orderBy('segment')->get())->resolve();
        $this->assertSame([0, 90], array_column($resources, 'overtime_minutes'));
        $this->assertSame([315, 165], array_column($resources, 'normal_minutes'), 'Each part shows its own share, as its ledger line does.');
    }

    public function test_overtime_requests_count_in_full_until_the_company_nets_them_against_the_shift(): void
    {
        $long = $this->shift('Long Shift', '08:45', '22:30');
        $ani = $this->freelancer('Ani');

        foreach (['2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12'] as $date) {
            $this->schedule($ani, $date, $long);
            $this->attend($ani, $date, '08:45', '22:30');
        }

        $this->overtime($ani, '2026-11-09', '18:00', '22:30', 270);                // inside the shift
        $this->overtime($ani, '2026-11-10', '22:00', '23:30', 90);                 // half an hour inside
        $this->overtime($ani, '2026-11-11', '22:30', '23:30', 60, actual: 45);     // after the shift, 45 minutes worked
        $this->overtime($ani, '2026-11-12', '22:00', '23:30', 90, actual: 60);     // 22:00 - 23:00 worked, half inside

        $minutes = fn (): array => $this->ledger()->days(LedgerQuery::between($this->admin, '2026-11-09', '2026-11-12'))->collect()->pluck('request_overtime_minutes', 'date')->all();

        $this->assertSame(['2026-11-09' => 270, '2026-11-10' => 90, '2026-11-11' => 45, '2026-11-12' => 60], $minutes(), 'Without a netting date every request counts in full, with its actual duration.');

        $this->company->policy()->update(['overtime_netting_from' => '2026-11-10']);

        $this->assertSame(['2026-11-09' => 270, '2026-11-10' => 60, '2026-11-11' => 45, '2026-11-12' => 30], $minutes(), 'From the netting date on, time inside the shift is not counted twice; earlier dates stay.');

        $this->company->policy()->update(['overtime_netting_from' => '2026-11-01']);

        $summary = $this->ledger()->summary(LedgerQuery::between($this->admin, '2026-11-09', '2026-11-12'));
        $this->assertSame([0 + 60 + 45 + 30, 4, 0], [$summary['request_overtime_minutes'], $summary['overtime_count'], $summary['overtime_unattended_count']]);
    }

    public function test_overtime_on_a_day_without_work_is_flagged(): void
    {
        $ani = $this->freelancer('Ani');
        $this->overtime($ani, '2026-11-09', '18:00', '20:00', 120);

        $report = $this->ledger()->outletRows(LedgerQuery::between($this->admin, '2026-11-09', '2026-11-09'));

        $this->assertSame([1, 120, 1], [$report['total']['overtime_count'], $report['total']['request_overtime_minutes'], $report['total']['overtime_unattended_count']]);
    }

    // ── Where a line belongs and who sees it ─────────────────────────────────

    public function test_the_home_outlet_of_a_month_is_the_same_for_a_week_and_for_the_month(): void
    {
        $ani = $this->freelancer('Ani');
        $this->schedule($ani, '2026-11-02');
        $this->schedule($ani, '2026-11-03');
        ShiftSchedule::create(['freelancer_id' => $ani->id, 'date' => '2026-11-04', 'is_day_off' => true]);

        foreach (['2026-11-09', '2026-11-10', '2026-11-11'] as $date) {
            $this->schedule($ani, $date, outlet: $this->jchicken);
        }

        $outletOfTheDayOff = fn (string $from, string $to): ?string => $this->ledger()->lines(LedgerQuery::between($this->admin, $from, $to))
            ->firstWhere('kind', 'off')['outlet']['name'] ?? null;

        // The week holds only Luuca days, but the month is mostly JChicken.
        $this->assertSame('JChicken Ciledug', $outletOfTheDayOff('2026-11-02', '2026-11-08'));
        $this->assertSame('JChicken Ciledug', $outletOfTheDayOff('2026-11-01', '2026-11-30'));
        $this->assertSame('JChicken Ciledug', $outletOfTheDayOff('2026-11-04', '2026-11-04'));
    }

    public function test_a_lent_worker_is_seen_by_the_host_and_the_home_coordinator_with_the_same_day(): void
    {
        $host = $this->coordinator($this->jchickenBrand);
        $home = $this->coordinator($this->luucaBrand);
        $stranger = $this->coordinator(Division::create(['company_id' => $this->company->id, 'name' => 'MOMOYO', 'is_active' => true]));
        // Ani belongs to LUUCA and is lent to JChicken on the 10th.
        $ani = $this->freelancer('Ani');
        $this->schedule($ani, '2026-11-09');
        $this->schedule($ani, '2026-11-10', outlet: $this->jchicken);
        $this->attend($ani, '2026-11-09', '08:00', '17:00', ['company_location_id' => $this->luuca->id]);
        $this->attend($ani, '2026-11-10', '08:10', '16:00', ['status' => 'late', 'late_minutes' => 10, 'company_location_id' => $this->jchicken->id]);

        $dayOf = fn (User $viewer, string $date): ?array => $this->ledger()->days(LedgerQuery::between($viewer, '2026-11-09', '2026-11-10'))->firstWhere('date', $date);

        $this->assertNotNull($dayOf($host, '2026-11-10'));
        $this->assertEquals($dayOf($home, '2026-11-10'), $dayOf($host, '2026-11-10'), 'The same day, whoever looks at it.');
        $this->assertSame([1, 1, 10, 470], array_values(array_intersect_key($dayOf($host, '2026-11-10')['totals'], array_flip(['present_days', 'late_days', 'late_minutes', 'worked_minutes']))));

        $this->assertNull($dayOf($host, '2026-11-09'), 'The host sees the lent day only.');
        $this->assertNotNull($dayOf($home, '2026-11-09'));
        $this->assertSame([], $this->ledger()->days(LedgerQuery::between($stranger, '2026-11-09', '2026-11-10'))->all());

        $this->assertSame([1, 2], [
            $this->ledger()->summary(LedgerQuery::between($host, '2026-11-09', '2026-11-10'))['present_days'],
            $this->ledger()->summary(LedgerQuery::between($home, '2026-11-09', '2026-11-10'))['present_days'],
        ]);
    }

    public function test_planned_sessions_are_the_ones_the_session_resolver_gives(): void
    {
        $split = Shift::create(['company_id' => $this->company->id, 'name' => 'Split', 'start_time' => '07:45', 'end_time' => '13:00', 'second_start_time' => '17:45', 'second_end_time' => '22:00']);
        $night = $this->shift('Malam', '22:00', '06:00');
        $rostered = $this->freelancer('Berjadwal');
        $this->schedule($rostered, '2026-11-18', $split);
        $this->schedule($rostered, '2026-11-19', $night);
        ShiftSchedule::create(['freelancer_id' => $rostered->id, 'date' => '2026-11-19', 'sequence' => 2, 'shift_id' => $this->morning->id, 'company_location_id' => $this->jchicken->id]);
        $office = $this->employee('Kantor', ['shift_id' => $this->shift('Kantor', '09:00', '17:00')->id]);
        $policy = $this->employee('Jam Perusahaan');

        $lines = $this->ledger()->lines(LedgerQuery::between($this->admin, '2026-11-18', '2026-11-19'))->collect()->groupBy(fn (array $line): string => $line['worker']['name'].'|'.$line['date']);
        $resolver = app(SessionResolver::class);

        foreach ([[$rostered, 'Berjadwal'], [$office, 'Kantor'], [$policy, 'Jam Perusahaan']] as [$worker, $name]) {
            foreach (['2026-11-18', '2026-11-19'] as $date) {
                $sessions = collect($resolver->sessionsFor($worker->fresh(), Carbon::parse($date)))
                    ->map(fn ($session): array => [$session->sequence, $session->segment, $session->startAt->format('Y-m-d H:i:s'), $session->endAt->format('Y-m-d H:i:s'), $session->source])
                    ->sort()->values()->all();
                $planned = $lines["{$name}|{$date}"]
                    ->map(fn (array $line): array => [$line['sequence'], $line['segment'], $line['scheduled_start'], $line['scheduled_end'], $line['source']])
                    ->sort()->values()->all();

                $this->assertSame($sessions, $planned, "{$name} on {$date}");
            }
        }
    }

    /**
     * Whether a worker-day is expected is answered the same by the session resolver (the app: reminders,
     * check-in, today) and by the ledger (reports), for every kind of worker: rostered, default shift,
     * company hours, a freelancer without a default shift on a legacy placement row, a date with both a
     * day off and a placement row, and a plain day off; in a company without shift rosters and in one with
     * them. A freelancer with no schedule and no default shift ever is the one known exception in a company
     * without rosters: the reports keep counting the company's work week for them (signed off in the report
     * tests) while the app offers only the cover flow; in a roster company both say not scheduled.
     */
    public function test_expected_days_are_the_ones_the_session_resolver_expects(): void
    {
        $rostered = $this->freelancer('Berjadwal');
        $this->schedule($rostered, '2026-11-18');
        $office = $this->employee('Kantor', ['shift_id' => $this->shift('Kantor', '09:00', '17:00')->id]);
        $policy = $this->employee('Jam Perusahaan');
        $placed = $this->freelancer('Ditempatkan');
        ShiftSchedule::create(['freelancer_id' => $placed->id, 'date' => '2026-11-18', 'is_day_off' => false, 'company_location_id' => $this->luuca->id]);
        $mixed = $this->employee('Libur dan Outlet');
        ShiftSchedule::create(['employee_id' => $mixed->id, 'date' => '2026-11-18', 'sequence' => 1, 'is_day_off' => true]);
        ShiftSchedule::create(['employee_id' => $mixed->id, 'date' => '2026-11-18', 'sequence' => 2, 'is_day_off' => false, 'company_location_id' => $this->luuca->id]);
        $nobody = $this->freelancer('Tanpa Jadwal');
        $off = $this->freelancer('Libur');
        ShiftSchedule::create(['freelancer_id' => $off->id, 'date' => '2026-11-18', 'is_day_off' => true]);
        // Fixed hours end from the date of the first roster row, never before it: a Thursday day off leaves Wednesday on fixed hours.
        $fromThursday = $this->employee('Mulai Kamis', ['shift_id' => $office->shift_id]);
        ShiftSchedule::create(['employee_id' => $fromThursday->id, 'date' => '2026-11-19', 'is_day_off' => true]);
        // An approved cover's own row never ends them: Thursday after a Wednesday cover is still on fixed hours.
        $coverOnly = $this->employee('Hanya Cover', ['shift_id' => $office->shift_id]);
        $coverRow = RosterChangeContext::run('cover', null, fn (): ShiftSchedule => ShiftSchedule::create(['employee_id' => $coverOnly->id, 'date' => '2026-11-18', 'shift_id' => $this->morning->id, 'company_location_id' => $this->luuca->id]));
        Attendance::create([
            'employee_id' => $coverOnly->id, 'date' => '2026-11-18', 'check_in' => '2026-11-18 08:00:00', 'check_out' => '2026-11-18 16:00:00', 'status' => 'on_time',
            'shift_id' => $this->morning->id, 'shift_schedule_id' => $coverRow->id, 'company_location_id' => $this->luuca->id, 'is_off_schedule' => true,
        ]);
        $workers = ['Berjadwal' => $rostered, 'Kantor' => $office, 'Jam Perusahaan' => $policy, 'Ditempatkan' => $placed, 'Libur dan Outlet' => $mixed, 'Tanpa Jadwal' => $nobody, 'Libur' => $off, 'Mulai Kamis' => $fromThursday, 'Hanya Cover' => $coverOnly];

        $agreement = function (): array {
            $days = $this->ledger()->days(LedgerQuery::between($this->admin, '2026-11-18', '2026-11-19'))->collect()->keyBy(fn (array $day): string => $day['worker']['name'].'|'.$day['date']);
            $answers = [];

            foreach (func_get_arg(0) as $name => $worker) {
                foreach (['2026-11-18', '2026-11-19'] as $date) {
                    $sessions = app(SessionResolver::class)->sessionsFor($worker->fresh(), Carbon::parse($date));
                    $answers["{$name}|{$date}"] = [
                        'resolver' => collect($sessions)->contains(fn ($session): bool => $session->isExpected),
                        'ledger' => (bool) ($days["{$name}|{$date}"]['expected'] ?? false),
                    ];
                }
            }

            return $answers;
        };

        $withoutRoster = $agreement($workers);

        foreach ($withoutRoster as $key => $answer) {
            if (str_starts_with($key, 'Tanpa Jadwal|')) {
                continue;
            }

            $this->assertSame($answer['ledger'], $answer['resolver'], "Without rosters: {$key}");
        }

        $this->assertTrue($withoutRoster['Ditempatkan|2026-11-18']['resolver'], 'A placement row plans the company hours for a freelancer too.');
        $this->assertTrue($withoutRoster['Libur dan Outlet|2026-11-18']['resolver'], 'A placement row outweighs a day off.');
        $this->assertFalse($withoutRoster['Libur|2026-11-18']['resolver']);
        $this->assertFalse($withoutRoster['Tanpa Jadwal|2026-11-18']['resolver']);

        $this->company->policy()->update(['uses_shift_roster' => true]);
        app()->forgetScopedInstances();
        $withRoster = $agreement($workers);

        foreach ($withRoster as $key => $answer) {
            $this->assertSame($answer['ledger'], $answer['resolver'], "With rosters: {$key}");
        }

        $this->assertFalse($withRoster['Ditempatkan|2026-11-18']['resolver'], 'A row without a shift is no row in a roster company.');
        $this->assertFalse($withRoster['Libur dan Outlet|2026-11-18']['resolver']);
        $this->assertTrue($withRoster['Berjadwal|2026-11-18']['resolver']);
        $this->assertTrue($withRoster['Mulai Kamis|2026-11-18']['resolver'], 'The day before the first roster row keeps its fixed hours.');
        $this->assertFalse($withRoster['Mulai Kamis|2026-11-19']['resolver']);
        $this->assertTrue($withRoster['Hanya Cover|2026-11-19']['resolver'], 'A cover alone never ends the fixed hours.');

        foreach (['Mulai Kamis|2026-11-18', 'Hanya Cover|2026-11-19'] as $key) {
            $this->assertTrue($withoutRoster[$key]['resolver'], "Without rosters too: {$key}");
        }

        $this->assertFalse($withoutRoster['Mulai Kamis|2026-11-19']['resolver']);
    }

    // ── Cost ─────────────────────────────────────────────────────────────────

    public function test_a_month_of_two_hundred_workers_is_read_in_bulk_without_models(): void
    {
        $this->seedWorkers(20);
        $few = $this->countQueries(fn () => $this->ledger()->summary(LedgerQuery::month($this->admin, Carbon::parse('2026-11-01'))));

        $this->seedWorkers(180);
        $hydrated = 0;

        foreach ([Attendance::class, ShiftSchedule::class, Freelancer::class] as $model) {
            Event::listen("eloquent.retrieved: {$model}", function () use (&$hydrated): void {
                $hydrated++;
            });
        }

        $summary = [];
        $many = $this->countQueries(function () use (&$summary): void {
            $summary = $this->ledger()->summary(LedgerQuery::month($this->admin, Carbon::parse('2026-11-01')));
        });

        $this->assertSame([200 * 9, 200 * 9, 200 * 9 * 540], [$summary['scheduled_days'], $summary['present_days'], $summary['worked_minutes']]);
        $this->assertSame($few, $many, 'The number of queries does not grow with the number of workers.');
        $this->assertLessThanOrEqual(20, $many);
        $this->assertSame(0, $hydrated, 'Schedules, attendance and workers are read as plain rows.');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function ledger(): AttendanceLedger
    {
        return app(AttendanceLedger::class);
    }

    private function countQueries(\Closure $read): int
    {
        DataAccessService::flush();
        $this->admin = User::query()->findOrFail($this->admin->id);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $read();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function seedWorkers(int $count): void
    {
        $now = now()->toDateTimeString();
        // Nine days, so two hundred workers stay inside one read of schedules and one of attendance.
        $dates = ['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06', '2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12'];

        foreach (range(1, $count) as $ignored) {
            $worker = $this->freelancer('Pekerja '.(Freelancer::query()->count() + 1));
            $schedules = [];
            $attendances = [];

            foreach ($dates as $date) {
                $schedules[] = ['freelancer_id' => $worker->id, 'date' => $date, 'sequence' => 1, 'shift_id' => $this->morning->id, 'company_location_id' => $this->luuca->id, 'is_day_off' => false, 'created_at' => $now, 'updated_at' => $now];
                $attendances[] = ['freelancer_id' => $worker->id, 'date' => $date, 'segment' => 1, 'schedule_sequence' => 1, 'check_in' => "{$date} 08:00:00", 'check_out' => "{$date} 17:00:00", 'status' => 'on_time', 'late_minutes' => 0, 'company_location_id' => $this->luuca->id, 'created_at' => $now, 'updated_at' => $now];
            }

            DB::table('shift_schedules')->insert($schedules);
            DB::table('attendances')->insert($attendances);
        }
    }

    private function shift(string $name, string $start, string $end): Shift
    {
        return Shift::create(['company_id' => $this->company->id, 'name' => $name, 'start_time' => $start, 'end_time' => $end]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function freelancer(string $name, array $attributes = []): Freelancer
    {
        return Freelancer::create($attributes + ['company_id' => $this->company->id, 'division_id' => $this->luucaBrand->id, 'full_name' => $name, 'status' => 'active']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function employee(string $name, array $attributes = []): Employee
    {
        return Employee::create($attributes + [
            'company_id' => $this->company->id,
            'division_id' => $this->luucaBrand->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_number' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Staff'])->id,
            'nip' => 'EMP'.str_pad((string) (Employee::query()->count() + 1), 3, '0', STR_PAD_LEFT),
            'full_name' => $name,
            'status' => 'active',
            'join_date' => '2024-01-08',
        ]);
    }

    private function coordinator(Division $brand): User
    {
        $coordinator = User::factory()->create();
        $coordinator->assignRole('Koordinator');
        $coordinator->grantDataAccess(DataScope::Divisions, divisionIds: [$brand->id]);

        return $coordinator;
    }

    private function schedule(Freelancer $worker, string $date, ?Shift $shift = null, ?CompanyLocation $outlet = null): ShiftSchedule
    {
        return ShiftSchedule::create(['freelancer_id' => $worker->id, 'date' => $date, 'shift_id' => ($shift ?? $this->morning)->id, 'company_location_id' => ($outlet ?? $this->luuca)->id]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function attend(Freelancer $worker, string $date, string $in, ?string $out, array $attributes = []): Attendance
    {
        return Attendance::create($attributes + [
            'freelancer_id' => $worker->id,
            'date' => $date,
            'check_in' => "{$date} {$in}:00",
            'check_out' => $out ? "{$date} {$out}:00" : null,
            'status' => 'on_time',
            'late_minutes' => 0,
        ]);
    }

    private function overtime(Freelancer $worker, string $date, string $from, string $until, int $minutes, ?int $actual = null): OvertimeRequest
    {
        return OvertimeRequest::create([
            'freelancer_id' => $worker->id,
            'date' => $date,
            'start_time' => $from,
            'end_time' => $until,
            'duration_minutes' => $minutes,
            'actual_duration_minutes' => $actual,
            'reason' => 'Stok opname outlet',
            'status' => 'approved',
        ]);
    }
}
