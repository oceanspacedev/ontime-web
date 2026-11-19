<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\ShiftScheduleChange;
use App\Models\User;
use App\Services\AttendanceSnapshotBackfill;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * The schema for several schedules per day and the locked attendance snapshot, and the backfill that
 * gives attendance recorded before it a snapshot without changing any judged result.
 */
class ScheduleSchemaAndBackfillTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-11-10';

    /**
     * Migrations of this package, in order: shifts, company policies, roster sequence, attendance
     * snapshot, backfill, change log, corrections, leave.
     */
    private const MIGRATION_COUNT = 8;

    /**
     * Migrations added after this package, rolled back first (WP12: the check-in results kept aside
     * while a check-out waits for approval; WPFX: the state a row had before a leave turned it into one; the
     * colleague's schedule row a cover replaces; the change-log entries of cover rows approved before the
     * change log existed).
     */
    private const LATER_MIGRATION_COUNT = 4;

    private Company $company;

    private Division $division;

    private Shift $morning;

    private Shift $split;

    private Shift $night;

    private CompanyLocation $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->company = Company::create(['name' => 'PT Franchise', 'code' => 'PFC', 'is_active' => true]);
        $this->company->policy->update(['work_start_time' => '09:00', 'work_end_time' => '18:00', 'late_tolerance_minutes' => 10]);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'MOMOYO', 'is_active' => true]);
        $this->morning = Shift::create(['company_id' => $this->company->id, 'name' => 'Pagi', 'code' => 'P', 'start_time' => '08:00', 'end_time' => '16:00', 'late_tolerance_minutes' => 5]);
        $this->split = Shift::create(['company_id' => $this->company->id, 'name' => 'Split', 'code' => 'SP', 'start_time' => '07:45', 'end_time' => '13:00', 'second_start_time' => '17:45', 'second_end_time' => '22:00']);
        $this->night = Shift::create(['company_id' => $this->company->id, 'name' => 'Malam', 'code' => 'M', 'start_time' => '22:00', 'end_time' => '06:00']);
        $this->outlet = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $this->division->id, 'name' => 'Momoyo Ciledug', 'latitude' => -6.2297, 'longitude' => 106.7112]);
    }

    // ── Backfill ─────────────────────────────────────────────────────────────

    public function test_attendance_on_its_single_rostered_shift_is_linked_and_gets_that_shifts_hours(): void
    {
        $employee = $this->employee();
        $schedule = ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::DATE, 'shift_id' => $this->morning->id, 'company_location_id' => $this->outlet->id, 'section' => 'Kasir']);
        $attendanceId = $this->legacyAttendance(['employee_id' => $employee->id, 'shift_id' => $this->morning->id, 'company_location_id' => $this->outlet->id]);

        $result = app(AttendanceSnapshotBackfill::class)->run();

        $this->assertSame(['filled' => 1, 'linked' => 1, 'remaining' => 0], $result);

        $attendance = Attendance::findOrFail($attendanceId);
        $this->assertTrue($attendance->hasSnapshot());
        $this->assertSame($schedule->id, $attendance->shift_schedule_id);
        $this->assertTrue($attendance->schedule->is($schedule));
        $this->assertSame(1, $attendance->schedule_sequence);
        $this->assertSame('schedule', $attendance->sched_source);
        $this->assertSame(self::DATE.' 08:00:00', $attendance->sched_start_at->toDateTimeString());
        $this->assertSame(self::DATE.' 16:00:00', $attendance->sched_end_at->toDateTimeString());
        $this->assertSame(5, $attendance->sched_tolerance_minutes);
        $this->assertSame(480, $attendance->sched_scheduled_minutes);
        $this->assertNull($attendance->sched_normal_minutes);
        $this->assertSame('Pagi', $attendance->sched_shift_name);
        $this->assertSame('P', $attendance->sched_shift_code);
        $this->assertSame('Momoyo Ciledug', $attendance->sched_outlet_name);
        $this->assertSame('Kasir', $attendance->sched_section);
        $this->assertTrue($schedule->isLocked());
        $this->assertTrue($schedule->attendances->first()->is($attendance));
    }

    public function test_second_part_of_a_split_shift_gets_the_second_parts_hours_and_the_whole_shifts_minutes(): void
    {
        $employee = $this->employee();
        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::DATE, 'shift_id' => $this->split->id]);
        $first = $this->legacyAttendance(['employee_id' => $employee->id, 'shift_id' => $this->split->id, 'segment' => 1, 'check_in' => self::DATE.' 07:40:00']);
        $second = $this->legacyAttendance(['employee_id' => $employee->id, 'shift_id' => $this->split->id, 'segment' => 2, 'check_in' => self::DATE.' 17:40:00']);

        app(AttendanceSnapshotBackfill::class)->run();

        $first = Attendance::findOrFail($first);
        $second = Attendance::findOrFail($second);

        $this->assertSame(self::DATE.' 07:45:00', $first->sched_start_at->toDateTimeString());
        $this->assertSame(self::DATE.' 13:00:00', $first->sched_end_at->toDateTimeString());
        $this->assertSame(self::DATE.' 17:45:00', $second->sched_start_at->toDateTimeString());
        $this->assertSame(self::DATE.' 22:00:00', $second->sched_end_at->toDateTimeString());
        $this->assertSame(315 + 255, $first->sched_scheduled_minutes);
        $this->assertSame(315 + 255, $second->sched_scheduled_minutes);
        $this->assertSame($first->shift_schedule_id, $second->shift_schedule_id);
        $this->assertNotNull($second->shift_schedule_id);
        // No tolerance on the shift: the company policy's applies.
        $this->assertSame(10, $second->sched_tolerance_minutes);
    }

    public function test_overnight_shift_ends_on_the_next_day(): void
    {
        $employee = $this->employee();
        $attendanceId = $this->legacyAttendance(['employee_id' => $employee->id, 'shift_id' => $this->night->id, 'check_in' => self::DATE.' 21:55:00']);

        app(AttendanceSnapshotBackfill::class)->run();

        $attendance = Attendance::findOrFail($attendanceId);
        $this->assertSame(self::DATE.' 22:00:00', $attendance->sched_start_at->toDateTimeString());
        $this->assertSame('2026-11-11 06:00:00', $attendance->sched_end_at->toDateTimeString());
        $this->assertSame(480, $attendance->sched_scheduled_minutes);
    }

    public function test_attendance_on_another_shift_than_the_roster_row_is_not_linked_and_reads_as_default(): void
    {
        $employee = $this->employee();
        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::DATE, 'shift_id' => $this->night->id]);
        $attendanceId = $this->legacyAttendance(['employee_id' => $employee->id, 'shift_id' => $this->morning->id]);

        $result = app(AttendanceSnapshotBackfill::class)->run();

        $attendance = Attendance::findOrFail($attendanceId);
        $this->assertSame(0, $result['linked']);
        $this->assertNull($attendance->shift_schedule_id);
        $this->assertSame('default', $attendance->sched_source);
        $this->assertSame(self::DATE.' 08:00:00', $attendance->sched_start_at->toDateTimeString());
        $this->assertNull($attendance->sched_section);
    }

    public function test_attendance_without_any_roster_row_reads_as_default_shift(): void
    {
        $employee = $this->employee();
        $attendanceId = $this->legacyAttendance(['employee_id' => $employee->id, 'shift_id' => $this->morning->id]);

        app(AttendanceSnapshotBackfill::class)->run();

        $attendance = Attendance::findOrFail($attendanceId);
        $this->assertNull($attendance->shift_schedule_id);
        $this->assertSame('default', $attendance->sched_source);
    }

    public function test_day_off_row_is_never_linked(): void
    {
        $employee = $this->employee();
        $dayOff = ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::DATE, 'is_day_off' => true]);
        $attendanceId = $this->legacyAttendance(['employee_id' => $employee->id, 'shift_id' => $this->morning->id, 'is_off_schedule' => true]);

        app(AttendanceSnapshotBackfill::class)->run();

        $attendance = Attendance::findOrFail($attendanceId);
        $this->assertNull($attendance->shift_schedule_id);
        $this->assertSame('cover', $attendance->sched_source);
        $this->assertSame(self::DATE.' 08:00:00', $attendance->sched_start_at->toDateTimeString());
        $this->assertFalse($dayOff->isLocked());
    }

    public function test_approved_cover_check_in_is_linked_to_the_row_that_carries_the_cover_shift(): void
    {
        $employee = $this->employee();
        $schedule = ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::DATE, 'shift_id' => $this->morning->id]);
        $attendanceId = $this->legacyAttendance(['employee_id' => $employee->id, 'shift_id' => $this->morning->id, 'is_off_schedule' => true]);

        app(AttendanceSnapshotBackfill::class)->run();

        $attendance = Attendance::findOrFail($attendanceId);
        $this->assertSame($schedule->id, $attendance->shift_schedule_id);
        $this->assertSame('cover', $attendance->sched_source);
    }

    public function test_employee_without_a_shift_gets_the_company_policy_hours(): void
    {
        $employee = $this->employee();
        $attendanceId = $this->legacyAttendance(['employee_id' => $employee->id, 'shift_id' => null, 'check_in' => self::DATE.' 09:05:00']);

        app(AttendanceSnapshotBackfill::class)->run();

        $attendance = Attendance::findOrFail($attendanceId);
        $this->assertSame('policy', $attendance->sched_source);
        $this->assertSame(self::DATE.' 09:00:00', $attendance->sched_start_at->toDateTimeString());
        $this->assertSame(self::DATE.' 18:00:00', $attendance->sched_end_at->toDateTimeString());
        $this->assertSame(540, $attendance->sched_scheduled_minutes);
        $this->assertSame(10, $attendance->sched_tolerance_minutes);
        $this->assertNull($attendance->sched_shift_name);
        $this->assertNull($attendance->shift_schedule_id);
    }

    public function test_freelancer_without_a_shift_has_no_scheduled_hours(): void
    {
        $freelancer = Freelancer::create(['company_id' => $this->company->id, 'division_id' => $this->division->id, 'freelancer_number' => 'FRL-1', 'full_name' => 'Lepas Satu', 'status' => 'active']);
        $attendanceId = $this->legacyAttendance(['freelancer_id' => $freelancer->id, 'shift_id' => null]);

        $result = app(AttendanceSnapshotBackfill::class)->run();

        $attendance = Attendance::findOrFail($attendanceId);
        $this->assertSame(0, $result['remaining']);
        $this->assertTrue($attendance->hasSnapshot());
        $this->assertSame('none', $attendance->sched_source);
        $this->assertNull($attendance->sched_start_at);
        $this->assertNull($attendance->sched_end_at);
        $this->assertNull($attendance->sched_scheduled_minutes);
    }

    public function test_leave_row_without_check_in_keeps_no_snapshot(): void
    {
        $employee = $this->employee();
        $attendanceId = $this->legacyAttendance(['employee_id' => $employee->id, 'shift_id' => $this->morning->id, 'check_in' => null, 'status' => 'leave']);

        $result = app(AttendanceSnapshotBackfill::class)->run();

        $attendance = Attendance::findOrFail($attendanceId);
        $this->assertSame(['filled' => 0, 'linked' => 0, 'remaining' => 0], $result);
        $this->assertFalse($attendance->hasSnapshot());
        $this->assertNull($attendance->sched_start_at);
        $this->assertNull($attendance->shift_schedule_id);
    }

    public function test_judged_result_is_never_changed_and_a_second_run_changes_nothing(): void
    {
        $employee = $this->employee();
        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::DATE, 'shift_id' => $this->morning->id]);
        $attendanceId = $this->legacyAttendance([
            'employee_id' => $employee->id,
            'shift_id' => $this->morning->id,
            'check_in' => self::DATE.' 08:00:00',
            'check_out' => self::DATE.' 16:00:00',
            // Deliberately not what the roster would give today: the stored verdict wins.
            'status' => 'late',
            'late_minutes' => 77,
            'check_in_late_minutes' => 66,
            'early_leave_minutes' => 55,
            'updated_at' => '2026-11-10 16:00:00',
        ]);
        $verdict = ['status', 'late_minutes', 'check_in_late_minutes', 'early_leave_minutes', 'check_in', 'check_out', 'shift_id', 'company_location_id', 'schedule_sequence', 'segment', 'updated_at'];
        $before = (array) DB::table('attendances')->where('id', $attendanceId)->first($verdict);

        $first = app(AttendanceSnapshotBackfill::class)->run();
        $afterFirstRun = (array) DB::table('attendances')->where('id', $attendanceId)->first();

        $this->assertSame(1, $first['filled']);
        $this->assertEquals($before, array_intersect_key($afterFirstRun, array_flip($verdict)));
        $this->assertSame('schedule', $afterFirstRun['sched_source']);

        // A roster change after the first run must not reach an already filled row.
        $this->morning->update(['start_time' => '10:00', 'end_time' => '18:00']);
        $second = app(AttendanceSnapshotBackfill::class)->run();

        $this->assertSame(['filled' => 0, 'linked' => 0, 'remaining' => 0], $second);
        $this->assertSame($afterFirstRun, (array) DB::table('attendances')->where('id', $attendanceId)->first());
    }

    public function test_backfill_only_fills_rows_without_a_snapshot(): void
    {
        $employee = $this->employee();
        $filled = $this->legacyAttendance(['employee_id' => $employee->id, 'shift_id' => $this->morning->id, 'sched_source' => 'schedule', 'sched_start_at' => self::DATE.' 07:00:00']);
        $empty = $this->legacyAttendance(['employee_id' => $employee->id, 'shift_id' => $this->morning->id, 'date' => '2026-11-11', 'check_in' => '2026-11-11 08:00:00']);

        $result = app(AttendanceSnapshotBackfill::class)();

        $this->assertSame(1, $result['filled']);
        $this->assertSame(self::DATE.' 07:00:00', Attendance::findOrFail($filled)->sched_start_at->toDateTimeString());
        $this->assertSame('2026-11-11 08:00:00', Attendance::findOrFail($empty)->sched_start_at->toDateTimeString());
    }

    public function test_tolerance_outside_the_snapshot_column_range_is_clamped(): void
    {
        $employee = $this->employee();
        $this->morning->update(['late_tolerance_minutes' => 70000]);
        $this->company->policy->update(['late_tolerance_minutes' => -5]);
        $onShift = $this->legacyAttendance(['employee_id' => $employee->id, 'shift_id' => $this->morning->id]);
        $onPolicy = $this->legacyAttendance(['employee_id' => $employee->id, 'shift_id' => null, 'date' => '2026-11-11', 'check_in' => '2026-11-11 09:00:00']);

        $result = app(AttendanceSnapshotBackfill::class)->run();

        $this->assertSame(0, $result['remaining']);
        $this->assertSame(AttendanceSnapshotBackfill::MAX_TOLERANCE_MINUTES, Attendance::findOrFail($onShift)->sched_tolerance_minutes);
        $this->assertSame(0, Attendance::findOrFail($onPolicy)->sched_tolerance_minutes);
    }

    public function test_a_chunk_is_committed_once_and_not_once_per_row(): void
    {
        $employee = $this->employee();

        foreach (['2026-11-10', '2026-11-11', '2026-11-12'] as $date) {
            $this->legacyAttendance(['employee_id' => $employee->id, 'shift_id' => $this->morning->id, 'date' => $date, 'check_in' => "{$date} 08:00:00"]);
        }

        $commits = 0;
        Event::listen(TransactionCommitted::class, function () use (&$commits): void {
            $commits++;
        });

        $result = app(AttendanceSnapshotBackfill::class)->run();

        $this->assertSame(3, $result['filled']);
        $this->assertSame(1, $commits);
    }

    public function test_a_chunk_that_fails_is_rolled_back_and_filled_by_the_next_run(): void
    {
        $employee = $this->employee();
        $first = $this->legacyAttendance(['employee_id' => $employee->id, 'shift_id' => $this->morning->id]);
        $second = $this->legacyAttendance(['employee_id' => $employee->id, 'shift_id' => $this->morning->id, 'date' => '2026-11-11', 'check_in' => '2026-11-11 08:00:00']);

        DB::unprepared("CREATE TRIGGER fail_second_snapshot BEFORE UPDATE ON attendances WHEN NEW.id = {$second} BEGIN SELECT RAISE(ABORT, 'snapshot refused'); END");

        try {
            app(AttendanceSnapshotBackfill::class)->run();
            $this->fail('The failing row must stop the run.');
        } catch (QueryException) {
            $this->assertNull(DB::table('attendances')->where('id', $first)->value('sched_source'));
        }

        DB::unprepared('DROP TRIGGER fail_second_snapshot');

        $this->assertSame(['filled' => 2, 'linked' => 0, 'remaining' => 0], app(AttendanceSnapshotBackfill::class)->run());
        $this->assertSame('default', DB::table('attendances')->where('id', $second)->value('sched_source'));
    }

    public function test_a_row_filled_by_another_run_in_the_meantime_is_counted_as_neither_filled_nor_linked(): void
    {
        $employee = $this->employee();
        $schedule = ShiftSchedule::create(['employee_id' => $employee->id, 'shift_id' => $this->morning->id, 'date' => self::DATE]);
        $attendance = $this->legacyAttendance(['employee_id' => $employee->id, 'shift_id' => $this->morning->id]);

        // Stands in for a second run: it fills the row after this run has read its chunk, before the update.
        $filledElsewhere = false;
        DB::listen(function (QueryExecuted $query) use ($attendance, &$filledElsewhere): void {
            if ($filledElsewhere || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, '"shift_schedules"')) {
                return;
            }

            $filledElsewhere = true;
            DB::table('attendances')->where('id', $attendance)->update(['sched_source' => 'default']);
        });

        $result = app(AttendanceSnapshotBackfill::class)->run();

        $this->assertTrue($filledElsewhere);
        $this->assertSame(['filled' => 0, 'linked' => 0, 'remaining' => 0], $result);
        $this->assertSame('default', DB::table('attendances')->where('id', $attendance)->value('sched_source'));
        $this->assertNull(DB::table('attendances')->where('id', $attendance)->value('shift_schedule_id'));
        $this->assertNotNull($schedule->id);
    }

    public function test_roster_rows_are_read_per_attendance_date_and_not_across_the_span_between_them(): void
    {
        $employee = $this->employee();
        $other = $this->employee();
        $old = ShiftSchedule::create(['employee_id' => $employee->id, 'date' => '2026-03-02', 'shift_id' => $this->morning->id]);
        $recent = ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::DATE, 'shift_id' => $this->morning->id]);
        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => '2026-07-01', 'shift_id' => $this->night->id]);
        ShiftSchedule::create(['employee_id' => $other->id, 'date' => '2026-03-02', 'shift_id' => $this->morning->id]);
        $recentId = $this->legacyAttendance(['employee_id' => $employee->id, 'shift_id' => $this->morning->id]);
        $otherId = $this->legacyAttendance(['employee_id' => $other->id, 'shift_id' => $this->morning->id]);
        // Created late for an old date, so it sits among recent ids.
        $oldId = $this->legacyAttendance(['employee_id' => $employee->id, 'shift_id' => $this->morning->id, 'date' => '2026-03-02', 'check_in' => '2026-03-02 08:00:00']);

        $rosterReads = [];
        DB::listen(function (QueryExecuted $query) use (&$rosterReads): void {
            if (str_contains($query->sql, 'from "shift_schedules"')) {
                $rosterReads[] = $query->bindings;
            }
        });

        $result = app(AttendanceSnapshotBackfill::class)->run();

        $this->assertSame(['filled' => 3, 'linked' => 2, 'remaining' => 0], $result);
        $this->assertSame($recent->id, Attendance::findOrFail($recentId)->shift_schedule_id);
        $this->assertSame($old->id, Attendance::findOrFail($oldId)->shift_schedule_id);
        $this->assertNull(Attendance::findOrFail($otherId)->shift_schedule_id);

        $this->assertCount(2, $rosterReads);

        foreach ($rosterReads as $bindings) {
            $dates = array_values(array_filter($bindings, fn (mixed $binding): bool => is_string($binding) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $binding) === 1));
            $workerIds = array_values(array_filter($bindings, 'is_int'));

            $this->assertCount(2, $dates);
            $this->assertSame(1, (int) Carbon::parse($dates[0])->diffInDays(Carbon::parse($dates[1])));
            $this->assertSame($dates[0] === '2026-03-02' ? [$employee->id] : [$employee->id, $other->id], $workerIds);
        }
    }

    // ── Unique keys ──────────────────────────────────────────────────────────

    public function test_a_worker_can_have_two_schedules_on_one_date_with_different_sequences(): void
    {
        $employee = $this->employee();
        $first = ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::DATE, 'shift_id' => $this->morning->id]);
        $second = ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::DATE, 'sequence' => 2, 'shift_id' => $this->night->id]);

        $this->assertSame(1, $first->fresh()->sequence);
        $this->assertSame(2, $second->fresh()->sequence);
        $this->assertSame(2, ShiftSchedule::query()->where('employee_id', $employee->id)->whereDate('date', self::DATE)->count());
    }

    public function test_the_same_sequence_twice_on_one_date_is_refused(): void
    {
        $employee = $this->employee();
        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::DATE, 'shift_id' => $this->morning->id]);

        $this->expectException(QueryException::class);

        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::DATE, 'shift_id' => $this->night->id]);
    }

    public function test_duplicate_attendance_of_the_same_schedule_and_segment_is_still_refused(): void
    {
        $employee = $this->employee();
        $this->legacyAttendance(['employee_id' => $employee->id]);

        $this->expectException(QueryException::class);

        $this->legacyAttendance(['employee_id' => $employee->id]);
    }

    public function test_attendance_of_a_second_schedule_is_storable_beside_the_first(): void
    {
        $employee = $this->employee();
        $second = ['employee_id' => $employee->id, 'date' => self::DATE, 'schedule_sequence' => 2, 'segment' => 1, 'check_in' => self::DATE.' 16:05:00', 'status' => 'on_time'];

        $first = Attendance::create(['schedule_sequence' => 1, 'check_in' => self::DATE.' 08:00:00'] + $second);
        $stored = Attendance::create($second);
        Attendance::create(['segment' => 2] + $second);

        $this->assertSame(1, $first->fresh()->schedule_sequence);
        $this->assertSame(2, $stored->fresh()->schedule_sequence);
        $this->assertSame(3, Attendance::query()->where('employee_id', $employee->id)->count());

        $this->expectException(QueryException::class);

        Attendance::create($second);
    }

    public function test_deleting_a_schedule_keeps_its_attendance_and_only_clears_the_link(): void
    {
        $employee = $this->employee();
        $schedule = ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::DATE, 'shift_id' => $this->morning->id]);
        $attendanceId = $this->legacyAttendance(['employee_id' => $employee->id, 'shift_id' => $this->morning->id, 'shift_schedule_id' => $schedule->id]);

        DB::table('shift_schedules')->where('id', $schedule->id)->delete();

        $this->assertNull(Attendance::findOrFail($attendanceId)->shift_schedule_id);
    }

    // ── Model plumbing ───────────────────────────────────────────────────────

    public function test_normal_minutes_default_to_the_scheduled_minutes_and_never_exceed_them(): void
    {
        $longShift = Shift::create(['company_id' => $this->company->id, 'name' => 'Long Shift', 'code' => 'LS', 'start_time' => '08:45', 'end_time' => '22:30']);

        $this->assertSame(825, $longShift->scheduledMinutes());
        $this->assertNull($longShift->fresh()->normal_minutes);
        $this->assertSame(825, $longShift->normalMinutes());

        $longShift->update(['normal_minutes' => 480]);
        $this->assertSame(480, $longShift->fresh()->normalMinutes());

        $longShift->update(['normal_minutes' => 900]);
        $this->assertSame(825, $longShift->fresh()->normalMinutes());
    }

    public function test_new_policy_flags_default_to_off(): void
    {
        $policy = $this->company->policy->fresh();

        $this->assertFalse($policy->uses_shift_roster);
        $this->assertNull($policy->overtime_netting_from);

        $policy->update(['uses_shift_roster' => true, 'overtime_netting_from' => '2026-12-01']);

        $this->assertTrue($policy->fresh()->uses_shift_roster);
        $this->assertSame('2026-12-01', $policy->fresh()->overtime_netting_from->toDateString());
    }

    public function test_roster_change_log_entry_outlives_its_schedule(): void
    {
        $employee = $this->employee();
        $editor = User::factory()->create();
        $schedule = ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::DATE, 'shift_id' => $this->morning->id, 'updated_by' => $editor->id]);
        $change = ShiftScheduleChange::factory()->deleted()->create([
            'shift_schedule_id' => $schedule->id,
            'employee_id' => $employee->id,
            'date' => self::DATE,
            'reason' => 'Salah input',
            'changed_by' => $editor->id,
        ]);

        // The schedule's own creation is logged first (WP06), the entry made above follows it.
        $this->assertTrue($schedule->changes->last()->is($change));
        $this->assertSame([ShiftScheduleChange::ACTION_CREATED, ShiftScheduleChange::ACTION_DELETED], $schedule->changes->pluck('action')->all());
        $this->assertTrue($schedule->updater->is($editor));

        $schedule->delete();
        $change = $change->fresh();

        $this->assertNotNull($change);
        $this->assertNull($change->schedule);
        $this->assertSame(ShiftScheduleChange::ACTION_DELETED, $change->action);
        $this->assertTrue($change->before['is_day_off']);
        $this->assertNull($change->after);
        $this->assertSame(self::DATE, $change->date->toDateString());
        $this->assertNotNull($change->created_at);
        $this->assertTrue($change->changer->is($editor));
        $this->assertTrue($change->employee->is($employee));
    }

    public function test_correction_and_leave_keep_their_new_columns(): void
    {
        $employee = $this->employee();

        $correction = AttendanceCorrection::create([
            'employee_id' => $employee->id,
            'date' => self::DATE,
            'corrected_check_out' => '23:30',
            'reason' => 'Lupa absen pulang',
            'status' => 'pending',
        ])->fresh();

        $this->assertSame('worker', $correction->origin);
        $this->assertFalse($correction->check_out_next_day);
        $this->assertNull($correction->before_values);
        $this->assertNull($correction->target_segment);

        $correction->update([
            'target_sequence' => 2,
            'target_segment' => 1,
            'check_out_next_day' => true,
            'corrected_shift_id' => $this->morning->id,
            'corrected_company_location_id' => $this->outlet->id,
            'origin' => 'admin',
            'before_values' => ['status' => 'late', 'late_minutes' => 12],
        ]);
        $correction = $correction->fresh();

        $this->assertSame(2, $correction->target_sequence);
        $this->assertTrue($correction->check_out_next_day);
        $this->assertSame($this->morning->id, $correction->corrected_shift_id);
        $this->assertSame(['status' => 'late', 'late_minutes' => 12], $correction->before_values);

        $leave = LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type' => 'annual',
            'start_date' => '2026-11-12',
            'end_date' => '2026-11-14',
            'days_count' => 3,
            'reason' => 'Acara keluarga',
            'status' => 'pending',
        ])->fresh();

        $this->assertNull($leave->charged_dates);

        $leave->update(['charged_dates' => ['2026-11-12', '2026-11-14']]);

        $this->assertSame(['2026-11-12', '2026-11-14'], $leave->fresh()->charged_dates);
    }

    public function test_old_unique_keys_are_replaced_by_the_sequence_keys(): void
    {
        $scheduleIndexes = collect(Schema::getIndexes('shift_schedules'))->pluck('name')->all();
        $attendanceIndexes = collect(Schema::getIndexes('attendances'))->pluck('name')->all();

        foreach (['employee', 'intern', 'freelancer'] as $worker) {
            $this->assertContains("shift_schedules_{$worker}_date_seq_unique", $scheduleIndexes);
            $this->assertNotContains("shift_schedules_{$worker}_id_date_unique", $scheduleIndexes);
            $this->assertContains("attendances_{$worker}_date_seq_segment_unique", $attendanceIndexes);
            $this->assertNotContains("attendances_{$worker}_date_segment_unique", $attendanceIndexes);
        }

        $this->assertContains('attendances_shift_schedule_id_index', $attendanceIndexes);
    }

    // ── Rolling back ─────────────────────────────────────────────────────────

    public function test_migrations_roll_back_to_the_old_unique_keys_when_no_second_schedule_exists(): void
    {
        $connection = $this->migratedScratchConnection();

        $this->artisan('migrate:rollback', ['--database' => $connection, '--step' => self::MIGRATION_COUNT + self::LATER_MIGRATION_COUNT])->assertSuccessful();

        $schema = Schema::connection($connection);
        $scheduleIndexes = collect($schema->getIndexes('shift_schedules'))->pluck('name')->all();
        $attendanceIndexes = collect($schema->getIndexes('attendances'))->pluck('name')->all();

        foreach (['employee', 'intern', 'freelancer'] as $worker) {
            $this->assertContains("shift_schedules_{$worker}_id_date_unique", $scheduleIndexes);
            $this->assertNotContains("shift_schedules_{$worker}_date_seq_unique", $scheduleIndexes);
            $this->assertContains("attendances_{$worker}_date_segment_unique", $attendanceIndexes);
            $this->assertNotContains("attendances_{$worker}_date_seq_segment_unique", $attendanceIndexes);
        }

        $this->assertFalse($schema->hasTable('shift_schedule_changes'));
        $this->assertFalse($schema->hasColumn('shift_schedules', 'sequence'));
        $this->assertFalse($schema->hasColumn('shift_schedules', 'updated_by'));
        $this->assertFalse($schema->hasColumn('attendances', 'shift_schedule_id'));
        $this->assertFalse($schema->hasColumn('attendances', 'sched_source'));
        $this->assertFalse($schema->hasColumn('attendances', 'pending_punch'));
        $this->assertFalse($schema->hasColumn('shifts', 'normal_minutes'));
        $this->assertFalse($schema->hasColumn('company_policies', 'uses_shift_roster'));
        $this->assertFalse($schema->hasColumn('attendance_corrections', 'before_values'));
        $this->assertFalse($schema->hasColumn('leave_requests', 'charged_dates'));

        // And forward again, as a deploy after a rollback would.
        $this->artisan('migrate', ['--database' => $connection])->assertSuccessful();
        $this->assertTrue($schema->hasColumn('attendances', 'sched_source'));
    }

    public function test_rollback_is_refused_while_a_second_schedule_exists(): void
    {
        $connection = $this->migratedScratchConnection();
        DB::connection($connection)->table('shift_schedules')->insert(['date' => self::DATE, 'sequence' => 2, 'is_day_off' => true]);

        // Leave, corrections, change log, backfill and the attendance snapshot roll back; the roster sequence is next.
        $this->artisan('migrate:rollback', ['--database' => $connection, '--step' => 5 + self::LATER_MIGRATION_COUNT])->assertSuccessful();
        $this->assertFalse(Schema::connection($connection)->hasColumn('attendances', 'schedule_sequence'));

        try {
            Artisan::call('migrate:rollback', ['--database' => $connection, '--step' => 1]);
            $this->fail('Rolling back the sequence column with a second schedule on a date must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('shift_schedules.sequence > 1', $exception->getMessage());
        }

        $this->assertTrue(Schema::connection($connection)->hasColumn('shift_schedules', 'sequence'));
        $this->assertSame(1, DB::connection($connection)->table('shift_schedules')->count());
    }

    public function test_rollback_is_refused_while_attendance_of_a_second_schedule_exists(): void
    {
        $connection = $this->migratedScratchConnection();
        DB::connection($connection)->table('attendances')->insert(['date' => self::DATE, 'schedule_sequence' => 2, 'status' => 'on_time']);

        // Leave, corrections, change log and the backfill roll back; the attendance snapshot is next.
        $this->artisan('migrate:rollback', ['--database' => $connection, '--step' => 4 + self::LATER_MIGRATION_COUNT])->assertSuccessful();

        try {
            Artisan::call('migrate:rollback', ['--database' => $connection, '--step' => 1]);
            $this->fail('Rolling back the attendance key with attendance on a second schedule must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('attendances.schedule_sequence > 1', $exception->getMessage());
        }

        $this->assertTrue(Schema::connection($connection)->hasColumn('attendances', 'schedule_sequence'));
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /**
     * A second, empty in-memory database with every migration applied, so rolling back never touches
     * the database the rest of the suite shares.
     */
    private function migratedScratchConnection(): string
    {
        $default = config('database.default');
        config(['database.connections.schema_rollback' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);

        $this->artisan('migrate', ['--database' => 'schema_rollback'])->assertSuccessful();
        $this->assertSame($default, config('database.default'));

        return 'schema_rollback';
    }

    private function employee(): Employee
    {
        $user = User::factory()->create();

        return Employee::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Crew'])->id,
            'nip' => 'PF-'.$user->id,
            'full_name' => "Crew {$user->id}",
            'status' => 'active',
        ]);
    }

    /**
     * A row as it was stored before the snapshot existed: inserted without the model, so no hook
     * judges it and every snapshot column is empty unless given.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function legacyAttendance(array $attributes): int
    {
        return DB::table('attendances')->insertGetId($attributes + [
            'date' => self::DATE,
            'check_in' => self::DATE.' 08:03:00',
            'status' => 'on_time',
            'late_minutes' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
