<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\DataScope;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Intern;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\ShiftScheduleChange;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\AttendanceReminderNotification;
use App\Services\ApprovalFlowService;
use App\Services\Attendance\LedgerQuery;
use App\Services\AttendanceLedger;
use App\Services\AttendanceReminderService;
use App\Services\SessionResolver;
use App\Services\ShiftSwapService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * D3: in a company switched to shift rosters (company_policies.uses_shift_roster) a day without a schedule is
 * not scheduled. A worker with no schedule row is not expected and never absent, and checks in only through
 * the off-schedule cover flow with approval; a never-rostered worker with a default shift keeps fixed hours.
 * A company that is not switched keeps the legacy rules (asserted at the end of this class).
 */
class RosterCompanyUnscheduledTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-11-16';

    private Company $company;

    private Division $division;

    private CompanyLocation $outlet;

    private Shift $morning;

    private User $admin;

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

        $tenant = Tenant::factory()->create(['name' => 'Klien Resto', 'code' => 'KR', 'is_active' => true]);
        $this->company = new Company(['name' => 'PT Outlet Resto', 'code' => 'RST', 'is_active' => true]);
        $this->company->tenant_id = $tenant->id;
        $this->company->save();
        app(ApprovalFlowService::class)->createDefaultFlows($this->company);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Outlet', 'code' => 'OTL', 'is_active' => true]);
        $this->company->policy()->update([
            'require_gps' => false,
            'require_photo' => false,
            'require_face_recognition' => false,
            'require_liveness' => false,
            'work_start_time' => '08:00:00',
            'work_end_time' => '17:00:00',
            'late_tolerance_minutes' => 15,
            'uses_shift_roster' => true,
        ]);
        $this->company->unsetRelation('policy');

        $this->morning = Shift::create(['company_id' => $this->company->id, 'name' => 'Pagi', 'code' => 'P', 'start_time' => '08:00', 'end_time' => '16:00', 'late_tolerance_minutes' => 0, 'is_active' => true]);
        $this->outlet = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $this->division->id, 'name' => 'Outlet Mall', 'latitude' => -6.2, 'longitude' => 106.8, 'radius_meters' => 100, 'is_active' => true]);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->forceFill(['tenant_id' => $tenant->id])->save();
        $this->admin->assignRole(['Admin', 'Approver']);
        $this->admin->grantDataAccess(DataScope::All);
    }

    // ── A worker without a schedule ─────────────────────────────────────────

    public function test_an_unscheduled_employee_and_intern_are_offered_the_cover_flow_and_a_plain_check_in_is_refused(): void
    {
        $andi = $this->employee('Andi');
        $ika = $this->intern('Ika');
        $this->at('07:50:00');

        foreach ([$andi->user, $ika->user] as $user) {
            Sanctum::actingAs($user);

            $this->getJson('/api/v1/attendance/cover-options')
                ->assertOk()
                ->assertJsonPath('is_off_schedule', true)
                ->assertJsonPath('off_schedule_reason', 'no_schedule')
                ->assertJsonPath('default_shift_id', null);

            $this->getJson('/api/v1/attendance/today')
                ->assertOk()
                ->assertJsonPath('is_scheduled', false)
                ->assertJsonPath('schedule.is_off_schedule', true)
                ->assertJsonPath('schedule.shift', null)
                ->assertJsonPath('schedule.is_day_off', false)
                ->assertJsonPath('schedule.start_time', '08:00')
                ->assertJsonPath('schedules', []);

            $this->checkIn($user)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('cover_shift_id')
                ->assertJsonPath('message', 'Pilih shift yang Anda gantikan untuk masuk di luar jadwal.');
        }

        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_a_cover_check_in_waits_for_approval_with_its_outlet_and_its_approval_rosters_a_scheduled_present_day(): void
    {
        $andi = $this->employee('Andi');
        $this->at('07:58:00');

        $this->checkIn($andi->user, ['cover_shift_id' => $this->morning->id, 'cover_location_id' => $this->outlet->id])
            ->assertOk()
            ->assertJsonPath('requires_approval', true)
            ->assertJsonPath('attendance.is_off_schedule', true)
            ->assertJsonPath('attendance.outlet.id', $this->outlet->id);

        $row = $this->rowOf($andi);
        $this->assertSame(AttendanceStatus::PendingApproval, $row->status);
        $this->assertSame($this->outlet->id, (int) $row->company_location_id);
        $this->assertSame('cover', $row->sched_source);
        $this->assertNull($row->shift_schedule_id);

        app(ApprovalFlowService::class)->approveStep($row->fresh(), $this->approver());

        $schedule = ShiftSchedule::query()->where('employee_id', $andi->id)->whereDate('date', self::MONDAY)->sole();
        $this->assertSame($this->morning->id, (int) $schedule->shift_id);
        $this->assertSame($this->outlet->id, (int) $schedule->company_location_id);
        $this->assertSame($schedule->id, (int) $row->fresh()->shift_schedule_id);
        $this->assertTrue(ShiftScheduleChange::query()->where('shift_schedule_id', $schedule->id)->where('action', 'created')->where('source', 'cover')->exists());

        $this->travelTo(Carbon::parse('2026-11-17 12:00:00'));
        $day = $this->day($andi, self::MONDAY);

        $this->assertTrue($day['expected']);
        $this->assertSame('present', $day['status']);
        $this->assertSame($schedule->id, $day['lines'][0]['schedule_id']);
    }

    public function test_blank_past_days_are_absent_neither_in_laporan_nor_in_the_recap_and_a_day_off_is_off(): void
    {
        [$andi, $ika] = $this->rosteredPair();
        // Andi's default shift gives fixed hours before his first row; he starts that day here.
        $andi->update(['join_date' => '2026-11-09']);
        $this->travelTo(Carbon::parse('2026-11-21 12:00:00'));
        Sanctum::actingAs($this->admin);

        $summary = $this->getJson('/api/v1/laporan-absensi?start_date=2026-11-01&end_date=2026-11-20')->assertOk()->json('summary');
        $this->assertSame([1, 1], [$summary['total_present'], $summary['total_absent']]);

        $recap = $this->getJson('/api/v1/team/recap?month=2026-11')->assertOk();
        $lines = collect($recap->json('data'))->keyBy('worker.name');
        $this->assertSame([1, 1, 1], [$recap->json('total.present_days'), $recap->json('total.absent_days'), $recap->json('total.off_days')]);
        $this->assertSame([1, 0], [$lines['Andi']['present_days'], $lines['Andi']['absent_days']]);
        $this->assertSame([0, 1, 1], [$lines['Ika']['present_days'], $lines['Ika']['absent_days'], $lines['Ika']['off_days']]);

        $this->assertSame('off', $this->day($ika, '2026-11-11')['status']);
        $this->assertNull($this->day($andi, '2026-11-12'), 'A blank weekday is not scheduled: nothing to report.');
    }

    public function test_a_day_off_row_gives_the_day_off_reason(): void
    {
        $ika = $this->intern('Ika');
        ShiftSchedule::create(['intern_id' => $ika->id, 'date' => self::MONDAY, 'is_day_off' => true]);
        Sanctum::actingAs($ika->user);

        $this->getJson('/api/v1/attendance/cover-options')
            ->assertOk()
            ->assertJsonPath('is_off_schedule', true)
            ->assertJsonPath('off_schedule_reason', 'day_off');

        $this->travelTo(Carbon::parse('2026-11-17 12:00:00'));
        $this->assertSame('off', $this->day($ika, self::MONDAY)['status']);
    }

    public function test_a_row_without_a_shift_that_is_not_a_day_off_is_a_blank_day_and_a_cover_fills_it(): void
    {
        $andi = $this->employee('Andi', $this->morning);
        $placement = ShiftSchedule::create(['employee_id' => $andi->id, 'date' => self::MONDAY, 'is_day_off' => false, 'company_location_id' => $this->outlet->id]);
        $this->at('07:55:00');
        Sanctum::actingAs($andi->user);

        $this->getJson('/api/v1/attendance/cover-options')
            ->assertOk()
            ->assertJsonPath('is_off_schedule', true)
            ->assertJsonPath('off_schedule_reason', 'no_schedule')
            ->assertJsonPath('default_shift_id', $this->morning->id);
        $this->checkIn($andi->user)->assertUnprocessable()->assertJsonValidationErrors('cover_shift_id');

        $this->travelTo(Carbon::parse('2026-11-17 12:00:00'));
        $this->assertNull($this->day($andi, self::MONDAY), 'Not expected, so never absent.');

        // A cover on that day turns the shift-less row into the covered shift instead of adding one.
        $this->travelTo(Carbon::parse('2026-11-23 07:55:00'));
        ShiftSchedule::create(['employee_id' => $andi->id, 'date' => '2026-11-23', 'is_day_off' => false, 'company_location_id' => $this->outlet->id]);
        $this->checkIn($andi->user, ['cover_shift_id' => $this->morning->id])->assertOk()->assertJsonPath('requires_approval', true);
        app(ApprovalFlowService::class)->approveStep($this->rowOf($andi, '2026-11-23')->fresh(), $this->approver());

        $rows = ShiftSchedule::query()->where('employee_id', $andi->id)->whereDate('date', '2026-11-23')->get();
        $this->assertCount(1, $rows);
        $this->assertSame($this->morning->id, (int) $rows->first()->shift_id);
        $this->assertNull($placement->fresh()->shift_id, 'The earlier placement row is left alone.');
    }

    // ── Fixed hours: a default shift and never rostered ─────────────────────

    public function test_a_never_rostered_worker_with_a_default_shift_keeps_fixed_hours_until_the_first_roster_row(): void
    {
        $dedi = $this->employee('Dedi', $this->morning);
        $this->at('07:55:00');
        Sanctum::actingAs($dedi->user);

        $this->getJson('/api/v1/attendance/cover-options')->assertOk()->assertJsonPath('is_off_schedule', false);
        $this->getJson('/api/v1/attendance/today')->assertOk()->assertJsonPath('is_scheduled', true)->assertJsonPath('schedule.shift.id', $this->morning->id);
        $this->checkIn($dedi->user)
            ->assertOk()
            ->assertJsonPath('requires_approval', false)
            ->assertJsonPath('attendance.is_off_schedule', false);
        $this->assertSame('default', $this->rowOf($dedi)->sched_source);

        // Expected Monday to Friday on the default shift; the weekend is not scheduled.
        $this->travelTo(Carbon::parse('2026-11-21 12:00:00'));
        $days = $this->days($dedi, '2026-11-15', '2026-11-20');

        $this->assertSame(
            ['2026-11-16' => 'present', '2026-11-17' => 'absent', '2026-11-18' => 'absent', '2026-11-19' => 'absent', '2026-11-20' => 'absent'],
            $days->map(fn (array $day): string => $day['status'])->all(),
        );

        // Once rostered (from the date of the first row), a blank day is not scheduled and needs the cover flow,
        // with the default shift pre-selected.
        $this->travelTo(Carbon::parse('2026-11-24 07:55:00'));
        ShiftSchedule::create(['employee_id' => $dedi->id, 'date' => '2026-11-23', 'shift_id' => $this->morning->id, 'company_location_id' => $this->outlet->id]);
        Sanctum::actingAs($dedi->user);

        $this->getJson('/api/v1/attendance/cover-options')
            ->assertOk()
            ->assertJsonPath('is_off_schedule', true)
            ->assertJsonPath('off_schedule_reason', 'no_schedule')
            ->assertJsonPath('default_shift_id', $this->morning->id);
        $this->checkIn($dedi->user)->assertUnprocessable()->assertJsonValidationErrors('cover_shift_id');

        $this->travelTo(Carbon::parse('2026-11-25 12:00:00'));
        $this->assertNull($this->day($dedi, '2026-11-24'));
    }

    public function test_a_later_roster_row_never_restates_the_fixed_hours_of_the_dates_before_it(): void
    {
        $dedi = $this->employee('Dedi', $this->morning);
        $this->travelTo(Carbon::parse('2026-11-21 12:00:00'));
        $absentWeek = ['2026-11-16' => 'absent', '2026-11-17' => 'absent', '2026-11-18' => 'absent', '2026-11-19' => 'absent', '2026-11-20' => 'absent'];

        $this->assertSame($absentWeek, $this->days($dedi, '2026-11-16', '2026-11-20')->map(fn (array $day): string => $day['status'])->all());

        // A row written for a later week (a future roster, an approved weekend cover) leaves earlier weeks alone.
        ShiftSchedule::create(['employee_id' => $dedi->id, 'date' => '2026-12-07', 'shift_id' => $this->morning->id, 'company_location_id' => $this->outlet->id]);

        $this->assertSame($absentWeek, $this->days($dedi, '2026-11-16', '2026-11-20')->map(fn (array $day): string => $day['status'])->all());
        $this->assertSame(5, app(AttendanceLedger::class)->summary(LedgerQuery::forWorkers(['employee:'.$dedi->id], '2026-11-16', '2026-11-20'))['absent_days']);

        // A weekday before that week still has its fixed hours: a plain check-in, no cover flow.
        $this->travelTo(Carbon::parse('2026-11-24 07:55:00'));
        $this->checkIn($dedi->user)->assertOk()->assertJsonPath('attendance.is_off_schedule', false);
        $this->assertSame('default', $this->rowOf($dedi, '2026-11-24')->sched_source);

        // From the date of the first row on, a blank weekday is not scheduled.
        $this->travelTo(Carbon::parse('2026-12-08 12:00:00'));
        $this->assertNull($this->day($dedi, '2026-12-08'));
    }

    public function test_a_past_dated_row_mid_week_never_restates_the_weekdays_before_it(): void
    {
        $dedi = $this->employee('Dedi', $this->morning);
        $this->travelTo(Carbon::parse('2026-11-21 12:00:00'));
        $status = fn (): array => $this->days($dedi, '2026-11-16', '2026-11-20')->map(fn (array $day): string => $day['status'])->all();

        $this->assertSame(['2026-11-16' => 'absent', '2026-11-17' => 'absent', '2026-11-18' => 'absent', '2026-11-19' => 'absent', '2026-11-20' => 'absent'], $status());

        // A row written afterwards for Thursday: Monday to Wednesday were reported on fixed hours and stay so;
        // Thursday follows its row and the blank Friday after it is not scheduled.
        ShiftSchedule::create(['employee_id' => $dedi->id, 'date' => '2026-11-19', 'shift_id' => $this->morning->id, 'company_location_id' => $this->outlet->id]);

        $this->assertSame(['2026-11-16' => 'absent', '2026-11-17' => 'absent', '2026-11-18' => 'absent', '2026-11-19' => 'absent'], $status());
        $this->assertSame(4, app(AttendanceLedger::class)->summary(LedgerQuery::forWorkers(['employee:'.$dedi->id], '2026-11-16', '2026-11-20'))['absent_days']);
        $this->assertTrue(collect(app(SessionResolver::class)->sessionsFor($dedi->fresh(), Carbon::parse('2026-11-18')))->contains(fn ($session): bool => $session->isExpected));
        $this->assertSame([], app(SessionResolver::class)->sessionsFor($dedi->fresh(), Carbon::parse('2026-11-20')));
    }

    public function test_an_approved_cover_alone_never_ends_the_fixed_hours(): void
    {
        $dedi = $this->employee('Dedi', $this->morning);
        $absentWeek = ['2026-11-16' => 'absent', '2026-11-17' => 'absent', '2026-11-18' => 'absent', '2026-11-19' => 'absent', '2026-11-20' => 'absent'];

        // A Saturday cover in the same week, approved: it writes Dedi's own roster row for Saturday.
        $this->travelTo(Carbon::parse('2026-11-21 07:55:00'));
        $this->checkIn($dedi->user, ['cover_shift_id' => $this->morning->id])->assertOk()->assertJsonPath('requires_approval', true);
        app(ApprovalFlowService::class)->approveStep($this->rowOf($dedi, '2026-11-21')->fresh(), $this->approver());
        $this->assertSame(1, ShiftSchedule::query()->where('employee_id', $dedi->id)->count());

        // The weekdays already reported keep their fixed hours, and so do the weeks after.
        $this->travelTo(Carbon::parse('2026-11-23 07:55:00'));
        $this->assertSame($absentWeek, $this->days($dedi, '2026-11-16', '2026-11-20')->map(fn (array $day): string => $day['status'])->all());
        $this->assertSame('present', $this->day($dedi, '2026-11-21')['status']);
        Sanctum::actingAs($dedi->user);
        $this->getJson('/api/v1/attendance/cover-options')->assertOk()->assertJsonPath('is_off_schedule', false);
        $this->checkIn($dedi->user)->assertOk()->assertJsonPath('attendance.is_off_schedule', false);
        $this->assertSame('default', $this->rowOf($dedi, '2026-11-23')->sched_source);

        $this->artisan('attendance:roster-preflight', ['--company' => ['RST']])
            ->expectsOutputToContain('Punya shift default dan belum mulai dijadwalkan (tetap jam tetap Senin-Jumat sampai tanggal jadwal pertamanya): 1')
            ->expectsOutputToContain("Dedi (karyawan #{$dedi->id})")
            ->assertSuccessful();
    }

    public function test_a_cover_approved_before_the_change_log_existed_never_ends_the_fixed_hours_after_the_backfill(): void
    {
        $dedi = $this->employee('Dedi', $this->morning);

        // A Saturday cover approved before deploy: its own roster row has the approval's note and no change log.
        $this->travelTo(Carbon::parse('2026-11-21 07:55:00'));
        $this->checkIn($dedi->user, ['cover_shift_id' => $this->morning->id])->assertOk()->assertJsonPath('requires_approval', true);
        app(ApprovalFlowService::class)->approveStep($this->rowOf($dedi, '2026-11-21')->fresh(), $this->approver());
        $row = ShiftSchedule::query()->where('employee_id', $dedi->id)->sole();
        $this->assertSame('Masuk di luar jadwal (disetujui)', $row->notes);
        ShiftScheduleChange::query()->delete();

        // Without the backfill the legacy row would start the roster on Saturday and end the fixed hours.
        $this->travelTo(Carbon::parse('2026-11-24 12:00:00'));
        $this->assertSame([], app(SessionResolver::class)->sessionsFor($dedi->fresh(), Carbon::parse('2026-11-23')));

        $this->runCoverBackfill();
        $this->runCoverBackfill();
        app()->forgetScopedInstances();

        $entry = ShiftScheduleChange::query()->where('shift_schedule_id', $row->id)->sole();
        $this->assertSame([ShiftScheduleChange::ACTION_CREATED, 'cover', $this->morning->id], [$entry->action, $entry->source, $entry->after['shift_id']]);
        $this->assertTrue(collect(app(SessionResolver::class)->sessionsFor($dedi->fresh(), Carbon::parse('2026-11-23')))->contains(fn ($session): bool => $session->isExpected));
        $this->assertSame('absent', $this->day($dedi, '2026-11-23')['status']);
        $this->assertSame('present', $this->day($dedi, '2026-11-21')['status']);
        $this->artisan('attendance:roster-preflight', ['--company' => ['RST']])
            ->expectsOutputToContain('Punya shift default dan belum mulai dijadwalkan (tetap jam tetap Senin-Jumat sampai tanggal jadwal pertamanya): 1')
            ->expectsOutputToContain("Dedi (karyawan #{$dedi->id})")
            ->assertSuccessful();
    }

    public function test_the_backfill_keeps_a_legacy_pic_day_off_that_a_cover_converted_on_the_roster(): void
    {
        $dedi = $this->employee('Dedi', $this->morning);
        // Before deploy: the PIC rostered Monday as a day off and Friday as a shift; the cover converted Monday.
        ShiftSchedule::create(['employee_id' => $dedi->id, 'date' => self::MONDAY, 'is_day_off' => true]);
        ShiftSchedule::create(['employee_id' => $dedi->id, 'date' => '2026-11-20', 'shift_id' => $this->morning->id, 'company_location_id' => $this->outlet->id]);
        $this->at('07:55:00');
        $this->checkIn($dedi->user, ['cover_shift_id' => $this->morning->id])->assertOk()->assertJsonPath('requires_approval', true);
        app(ApprovalFlowService::class)->approveStep($this->rowOf($dedi)->fresh(), $this->approver());
        $monday = ShiftSchedule::query()->where('employee_id', $dedi->id)->whereDate('date', self::MONDAY)->sole();
        $this->assertSame('Masuk di luar jadwal (disetujui)', $monday->notes);
        ShiftScheduleChange::query()->delete();

        $this->runCoverBackfill();

        $this->assertSame(0, ShiftScheduleChange::query()->count());
        $this->travelTo(Carbon::parse('2026-11-21 12:00:00'));
        $this->assertSame([], app(SessionResolver::class)->sessionsFor($dedi->fresh(), Carbon::parse('2026-11-17')));
        $this->assertSame(['2026-11-16', '2026-11-20'], $this->days($dedi, '2026-11-16', '2026-11-20')->keys()->all());
    }

    public function test_a_cover_on_a_day_off_the_pic_rostered_never_moves_the_start_of_the_roster(): void
    {
        $dedi = $this->employee('Dedi', $this->morning);
        // The PIC's first row for Dedi is a day off on Monday; the next one is Friday. Tuesday to Thursday are blank.
        ShiftSchedule::create(['employee_id' => $dedi->id, 'date' => self::MONDAY, 'is_day_off' => true]);
        ShiftSchedule::create(['employee_id' => $dedi->id, 'date' => '2026-11-20', 'shift_id' => $this->morning->id, 'company_location_id' => $this->outlet->id]);

        // Dedi covers on his day off; the approval turns the PIC's day-off row into the covered shift.
        $this->at('07:55:00');
        $this->checkIn($dedi->user, ['cover_shift_id' => $this->morning->id])->assertOk()->assertJsonPath('requires_approval', true);
        app(ApprovalFlowService::class)->approveStep($this->rowOf($dedi)->fresh(), $this->approver());
        $this->assertSame(2, ShiftSchedule::query()->where('employee_id', $dedi->id)->count());
        $this->assertNotNull($this->rowOf($dedi)->fresh()->shift_schedule_id);

        // The roster still starts on Monday: the blank days after it are not expected.
        $this->travelTo(Carbon::parse('2026-11-21 12:00:00'));
        $this->assertSame([], app(SessionResolver::class)->sessionsFor($dedi->fresh(), Carbon::parse('2026-11-17')));
        $this->assertSame(['2026-11-16', '2026-11-20'], $this->days($dedi, '2026-11-16', '2026-11-20')->keys()->all());
        $this->assertSame(1, app(AttendanceLedger::class)->summary(LedgerQuery::forWorkers(['employee:'.$dedi->id], '2026-11-16', '2026-11-20'))['absent_days']);
    }

    public function test_the_preflight_keeps_a_worker_whose_rows_all_lie_ahead_on_fixed_hours(): void
    {
        $dedi = $this->employee('Dedi', $this->morning);
        ShiftSchedule::create(['employee_id' => $dedi->id, 'date' => '2026-12-21', 'shift_id' => $this->morning->id, 'company_location_id' => $this->outlet->id]);

        $this->artisan('attendance:roster-preflight', ['--company' => ['RST']])
            ->expectsOutputToContain('Punya shift default dan belum mulai dijadwalkan (tetap jam tetap Senin-Jumat sampai tanggal jadwal pertamanya): 1')
            ->expectsOutputToContain("Dedi (karyawan #{$dedi->id})")
            ->expectsOutputToContain('Punya shift default, pernah dijadwalkan, tanpa jadwal 14 hari ke depan (hanya bisa lewat "Masuk di luar jadwal"): 0')
            ->assertSuccessful();
    }

    // ── Reminders, swap and the live roster ────────────────────────────────

    public function test_no_reminder_goes_out_on_a_blank_day_of_a_rostered_worker(): void
    {
        Notification::fake();
        config(['services.onesignal.app_id' => 'app-id', 'services.onesignal.rest_api_key' => 'rest-key']);
        $andi = $this->employee('Andi', $this->morning);
        // Rostered from the Friday before: Monday is a blank day.
        ShiftSchedule::create(['employee_id' => $andi->id, 'date' => '2026-11-13', 'shift_id' => $this->morning->id]);
        $dedi = $this->employee('Dedi', $this->morning);

        $this->at('07:45:00');
        $sent = app(AttendanceReminderService::class)->sendDue(now());

        $this->assertSame(1, $sent[AttendanceReminderService::SHIFT_SOON], 'Only the fixed-hours worker is reminded.');
        Notification::assertSentTo($dedi->user, AttendanceReminderNotification::class);
        Notification::assertNotSentTo($andi->user, AttendanceReminderNotification::class);
    }

    public function test_a_swap_reads_a_blank_day_of_a_rostered_worker_as_off_without_the_default_shift(): void
    {
        $andi = $this->employee('Andi', $this->morning);
        // Rostered from the Friday before: Monday is a blank day.
        ShiftSchedule::create(['employee_id' => $andi->id, 'date' => '2026-11-13', 'shift_id' => $this->morning->id]);
        $placement = ShiftSchedule::create(['employee_id' => $andi->id, 'date' => '2026-11-18', 'is_day_off' => false, 'company_location_id' => $this->outlet->id]);
        $dedi = $this->employee('Dedi', $this->morning);
        $swaps = app(ShiftSwapService::class);
        $monday = Carbon::parse(self::MONDAY);

        $this->assertTrue($swaps->cellState($andi, null, $monday)['off']);
        $this->assertTrue($swaps->cellState($andi, $placement, Carbon::parse('2026-11-18'))['off']);
        $this->assertSame(['off' => false, 'shift_id' => $this->morning->id], array_intersect_key($swaps->cellState($dedi, null, $monday), array_flip(['off', 'shift_id'])));
        $this->assertTrue($swaps->cellState($dedi, null, Carbon::parse('2026-11-21'))['off'], 'Fixed hours do not reach the weekend.');
    }

    public function test_the_live_roster_lists_an_unscheduled_check_in_as_tanpa_jadwal(): void
    {
        $andi = $this->employee('Andi', $this->morning);
        // Rostered from the Friday before: Monday is a blank day.
        ShiftSchedule::create(['employee_id' => $andi->id, 'date' => '2026-11-13', 'shift_id' => $this->morning->id]);
        $dedi = $this->employee('Dedi', $this->morning);
        $this->at('08:00:00');
        $this->checkIn($andi->user, ['cover_shift_id' => $this->morning->id])->assertOk();
        $this->checkIn($dedi->user)->assertOk();

        $coordinator = User::factory()->create(['is_active' => true]);
        $coordinator->forceFill(['tenant_id' => $this->company->tenant_id])->save();
        $coordinator->assignRole('Koordinator');
        $coordinator->grantDataAccess(DataScope::Divisions, divisionIds: [$this->division->id]);
        Sanctum::actingAs($coordinator);

        $group = collect($this->getJson('/api/v1/team/roster?date='.self::MONDAY)->assertOk()->json('outlets'))->firstWhere('kind', 'unscheduled');
        $notes = collect($group['entries'])->pluck('note', 'worker.name');

        $this->assertSame('Tanpa jadwal', $notes['Andi'], 'A roster worker\'s blank day is not their default shift.');
        $this->assertSame('Shift default', $notes['Dedi']);
    }

    // ── Switching and the pre-flight listing ────────────────────────────────

    public function test_the_preflight_command_lists_who_the_switch_affects(): void
    {
        $dedi = $this->employee('Dedi', $this->morning);
        $andi = $this->employee('Andi');
        $budi = $this->employee('Budi', $this->morning);
        $ika = $this->intern('Ika');
        ShiftSchedule::create(['intern_id' => $ika->id, 'date' => '2026-11-20', 'shift_id' => $this->morning->id]);
        ShiftSchedule::create(['intern_id' => $ika->id, 'date' => '2026-11-10', 'is_day_off' => false, 'company_location_id' => $this->outlet->id]);
        // A default shift and a roster row in the past only: nothing ahead, so only the cover flow is left.
        ShiftSchedule::create(['employee_id' => $budi->id, 'date' => '2026-10-05', 'is_day_off' => true]);

        $this->artisan('attendance:roster-preflight', ['--company' => ['RST']])
            ->expectsOutputToContain('PT Outlet Resto')
            ->expectsOutputToContain('Baris jadwal kerja (dengan shift): 1')
            ->expectsOutputToContain('Baris jadwal tanpa shift dan bukan libur (dibaca sebagai hari kosong): 1')
            ->expectsOutputToContain('Punya shift default dan belum mulai dijadwalkan (tetap jam tetap Senin-Jumat sampai tanggal jadwal pertamanya): 1')
            ->expectsOutputToContain("Dedi (karyawan #{$dedi->id})")
            ->expectsOutputToContain('Tanpa shift default dan tanpa jadwal 14 hari ke depan (hanya bisa lewat "Masuk di luar jadwal"): 1')
            ->expectsOutputToContain("Andi (karyawan #{$andi->id})")
            ->expectsOutputToContain('Punya shift default, pernah dijadwalkan, tanpa jadwal 14 hari ke depan (hanya bisa lewat "Masuk di luar jadwal"): 1')
            ->expectsOutputToContain("Budi (karyawan #{$budi->id})")
            ->assertSuccessful();

        $this->assertDatabaseCount('shift_schedules', 3);
    }

    public function test_the_policy_form_and_the_app_switch_the_company_and_an_older_app_leaves_it_alone(): void
    {
        $this->company->policy()->update(['uses_shift_roster' => false]);
        $this->actingAs($this->admin);

        Livewire::test(ListCompanies::class)
            ->callAction(TestAction::make('managePolicy')->table($this->company), ['uses_shift_roster' => true])
            ->assertHasNoFormErrors();
        $this->assertTrue($this->company->policy->fresh()->uses_shift_roster);

        Sanctum::actingAs($this->admin);
        $url = "/api/v1/client/companies/{$this->company->id}/policy";
        $payload = [
            'late_tolerance_minutes' => 15, 'require_gps' => false, 'geofence_radius_meters' => 100, 'mock_location_action' => 'reject',
            'selfie_mode' => 'none', 'face_match_threshold' => 60, 'face_fail_action' => 'reject', 'annual_leave_quota' => 12, 'default_approval_stages' => 1,
        ];

        $this->getJson($url)->assertOk()->assertJsonPath('data.uses_shift_roster', true);
        $this->putJson($url, $payload)->assertOk();
        $this->assertTrue($this->company->policy->fresh()->uses_shift_roster, 'The released app does not send the flag.');
        $this->putJson($url, [...$payload, 'uses_shift_roster' => 'ya'])->assertUnprocessable()->assertJsonValidationErrors('uses_shift_roster');
        $this->putJson($url, [...$payload, 'uses_shift_roster' => false])->assertOk()->assertJsonPath('data.uses_shift_roster', false);
    }

    // ── A company that is not switched keeps the legacy rules ───────────────

    public function test_without_the_flag_an_unscheduled_employee_works_the_company_hours_and_blank_weekdays_are_absent(): void
    {
        $this->company->policy()->update(['uses_shift_roster' => false]);
        $andi = $this->employee('Andi');
        $this->at('08:20:00');
        Sanctum::actingAs($andi->user);

        $this->getJson('/api/v1/attendance/cover-options')->assertOk()->assertJsonPath('is_off_schedule', false)->assertJsonPath('off_schedule_reason', null);
        $this->getJson('/api/v1/attendance/today')->assertOk()->assertJsonPath('is_scheduled', true)->assertJsonPath('schedule.is_off_schedule', false);
        $this->checkIn($andi->user)
            ->assertOk()
            ->assertJsonPath('requires_approval', false)
            ->assertJsonPath('attendance.late_minutes', 20);
        $this->assertSame('policy', $this->rowOf($andi)->sched_source);
    }

    public function test_without_the_flag_the_rostered_pair_reads_as_before(): void
    {
        $this->company->policy()->update(['uses_shift_roster' => false]);
        $this->rosteredPair();
        $this->travelTo(Carbon::parse('2026-11-21 12:00:00'));
        Sanctum::actingAs($this->admin);

        // Andi is on a roster from 9 November, Ika from 10 November (the date of each one's first row): the
        // weekdays before are absences, Ika's Monday 9 November included.
        $summary = $this->getJson('/api/v1/laporan-absensi?start_date=2026-11-01&end_date=2026-11-20')->assertOk()->json('summary');
        $this->assertSame([1, 12], [$summary['total_present'], $summary['total_absent']]);
        $this->assertSame(12, $this->getJson('/api/v1/team/recap?month=2026-11')->assertOk()->json('total.absent_days'));
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Andi (default shift) rostered and present on Monday 9 November; Ika (no default shift) rostered and
     * absent on Tuesday 10, a day off on Wednesday 11. Every other day of November is blank.
     *
     * @return array{0: Employee, 1: Intern}
     */
    private function rosteredPair(): array
    {
        $andi = $this->employee('Andi', $this->morning);
        $ika = $this->intern('Ika');
        $monday = ShiftSchedule::create(['employee_id' => $andi->id, 'date' => '2026-11-09', 'shift_id' => $this->morning->id, 'company_location_id' => $this->outlet->id]);
        ShiftSchedule::create(['intern_id' => $ika->id, 'date' => '2026-11-10', 'shift_id' => $this->morning->id, 'company_location_id' => $this->outlet->id]);
        ShiftSchedule::create(['intern_id' => $ika->id, 'date' => '2026-11-11', 'is_day_off' => true]);
        Attendance::create([
            'employee_id' => $andi->id, 'date' => '2026-11-09', 'check_in' => '2026-11-09 08:00:00', 'check_out' => '2026-11-09 16:00:00',
            'status' => 'on_time', 'late_minutes' => 0, 'shift_id' => $this->morning->id, 'shift_schedule_id' => $monday->id, 'company_location_id' => $this->outlet->id,
        ]);

        return [$andi, $ika];
    }

    /**
     * Run the backfill that gives a legacy cover row its "created" entry in the change log.
     */
    private function runCoverBackfill(): void
    {
        (include database_path('migrations/2026_11_18_234025_backfill_cover_entries_in_shift_schedule_changes.php'))->up();
    }

    private function at(string $time): void
    {
        $this->travelTo(Carbon::parse(self::MONDAY.' '.$time));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function checkIn(User $user, array $data = []): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->postJson('/api/v1/attendance/check-in', $data);
    }

    private function rowOf(Employee|Intern $worker, string $date = self::MONDAY): Attendance
    {
        return Attendance::query()->where($worker instanceof Employee ? 'employee_id' : 'intern_id', $worker->id)->whereDate('date', $date)->sole();
    }

    /**
     * @return Collection<string, array<string, mixed>>
     */
    private function days(Employee|Intern $worker, string $from, string $to): Collection
    {
        $key = ($worker instanceof Employee ? 'employee:' : 'intern:').$worker->id;

        return app(AttendanceLedger::class)->days(LedgerQuery::forWorkers([$key], $from, $to))->collect()->keyBy('date');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function day(Employee|Intern $worker, string $date): ?array
    {
        return $this->days($worker, $date, $date)->get($date);
    }

    private function approver(): User
    {
        $approver = User::factory()->create(['is_active' => true]);
        $approver->forceFill(['tenant_id' => $this->company->tenant_id])->save();
        $approver->assignRole('Approver');
        $this->grantDataAccessTo($approver, $this->company->id, $this->division->id);

        return $approver;
    }

    private function employee(string $name, ?Shift $defaultShift = null): Employee
    {
        $user = $this->account('Employee');

        return Employee::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_number' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Kasir'])->id,
            'nip' => 'EMP-'.$user->id,
            'full_name' => $name,
            'join_date' => '2026-01-01',
            'status' => 'active',
            'shift_id' => $defaultShift?->id,
        ]);
    }

    private function intern(string $name): Intern
    {
        $user = $this->account('Intern');

        return Intern::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'nis' => 'NIS-'.$user->id,
            'full_name' => $name,
            'institution' => 'Universitas Uji',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31',
            'status' => 'active',
        ]);
    }

    private function account(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['tenant_id' => $this->company->tenant_id])->save();
        $user->assignRole($role);

        return $user;
    }
}
