<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Filament\Pages\AbsenHariIni;
use App\Filament\Resources\Attendances\Pages\EditAttendance;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\CompanyPolicy;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalFlowService;
use App\Services\AttendanceLatenessService;
use App\Services\SessionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Every attendance with a check-in keeps the schedule that applied (the sched_* snapshot and the roster
 * link), whichever path wrote it, and lateness, early leave and overnight detection read that snapshot.
 * The roster hooks still run in this stage: a roster change re-judges the row and rewrites its snapshot.
 *
 * Monday 16 Nov 2026.
 */
class AttendanceSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private const D = '2026-11-16';

    private const D1 = '2026-11-17';

    private Company $company;

    private Division $division;

    private Shift $morning;

    private Shift $split;

    private Shift $night;

    private CompanyLocation $outlet;

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
        Notification::fake();

        foreach (['Superadmin', 'Admin', 'PIC', 'Koordinator', 'Approver', 'BOD', 'Employee', 'Intern', 'Freelancer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->travelTo(Carbon::parse(self::D.' 07:00:00'));

        $tenant = Tenant::factory()->create(['name' => 'Klien A', 'code' => 'KA', 'is_active' => true]);
        $this->company = new Company(['name' => 'PT Franchise', 'code' => 'PFC', 'is_active' => true]);
        $this->company->tenant_id = $tenant->id;
        $this->company->save();
        $this->company->policy->update([
            ...CompanyPolicy::flagsForSelfieMode('none'),
            'require_gps' => false,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'late_tolerance_minutes' => 10,
        ]);
        app(ApprovalFlowService::class)->createDefaultFlows($this->company);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'MOMOYO', 'code' => 'MMY', 'is_active' => true]);

        $this->morning = Shift::create(['company_id' => $this->company->id, 'name' => 'Pagi', 'code' => 'P', 'start_time' => '08:00', 'end_time' => '16:00', 'late_tolerance_minutes' => 5, 'normal_minutes' => 420]);
        $this->split = Shift::create(['company_id' => $this->company->id, 'name' => 'Split', 'code' => 'SP', 'start_time' => '07:45', 'end_time' => '13:00', 'second_start_time' => '17:45', 'second_end_time' => '22:00']);
        $this->night = Shift::create(['company_id' => $this->company->id, 'name' => 'Malam', 'code' => 'M', 'start_time' => '22:00', 'end_time' => '06:00']);
        $this->outlet = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $this->division->id, 'name' => 'Momoyo Ciledug', 'latitude' => -6.2297, 'longitude' => 106.7112]);
    }

    // ── Check-in writes the snapshot ─────────────────────────────────────────

    public function test_check_in_on_a_rostered_day_keeps_the_roster_row_and_its_hours(): void
    {
        $employee = $this->employee();
        $schedule = ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::D, 'shift_id' => $this->morning->id, 'company_location_id' => $this->outlet->id, 'section' => 'Kasir']);

        $this->at(self::D.' 08:10:00');
        $row = $this->checkedIn($employee);

        $this->assertSame(AttendanceStatus::Late, $row->status);
        $this->assertSame($schedule->id, $row->shift_schedule_id);
        $this->assertSame(1, $row->schedule_sequence);
        $this->assertSame($this->morning->id, (int) $row->shift_id);
        $this->assertSame($this->outlet->id, (int) $row->company_location_id);
        $this->assertSame(self::D.' 08:00:00', $row->sched_start_at->format('Y-m-d H:i:s'));
        $this->assertSame(self::D.' 16:00:00', $row->sched_end_at->format('Y-m-d H:i:s'));
        $this->assertSame(5, $row->sched_tolerance_minutes);
        $this->assertSame(480, $row->sched_scheduled_minutes);
        $this->assertSame(420, $row->sched_normal_minutes);
        $this->assertSame('Pagi', $row->sched_shift_name);
        $this->assertSame('P', $row->sched_shift_code);
        $this->assertSame('Momoyo Ciledug', $row->sched_outlet_name);
        $this->assertSame('Kasir', $row->sched_section);
        $this->assertSame('schedule', $row->sched_source);
        $this->assertTrue($row->hasSnapshot());
    }

    public function test_check_in_on_the_default_shift_has_no_roster_link(): void
    {
        $employee = $this->employee(['shift_id' => $this->morning->id]);

        $this->at(self::D.' 07:55:00');
        $row = $this->checkedIn($employee);

        $this->assertNull($row->shift_schedule_id);
        $this->assertSame(1, $row->schedule_sequence);
        $this->assertSame('default', $row->sched_source);
        $this->assertSame(self::D.' 08:00:00', $row->sched_start_at->format('Y-m-d H:i:s'));
        $this->assertSame(self::D.' 16:00:00', $row->sched_end_at->format('Y-m-d H:i:s'));
        $this->assertSame(420, $row->sched_normal_minutes);
        $this->assertNull($row->sched_outlet_name);
        $this->assertNull($row->sched_section);
    }

    public function test_check_in_on_company_hours_keeps_the_policy_hours_and_no_normal_minutes(): void
    {
        $employee = $this->employee();

        $this->at(self::D.' 09:20:00');
        $row = $this->checkedIn($employee);

        $this->assertSame(AttendanceStatus::Late, $row->status);
        $this->assertNull($row->shift_schedule_id);
        $this->assertNull($row->shift_id);
        $this->assertSame('policy', $row->sched_source);
        $this->assertSame(self::D.' 09:00:00', $row->sched_start_at->format('Y-m-d H:i:s'));
        $this->assertSame(self::D.' 18:00:00', $row->sched_end_at->format('Y-m-d H:i:s'));
        $this->assertSame(10, $row->sched_tolerance_minutes);
        $this->assertSame(540, $row->sched_scheduled_minutes);
        $this->assertNull($row->sched_normal_minutes);
        $this->assertNull($row->sched_shift_name);
        $this->assertNull($row->sched_shift_code);
    }

    public function test_an_off_schedule_check_in_is_a_cover_and_is_linked_to_the_roster_row_once_approved(): void
    {
        $employee = $this->employee();
        $dayOff = ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::D, 'is_day_off' => true]);

        $this->at(self::D.' 08:03:00');
        $row = $this->checkedIn($employee, ['cover_shift_id' => $this->morning->id]);

        $this->assertSame(AttendanceStatus::PendingApproval, $row->status);
        $this->assertTrue($row->is_off_schedule);
        $this->assertSame('cover', $row->sched_source);
        $this->assertNull($row->shift_schedule_id, 'A day off is never the schedule of an attendance.');
        $this->assertSame(self::D.' 08:00:00', $row->sched_start_at->format('Y-m-d H:i:s'));
        $this->assertSame(self::D.' 16:00:00', $row->sched_end_at->format('Y-m-d H:i:s'));
        $this->assertSame('Pagi', $row->sched_shift_name);

        $row->applyGeofenceApproval();

        $row->refresh();
        $this->assertFalse($dayOff->fresh()->is_day_off);
        $this->assertSame($dayOff->id, $row->shift_schedule_id);
        $this->assertSame(1, $row->schedule_sequence);
        $this->assertSame('cover', $row->sched_source);
        $this->assertSame(self::D.' 16:00:00', $row->sched_end_at->format('Y-m-d H:i:s'));
        $this->assertSame(AttendanceStatus::OnTime, $row->status);
    }

    public function test_the_second_part_of_a_split_shift_keeps_its_own_hours_and_the_same_roster_row(): void
    {
        $employee = $this->employee();
        $schedule = ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::D, 'shift_id' => $this->split->id, 'company_location_id' => $this->outlet->id]);

        $this->at(self::D.' 07:45:00');
        $first = $this->checkedIn($employee);
        $this->at(self::D.' 13:00:00');
        $this->checkOut($employee)->assertOk();
        $this->at(self::D.' 17:40:00');
        $second = $this->checkedIn($employee);

        $this->assertSame(1, (int) $first->segment);
        $this->assertSame(self::D.' 07:45:00', $first->sched_start_at->format('Y-m-d H:i:s'));
        $this->assertSame(self::D.' 13:00:00', $first->sched_end_at->format('Y-m-d H:i:s'));

        $this->assertSame(2, (int) $second->segment);
        $this->assertSame($schedule->id, $second->shift_schedule_id);
        $this->assertSame(1, $second->schedule_sequence);
        $this->assertSame(self::D.' 17:45:00', $second->sched_start_at->format('Y-m-d H:i:s'));
        $this->assertSame(self::D.' 22:00:00', $second->sched_end_at->format('Y-m-d H:i:s'));
        $this->assertSame(570, $second->sched_scheduled_minutes, 'The scheduled minutes cover the whole schedule, both parts.');
        $this->assertNull($second->sched_normal_minutes);
        $this->assertSame('Momoyo Ciledug', $second->sched_outlet_name);
        $this->assertSame('schedule', $second->sched_source);
    }

    public function test_an_overnight_shift_ends_on_the_next_day_in_the_snapshot(): void
    {
        $employee = $this->employee();
        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::D, 'shift_id' => $this->night->id]);

        $this->at(self::D.' 21:50:00');
        $row = $this->checkedIn($employee);

        $this->assertSame(self::D, $row->date->toDateString());
        $this->assertSame(self::D.' 22:00:00', $row->sched_start_at->format('Y-m-d H:i:s'));
        $this->assertSame(self::D1.' 06:00:00', $row->sched_end_at->format('Y-m-d H:i:s'));
        $this->assertSame(480, $row->sched_scheduled_minutes);

        // Still open after midnight, as its kept hours cross it: the check-out lands on the same row.
        $this->at(self::D1.' 05:30:00');
        $this->checkOut($employee)->assertOk()->assertJsonPath('attendance.id', $row->id);
        $this->assertSame(30, $row->fresh()->early_leave_minutes);
    }

    public function test_the_web_check_in_page_writes_the_same_snapshot(): void
    {
        $employee = $this->employee();
        $schedule = ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::D, 'shift_id' => $this->morning->id, 'company_location_id' => $this->outlet->id, 'section' => 'Kasir']);

        $this->at(self::D.' 08:00:00');
        $this->actingAs($employee->user);
        Livewire::test(AbsenHariIni::class)->call('processCheckIn')->assertHasNoErrors();

        $row = Attendance::query()->where('employee_id', $employee->id)->sole();
        $this->assertNotNull($row->check_in);
        $this->assertSame($schedule->id, $row->shift_schedule_id);
        $this->assertSame('schedule', $row->sched_source);
        $this->assertSame(self::D.' 08:00:00', $row->sched_start_at->format('Y-m-d H:i:s'));
        $this->assertSame(self::D.' 16:00:00', $row->sched_end_at->format('Y-m-d H:i:s'));
        $this->assertSame(5, $row->sched_tolerance_minutes);
        $this->assertSame(480, $row->sched_scheduled_minutes);
        $this->assertSame(420, $row->sched_normal_minutes);
        $this->assertSame('Pagi', $row->sched_shift_name);
        $this->assertSame('Momoyo Ciledug', $row->sched_outlet_name);
        $this->assertSame('Kasir', $row->sched_section);
    }

    // ── Judgement reads the snapshot ─────────────────────────────────────────

    public function test_early_leave_at_check_out_uses_the_kept_hours_after_the_shift_master_changed(): void
    {
        $employee = $this->employee();
        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::D, 'shift_id' => $this->morning->id]);

        $this->at(self::D.' 08:00:00');
        $row = $this->checkedIn($employee);

        // Changed straight in the database, so no roster hook judges the row again.
        DB::table('shifts')->where('id', $this->morning->id)->update(['end_time' => '18:00', 'start_time' => '10:00']);

        $this->at(self::D.' 15:30:00');
        $this->checkOut($employee)->assertOk();

        $row->refresh();
        $this->assertSame(30, $row->early_leave_minutes, 'Judged against 16:00, the end that applied at check-in.');
        $this->assertSame(self::D.' 16:00:00', $row->sched_end_at->format('Y-m-d H:i:s'));
    }

    public function test_a_row_without_a_snapshot_is_still_judged_against_its_shift(): void
    {
        $employee = $this->employee();
        $row = Attendance::create([
            'employee_id' => $employee->id,
            'date' => self::D,
            'shift_id' => $this->morning->id,
            'check_in' => self::D.' 08:00:00',
            'status' => AttendanceStatus::OnTime,
        ]);
        $this->assertFalse($row->fresh()->hasSnapshot());

        DB::table('shifts')->where('id', $this->morning->id)->update(['end_time' => '18:00']);

        $this->at(self::D.' 15:30:00');
        $this->checkOut($employee)->assertOk();

        $row->refresh();
        $this->assertSame(150, $row->early_leave_minutes);
        $this->assertFalse($row->hasSnapshot(), 'A check-out never fills in a missing snapshot.');
    }

    public function test_approving_a_check_in_without_a_stored_verdict_judges_it_against_the_snapshot(): void
    {
        $employee = $this->employee();
        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::D, 'shift_id' => $this->morning->id]);

        $this->at(self::D.' 08:20:00');
        $row = $this->checkedIn($employee);
        DB::table('attendances')->where('id', $row->id)->update(['status' => 'pending_approval', 'late_minutes' => 0, 'check_in_late_minutes' => null]);
        DB::table('shifts')->where('id', $this->morning->id)->update(['start_time' => '09:00']);

        $row->fresh()->applyGeofenceApproval();

        $row->refresh();
        $this->assertSame(AttendanceStatus::Late, $row->status);
        $this->assertSame(20, $row->late_minutes, 'Counted from 08:00, the start that applied at check-in.');
    }

    public function test_reading_attendance_never_writes_a_snapshot(): void
    {
        $employee = $this->employee();
        $row = Attendance::create([
            'employee_id' => $employee->id,
            'date' => self::D,
            'shift_id' => $this->morning->id,
            'check_in' => self::D.' 08:00:00',
            'check_out' => self::D.' 16:00:00',
            'status' => AttendanceStatus::OnTime,
        ]);
        $before = (array) DB::table('attendances')->where('id', $row->id)->first();

        $this->at(self::D.' 19:00:00');
        Sanctum::actingAs($employee->user);
        $this->getJson('/api/v1/attendance/today')->assertOk();
        $this->getJson('/api/v1/attendances')->assertOk();
        $this->getJson('/api/v1/attendances/'.$row->id)->assertOk();
        $this->getJson('/api/v1/laporan-absensi?start_date='.self::D.'&end_date='.self::D);

        $sessions = app(SessionResolver::class);
        $window = $sessions->windowOf($row->fresh());
        $this->assertSame(self::D.' 08:00:00', $window['start']->format('Y-m-d H:i:s'));
        $this->assertSame(self::D.' 16:00:00', $window['end']->format('Y-m-d H:i:s'));
        $this->assertSame(self::D.' 23:59:59', $sessions->checkOutClosesAt($row->fresh())->format('Y-m-d H:i:s'));

        $this->assertSame($before, (array) DB::table('attendances')->where('id', $row->id)->first());
    }

    // ── Other writers ────────────────────────────────────────────────────────

    public function test_an_approved_correction_that_creates_the_row_writes_the_snapshot(): void
    {
        $employee = $this->employee();
        $schedule = ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::D, 'shift_id' => $this->morning->id, 'company_location_id' => $this->outlet->id, 'section' => 'Dapur']);

        $this->at(self::D.' 20:00:00');
        $correction = AttendanceCorrection::create([
            'employee_id' => $employee->id,
            'date' => self::D,
            'corrected_check_in' => '08:20',
            'corrected_check_out' => '15:00',
            'reason' => 'Lupa absen',
            'status' => 'pending',
        ]);
        $correction->applyCorrection();

        $row = Attendance::query()->where('employee_id', $employee->id)->sole();
        $this->assertSame(AttendanceStatus::Late, $row->status);
        $this->assertSame(20, $row->late_minutes);
        $this->assertSame(60, $row->early_leave_minutes);
        $this->assertSame($schedule->id, $row->shift_schedule_id);
        $this->assertSame('schedule', $row->sched_source);
        $this->assertSame(self::D.' 08:00:00', $row->sched_start_at->format('Y-m-d H:i:s'));
        $this->assertSame(self::D.' 16:00:00', $row->sched_end_at->format('Y-m-d H:i:s'));
        $this->assertSame(5, $row->sched_tolerance_minutes);
        $this->assertSame(420, $row->sched_normal_minutes);
        $this->assertSame('Pagi', $row->sched_shift_name);
        $this->assertSame('Momoyo Ciledug', $row->sched_outlet_name);
        $this->assertSame('Dapur', $row->sched_section);
    }

    public function test_a_correction_of_a_row_that_was_already_checked_in_leaves_its_missing_snapshot_alone(): void
    {
        $employee = $this->employee();
        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::D, 'shift_id' => $this->morning->id]);
        $row = Attendance::create([
            'employee_id' => $employee->id,
            'date' => self::D,
            'shift_id' => $this->morning->id,
            'check_in' => self::D.' 08:00:00',
            'status' => AttendanceStatus::OnTime,
        ]);

        $this->at(self::D.' 20:00:00');
        AttendanceCorrection::create([
            'employee_id' => $employee->id,
            'date' => self::D,
            'corrected_check_out' => '16:05',
            'reason' => 'Lupa absen pulang',
            'status' => 'pending',
        ])->applyCorrection();

        $row->refresh();
        $this->assertNotNull($row->check_out);
        $this->assertFalse($row->hasSnapshot());
    }

    public function test_a_freelancer_without_any_shift_gets_a_snapshot_without_hours(): void
    {
        $freelancer = $this->freelancer();

        $this->at(self::D.' 20:00:00');
        AttendanceCorrection::create([
            'freelancer_id' => $freelancer->id,
            'date' => self::D,
            'corrected_check_in' => '10:00',
            'corrected_check_out' => '15:00',
            'reason' => 'Lupa absen',
            'status' => 'pending',
        ])->applyCorrection();

        $row = Attendance::query()->where('freelancer_id', $freelancer->id)->sole();
        $this->assertTrue($row->hasSnapshot());
        $this->assertSame('none', $row->sched_source);
        $this->assertNull($row->sched_start_at);
        $this->assertNull($row->sched_end_at);
        $this->assertNull($row->shift_schedule_id);
        $this->assertSame(0, (int) $row->early_leave_minutes);
    }

    public function test_a_roster_change_of_the_checked_in_schedule_is_refused_and_the_snapshot_stays(): void
    {
        $employee = $this->employee();
        $late = Shift::create(['company_id' => $this->company->id, 'name' => 'Siang', 'code' => 'S', 'start_time' => '10:00', 'end_time' => '18:00', 'late_tolerance_minutes' => 0]);
        $schedule = ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::D, 'shift_id' => $this->morning->id]);

        $this->at(self::D.' 08:30:00');
        $row = $this->checkedIn($employee);
        $this->assertSame(30, $row->late_minutes);

        // D4: neither a change nor a removal of the schedule the row is locked to goes through.
        foreach ([fn () => $schedule->update(['shift_id' => $late->id]), fn () => $schedule->delete()] as $change) {
            try {
                $change();
                $this->fail('The schedule of a checked-in row must not change.');
            } catch (ValidationException $exception) {
                $this->assertSame([ShiftSchedule::LOCKED_MESSAGE], $exception->errors()['date']);
            }
        }

        $row->refresh();
        $this->assertSame(AttendanceStatus::Late, $row->status);
        $this->assertSame(30, $row->late_minutes);
        $this->assertSame($this->morning->id, (int) $row->shift_id);
        $this->assertSame($schedule->id, $row->shift_schedule_id);
        $this->assertSame(self::D.' 08:00:00', $row->sched_start_at->format('Y-m-d H:i:s'));
        $this->assertSame('schedule', $row->sched_source);
        $this->assertSame($this->morning->id, $schedule->fresh()->shift_id);
    }

    public function test_recalculation_never_writes_the_snapshot_and_reports_an_unchanged_verdict(): void
    {
        $employee = $this->employee();
        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::D, 'shift_id' => $this->morning->id]);
        $row = Attendance::create([
            'employee_id' => $employee->id,
            'date' => self::D,
            'shift_id' => $this->morning->id,
            'check_in' => self::D.' 08:00:00',
            'status' => AttendanceStatus::OnTime,
            'late_minutes' => 0,
            'check_in_late_minutes' => 0,
        ]);

        $this->assertFalse(app(AttendanceLatenessService::class)->recalculate($row));
        $this->assertNull($row->fresh()->sched_source);
        $this->assertSame(AttendanceStatus::OnTime, $row->fresh()->status);
    }

    public function test_an_admin_edit_that_moves_the_times_rewrites_the_snapshot(): void
    {
        $employee = $this->employee();
        $schedule = ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::D, 'shift_id' => $this->morning->id]);
        $row = Attendance::create([
            'employee_id' => $employee->id,
            'date' => self::D,
            'shift_id' => $this->morning->id,
            'check_in' => self::D.' 08:00:00',
            'status' => AttendanceStatus::OnTime,
            'late_minutes' => 0,
            'check_in_late_minutes' => 0,
        ]);
        $this->actingAsAdmin();

        Livewire::test(EditAttendance::class, ['record' => $row->getRouteKey()])
            ->fillForm(['check_in' => self::D.' 08:40:00'])
            ->call('save')
            ->assertHasNoFormErrors();

        $row->refresh();
        $this->assertSame(40, $row->late_minutes);
        $this->assertSame($schedule->id, $row->shift_schedule_id);
        $this->assertSame('schedule', $row->sched_source);
        $this->assertSame(self::D.' 08:00:00', $row->sched_start_at->format('Y-m-d H:i:s'));
    }

    public function test_an_admin_edit_that_gives_a_check_in_to_an_absent_row_writes_the_snapshot_without_judging_it(): void
    {
        $employee = $this->employee();
        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::D, 'shift_id' => $this->morning->id]);
        $row = Attendance::create(['employee_id' => $employee->id, 'date' => self::D, 'status' => AttendanceStatus::Absent]);
        $this->actingAsAdmin();

        Livewire::test(EditAttendance::class, ['record' => $row->getRouteKey()])
            ->fillForm(['check_in' => self::D.' 08:40:00'])
            ->call('save')
            ->assertHasNoFormErrors();

        $row->refresh();
        $this->assertSame(AttendanceStatus::Absent, $row->status);
        $this->assertSame('schedule', $row->sched_source);
        $this->assertSame($this->morning->id, (int) $row->shift_id);
        $this->assertSame(self::D.' 16:00:00', $row->sched_end_at->format('Y-m-d H:i:s'));
    }

    public function test_an_admin_edit_that_keeps_the_times_does_not_fill_in_a_missing_snapshot(): void
    {
        $employee = $this->employee();
        $row = Attendance::create([
            'employee_id' => $employee->id,
            'date' => self::D,
            'shift_id' => $this->morning->id,
            'check_in' => self::D.' 08:00:00',
            'status' => AttendanceStatus::OnTime,
        ]);
        $this->actingAsAdmin();

        Livewire::test(EditAttendance::class, ['record' => $row->getRouteKey()])
            ->fillForm(['notes' => 'Diperiksa'])
            ->call('save')
            ->assertHasNoFormErrors();

        $row->refresh();
        $this->assertSame('Diperiksa', $row->notes);
        $this->assertFalse($row->hasSnapshot());
    }

    public function test_a_stale_admin_form_is_refused_after_the_snapshot_changed(): void
    {
        $employee = $this->employee();
        $schedule = ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::D, 'shift_id' => $this->morning->id]);
        $this->at(self::D.' 08:00:00');
        $row = $this->checkedIn($employee);
        $this->actingAsAdmin();

        $form = Livewire::test(EditAttendance::class, ['record' => $row->getRouteKey()]);

        // An approved correction re-snapshots the row meanwhile (the only writer allowed to under D4).
        Attendance::query()->whereKey($row->id)->update(['sched_shift_name' => 'Pagi (koreksi)']);

        $form->fillForm(['notes' => 'Catatan lama'])->call('save');

        $this->assertNull($row->fresh()->notes);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function employee(array $attributes = []): Employee
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['tenant_id' => $this->company->tenant_id])->save();
        $user->assignRole('Employee');

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
        $user->forceFill(['tenant_id' => $this->company->tenant_id])->save();
        $user->assignRole('Freelancer');

        return Freelancer::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'freelancer_code' => 'FL-'.$user->id,
            'full_name' => "Lepas {$user->id}",
            'start_date' => '2026-01-01',
            'status' => 'active',
        ]);
    }

    private function actingAsAdmin(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('Superadmin');
        $this->actingAs($admin);
    }

    private function at(string $dateTime): void
    {
        $this->travelTo(Carbon::parse($dateTime));
    }

    /**
     * Check in through the API and return the stored row.
     *
     * @param  array<string, mixed>  $data
     */
    private function checkedIn(Employee $employee, array $data = []): Attendance
    {
        Sanctum::actingAs(User::findOrFail($employee->user_id));

        $id = $this->post('/api/v1/attendance/check-in', $data, ['Accept' => 'application/json'])->assertOk()->json('attendance.id');

        return Attendance::findOrFail($id);
    }

    private function checkOut(Employee $employee): TestResponse
    {
        Sanctum::actingAs(User::findOrFail($employee->user_id));

        return $this->post('/api/v1/attendance/check-out', [], ['Accept' => 'application/json']);
    }
}
