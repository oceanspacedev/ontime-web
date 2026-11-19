<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalFlowService;
use App\Services\OutletRosterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The contract of the released mobile app (mobile/src/lib/api/types.ts), pinned before the attendance and
 * roster engine changes: the keys the app reads, their types, and the requests it sends without the newer
 * optional fields. Only structure and types are asserted, so added keys never break it; a key that is
 * removed, renamed or re-typed does.
 *
 * Monday 16 Nov 2026 is a single-schedule day, Tuesday a split-shift day and Wednesday a blank day.
 */
class OldClientContractTest extends TestCase
{
    use RefreshDatabase;

    private const SINGLE_DAY = '2026-11-16';

    private const SPLIT_DAY = '2026-11-17';

    private const BLANK_DAY = '2026-11-18';

    /** @var list<string> Keys of an attendance the released app reads. */
    private const ATTENDANCE_KEYS = [
        'id', 'employee_id', 'intern_id', 'freelancer_id', 'date', 'segment', 'check_in', 'check_out',
        'check_in_lat', 'check_in_lng', 'check_out_lat', 'check_out_lng', 'check_in_photo', 'check_out_photo',
        'status', 'is_corrected', 'is_out_of_bounds', 'is_mock_location', 'is_face_verified', 'face_match_score',
        'face_verification_notes', 'liveness_verified', 'liveness_notes', 'late_minutes', 'early_leave_minutes',
        'current_step', 'total_steps', 'rejection_reason', 'is_off_schedule', 'replaced_worker', 'off_schedule_label',
        'employee', 'intern', 'freelancer', 'user',
    ];

    /** @var list<string> Keys of one line of the monthly recap. */
    private const RECAP_KEYS = [
        'worker', 'outlet', 'scheduled_days', 'present_days', 'pending_days', 'late_days', 'late_minutes',
        'early_leave_days', 'early_leave_minutes', 'overtime_count', 'overtime_hours', 'absent_days', 'off_days',
        'scheduled_hours', 'worked_hours',
    ];

    /** @var list<string> Keys added to an attendance by the attendance ledger; the released app ignores them. */
    private const ATTENDANCE_ADDED_KEYS = [
        'schedule_id', 'schedule_sequence', 'shift', 'outlet', 'scheduled_start', 'scheduled_end', 'worked_minutes',
        'normal_minutes', 'overtime_minutes', 'reported_late_minutes', 'is_missing_check_out', 'pending_punch', 'schedule_label',
    ];

    /** @var list<string> Keys added to a line of the monthly recap, after the ones the released app reads. */
    private const RECAP_ADDED_KEYS = [
        'leave_days', 'missing_check_out_days', 'incomplete_days', 'sessions_scheduled', 'sessions_worked', 'sessions_missed',
        'normal_hours', 'auto_overtime_hours', 'request_overtime_hours', 'scheduled_days_to_date', 'scheduled_hours_to_date',
        'overtime_unattended_count',
    ];

    /** @var list<string> Roster states the released app knows; an unknown one would be drawn as nothing. */
    private const ROSTER_STATES = ['hadir', 'terlambat', 'pulang', 'menunggu', 'belum', 'nanti', 'libur', 'ditolak', 'cuti'];

    private Company $company;

    private Division $division;

    private CompanyLocation $outlet;

    private Shift $morning;

    private Shift $split;

    private User $coordinator;

    private Employee $worker;

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

        foreach (['Superadmin', 'Admin', 'PIC', 'Koordinator', 'Approver', 'BOD', 'Employee', 'Intern', 'Freelancer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->travelTo(Carbon::parse(self::SINGLE_DAY.' 07:55:00'));

        $tenant = Tenant::factory()->create(['name' => 'Klien Kontrak', 'code' => 'KK', 'is_active' => true]);
        $this->company = new Company(['name' => 'PT Franchise', 'code' => 'PFC', 'is_active' => true]);
        $this->company->tenant_id = $tenant->id;
        $this->company->save();
        app(ApprovalFlowService::class)->createDefaultFlows($this->company);
        $this->company->policy()->update([
            'require_gps' => false,
            'require_photo' => false,
            'require_face_recognition' => false,
            'require_liveness' => false,
        ]);
        $this->company->unsetRelation('policy');

        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'MOMOYO', 'code' => 'MMY', 'is_active' => true]);
        $this->outlet = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $this->division->id, 'name' => 'Momoyo Ciledug', 'latitude' => -6.2297, 'longitude' => 106.7112, 'is_active' => true]);
        $this->morning = Shift::create(['company_id' => $this->company->id, 'division_id' => $this->division->id, 'name' => 'Pagi', 'code' => 'P', 'start_time' => '08:00', 'end_time' => '17:00']);
        $this->split = Shift::create(['company_id' => $this->company->id, 'division_id' => $this->division->id, 'name' => 'Split', 'code' => 'SP', 'start_time' => '07:45', 'end_time' => '13:00', 'second_start_time' => '17:45', 'second_end_time' => '22:00']);

        $this->coordinator = User::factory()->create(['name' => 'Loocita', 'is_active' => true]);
        $this->coordinator->forceFill(['tenant_id' => $tenant->id])->save();
        $this->coordinator->assignRole('Koordinator');
        $this->coordinator->grantDataAccess(DataScope::Divisions, divisionIds: [$this->division->id]);

        $user = User::factory()->create(['name' => 'Thia', 'is_active' => true]);
        $user->forceFill(['tenant_id' => $tenant->id])->save();
        $user->assignRole('Employee');
        $this->worker = Employee::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_number' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Crew'])->id,
            'nip' => 'PF-001',
            'full_name' => 'Thia Crew',
            'join_date' => '2026-01-01',
            'status' => 'permanent',
        ]);

        ShiftSchedule::create(['employee_id' => $this->worker->id, 'date' => self::SINGLE_DAY, 'shift_id' => $this->morning->id, 'company_location_id' => $this->outlet->id, 'section' => 'Kasir']);
        ShiftSchedule::create(['employee_id' => $this->worker->id, 'date' => self::SPLIT_DAY, 'shift_id' => $this->split->id, 'company_location_id' => $this->outlet->id, 'section' => 'Bar']);
    }

    public function test_today_on_a_single_schedule_day_keeps_one_schedule_and_one_attendance_object(): void
    {
        Sanctum::actingAs($this->worker->user);

        $before = $this->getJson('/api/v1/attendance/today')->assertOk();
        $this->assertTodayShape($before);
        $this->assertNull($before->json('attendance'));
        $this->assertFalse($before->json('has_checked_in'));
        $this->assertSame(['08:00', '17:00', 1, 1], [$before->json('schedule.start_time'), $before->json('schedule.end_time'), $before->json('schedule.segment'), $before->json('schedule.segment_count')]);
        $this->assertSame($this->outlet->id, $before->json('schedule.outlet.id'));

        $this->postJson('/api/v1/attendance/check-in')->assertOk();

        $after = $this->getJson('/api/v1/attendance/today')->assertOk();
        $this->assertTodayShape($after);
        $this->assertTrue($after->json('has_checked_in'));
        $this->assertFalse($after->json('has_checked_out'));
        $this->assertAttendanceShape($after->json('attendance'));
        $this->assertCount(1, $after->json('attendances'));
        $this->assertAttendanceShape($after->json('attendances.0'));
        $this->assertIsArray($after->json('check_out_outlet'));
        $this->assertSame($this->outlet->id, $after->json('check_out_outlet.id'));
    }

    public function test_today_on_a_split_shift_day_moves_from_segment_one_to_segment_two(): void
    {
        Sanctum::actingAs($this->worker->user);
        $this->travelTo(Carbon::parse(self::SPLIT_DAY.' 07:40:00'));

        $first = $this->getJson('/api/v1/attendance/today')->assertOk();
        $this->assertTodayShape($first);
        $this->assertSame([1, 2, '07:45', '13:00'], [$first->json('schedule.segment'), $first->json('schedule.segment_count'), $first->json('schedule.start_time'), $first->json('schedule.end_time')]);
        $this->assertSame([['start' => '07:45', 'end' => '13:00'], ['start' => '17:45', 'end' => '22:00']], $first->json('schedule.segments'));

        $this->postJson('/api/v1/attendance/check-in')->assertOk();
        $this->travelTo(Carbon::parse(self::SPLIT_DAY.' 13:00:00'));
        $this->postJson('/api/v1/attendance/check-out')->assertOk();

        $this->travelTo(Carbon::parse(self::SPLIT_DAY.' 17:40:00'));
        $second = $this->getJson('/api/v1/attendance/today')->assertOk();
        $this->assertTodayShape($second);
        $this->assertSame([2, '17:45', '22:00'], [$second->json('schedule.segment'), $second->json('schedule.start_time'), $second->json('schedule.end_time')]);
        $this->assertFalse($second->json('has_checked_in'), 'The card resets to "Absen Masuk" for the second segment.');
        $this->assertCount(1, $second->json('attendances'));

        $this->postJson('/api/v1/attendance/check-in')->assertOk()->assertJsonPath('attendance.segment', 2);
        $this->travelTo(Carbon::parse(self::SPLIT_DAY.' 22:00:00'));
        $this->postJson('/api/v1/attendance/check-out')->assertOk();

        $done = $this->getJson('/api/v1/attendance/today')->assertOk();
        $this->assertTodayShape($done);
        $this->assertTrue($done->json('has_checked_in'));
        $this->assertTrue($done->json('has_checked_out'));
        $this->assertSame([1, 2], array_column($done->json('attendances'), 'segment'));
    }

    public function test_today_on_a_blank_day_still_answers_one_schedule_object_with_hours(): void
    {
        Sanctum::actingAs($this->worker->user);
        $this->travelTo(Carbon::parse(self::BLANK_DAY.' 07:55:00'));

        $response = $this->getJson('/api/v1/attendance/today')->assertOk();

        $this->assertTodayShape($response);
        $this->assertNull($response->json('attendance'));
        $this->assertSame([], $response->json('attendances'));
        $this->assertNull($response->json('schedule.shift'));
        $this->assertFalse($response->json('schedule.is_day_off'));
    }

    public function test_today_on_a_two_schedule_day_keeps_one_schedule_and_one_attendance_object(): void
    {
        $evening = Shift::create(['company_id' => $this->company->id, 'division_id' => $this->division->id, 'name' => 'Malam Pendek', 'code' => 'MP', 'start_time' => '18:00', 'end_time' => '22:00']);
        ShiftSchedule::create(['employee_id' => $this->worker->id, 'date' => self::BLANK_DAY, 'sequence' => 1, 'shift_id' => $this->morning->id, 'company_location_id' => $this->outlet->id]);
        ShiftSchedule::create(['employee_id' => $this->worker->id, 'date' => self::BLANK_DAY, 'sequence' => 2, 'shift_id' => $evening->id, 'company_location_id' => $this->outlet->id]);
        Sanctum::actingAs($this->worker->user);

        $this->travelTo(Carbon::parse(self::BLANK_DAY.' 07:55:00'));
        $this->assertCheckShape($this->postJson('/api/v1/attendance/check-in')->assertOk());
        $this->travelTo(Carbon::parse(self::BLANK_DAY.' 17:00:00'));
        $this->assertCheckShape($this->postJson('/api/v1/attendance/check-out')->assertOk());

        $this->travelTo(Carbon::parse(self::BLANK_DAY.' 17:30:00'));
        $between = $this->getJson('/api/v1/attendance/today')->assertOk();
        $this->assertTodayShape($between);
        $this->assertFalse($between->json('has_checked_in'), 'The card resets to "Absen Masuk" for the second schedule.');
        $this->assertSame(['18:00', '22:00', 1, 1], [$between->json('schedule.start_time'), $between->json('schedule.end_time'), $between->json('schedule.segment'), $between->json('schedule.segment_count')]);

        $second = $this->postJson('/api/v1/attendance/check-in')->assertOk();
        $this->assertCheckShape($second);
        $this->assertSame([1, 2], [$second->json('attendance.segment'), $second->json('attendance.schedule_sequence')]);
        $this->travelTo(Carbon::parse(self::BLANK_DAY.' 22:00:00'));
        $this->postJson('/api/v1/attendance/check-out')->assertOk();

        $done = $this->getJson('/api/v1/attendance/today')->assertOk();
        $this->assertTodayShape($done);
        $this->assertTrue($done->json('has_checked_in'));
        $this->assertTrue($done->json('has_checked_out'));
        $this->assertCount(2, $done->json('attendances'));

        foreach ($done->json('attendances') as $attendance) {
            $this->assertAttendanceShape($attendance);
        }
    }

    public function test_a_cover_on_a_day_off_answers_the_legacy_shapes(): void
    {
        ShiftSchedule::create(['employee_id' => $this->worker->id, 'date' => self::BLANK_DAY, 'is_day_off' => true]);
        Sanctum::actingAs($this->worker->user);
        $this->travelTo(Carbon::parse(self::BLANK_DAY.' 07:55:00'));

        $before = $this->getJson('/api/v1/attendance/today')->assertOk();
        $this->assertTodayShape($before);
        $this->assertTrue($before->json('schedule.is_off_schedule'));
        $this->assertTrue($before->json('schedule.is_day_off'));

        $cover = $this->postJson('/api/v1/attendance/check-in', ['cover_shift_id' => $this->morning->id])->assertOk();
        $this->assertCheckShape($cover);
        $this->assertTrue($cover->json('requires_approval'));

        $after = $this->getJson('/api/v1/attendance/today')->assertOk();
        $this->assertTodayShape($after);
        $this->assertTrue($after->json('has_checked_in'));
        $this->assertTrue($after->json('schedule.is_off_schedule'));
        $this->assertAttendanceShape($after->json('attendance'));

        $this->getJson('/api/v1/attendance/cover-options')
            ->assertOk()
            ->assertJsonStructure(['date', 'is_off_schedule', 'off_schedule_reason', 'shifts', 'colleagues']);
    }

    public function test_check_in_and_check_out_without_a_schedule_id_answer_the_legacy_shape(): void
    {
        Sanctum::actingAs($this->worker->user);

        $checkIn = $this->postJson('/api/v1/attendance/check-in')->assertOk();
        $this->assertCheckShape($checkIn);
        $this->assertSame(self::SINGLE_DAY, $checkIn->json('attendance.date'));
        $this->assertSame(1, $checkIn->json('attendance.segment'));
        $this->assertNull($checkIn->json('attendance.check_out'));

        $this->postJson('/api/v1/attendance/check-in')
            ->assertStatus(409)
            ->assertJsonStructure(['message', 'errors']);

        $this->travelTo(Carbon::parse(self::SINGLE_DAY.' 17:05:00'));
        $checkOut = $this->postJson('/api/v1/attendance/check-out')->assertOk();
        $this->assertCheckShape($checkOut);
        $this->assertIsString($checkOut->json('attendance.check_out'));
        $this->assertSame($checkIn->json('attendance.id'), $checkOut->json('attendance.id'));
    }

    public function test_attendance_list_and_detail_keep_the_resource_keys(): void
    {
        Sanctum::actingAs($this->worker->user);
        $id = $this->postJson('/api/v1/attendance/check-in')->assertOk()->json('attendance.id');

        $list = $this->getJson('/api/v1/attendances?start_date='.self::SINGLE_DAY.'&end_date='.self::SPLIT_DAY)
            ->assertOk()
            ->assertJsonStructure(['data', 'pagination' => ['current_page', 'last_page', 'per_page', 'total']]);
        $this->assertCount(1, $list->json('data'));
        $this->assertAttendanceShape($list->json('data.0'));

        $detail = $this->getJson("/api/v1/attendances/{$id}")->assertOk();
        $this->assertAttendanceShape($detail->json('data'));
    }

    public function test_team_week_keeps_one_cell_object_per_day(): void
    {
        Sanctum::actingAs($this->coordinator);

        $response = $this->getJson('/api/v1/team/week?date='.self::SINGLE_DAY)
            ->assertOk()
            ->assertJsonStructure(['start', 'end', 'days', 'workers' => [['worker' => ['type', 'id', 'company_id', 'number', 'name'], 'days', 'can_edit']]]);

        $this->assertSame(self::SINGLE_DAY, $response->json('start'));
        $this->assertCount(7, $response->json('days'));
        $this->assertCount(1, $response->json('workers'));

        $days = $response->json('workers.0.days');
        $this->assertSame([self::SINGLE_DAY, self::SPLIT_DAY], array_keys($days), 'A blank day has no cell.');

        foreach ($days as $cell) {
            $this->assertIsArray($cell);
            $this->assertFalse(array_is_list($cell), 'A day holds one cell object, not a list of schedules.');

            foreach (['schedule_id', 'is_day_off', 'shift', 'location_id', 'outlet', 'section', 'borrowed_from'] as $key) {
                $this->assertArrayHasKey($key, $cell);
            }

            $this->assertIsInt($cell['schedule_id']);
            $this->assertIsBool($cell['is_day_off']);
            $this->assertIsArray($cell['shift']);

            foreach (['id', 'name', 'code', 'talenta_code', 'label', 'segments'] as $key) {
                $this->assertArrayHasKey($key, $cell['shift']);
            }
        }

        $this->assertCount(2, $days[self::SPLIT_DAY]['shift']['segments']);
        $this->assertTrue($response->json('workers.0.can_edit'));
    }

    public function test_team_roster_keeps_its_entry_shape_states_and_counts(): void
    {
        Sanctum::actingAs($this->worker->user);
        $this->postJson('/api/v1/attendance/check-in')->assertOk();

        Sanctum::actingAs($this->coordinator);
        $response = $this->getJson('/api/v1/team/roster?date='.self::SINGLE_DAY)
            ->assertOk()
            ->assertJsonStructure(['date', 'outlets' => [['location' => ['id', 'name'], 'counts', 'entries']]]);

        $this->assertSame(self::SINGLE_DAY, $response->json('date'));
        $outlet = $response->json('outlets.0');

        foreach (['scheduled', ...array_keys(OutletRosterService::STATE_LABELS), 'cuti'] as $key) {
            $this->assertArrayHasKey($key, $outlet['counts']);
            $this->assertIsInt($outlet['counts'][$key]);
        }

        $this->assertCount(1, $outlet['entries']);
        $entry = $outlet['entries'][0];

        foreach (['schedule_id', 'worker', 'shift', 'location_id', 'borrowed_from', 'section', 'state', 'check_in', 'check_out', 'late_minutes', 'segments', 'attendances', 'can_edit'] as $key) {
            $this->assertArrayHasKey($key, $entry);
        }

        $this->assertContains($entry['state'], self::ROSTER_STATES);
        $this->assertSame('hadir', $entry['state']);
        $this->assertIsInt($entry['schedule_id']);
        $this->assertIsInt($entry['late_minutes']);
        $this->assertIsBool($entry['can_edit']);
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $entry['check_in']);
        $this->assertNull($entry['check_out']);
        $this->assertSame(['type' => 'employee', 'id' => $this->worker->id], array_intersect_key($entry['worker'], array_flip(['type', 'id'])));

        foreach (['id', 'name', 'code', 'label', 'start_time', 'end_time'] as $key) {
            $this->assertArrayHasKey($key, $entry['shift']);
        }

        $this->assertTrue(array_is_list($entry['attendances']));

        foreach (['segment', 'check_in', 'check_out', 'late_minutes'] as $key) {
            $this->assertArrayHasKey($key, $entry['attendances'][0]);
        }

        // The split-shift and the blank day answer the same shape; a blank day lists no entry for the worker.
        $this->travelTo(Carbon::parse(self::BLANK_DAY.' 09:00:00'));
        $split = $this->getJson('/api/v1/team/roster?date='.self::SPLIT_DAY)->assertOk();
        $this->assertContains($split->json('outlets.0.entries.0.state'), self::ROSTER_STATES);
        $this->assertCount(2, $split->json('outlets.0.entries.0.segments'));
        $this->assertSame([], $this->getJson('/api/v1/team/roster?date='.self::BLANK_DAY)->assertOk()->json('outlets'));
    }

    public function test_team_recap_rows_keep_their_keys_and_types(): void
    {
        Sanctum::actingAs($this->worker->user);
        $this->postJson('/api/v1/attendance/check-in')->assertOk();
        $this->travelTo(Carbon::parse(self::SINGLE_DAY.' 17:00:00'));
        $this->postJson('/api/v1/attendance/check-out')->assertOk();

        Sanctum::actingAs($this->coordinator);
        $this->travelTo(Carbon::parse(self::BLANK_DAY.' 09:00:00'));
        $response = $this->getJson('/api/v1/team/recap?month=2026-11')->assertOk()->assertJsonPath('month', '2026-11');

        $rows = $response->json('data');
        $this->assertTrue(array_is_list($rows));
        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            foreach (self::RECAP_KEYS as $key) {
                $this->assertArrayHasKey($key, $row);
            }

            foreach (['type', 'id', 'number', 'name', 'company', 'division'] as $key) {
                $this->assertArrayHasKey($key, $row['worker']);
            }

            foreach (['scheduled_days', 'present_days', 'pending_days', 'late_days', 'late_minutes', 'early_leave_days', 'early_leave_minutes', 'overtime_count', 'absent_days', 'off_days'] as $key) {
                $this->assertIsInt($row[$key], $key);
            }

            foreach (['overtime_hours', 'scheduled_hours', 'worked_hours'] as $key) {
                $this->assertIsNumeric($row[$key], $key);
            }

            // Additions come after the released keys, never between or instead of them.
            $this->assertSame(['worker', 'outlet', ...array_diff(self::RECAP_KEYS, ['worker', 'outlet', 'overtime_count', 'overtime_hours']), 'overtime_count', 'overtime_hours', ...self::RECAP_ADDED_KEYS], array_keys($row));

            foreach (self::RECAP_ADDED_KEYS as $key) {
                $this->assertIsNumeric($row[$key], $key);
            }
        }

        // The total is new: the same figures without worker and outlet, in distinct worker-days.
        $this->assertSame(array_values(array_diff(array_keys($rows[0]), ['worker', 'outlet'])), array_keys($response->json('total')));

        $outletRow = collect($rows)->firstWhere('outlet', 'Momoyo Ciledug');
        $this->assertSame([2, 1, 1], [$outletRow['scheduled_days'], $outletRow['present_days'], $outletRow['absent_days']]);

        $this->getJson('/api/v1/team/recap/link?month=2026-11')->assertOk()->assertJsonStructure(['url', 'expires_in']);
    }

    public function test_laporan_absensi_keeps_its_summary_keys(): void
    {
        Sanctum::actingAs($this->worker->user);
        $this->postJson('/api/v1/attendance/check-in')->assertOk();

        Sanctum::actingAs($this->coordinator);
        $response = $this->getJson('/api/v1/laporan-absensi?start_date='.self::SINGLE_DAY.'&end_date='.self::BLANK_DAY)
            ->assertOk()
            ->assertJsonStructure([
                'start_date', 'end_date',
                'summary' => ['total_present', 'total_late', 'total_leave', 'total_absent'],
                'data',
                'pagination' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);

        foreach (['total_present', 'total_late', 'total_leave', 'total_absent'] as $key) {
            $this->assertIsInt($response->json("summary.{$key}"), $key);
        }

        // Added by the attendance ledger, after the four keys the released app reads.
        $this->assertSame(['total_present', 'total_late', 'total_leave', 'total_absent', 'total_pending', 'total_missing_check_out', 'total_worked_hours', 'total_overtime_hours'], array_keys($response->json('summary')));
        $this->assertIsInt($response->json('summary.total_pending'));
        $this->assertIsInt($response->json('summary.total_missing_check_out'));
        $this->assertIsNumeric($response->json('summary.total_worked_hours'));
        $this->assertIsNumeric($response->json('summary.total_overtime_hours'));

        $this->assertSame(1, $response->json('summary.total_present'));
        $this->assertCount(1, $response->json('data'));
        $this->assertAttendanceShape($response->json('data.0'));
    }

    public function test_overtime_requests_keep_their_keys_and_accept_the_legacy_payload(): void
    {
        Sanctum::actingAs($this->worker->user);

        $legacyKeys = [
            'id', 'employee_id', 'intern_id', 'freelancer_id', 'date', 'start_time', 'end_time', 'duration_minutes',
            'actual_duration_minutes', 'reason', 'status', 'current_step', 'total_steps', 'rejection_reason', 'rejection_note',
            'created_at', 'updated_at', 'employee', 'intern', 'freelancer', 'user',
        ];

        $created = $this->postJson('/api/v1/overtime-requests', ['date' => self::SINGLE_DAY, 'start_time' => '17:00', 'end_time' => '19:00', 'reason' => 'Stok opname'])
            ->assertCreated()
            ->assertJsonPath('data.duration_minutes', 120)
            ->assertJsonPath('data.status', 'pending')
            ->json('data');

        $listed = $this->getJson('/api/v1/overtime-requests')->assertOk()->json('data.0');

        foreach ([$created, $listed] as $overtime) {
            $this->assertSame([], array_values(array_diff($legacyKeys, array_keys($overtime))), 'A key the released app reads is missing.');
            // Added: what the request counts for once the company nets requests against shift hours.
            $this->assertSame(['counted_minutes'], array_values(array_diff(array_keys($overtime), $legacyKeys)));
            $this->assertNull($overtime['counted_minutes']);
        }
    }

    public function test_corrections_and_leave_keep_their_keys_and_accept_the_legacy_payload(): void
    {
        $requestBase = ['id', 'employee_id', 'intern_id', 'freelancer_id', 'reason', 'status', 'current_step', 'rejection_reason', 'created_at', 'updated_at', 'employee', 'intern', 'freelancer', 'user'];
        $this->travelTo(Carbon::parse(self::BLANK_DAY.' 12:00:00'));
        Sanctum::actingAs($this->worker->user);

        $correctionKeys = [...$requestBase, 'date', 'corrected_check_in', 'corrected_check_out', 'total_steps', 'attachment'];
        $id = $this->postJson('/api/v1/attendance-corrections', ['date' => self::SINGLE_DAY, 'corrected_check_in' => '08:00', 'corrected_check_out' => '17:00', 'reason' => 'Lupa absen'])
            ->assertCreated()
            ->json('data.id');

        foreach ([
            $this->getJson("/api/v1/attendance-corrections/{$id}")->assertOk()->json('data'),
            $this->getJson('/api/v1/attendance-corrections')->assertOk()->json('data.0'),
        ] as $correction) {
            $this->assertSame([], array_values(array_diff($correctionKeys, array_keys($correction))), 'A correction key the released app reads is missing.');
            $this->assertSame(self::SINGLE_DAY, substr((string) $correction['date'], 0, 10));
            $this->assertIsString($correction['corrected_check_in']);
            $this->assertIsString($correction['corrected_check_out']);
            $this->assertIsInt($correction['total_steps']);
        }

        $leaveKeys = [...$requestBase, 'leave_type', 'leave_type_label', 'start_date', 'end_date', 'days_count', 'total_steps', 'attachment'];
        $leaveId = $this->postJson('/api/v1/leave-requests', ['leave_type' => 'sick', 'start_date' => '2026-11-19', 'end_date' => '2026-11-19', 'reason' => 'Sakit'])
            ->assertCreated()
            ->json('data.id');

        foreach ([
            $this->getJson("/api/v1/leave-requests/{$leaveId}")->assertOk()->json('data'),
            $this->getJson('/api/v1/leave-requests')->assertOk()->json('data.0'),
        ] as $leave) {
            $this->assertSame([], array_values(array_diff($leaveKeys, array_keys($leave))), 'A leave key the released app reads is missing.');
            $this->assertIsInt($leave['days_count']);
            $this->assertIsString($leave['leave_type_label']);
        }
    }

    public function test_cover_option_entries_and_an_unclosed_previous_day_keep_their_keys(): void
    {
        $colleague = Employee::create([
            'user_id' => User::factory()->create(['is_active' => true])->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_number' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Crew'])->id,
            'nip' => 'PF-002',
            'full_name' => 'Rekan Crew',
            'join_date' => '2026-01-01',
            'status' => 'permanent',
        ]);
        ShiftSchedule::create(['employee_id' => $colleague->id, 'date' => self::BLANK_DAY, 'shift_id' => $this->morning->id, 'company_location_id' => $this->outlet->id]);

        // Checked in on Monday and never out.
        Sanctum::actingAs($this->worker->user);
        $this->postJson('/api/v1/attendance/check-in')->assertOk();

        $this->travelTo(Carbon::parse(self::BLANK_DAY.' 07:50:00'));
        $options = $this->getJson('/api/v1/attendance/cover-options')
            ->assertOk()
            ->assertJsonStructure([
                'shifts' => [['id', 'name', 'start_time', 'end_time']],
                'colleagues' => [['key', 'name', 'type', 'shift' => ['id', 'name', 'start_time', 'end_time'], 'outlet' => ['id', 'name'], 'has_checked_in']],
            ]);
        $this->assertSame('employee:'.$colleague->id, $options->json('colleagues.0.key'));
        $this->assertIsBool($options->json('colleagues.0.has_checked_in'));

        $today = $this->getJson('/api/v1/attendance/today')
            ->assertOk()
            ->assertJsonStructure(['unclosed_previous' => ['date', 'check_in', 'shift', 'correction_pending']]);
        $this->assertSame(self::SINGLE_DAY, $today->json('unclosed_previous.date'));
        $this->assertSame('07:55', $today->json('unclosed_previous.check_in'));
        $this->assertIsBool($today->json('unclosed_previous.correction_pending'));
    }

    public function test_changing_a_schedule_without_a_schedule_id_works_as_before(): void
    {
        Sanctum::actingAs($this->coordinator);
        $change = ['worker_type' => 'employee', 'worker_id' => $this->worker->id, 'date' => self::BLANK_DAY];

        $saved = $this->putJson('/api/v1/team/schedules', $change + ['mode' => 'shift', 'shift_id' => $this->morning->id, 'company_location_id' => $this->outlet->id, 'section' => 'Kasir'])
            ->assertOk()
            ->assertJsonStructure(['message', 'data' => ['id', 'date', 'is_day_off', 'shift_id', 'company_location_id', 'section']]);

        $this->assertIsInt($saved->json('data.id'));
        $this->assertSame([self::BLANK_DAY, false, $this->morning->id, $this->outlet->id, 'Kasir'], [$saved->json('data.date'), $saved->json('data.is_day_off'), $saved->json('data.shift_id'), $saved->json('data.company_location_id'), $saved->json('data.section')]);

        $off = $this->putJson('/api/v1/team/schedules', $change + ['mode' => 'off'])->assertOk();
        $this->assertSame([$saved->json('data.id'), true, null, null], [$off->json('data.id'), $off->json('data.is_day_off'), $off->json('data.shift_id'), $off->json('data.company_location_id')]);

        $this->putJson('/api/v1/team/schedules', $change + ['mode' => 'clear'])
            ->assertOk()
            ->assertJsonPath('data', null);
        $this->assertFalse(ShiftSchedule::query()->where('employee_id', $this->worker->id)->whereDate('date', self::BLANK_DAY)->exists());

        $this->putJson('/api/v1/team/schedules', $change + ['mode' => 'shift'])
            ->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors' => ['shift_id']]);

        $this->postJson('/api/v1/team/schedules/copy', ['from' => self::SINGLE_DAY, 'to' => '2026-11-23'])
            ->assertOk()
            ->assertJsonStructure(['message', 'data' => ['copied', 'skipped_existing', 'skipped_unavailable', 'skipped_ended', 'from', 'to']])
            ->assertJsonPath('data.copied', 2);
    }

    public function test_week_roster_and_id_less_changes_on_a_two_schedule_day_keep_the_legacy_shapes(): void
    {
        $evening = Shift::create(['company_id' => $this->company->id, 'division_id' => $this->division->id, 'name' => 'Malam Pendek', 'code' => 'MP', 'start_time' => '18:00', 'end_time' => '22:00']);
        $second = ShiftSchedule::create(['employee_id' => $this->worker->id, 'date' => self::BLANK_DAY, 'sequence' => 2, 'shift_id' => $evening->id, 'company_location_id' => $this->outlet->id]);
        $first = ShiftSchedule::create(['employee_id' => $this->worker->id, 'date' => self::BLANK_DAY, 'sequence' => 1, 'shift_id' => $this->morning->id, 'company_location_id' => $this->outlet->id]);
        Sanctum::actingAs($this->coordinator);

        // Week: still one cell object per day, the primary (earliest) schedule.
        $cell = $this->getJson('/api/v1/team/week?date='.self::BLANK_DAY)->assertOk()->json('workers.0.days.'.self::BLANK_DAY);
        $this->assertFalse(array_is_list($cell));
        $this->assertSame($first->id, $cell['schedule_id']);
        $this->assertIsBool($cell['is_day_off']);
        $this->assertIsArray($cell['shift']);

        // Roster: each schedule is an entry of the known shape and states.
        $this->travelTo(Carbon::parse(self::BLANK_DAY.' 09:00:00'));
        $entries = collect($this->getJson('/api/v1/team/roster?date='.self::BLANK_DAY)->assertOk()->json('outlets.0.entries'));
        $this->assertSame([$first->id, $second->id], $entries->pluck('schedule_id')->all());

        foreach ($entries as $entry) {
            foreach (['schedule_id', 'worker', 'shift', 'location_id', 'borrowed_from', 'section', 'state', 'check_in', 'check_out', 'late_minutes', 'segments', 'attendances', 'can_edit'] as $key) {
                $this->assertArrayHasKey($key, $entry);
            }

            $this->assertContains($entry['state'], self::ROSTER_STATES);
        }

        // PUT without schedule_id: a shift changes the primary row and answers the legacy data object.
        $change = ['worker_type' => 'employee', 'worker_id' => $this->worker->id, 'date' => self::BLANK_DAY];
        $saved = $this->putJson('/api/v1/team/schedules', $change + ['mode' => 'shift', 'shift_id' => $this->morning->id, 'company_location_id' => $this->outlet->id, 'section' => 'Kasir'])
            ->assertOk()
            ->assertJsonStructure(['message', 'data' => ['id', 'date', 'is_day_off', 'shift_id', 'company_location_id', 'section']]);
        $this->assertSame([$first->id, 'Kasir'], [$saved->json('data.id'), $saved->json('data.section')]);

        // A day off or clearing is refused with the legacy error shape, and nothing is removed.
        foreach (['off', 'clear'] as $mode) {
            $this->putJson('/api/v1/team/schedules', $change + ['mode' => $mode])
                ->assertUnprocessable()
                ->assertJsonStructure(['message', 'errors' => ['date']]);
        }

        $this->assertSame(2, ShiftSchedule::query()->where('employee_id', $this->worker->id)->whereDate('date', self::BLANK_DAY)->count());
    }

    /**
     * The home card of the released app: one schedule object with non-null hour strings and one
     * attendance object (or null), never a list.
     */
    private function assertTodayShape(TestResponse $response): void
    {
        $response->assertJsonStructure([
            'date', 'is_on_leave', 'has_checked_in', 'has_checked_out', 'attendance', 'attendances',
            'schedule' => ['shift', 'is_day_off', 'segment', 'segment_count', 'segments', 'start_time', 'end_time', 'late_tolerance_minutes', 'outlet', 'section', 'is_off_schedule'],
            'check_out_outlet', 'unclosed_previous',
        ]);

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $response->json('date'));
        $this->assertIsBool($response->json('is_on_leave'));
        $this->assertIsBool($response->json('has_checked_in'));
        $this->assertIsBool($response->json('has_checked_out'));
        $this->assertTrue(array_is_list($response->json('attendances')));

        $attendance = $response->json('attendance');
        $this->assertTrue($attendance === null || (is_array($attendance) && ! array_is_list($attendance)), 'attendance is one object or null.');

        $schedule = $response->json('schedule');
        $this->assertFalse(array_is_list($schedule), 'schedule is one object.');
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $schedule['start_time']);
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $schedule['end_time']);
        $this->assertIsBool($schedule['is_day_off']);
        $this->assertIsBool($schedule['is_off_schedule']);
        $this->assertContains($schedule['segment'], [1, 2]);
        $this->assertIsInt($schedule['segment_count']);
        $this->assertIsInt($schedule['late_tolerance_minutes']);
        $this->assertTrue(array_is_list($schedule['segments']));

        if ($schedule['shift'] !== null) {
            foreach (['id', 'name', 'code', 'talenta_code'] as $key) {
                $this->assertArrayHasKey($key, $schedule['shift']);
            }
        }

        if ($schedule['outlet'] !== null) {
            foreach (['id', 'name', 'latitude', 'longitude', 'radius_meters'] as $key) {
                $this->assertArrayHasKey($key, $schedule['outlet']);
            }
        }
    }

    /**
     * @param  array<string, mixed>|null  $attendance
     */
    private function assertAttendanceShape(?array $attendance): void
    {
        $this->assertIsArray($attendance);

        foreach (self::ATTENDANCE_KEYS as $key) {
            $this->assertArrayHasKey($key, $attendance);
        }

        $this->assertIsInt($attendance['id']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $attendance['date']);
        $this->assertContains($attendance['segment'], [1, 2]);
        $this->assertIsString($attendance['check_in']);
        $this->assertIsString($attendance['status']);
        $this->assertIsBool($attendance['is_corrected']);
        $this->assertIsBool($attendance['is_off_schedule']);
        $this->assertIsInt($attendance['late_minutes']);
        $this->assertIsArray($attendance['employee']);
        $this->assertIsArray($attendance['user']);

        // Additions of the attendance ledger: after the released keys, each with one type.
        $this->assertSame([...self::ATTENDANCE_KEYS, ...self::ATTENDANCE_ADDED_KEYS], array_keys($attendance));
        $this->assertIsInt($attendance['schedule_sequence']);
        $this->assertIsInt($attendance['worked_minutes']);
        $this->assertIsInt($attendance['overtime_minutes']);
        $this->assertIsInt($attendance['reported_late_minutes']);
        $this->assertIsBool($attendance['is_missing_check_out']);
        $this->assertSame($attendance['late_minutes'], $attendance['reported_late_minutes'], 'The same number once the check-in is settled; late_minutes stays the raw column.');

        foreach (['shift' => ['id', 'name', 'code'], 'outlet' => ['id', 'name']] as $key => $fields) {
            if ($attendance[$key] !== null) {
                $this->assertSame($fields, array_keys($attendance[$key]), $key);
            }
        }

        foreach (['scheduled_start', 'scheduled_end'] as $key) {
            if ($attendance[$key] !== null) {
                $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $attendance[$key], $key);
            }
        }
    }

    private function assertCheckShape(TestResponse $response): void
    {
        $response->assertJsonStructure(['message', 'attendance', 'requires_approval', 'approval_reasons']);

        $this->assertIsString($response->json('message'));
        $this->assertIsBool($response->json('requires_approval'));
        $this->assertIsArray($response->json('approval_reasons'));
        $this->assertAttendanceShape($response->json('attendance'));
    }
}
