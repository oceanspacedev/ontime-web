<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\ShiftScheduleChange;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalFlowService;
use App\Services\Attendance\LedgerQuery;
use App\Services\AttendanceCheckService;
use App\Services\AttendanceLatenessService;
use App\Services\AttendanceLedger;
use App\Services\OffScheduleCheckInService;
use App\Services\SessionResolver;
use App\Services\WorkScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * D1: one worker attends several schedules a day (Luuca 08:00 - 15:00, then JChicken 16:00 - 22:00), each with
 * its own outlet, check-in and check-out; a tap lands on the session its time belongs to; the today payload
 * follows the session the worker is on; and a cover always records an outlet.
 */
class MultiSessionAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-11-16';

    private const AT_LUUCA = ['latitude' => -6.2301, 'longitude' => 106.7120];

    private const AT_JCHICKEN = ['latitude' => -6.2365, 'longitude' => 106.7150];

    private const AT_MOMOYO = ['latitude' => -6.2285, 'longitude' => 106.7050];

    private Company $company;

    private Division $division;

    private CompanyLocation $luuca;

    private CompanyLocation $jchicken;

    private CompanyLocation $momoyo;

    private Shift $luucaDay;

    private Shift $jchickenEvening;

    private Shift $split;

    private Freelancer $bayu;

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

        $this->travelTo(Carbon::parse(self::MONDAY.' 06:00:00'));

        $tenant = Tenant::factory()->create(['name' => 'Klien Ciledug', 'code' => 'KC', 'is_active' => true]);
        $this->company = new Company(['name' => 'PT Franchise', 'code' => 'PFC', 'is_active' => true]);
        $this->company->tenant_id = $tenant->id;
        $this->company->save();
        app(ApprovalFlowService::class)->createDefaultFlows($this->company);
        $this->company->policy()->update([
            'require_gps' => true,
            'geofence_radius_meters' => 100,
            'require_photo' => false,
            'require_face_recognition' => false,
            'require_liveness' => false,
            'late_tolerance_minutes' => 0,
        ]);
        $this->company->unsetRelation('policy');

        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Ciledug', 'code' => 'CLD', 'is_active' => true]);
        $this->luuca = $this->outlet('Luuca Ciledug', self::AT_LUUCA);
        $this->jchicken = $this->outlet('JChicken Ciledug', self::AT_JCHICKEN);
        $this->momoyo = $this->outlet('Momoyo Ciledug', self::AT_MOMOYO);
        $this->luucaDay = $this->shift('Luuca Pagi', '08:00', '15:00');
        $this->jchickenEvening = $this->shift('JChicken Sore', '16:00', '22:00');
        $this->split = $this->shift('Split', '07:45', '13:00', '17:45', '22:00');
        $this->bayu = $this->freelancer('Bayu');
    }

    // ── Two schedules, two outlets ──────────────────────────────────────────

    public function test_two_schedules_of_one_day_are_two_check_ins_each_geofenced_to_its_own_outlet(): void
    {
        [$morning, $evening] = $this->twoOutletDay();

        $this->at('07:55:00');
        $first = $this->checkIn($this->bayu, self::AT_LUUCA)->assertOk()->assertJsonPath('requires_approval', false);
        $this->at('15:10:00');
        $this->checkOut($this->bayu, self::AT_LUUCA)->assertOk()->assertJsonPath('attendance.id', $first->json('attendance.id'));

        $this->at('15:50:00');
        $second = $this->checkIn($this->bayu, self::AT_JCHICKEN)->assertOk()->assertJsonPath('requires_approval', false);
        $this->at('22:00:00');
        $this->checkOut($this->bayu, self::AT_JCHICKEN)->assertOk()->assertJsonPath('attendance.id', $second->json('attendance.id'));

        $rows = $this->rows();
        $this->assertSame([[1, 1, $morning->id, $this->luuca->id], [2, 1, $evening->id, $this->jchicken->id]], $rows->map(fn (Attendance $row): array => [(int) $row->schedule_sequence, (int) $row->segment, (int) $row->shift_schedule_id, (int) $row->company_location_id])->all());
        $this->assertSame([AttendanceStatus::OnTime, AttendanceStatus::OnTime], $rows->pluck('status')->all());
        $this->assertSame(['Luuca Ciledug', 'JChicken Ciledug'], $rows->pluck('sched_outlet_name')->all());
        $this->assertTrue($rows->every(fn (Attendance $row): bool => $row->check_out !== null));
    }

    public function test_the_second_session_is_geofenced_against_its_own_outlet(): void
    {
        $this->twoOutletDay();

        $this->at('15:50:00');
        $this->checkIn($this->bayu, self::AT_LUUCA)
            ->assertOk()
            ->assertJsonPath('requires_approval', true)
            ->assertJsonPath('approval_reasons.0', 'di luar area outlet jadwal (JChicken Ciledug)')
            ->assertJsonPath('attendance.schedule_sequence', 2);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function firstSessionCheckOuts(): array
    {
        return [
            'shortly after its end' => ['16:00', '15:10:00'],
            'just before the next session' => ['16:00', '15:45:00'],
            'with a 30-minute gap, after the next one started' => ['15:30', '15:45:00'],
        ];
    }

    #[DataProvider('firstSessionCheckOuts')]
    public function test_the_first_session_is_checked_out_after_its_end_whatever_follows(string $nextStart, string $checkOutAt): void
    {
        $next = $this->shift('JChicken Sambung', $nextStart, '22:00');
        $this->schedule($this->bayu, 1, $this->luucaDay, $this->luuca);
        $this->schedule($this->bayu, 2, $next, $this->jchicken);

        $this->at('07:58:00');
        $id = $this->checkIn($this->bayu, self::AT_LUUCA)->assertOk()->json('attendance.id');

        $this->at($checkOutAt);
        $this->checkOut($this->bayu, self::AT_LUUCA)
            ->assertOk()
            ->assertJsonPath('attendance.id', $id)
            ->assertJsonPath('requires_approval', false);
        $this->assertSame(0, (int) Attendance::findOrFail($id)->early_leave_minutes);
    }

    public function test_an_open_first_session_does_not_block_the_next_one_after_its_end_and_becomes_a_missing_check_out(): void
    {
        $this->twoOutletDay();

        $this->at('07:55:00');
        $first = $this->checkIn($this->bayu, self::AT_LUUCA)->assertOk()->json('attendance.id');

        $this->at('15:50:00');
        $this->checkIn($this->bayu, self::AT_JCHICKEN)->assertOk()->assertJsonPath('attendance.schedule_sequence', 2);

        $service = app(AttendanceCheckService::class);
        $this->assertFalse($service->isLiveClosable(Attendance::findOrFail($first)));
        $this->getJson('/api/v1/attendances/'.$first)->assertOk()->assertJsonPath('data.is_missing_check_out', true);

        $this->at('22:00:00');
        $this->checkOut($this->bayu, self::AT_JCHICKEN)->assertOk()->assertJsonPath('attendance.schedule_sequence', 2);
        $this->assertNull(Attendance::findOrFail($first)->check_out);
    }

    public function test_a_check_in_for_the_next_session_while_the_first_still_runs_is_refused(): void
    {
        $this->twoOutletDay();

        $this->at('07:55:00');
        $this->checkIn($this->bayu, self::AT_LUUCA)->assertOk();

        $this->at('14:30:00');
        $this->checkIn($this->bayu, self::AT_JCHICKEN)
            ->assertStatus(409)
            ->assertJsonPath('message', AttendanceCheckService::ALREADY_CHECKED_IN_MESSAGE);
        $this->assertCount(1, $this->rows());
    }

    public function test_arriving_only_for_the_second_session_leaves_the_first_unworked_on_a_present_day(): void
    {
        $this->twoOutletDay();

        $this->at('15:50:00');
        $this->checkIn($this->bayu, self::AT_JCHICKEN)->assertOk()->assertJsonPath('attendance.schedule_sequence', 2)->assertJsonPath('attendance.late_minutes', 0);
        $this->at('22:00:00');
        $this->checkOut($this->bayu, self::AT_JCHICKEN)->assertOk();

        $this->travelTo(Carbon::parse('2026-11-17 09:00:00'));
        $summary = app(AttendanceLedger::class)->summary(LedgerQuery::forWorkers(['freelancer:'.$this->bayu->id], self::MONDAY, self::MONDAY));

        $this->assertSame([1, 0, 2, 1, 1], [$summary['present_days'], $summary['absent_days'], $summary['sessions_scheduled'], $summary['sessions_worked'], $summary['sessions_missed']]);
    }

    public function test_a_rejected_session_of_a_present_day_counts_as_missed(): void
    {
        $this->twoOutletDay();

        $this->at('07:55:00');
        $first = $this->checkIn($this->bayu, self::AT_JCHICKEN)->assertOk()->assertJsonPath('requires_approval', true)->json('attendance.id');
        $this->at('09:00:00');
        app(ApprovalFlowService::class)->rejectStep(Attendance::findOrFail($first), $this->approver(), 'Bukan di outlet');
        $this->at('15:50:00');
        $this->checkIn($this->bayu, self::AT_JCHICKEN)->assertOk()->assertJsonPath('attendance.schedule_sequence', 2);
        $this->at('22:00:00');
        $this->checkOut($this->bayu, self::AT_JCHICKEN)->assertOk();

        $this->travelTo(Carbon::parse('2026-11-17 09:00:00'));
        $summary = app(AttendanceLedger::class)->summary(LedgerQuery::forWorkers(['freelancer:'.$this->bayu->id], self::MONDAY, self::MONDAY));

        $this->assertSame([1, 0, 2, 1, 1], [$summary['present_days'], $summary['absent_days'], $summary['sessions_scheduled'], $summary['sessions_worked'], $summary['sessions_missed']]);
    }

    public function test_a_double_tap_is_refused(): void
    {
        $this->twoOutletDay();

        $this->at('07:55:00');
        $this->checkIn($this->bayu, self::AT_LUUCA)->assertOk();
        $this->checkIn($this->bayu, self::AT_LUUCA)->assertStatus(409);

        $this->assertCount(1, $this->rows());
    }

    // ── Choosing the session ────────────────────────────────────────────────

    public function test_a_named_schedule_is_honoured_and_another_workers_schedule_is_refused(): void
    {
        [$morning] = $this->twoOutletDay();
        $colleague = $this->freelancer('Citra');
        $foreign = $this->schedule($colleague, 1, $this->luucaDay, $this->luuca);

        // Left unattended in the morning: without a choice 15:30 lands on JChicken, with one on Luuca (late).
        $this->at('15:30:00');
        $this->checkIn($this->bayu, self::AT_LUUCA + ['schedule_id' => $foreign->id])
            ->assertUnprocessable()
            ->assertJsonPath('message', AttendanceCheckService::UNKNOWN_SCHEDULE_MESSAGE)
            ->assertJsonStructure(['errors' => ['schedule_id']]);

        $this->checkIn($this->bayu, self::AT_LUUCA + ['schedule_id' => $morning->id])
            ->assertOk()
            ->assertJsonPath('attendance.schedule_sequence', 1)
            ->assertJsonPath('attendance.schedule_id', $morning->id)
            ->assertJsonPath('attendance.late_minutes', 450);

        $this->checkIn($this->bayu, self::AT_LUUCA + ['schedule_id' => $morning->id])->assertStatus(409);
    }

    public function test_a_named_schedule_is_not_checked_in_before_it_opens(): void
    {
        [, $evening] = $this->twoOutletDay();

        $this->at('07:55:00');
        $this->checkIn($this->bayu, self::AT_JCHICKEN + ['schedule_id' => $evening->id])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Shift dimulai pukul 16:00. Absen masuk dibuka pukul 15:00.');
    }

    public function test_a_split_shift_tapped_only_for_its_second_part_books_the_second_part_on_time(): void
    {
        $this->schedule($this->bayu, 1, $this->split, $this->momoyo);

        $this->at('17:30:00');
        $this->checkIn($this->bayu, self::AT_MOMOYO)
            ->assertOk()
            ->assertJsonPath('attendance.segment', 2)
            ->assertJsonPath('attendance.status', 'on_time')
            ->assertJsonPath('attendance.late_minutes', 0);

        $this->assertCount(1, $this->rows());
    }

    public function test_a_split_shift_tapped_between_its_parts_says_when_the_second_part_opens(): void
    {
        $this->schedule($this->bayu, 1, $this->split, $this->momoyo);

        $this->at('14:00:00');
        $this->checkIn($this->bayu, self::AT_MOMOYO)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Segmen kedua dimulai pukul 17:45. Check in dibuka pukul 16:45.');
        $this->assertCount(0, $this->rows());
    }

    // ── Today ───────────────────────────────────────────────────────────────

    /**
     * After midnight the card describes last night's shift (its business date is yesterday): the server says
     * whether that date is scheduled (is_session_scheduled, with session_date), next to is_scheduled for the
     * calendar day, so the app does not show "Tidak ada jadwal hari ini" over last night's hours.
     */
    public function test_today_says_whether_the_business_date_of_the_current_session_is_scheduled(): void
    {
        $night = $this->shift('Malam', '22:00', '06:00');
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-15', 'shift_id' => $night->id, 'company_location_id' => $this->luuca->id]);
        Sanctum::actingAs($this->bayu->user);

        $this->at('00:30:00');
        $this->getJson('/api/v1/attendance/today')
            ->assertOk()
            ->assertJsonPath('date', self::MONDAY)
            ->assertJsonPath('is_scheduled', false)
            ->assertJsonPath('session_date', '2026-11-15')
            ->assertJsonPath('is_session_scheduled', true)
            ->assertJsonPath('schedule.start_time', '22:00')
            ->assertJsonPath('has_checked_in', false);

        // Later in the day nothing is left of last night: both answers are about today.
        $this->at('12:00:00');
        $this->getJson('/api/v1/attendance/today')
            ->assertOk()
            ->assertJsonPath('session_date', self::MONDAY)
            ->assertJsonPath('is_session_scheduled', false)
            ->assertJsonPath('is_scheduled', false);
    }

    public function test_today_follows_the_session_the_worker_is_on_and_lists_every_schedule(): void
    {
        [$morning, $evening] = $this->twoOutletDay();
        Sanctum::actingAs($this->bayu->user);

        $this->at('07:30:00');
        $this->getJson('/api/v1/attendance/today')
            ->assertOk()
            ->assertJsonPath('schedule.start_time', '08:00')
            ->assertJsonPath('schedule.outlet.id', $this->luuca->id)
            ->assertJsonPath('is_scheduled', true)
            ->assertJsonPath('schedules.0.schedule_id', $morning->id)
            ->assertJsonPath('schedules.0.is_current', true)
            ->assertJsonPath('schedules.0.state', 'nanti')
            ->assertJsonPath('schedules.1.schedule_id', $evening->id)
            ->assertJsonPath('schedules.1.sequence', 2)
            ->assertJsonPath('schedules.1.is_current', false);

        $this->at('07:55:00');
        $this->checkIn($this->bayu, self::AT_LUUCA)->assertOk();
        $this->at('15:05:00');
        $this->checkOut($this->bayu, self::AT_LUUCA)->assertOk();

        $this->at('15:20:00');
        $this->getJson('/api/v1/attendance/today')
            ->assertOk()
            ->assertJsonPath('has_checked_in', false)
            ->assertJsonPath('schedule.start_time', '16:00')
            ->assertJsonPath('schedule.end_time', '22:00')
            ->assertJsonPath('schedule.outlet.id', $this->jchicken->id)
            ->assertJsonPath('schedules.0.state', 'pulang')
            ->assertJsonPath('schedules.1.is_current', true)
            ->assertJsonPath('day_totals.worked_minutes', 430)
            ->assertJsonCount(1, 'attendances');

        $this->checkIn($this->bayu, self::AT_JCHICKEN)->assertOk();
        $this->at('22:00:00');
        $this->checkOut($this->bayu, self::AT_JCHICKEN)->assertOk();

        $this->getJson('/api/v1/attendance/today')
            ->assertOk()
            ->assertJsonPath('has_checked_in', true)
            ->assertJsonPath('has_checked_out', true)
            ->assertJsonPath('attendance.schedule_sequence', 2)
            ->assertJsonPath('day_totals.worked_minutes', 430 + 400)
            ->assertJsonCount(2, 'attendances');
    }

    public function test_an_unworked_earlier_session_does_not_hold_the_card(): void
    {
        $this->twoOutletDay();
        Sanctum::actingAs($this->bayu->user);

        // Checked in to Luuca and never out: once JChicken has started, the card offers its check-in.
        $this->at('07:55:00');
        $this->checkIn($this->bayu, self::AT_LUUCA)->assertOk();

        $this->at('15:30:00');
        $this->getJson('/api/v1/attendance/today')->assertOk()->assertJsonPath('has_checked_in', true)->assertJsonPath('schedule.start_time', '08:00');

        $this->at('16:05:00');
        $this->getJson('/api/v1/attendance/today')
            ->assertOk()
            ->assertJsonPath('has_checked_in', false)
            ->assertJsonPath('schedule.start_time', '16:00');
    }

    public function test_a_rejected_first_part_does_not_stick_the_today_card(): void
    {
        $this->schedule($this->bayu, 1, $this->split, $this->momoyo);
        Sanctum::actingAs($this->bayu->user);

        $this->at('07:50:00');
        $first = $this->checkIn($this->bayu, self::AT_LUUCA)->assertOk()->assertJsonPath('requires_approval', true)->json('attendance.id');
        $this->at('09:00:00');
        app(ApprovalFlowService::class)->rejectStep(Attendance::findOrFail($first), $this->approver(), 'Bukan di outlet');

        $this->at('16:50:00');
        $this->getJson('/api/v1/attendance/today')
            ->assertOk()
            ->assertJsonPath('has_checked_in', false)
            ->assertJsonPath('schedule.segment', 2)
            ->assertJsonPath('schedule.start_time', '17:45')
            ->assertJsonPath('check_out_outlet', null);

        $this->checkIn($this->bayu, self::AT_MOMOYO)->assertOk()->assertJsonPath('attendance.segment', 2);
        $this->at('22:00:00');
        $this->checkOut($this->bayu, self::AT_MOMOYO)->assertOk();

        $this->getJson('/api/v1/attendance/today')
            ->assertOk()
            ->assertJsonPath('attendance.segment', 2)
            ->assertJsonPath('has_checked_out', true)
            ->assertJsonPath('schedules.0.state', 'ditolak')
            ->assertJsonPath('schedules.1.state', 'pulang');
    }

    public function test_an_abandoned_first_part_does_not_stick_the_today_card_once_the_second_starts(): void
    {
        $this->schedule($this->bayu, 1, $this->split, $this->momoyo);
        Sanctum::actingAs($this->bayu->user);

        $this->at('07:50:00');
        $this->checkIn($this->bayu, self::AT_MOMOYO)->assertOk();

        $this->at('17:50:00');
        $this->getJson('/api/v1/attendance/today')
            ->assertOk()
            ->assertJsonPath('has_checked_in', false)
            ->assertJsonPath('schedule.segment', 2);
        $this->checkIn($this->bayu, self::AT_MOMOYO)->assertOk()->assertJsonPath('attendance.segment', 2);
    }

    public function test_at_the_end_of_a_worked_day_the_card_describes_the_last_attended_session_not_an_unattended_earlier_one(): void
    {
        $this->schedule($this->bayu, 1, $this->split, $this->momoyo);
        $this->schedule($this->bayu, 2, $this->shift('Jeda Uji', '13:30', '17:00'), $this->jchicken);
        Sanctum::actingAs($this->bayu->user);

        // Part 1 skipped; the break shift and part 2 both worked.
        $this->at('13:25:00');
        $this->checkIn($this->bayu, self::AT_JCHICKEN)->assertOk()->assertJsonPath('attendance.schedule_sequence', 2);
        $this->at('17:00:00');
        $this->checkOut($this->bayu, self::AT_JCHICKEN)->assertOk();
        $this->at('17:40:00');
        $this->checkIn($this->bayu, self::AT_MOMOYO)->assertOk()->assertJsonPath('attendance.segment', 2);
        $this->at('22:00:00');
        $this->checkOut($this->bayu, self::AT_MOMOYO)->assertOk();

        $this->at('22:05:00');
        Sanctum::actingAs($this->bayu->user);
        $this->getJson('/api/v1/attendance/today')
            ->assertOk()
            ->assertJsonPath('has_checked_in', true)
            ->assertJsonPath('has_checked_out', true)
            ->assertJsonPath('schedule.segment', 2)
            ->assertJsonPath('schedule.start_time', '17:45');
    }

    public function test_a_check_out_tap_after_the_last_session_closed_says_so_and_today_lists_the_earlier_open_one(): void
    {
        $this->twoOutletDay();

        $this->at('07:55:00');
        $this->checkIn($this->bayu, self::AT_LUUCA)->assertOk();
        $this->at('15:50:00');
        $this->checkIn($this->bayu, self::AT_JCHICKEN)->assertOk()->assertJsonPath('attendance.schedule_sequence', 2);
        $this->at('22:00:00');
        $this->checkOut($this->bayu, self::AT_JCHICKEN)->assertOk()->assertJsonPath('attendance.schedule_sequence', 2);

        $this->at('22:30:00');
        $this->checkOut($this->bayu, self::AT_JCHICKEN)
            ->assertStatus(409)
            ->assertJsonPath('message', AttendanceCheckService::ALREADY_CHECKED_OUT_MESSAGE);

        $this->at('22:40:00');
        Sanctum::actingAs($this->bayu->user);
        $this->getJson('/api/v1/attendance/today')
            ->assertOk()
            ->assertJsonPath('unclosed_previous.date', self::MONDAY)
            ->assertJsonPath('unclosed_previous.check_in', '07:55')
            ->assertJsonPath('unclosed_previous.correction_pending', false);
    }

    public function test_an_earlier_open_session_of_today_never_hides_a_forgotten_check_out_of_an_earlier_day(): void
    {
        $this->twoOutletDay();
        // Sunday's shift was never checked out.
        Attendance::create([
            'freelancer_id' => $this->bayu->id, 'date' => '2026-11-15', 'check_in' => '2026-11-15 09:00:00', 'status' => 'on_time',
            'shift_id' => $this->luucaDay->id, 'company_location_id' => $this->luuca->id,
        ]);

        // Today's first session is left open too: the worker checks in at the second outlet.
        $this->at('07:55:00');
        $this->checkIn($this->bayu, self::AT_LUUCA)->assertOk();
        $this->at('15:50:00');
        $this->checkIn($this->bayu, self::AT_JCHICKEN)->assertOk()->assertJsonPath('attendance.schedule_sequence', 2);

        $this->at('16:30:00');
        Sanctum::actingAs($this->bayu->user);
        $this->getJson('/api/v1/attendance/today')
            ->assertOk()
            // The released field keeps its meaning: the earlier day's forgotten check-out.
            ->assertJsonPath('unclosed_previous.date', '2026-11-15')
            ->assertJsonPath('unclosed_previous.check_in', '09:00')
            // Every open row is listed, oldest first.
            ->assertJsonPath('unclosed_list.0.date', '2026-11-15')
            ->assertJsonPath('unclosed_list.1.date', self::MONDAY)
            ->assertJsonPath('unclosed_list.1.check_in', '07:55')
            ->assertJsonPath('unclosed_list.1.schedule_sequence', 1)
            ->assertJsonCount(2, 'unclosed_list');
    }

    public function test_the_earlier_session_message_is_given_only_when_a_later_session_of_today_exists(): void
    {
        $this->twoOutletDay();
        $check = app(AttendanceCheckService::class);

        $this->at('07:55:00');
        $this->checkIn($this->bayu, self::AT_LUUCA)->assertOk();
        $morning = $this->rows()->sole();

        // The only session of today: a missed check-out is the plain forgotten check-out, never "an earlier session".
        $this->assertSame(AttendanceCheckService::FORGOTTEN_CHECK_OUT_MESSAGE, $check->notClosableMessage($morning));

        // A later session of today was checked in: the morning one is now an earlier session.
        $this->at('15:50:00');
        $this->checkIn($this->bayu, self::AT_JCHICKEN)->assertOk();
        $this->assertSame(AttendanceCheckService::FORGOTTEN_EARLIER_SESSION_MESSAGE, $check->notClosableMessage($morning->fresh()));

        // A refused check-out says so first.
        $morning->forceFill(['check_out_rejected_at' => now()])->save();
        $this->assertSame(AttendanceCheckService::CHECK_OUT_REFUSED_MESSAGE, $check->notClosableMessage($morning->fresh()));
    }

    public function test_a_check_out_tap_after_midnight_on_a_rejected_night_check_in_says_it_was_rejected(): void
    {
        $this->schedule($this->bayu, 1, $this->shift('Malam', '22:00', '06:00'), $this->luuca);

        $this->at('21:55:00');
        $id = $this->checkIn($this->bayu, self::AT_JCHICKEN)->assertOk()->assertJsonPath('requires_approval', true)->json('attendance.id');
        $this->at('23:00:00');
        app(ApprovalFlowService::class)->rejectStep(Attendance::findOrFail($id), $this->approver(), 'Bukan di outlet');

        $this->travelTo(Carbon::parse('2026-11-17 06:05:00'));
        $this->checkOut($this->bayu, self::AT_LUUCA)
            ->assertStatus(409)
            ->assertJsonPath('message', AttendanceCheckService::REJECTED_CHECK_OUT_MESSAGE);
    }

    public function test_a_tap_just_before_a_shift_starting_after_midnight_is_too_early_not_a_cover(): void
    {
        $dawn = $this->shift('Dini Hari', '00:30', '08:30');
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-17', 'shift_id' => $dawn->id, 'company_location_id' => $this->luuca->id]);

        $this->at('23:29:00');
        $this->checkIn($this->bayu, self::AT_LUUCA)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Shift dimulai pukul 00:30. Absen masuk dibuka pukul 23:30.');
        $this->checkIn($this->bayu, [...self::AT_LUUCA, 'cover_shift_id' => $dawn->id])->assertUnprocessable();
        $this->assertCount(0, $this->rows());

        $this->at('23:31:00');
        $this->checkIn($this->bayu, self::AT_LUUCA)->assertOk()->assertJsonPath('requires_approval', false);
        $this->assertSame('2026-11-17', $this->rows()->sole()->date->toDateString());
    }

    public function test_a_cover_of_a_shift_already_over_on_its_date_is_refused(): void
    {
        $this->at('18:00:00');
        $this->checkIn($this->bayu, [...self::AT_LUUCA, 'cover_shift_id' => $this->luucaDay->id])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Shift Luuca Pagi pada tanggal ini sudah selesai pukul 15:00. Pilih shift yang sedang atau akan berjalan.');

        $this->checkIn($this->bayu, [...self::AT_JCHICKEN, 'cover_shift_id' => $this->jchickenEvening->id])->assertOk()->assertJsonPath('requires_approval', true);
    }

    // ── Cover outlet ────────────────────────────────────────────────────────

    public function test_a_cover_without_a_colleague_stores_the_outlet_its_geofence_matched_and_the_approval_rosters_it(): void
    {
        $this->at('15:45:00');
        $id = $this->checkIn($this->bayu, self::AT_JCHICKEN + ['cover_shift_id' => $this->jchickenEvening->id])
            ->assertOk()
            ->assertJsonPath('requires_approval', true)
            ->assertJsonPath('approval_reasons', ['masuk di luar jadwal'])
            ->json('attendance.id');

        $row = Attendance::findOrFail($id);
        $this->assertSame($this->jchicken->id, (int) $row->company_location_id);
        $this->assertSame('JChicken Ciledug', $row->sched_outlet_name);

        $this->at('16:30:00');
        app(ApprovalFlowService::class)->approveStep($row->fresh(), $this->approver());

        $schedule = ShiftSchedule::query()->where('freelancer_id', $this->bayu->id)->sole();
        $this->assertSame([$this->jchickenEvening->id, $this->jchicken->id, 1], [(int) $schedule->shift_id, (int) $schedule->company_location_id, (int) $schedule->sequence]);
        $this->assertSame($schedule->id, (int) $row->fresh()->shift_schedule_id);
        $this->assertSame('cover', ShiftScheduleChange::query()->where('shift_schedule_id', $schedule->id)->sole()->source);
    }

    public function test_a_cover_naming_its_outlet_stores_that_outlet_and_is_geofenced_against_it(): void
    {
        $this->at('15:45:00');
        $this->checkIn($this->bayu, self::AT_JCHICKEN + ['cover_shift_id' => $this->jchickenEvening->id, 'cover_location_id' => $this->luuca->id])
            ->assertOk()
            ->assertJsonPath('approval_reasons', ['masuk di luar jadwal', 'di luar area outlet jadwal (Luuca Ciledug)'])
            ->assertJsonPath('attendance.outlet.id', $this->luuca->id);
    }

    public function test_a_cover_outlet_of_another_client_is_refused(): void
    {
        $other = new Company(['name' => 'PT Lain', 'code' => 'LAIN', 'is_active' => true]);
        $other->tenant_id = Tenant::factory()->create(['name' => 'Klien Lain', 'code' => 'KL', 'is_active' => true])->id;
        $other->save();
        $foreign = CompanyLocation::create(['company_id' => $other->id, 'name' => 'Outlet Lain', 'latitude' => -6.1, 'longitude' => 106.6, 'is_active' => true]);

        $this->at('15:45:00');
        $this->checkIn($this->bayu, self::AT_JCHICKEN + ['cover_shift_id' => $this->jchickenEvening->id, 'cover_location_id' => $foreign->id])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['cover_location_id']]);
        $this->assertCount(0, $this->rows());
    }

    public function test_a_cover_without_gps_or_outlet_waits_with_the_outlet_undecided(): void
    {
        $this->company->policy()->update(['require_gps' => false]);

        $this->at('15:45:00');
        $this->checkIn($this->bayu, ['cover_shift_id' => $this->jchickenEvening->id])
            ->assertOk()
            ->assertJsonPath('approval_reasons', ['masuk di luar jadwal', AttendanceCheckService::OUTLET_UNKNOWN_REASON])
            ->assertJsonPath('attendance.outlet', null);
    }

    public function test_a_cover_after_the_days_schedules_are_worked_takes_the_next_free_number(): void
    {
        $this->schedule($this->bayu, 1, $this->luucaDay, $this->luuca);

        $this->at('07:55:00');
        $this->checkIn($this->bayu, self::AT_LUUCA)->assertOk();
        $this->at('15:00:00');
        $this->checkOut($this->bayu, self::AT_LUUCA)->assertOk();

        // Without a cover shift the day is done; with one it is a further, off-schedule session.
        $this->at('15:45:00');
        $this->checkIn($this->bayu, self::AT_JCHICKEN)->assertStatus(409);
        $id = $this->checkIn($this->bayu, self::AT_JCHICKEN + ['cover_shift_id' => $this->jchickenEvening->id])
            ->assertOk()
            ->assertJsonPath('requires_approval', true)
            ->assertJsonPath('attendance.schedule_sequence', 2)
            ->json('attendance.id');

        app(ApprovalFlowService::class)->approveStep(Attendance::findOrFail($id), $this->approver());

        $rows = ShiftSchedule::query()->where('freelancer_id', $this->bayu->id)->orderBy('sequence')->get();
        $this->assertSame([[1, $this->luucaDay->id], [2, $this->jchickenEvening->id]], $rows->map(fn (ShiftSchedule $row): array => [(int) $row->sequence, (int) $row->shift_id])->all());
        $this->assertSame($rows[1]->id, (int) Attendance::findOrFail($id)->shift_schedule_id);
    }

    public function test_a_cover_row_added_meanwhile_on_the_same_shift_is_linked_not_doubled(): void
    {
        $this->at('15:45:00');
        $id = $this->checkIn($this->bayu, self::AT_JCHICKEN + ['cover_shift_id' => $this->jchickenEvening->id])->assertOk()->json('attendance.id');
        $added = $this->schedule($this->bayu, 1, $this->jchickenEvening, $this->jchicken);

        app(ApprovalFlowService::class)->approveStep(Attendance::findOrFail($id), $this->approver());

        $this->assertSame(1, ShiftSchedule::query()->where('freelancer_id', $this->bayu->id)->count());
        $this->assertSame([$added->id, 1], [(int) Attendance::findOrFail($id)->shift_schedule_id, (int) Attendance::findOrFail($id)->schedule_sequence]);
    }

    public function test_a_cover_whose_number_was_taken_meanwhile_moves_to_the_next_free_one(): void
    {
        $this->at('15:45:00');
        $id = $this->checkIn($this->bayu, self::AT_JCHICKEN + ['cover_shift_id' => $this->jchickenEvening->id])->assertOk()->json('attendance.id');
        $this->schedule($this->bayu, 1, $this->luucaDay, $this->luuca);

        app(ApprovalFlowService::class)->approveStep(Attendance::findOrFail($id), $this->approver());

        $cover = ShiftSchedule::query()->where('freelancer_id', $this->bayu->id)->where('shift_id', $this->jchickenEvening->id)->sole();
        $row = Attendance::findOrFail($id);
        $this->assertSame([2, $cover->id, 2], [(int) $cover->sequence, (int) $row->shift_schedule_id, (int) $row->schedule_sequence]);
    }

    public function test_a_cover_overlapping_a_schedule_added_meanwhile_writes_no_roster_row(): void
    {
        $this->at('15:45:00');
        $id = $this->checkIn($this->bayu, self::AT_JCHICKEN + ['cover_shift_id' => $this->jchickenEvening->id])->assertOk()->json('attendance.id');
        $added = $this->schedule($this->bayu, 1, $this->shift('Siang', '13:00', '21:00'), $this->luuca);

        app(ApprovalFlowService::class)->approveStep(Attendance::findOrFail($id), $this->approver());

        $this->assertSame([$added->id], ShiftSchedule::query()->where('freelancer_id', $this->bayu->id)->pluck('id')->all());
        $this->assertNull(Attendance::findOrFail($id)->shift_schedule_id);
        $this->assertSame(AttendanceStatus::OnTime, Attendance::findOrFail($id)->status);
    }

    public function test_replacing_a_colleague_gives_up_only_the_covered_schedule_of_their_day(): void
    {
        $citra = $this->freelancer('Citra');
        $kept = $this->schedule($citra, 1, $this->luucaDay, $this->luuca);
        $covered = $this->schedule($citra, 2, $this->jchickenEvening, $this->jchicken);

        $this->at('15:45:00');
        $id = $this->checkIn($this->bayu, self::AT_JCHICKEN + ['cover_shift_id' => $this->jchickenEvening->id, 'replaces_worker' => 'freelancer:'.$citra->id])
            ->assertOk()
            ->assertJsonPath('approval_reasons.0', 'masuk di luar jadwal (menggantikan Citra)')
            ->assertJsonPath('attendance.outlet.id', $this->jchicken->id)
            ->json('attendance.id');

        app(ApprovalFlowService::class)->approveStep(Attendance::findOrFail($id), $this->approver());

        $this->assertSame([$kept->id], ShiftSchedule::query()->where('freelancer_id', $citra->id)->pluck('id')->all());
        $this->assertNull($covered->fresh());
        $this->assertFalse((bool) $kept->fresh()->is_day_off);
    }

    public function test_two_backups_replace_a_colleague_on_each_of_her_two_schedules(): void
    {
        $citra = $this->freelancer('Citra');
        $this->schedule($citra, 1, $this->luucaDay, $this->luuca);
        $this->schedule($citra, 2, $this->jchickenEvening, $this->jchicken);
        $dodi = $this->freelancer('Dodi');

        $this->at('07:55:00');
        $morning = $this->checkIn($this->bayu, self::AT_LUUCA + ['cover_shift_id' => $this->luucaDay->id, 'replaces_worker' => 'freelancer:'.$citra->id])->assertOk()->json('attendance.id');

        // Each schedule of Citra is its own entry: the morning one is being replaced, the evening one is not.
        Sanctum::actingAs($dodi->user);
        $entries = collect($this->getJson('/api/v1/attendance/cover-options')->assertOk()->json('colleagues'))->where('name', 'Citra')->keyBy('shift.id');
        $this->assertSame([true, false], [$entries[$this->luucaDay->id]['being_replaced'], $entries[$this->jchickenEvening->id]['being_replaced']]);

        // The same shift a second time is refused; the other schedule is free to cover.
        $this->checkIn($dodi, self::AT_LUUCA + ['cover_shift_id' => $this->luucaDay->id, 'replaces_worker' => 'freelancer:'.$citra->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['replaces_worker' => 'Citra sudah digantikan rekan lain hari ini.']);

        $this->at('15:45:00');
        $evening = $this->checkIn($dodi, self::AT_JCHICKEN + ['cover_shift_id' => $this->jchickenEvening->id, 'replaces_worker' => 'freelancer:'.$citra->id])->assertOk()->json('attendance.id');

        app(ApprovalFlowService::class)->approveStep(Attendance::findOrFail($morning), $this->approver());
        app(ApprovalFlowService::class)->approveStep(Attendance::findOrFail($evening), $this->approver());

        $this->assertSame(0, ShiftSchedule::query()->where('freelancer_id', $citra->id)->where('is_day_off', false)->count(), 'Both schedules were covered: none is left to report absent.');
    }

    public function test_two_backups_replace_a_colleagues_two_rows_on_the_same_shift_at_two_outlets(): void
    {
        $citra = $this->freelancer('Citra');
        $atLuuca = $this->schedule($citra, 1, $this->luucaDay, $this->luuca);
        $atJchicken = $this->schedule($citra, 2, $this->luucaDay, $this->jchicken);
        $dodi = $this->freelancer('Dodi');
        $eko = $this->freelancer('Eko');

        // The released app names only the colleague: the first row of that shift nobody replaces yet is taken.
        $this->at('07:55:00');
        $first = $this->checkIn($this->bayu, self::AT_LUUCA + ['cover_shift_id' => $this->luucaDay->id, 'replaces_worker' => 'freelancer:'.$citra->id])->assertOk()->json('attendance.id');
        $this->assertSame($atLuuca->id, Attendance::findOrFail($first)->replaced_schedule_id);

        // Each row is its own entry, keyed by the row: only the Luuca one is being replaced.
        Sanctum::actingAs($dodi->user);
        $entries = collect($this->getJson('/api/v1/attendance/cover-options')->assertOk()->json('colleagues'))->where('name', 'Citra')->keyBy('schedule_id');
        $this->assertSame([true, false], [$entries[$atLuuca->id]['being_replaced'], $entries[$atJchicken->id]['being_replaced']]);
        $this->assertSame('freelancer:'.$citra->id.'#'.$atJchicken->id, $entries[$atJchicken->id]['schedule_key']);
        $this->assertSame('freelancer:'.$citra->id, $entries[$atJchicken->id]['key']);

        // A backup naming the taken row is refused; the other row on the same shift is free to cover.
        $this->checkIn($eko, self::AT_LUUCA + ['cover_shift_id' => $this->luucaDay->id, 'replaces_worker' => 'freelancer:'.$citra->id.'#'.$atLuuca->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['replaces_worker' => 'Citra sudah digantikan rekan lain hari ini.']);
        $second = $this->checkIn($dodi, self::AT_JCHICKEN + ['cover_shift_id' => $this->luucaDay->id, 'replaces_worker' => 'freelancer:'.$citra->id])->assertOk()->json('attendance.id');
        $this->assertSame($atJchicken->id, Attendance::findOrFail($second)->replaced_schedule_id);
        $this->assertSame($this->jchicken->id, (int) Attendance::findOrFail($second)->company_location_id);

        // Both rows taken now: a third backup is refused either way.
        $this->checkIn($eko, self::AT_JCHICKEN + ['cover_shift_id' => $this->luucaDay->id, 'replaces_worker' => 'freelancer:'.$citra->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['replaces_worker' => 'Citra sudah digantikan rekan lain hari ini.']);

        app(ApprovalFlowService::class)->approveStep(Attendance::findOrFail($first), $this->approver());
        app(ApprovalFlowService::class)->approveStep(Attendance::findOrFail($second), $this->approver());

        $this->assertSame(0, ShiftSchedule::query()->where('freelancer_id', $citra->id)->where('is_day_off', false)->count(), 'Both rows were covered: none is left to report absent.');
    }

    public function test_cover_options_list_the_outlets_a_cover_can_name(): void
    {
        Sanctum::actingAs($this->bayu->user);

        $this->getJson('/api/v1/attendance/cover-options')
            ->assertOk()
            ->assertJsonPath('is_off_schedule', true)
            ->assertJsonPath('off_schedule_reason', 'no_schedule')
            ->assertJsonPath('outlets', [
                ['id' => $this->jchicken->id, 'name' => 'JChicken Ciledug'],
                ['id' => $this->luuca->id, 'name' => 'Luuca Ciledug'],
                ['id' => $this->momoyo->id, 'name' => 'Momoyo Ciledug'],
            ]);
    }

    public function test_cover_options_list_every_outlet_a_cover_may_name_including_sister_companies_of_the_client(): void
    {
        $sister = new Company(['name' => 'PT Momoyo', 'code' => 'MMY', 'is_active' => true]);
        $sister->tenant_id = $this->company->tenant_id;
        $sister->save();
        $sisterOutlet = CompanyLocation::create(['company_id' => $sister->id, 'name' => 'Momoyo Bintaro', 'latitude' => -6.27, 'longitude' => 106.73, 'radius_meters' => 100, 'is_active' => true]);
        CompanyLocation::create(['company_id' => $sister->id, 'name' => 'Momoyo Lama', 'latitude' => -6.27, 'longitude' => 106.73, 'radius_meters' => 100, 'is_active' => false]);
        $stranger = Company::create(['name' => 'PT Lain', 'code' => 'LAIN', 'is_active' => true]);
        $strangerOutlet = CompanyLocation::create(['company_id' => $stranger->id, 'name' => 'Outlet Lain', 'latitude' => -6.27, 'longitude' => 106.73, 'radius_meters' => 100, 'is_active' => true]);
        Sanctum::actingAs($this->bayu->user);

        $outlets = collect($this->getJson('/api/v1/attendance/cover-options')->assertOk()->json('outlets'))->pluck('id');

        // The same outlets a cover check-in accepts: the sister company's active outlet in, another client's out.
        $this->assertEqualsCanonicalizing([$this->jchicken->id, $this->luuca->id, $this->momoyo->id, $sisterOutlet->id], $outlets->all());
        $this->assertSame($sisterOutlet->id, app(OffScheduleCheckInService::class)->coverLocation($this->bayu, $sisterOutlet->id)?->id);
        $this->assertNotContains($strangerOutlet->id, $outlets->all());
    }

    // ── Carried fixes of the session picker ─────────────────────────────────

    public function test_an_early_tap_before_todays_shift_is_not_a_very_late_check_in_to_last_nights_unattended_shift(): void
    {
        $night = $this->shift('Malam', '22:00', '06:00');
        $early = $this->shift('Pagi Awal', '06:00', '14:00');
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-15', 'shift_id' => $night->id, 'company_location_id' => $this->luuca->id]);
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => self::MONDAY, 'shift_id' => $early->id, 'company_location_id' => $this->luuca->id]);

        // 04:50: only last night's window is open, but today's 06:00 start is nearer than last night's 22:00.
        $this->at('04:50:00');
        $this->checkIn($this->bayu, self::AT_LUUCA)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Shift dimulai pukul 06:00. Absen masuk dibuka pukul 05:00.');
        $this->assertCount(0, Attendance::query()->where('freelancer_id', $this->bayu->id)->get());

        // Once today's window opens, the tap is today's shift.
        $this->at('05:10:00');
        $this->checkIn($this->bayu, self::AT_LUUCA)
            ->assertOk()
            ->assertJsonPath('attendance.date', self::MONDAY)
            ->assertJsonPath('attendance.late_minutes', 0);
    }

    public function test_a_tap_in_the_middle_of_last_nights_shift_still_books_it_late(): void
    {
        $night = $this->shift('Malam', '22:00', '06:00');
        $early = $this->shift('Pagi Awal', '06:00', '14:00');
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-15', 'shift_id' => $night->id, 'company_location_id' => $this->luuca->id]);
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => self::MONDAY, 'shift_id' => $early->id, 'company_location_id' => $this->luuca->id]);

        // 01:00 is nearer to last night's start (3 hours ago) than to today's (5 hours ahead).
        $this->at('01:00:00');
        $this->checkIn($this->bayu, self::AT_LUUCA)
            ->assertOk()
            ->assertJsonPath('attendance.date', '2026-11-15')
            ->assertJsonPath('attendance.late_minutes', 180);
    }

    /**
     * Carried must-fix (a), settled: a tap inside last night's unattended session that today's session does
     * not follow on (18:00 - 02:00, then 06:00) books last night's session, late; only after its scheduled
     * end does the tap belong to today's session (too early before it opens, booked once it is open).
     */
    public function test_a_tap_inside_last_nights_session_that_today_does_not_follow_on_books_it_late(): void
    {
        $evening = $this->shift('Sore Malam', '18:00', '02:00');
        $early = $this->shift('Pagi Awal', '06:00', '14:00');
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-15', 'shift_id' => $evening->id, 'company_location_id' => $this->luuca->id]);
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => self::MONDAY, 'shift_id' => $early->id, 'company_location_id' => $this->luuca->id]);

        // 01:00 is 7 hours after last night's start and 5 before today's, but last night's shift runs until 02:00.
        $this->at('01:00:00');
        $this->checkIn($this->bayu, self::AT_LUUCA)
            ->assertOk()
            ->assertJsonPath('attendance.date', '2026-11-15')
            ->assertJsonPath('attendance.late_minutes', 420);
    }

    public function test_after_last_nights_session_ended_a_tap_belongs_to_todays_session(): void
    {
        $evening = $this->shift('Sore Malam', '18:00', '02:00');
        $early = $this->shift('Pagi Awal', '06:00', '14:00');
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-15', 'shift_id' => $evening->id, 'company_location_id' => $this->luuca->id]);
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => self::MONDAY, 'shift_id' => $early->id, 'company_location_id' => $this->luuca->id]);

        $this->at('03:00:00');
        $this->checkIn($this->bayu, self::AT_LUUCA)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Shift dimulai pukul 06:00. Absen masuk dibuka pukul 05:00.');

        $this->at('05:30:00');
        $this->checkIn($this->bayu, self::AT_LUUCA)
            ->assertOk()
            ->assertJsonPath('attendance.date', self::MONDAY)
            ->assertJsonPath('attendance.late_minutes', 0);
    }

    public function test_a_named_split_schedule_without_a_part_books_the_part_whose_window_is_open(): void
    {
        $split = $this->schedule($this->bayu, 1, $this->split, $this->momoyo);

        $this->at('17:30:00');
        $this->checkIn($this->bayu, self::AT_MOMOYO + ['schedule_id' => $split->id])
            ->assertOk()
            ->assertJsonPath('attendance.schedule_id', $split->id)
            ->assertJsonPath('attendance.segment', 2)
            ->assertJsonPath('attendance.late_minutes', 0);
    }

    public function test_a_named_split_schedule_without_a_part_between_its_parts_is_too_early_for_the_next(): void
    {
        $split = $this->schedule($this->bayu, 1, $this->split, $this->momoyo);

        $this->at('14:00:00');
        $this->checkIn($this->bayu, self::AT_MOMOYO + ['schedule_id' => $split->id])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Segmen kedua dimulai pukul 17:45. Check in dibuka pukul 16:45.');
    }

    // ── Planning ────────────────────────────────────────────────────────────

    public function test_a_legacy_placement_row_is_expected_by_the_session_resolver_as_by_the_ledger(): void
    {
        // A company without shift rosters: Bayu has a default shift, a roster row on Monday and on Tuesday
        // a legacy placement row (an outlet, no shift, not a day off).
        $this->bayu->update(['shift_id' => $this->luucaDay->id]);
        $this->schedule($this->bayu, 1, $this->jchickenEvening, $this->jchicken);
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-17', 'is_day_off' => false, 'company_location_id' => $this->luuca->id]);

        $tuesday = app(SessionResolver::class)->sessionsFor($this->bayu->fresh(), Carbon::parse('2026-11-17'));

        $this->assertCount(1, $tuesday);
        $this->assertTrue($tuesday[0]->isExpected);
        $this->assertSame($this->luuca->id, $tuesday[0]->location?->id);

        $this->travelTo(Carbon::parse('2026-11-18 12:00:00'));
        $day = app(AttendanceLedger::class)->days(LedgerQuery::forWorkers(['freelancer:'.$this->bayu->id], '2026-11-17', '2026-11-17'))->collect()->first();

        $this->assertTrue($day['expected']);
        $this->assertSame('absent', $day['status']);
    }

    /**
     * Carried (e), settled on the ledger's rule: in a company without shift rosters a legacy placement row
     * plans the company hours for a freelancer without a default shift too, so the app lets him check in on
     * them and the ledger expects him; the resolver and the ledger agree.
     */
    public function test_a_legacy_placement_row_plans_the_company_hours_for_a_freelancer_without_a_default_shift(): void
    {
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => self::MONDAY, 'is_day_off' => false, 'company_location_id' => $this->luuca->id]);

        $sessions = app(SessionResolver::class)->sessionsFor($this->bayu->fresh(), Carbon::parse(self::MONDAY));

        $this->assertCount(1, $sessions);
        $this->assertSame(['policy', true, $this->luuca->id], [$sessions[0]->source, $sessions[0]->isExpected, $sessions[0]->location?->id]);

        $this->at('08:10:00');
        $this->checkIn($this->bayu, self::AT_LUUCA)
            ->assertOk()
            ->assertJsonPath('requires_approval', false)
            ->assertJsonPath('attendance.date', self::MONDAY);
        $this->assertSame('policy', $this->rows()->sole()->sched_source);
        $this->assertFalse((bool) $this->rows()->sole()->is_off_schedule);

        $this->travelTo(Carbon::parse('2026-11-17 12:00:00'));
        $day = app(AttendanceLedger::class)->days(LedgerQuery::forWorkers(['freelancer:'.$this->bayu->id], self::MONDAY, self::MONDAY))->collect()->first();

        $this->assertTrue($day['expected']);
        $this->assertSame('present', $day['status']);
    }

    /**
     * Item (1): in a roster company the hours of a date with a shift-less row next to a working row come
     * from the working row, whichever row the database returns first; lateness through resolve() is the
     * ledger's.
     */
    public function test_resolve_takes_the_working_row_of_a_date_that_also_holds_a_shiftless_row(): void
    {
        $this->company->policy()->update(['uses_shift_roster' => true]);
        $this->bayu->unsetRelation('company');
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => self::MONDAY, 'sequence' => 1, 'is_day_off' => false, 'company_location_id' => $this->momoyo->id]);
        $working = $this->schedule($this->bayu, 2, $this->luucaDay, $this->luuca);
        $bayu = $this->bayu->fresh();
        $schedule = app(WorkScheduleService::class);

        $hours = $schedule->resolve($bayu, Carbon::parse(self::MONDAY));

        $this->assertSame(['schedule', $this->luucaDay->id, $working->id, 2], [$hours['source'], $hours['shift']?->id, $hours['schedule_id'], $hours['sequence']]);
        $this->assertSame(60, $schedule->lateMinutes($hours, Carbon::parse(self::MONDAY.' 09:00:00'), Carbon::parse(self::MONDAY)));

        // A check-in without a snapshot (written by hand) is judged through resolve(), as the ledger judges it.
        $row = Attendance::create(['freelancer_id' => $this->bayu->id, 'date' => self::MONDAY, 'schedule_sequence' => 2, 'segment' => 1, 'company_location_id' => $this->luuca->id, 'check_in' => self::MONDAY.' 09:00:00', 'check_out' => self::MONDAY.' 15:00:00', 'status' => 'on_time', 'late_minutes' => 0]);
        $judged = app(AttendanceLatenessService::class)->judge($row->fresh());
        $row->forceFill(['late_minutes' => $judged['late_minutes'], 'status' => 'late'])->save();

        $this->travelTo(Carbon::parse('2026-11-17 12:00:00'));
        $day = app(AttendanceLedger::class)->days(LedgerQuery::forWorkers(['freelancer:'.$this->bayu->id], self::MONDAY, self::MONDAY))->collect()->first();

        $this->assertSame(60, $judged['late_minutes']);
        $this->assertSame(60, $day['totals']['late_minutes']);
    }

    public function test_a_blank_weekday_of_a_rostered_worker_is_not_expected_but_still_has_standing_hours(): void
    {
        $this->bayu->update(['shift_id' => $this->luucaDay->id]);
        $this->schedule($this->bayu, 1, $this->jchickenEvening, $this->jchicken);
        $resolver = app(SessionResolver::class);

        $tuesday = $resolver->sessionsFor($this->bayu->fresh(), Carbon::parse('2026-11-17'));
        $beforeRoster = $resolver->sessionsFor($this->bayu->fresh(), Carbon::parse('2026-11-09'));

        $this->assertCount(1, $tuesday);
        $this->assertSame('default', $tuesday[0]->source);
        $this->assertFalse($tuesday[0]->isExpected, 'On a roster only the rostered days are expected, as the ledger counts them.');
        $this->assertTrue($beforeRoster[0]->isExpected);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Bayu at Luuca 08:00 - 15:00 (schedule 1), then at JChicken 16:00 - 22:00 (schedule 2).
     *
     * @return array{0: ShiftSchedule, 1: ShiftSchedule}
     */
    private function twoOutletDay(): array
    {
        return [
            $this->schedule($this->bayu, 1, $this->luucaDay, $this->luuca),
            $this->schedule($this->bayu, 2, $this->jchickenEvening, $this->jchicken),
        ];
    }

    private function at(string $time): void
    {
        $this->travelTo(Carbon::parse(self::MONDAY.' '.$time));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function checkIn(Freelancer $worker, array $data = []): TestResponse
    {
        Sanctum::actingAs($worker->user);

        return $this->postJson('/api/v1/attendance/check-in', $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function checkOut(Freelancer $worker, array $data = []): TestResponse
    {
        Sanctum::actingAs($worker->user);

        return $this->postJson('/api/v1/attendance/check-out', $data);
    }

    /**
     * @return Collection<int, Attendance>
     */
    private function rows(): Collection
    {
        return Attendance::query()->where('freelancer_id', $this->bayu->id)->orderBy('schedule_sequence')->orderBy('segment')->get();
    }

    private function schedule(Freelancer $worker, int $sequence, Shift $shift, CompanyLocation $outlet): ShiftSchedule
    {
        return ShiftSchedule::create(['freelancer_id' => $worker->id, 'date' => self::MONDAY, 'sequence' => $sequence, 'shift_id' => $shift->id, 'company_location_id' => $outlet->id]);
    }

    /**
     * @param  array{latitude: float, longitude: float}  $at
     */
    private function outlet(string $name, array $at): CompanyLocation
    {
        return CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $this->division->id, 'name' => $name, 'latitude' => $at['latitude'], 'longitude' => $at['longitude'], 'radius_meters' => 100, 'is_active' => true]);
    }

    private function shift(string $name, string $start, string $end, ?string $secondStart = null, ?string $secondEnd = null): Shift
    {
        return Shift::create(['company_id' => $this->company->id, 'name' => $name, 'start_time' => $start, 'end_time' => $end, 'second_start_time' => $secondStart, 'second_end_time' => $secondEnd, 'late_tolerance_minutes' => 0]);
    }

    private function freelancer(string $name): Freelancer
    {
        $user = User::factory()->create(['name' => $name, 'is_active' => true]);
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

    private function approver(): User
    {
        $approver = User::factory()->create(['is_active' => true]);
        $approver->forceFill(['tenant_id' => $this->company->tenant_id])->save();
        $approver->assignRole('Approver');
        $this->grantDataAccessTo($approver, $this->company->id, $this->division->id);

        return $approver;
    }
}
