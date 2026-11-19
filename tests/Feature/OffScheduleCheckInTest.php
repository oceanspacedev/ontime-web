<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Filament\Pages\AbsenHariIni;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\ShiftScheduleChange;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalFlowService;
use App\Services\AttendanceCheckService;
use App\Services\AttendanceLatenessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * "Masuk di luar jadwal": an emergency backup check-in on a rostered day off, or by a freelancer
 * without any roster, waits for approval; once approved the rosters of the date follow it.
 */
class OffScheduleCheckInTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-11-16';

    private Company $company;

    private Division $division;

    private Shift $morning;

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

        foreach (['Superadmin', 'Admin', 'PIC', 'Koordinator', 'Approver', 'BOD', 'Employee', 'Intern', 'Freelancer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->travelTo(Carbon::parse(self::MONDAY.' 06:00:00'));

        $tenant = Tenant::factory()->create(['name' => 'Klien A', 'code' => 'KA', 'is_active' => true]);
        $this->company = new Company(['name' => 'PT Resto', 'code' => 'RESTO', 'is_active' => true]);
        $this->company->tenant_id = $tenant->id;
        $this->company->save();
        app(ApprovalFlowService::class)->createDefaultFlows($this->company);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Dapur', 'code' => 'DPR', 'is_active' => true]);
        $this->company->policy()->update([
            'require_gps' => false,
            'require_photo' => false,
            'require_face_recognition' => false,
            'require_liveness' => false,
            'work_start_time' => '08:00:00',
            'work_end_time' => '17:00:00',
            'late_tolerance_minutes' => 15,
        ]);
        $this->company->unsetRelation('policy');

        $this->morning = $this->shift('Pagi', '08:00', '16:00');
        $this->outlet = CompanyLocation::create(['company_id' => $this->company->id, 'name' => 'Outlet Mall', 'latitude' => -6.2, 'longitude' => 106.8, 'radius_meters' => 100, 'is_active' => true]);
    }

    // ── Check-in ────────────────────────────────────────────────────────────

    public function test_employee_check_in_on_a_rostered_day_off_waits_for_approval(): void
    {
        $employee = $this->employee('Andi');
        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::MONDAY, 'is_day_off' => true]);

        $this->at('08:05:00');
        $this->checkIn($employee->user, ['cover_shift_id' => $this->morning->id])
            ->assertOk()
            ->assertJsonPath('requires_approval', true)
            ->assertJsonPath('approval_reasons.0', 'masuk di luar jadwal')
            ->assertJsonPath('attendance.is_off_schedule', true)
            ->assertJsonPath('attendance.off_schedule_label', 'Masuk di luar jadwal · shift Pagi');

        $row = $this->rowOf('employee_id', $employee->id);
        $this->assertSame(AttendanceStatus::PendingApproval, $row->status);
        $this->assertTrue($row->is_off_schedule);
        $this->assertSame(0, $row->late_minutes);
        $this->assertSame(5, $row->check_in_late_minutes);
        $this->assertSame($this->morning->id, $row->shift_id);
        $this->assertSame('Masuk di luar jadwal', $row->notes);
        $this->assertSame(1, $row->approvalSteps()->where('status', 'pending')->count());
    }

    public function test_off_schedule_check_in_without_a_cover_shift_is_refused_for_every_worker_type(): void
    {
        $employee = $this->employee('Andi', $this->morning);
        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::MONDAY, 'is_day_off' => true]);
        $freelancer = $this->freelancer('Citra');
        $this->at('10:00:00');

        foreach ([$employee->user, $freelancer->user] as $user) {
            $this->checkIn($user)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('cover_shift_id')
                ->assertJsonPath('message', 'Pilih shift yang Anda gantikan untuk masuk di luar jadwal.');
        }

        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_off_schedule_check_in_without_any_active_shift_tells_the_worker_to_ask_the_pic(): void
    {
        $this->morning->update(['is_active' => false]);
        $freelancer = $this->freelancer('Citra');
        $this->at('10:00:00');

        $this->checkIn($freelancer->user)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Belum ada shift aktif di badan usaha Anda, jadi masuk di luar jadwal belum bisa dicatat. Hubungi koordinator atau PIC.');
    }

    public function test_web_check_in_on_a_day_off_needs_the_cover_shift_and_is_not_stored_as_on_time(): void
    {
        $employee = $this->employee('Andi');
        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::MONDAY, 'is_day_off' => true]);
        $this->at('08:00:00');
        $this->actingAs($employee->user);

        Livewire::test(AbsenHariIni::class)
            ->assertSee('Masuk di luar jadwal')
            ->assertSee('Shift yang digantikan (wajib)')
            ->call('processCheckIn')
            ->assertReturned(fn (array $result): bool => $result['ok'] === false && $result['field'] === 'cover_shift_id');
        $this->assertDatabaseCount('attendances', 0);

        Livewire::test(AbsenHariIni::class)
            ->set('offScheduleShiftId', (string) $this->morning->id)
            ->call('processCheckIn');

        $row = $this->rowOf('employee_id', $employee->id);
        $this->assertSame(AttendanceStatus::PendingApproval, $row->status);
        $this->assertTrue($row->is_off_schedule);
        $this->assertSame($this->morning->id, $row->shift_id);
    }

    /**
     * A fixed-hours company (uses_shift_roster off); a company on shift rosters: RosterCompanyUnscheduledTest.
     */
    public function test_employee_without_a_dated_schedule_keeps_the_default_shift(): void
    {
        $employee = $this->employee('Andi', $this->morning);
        $this->at('07:55:00');

        $this->checkIn($employee->user)->assertOk()->assertJsonPath('requires_approval', false);

        $row = $this->rowOf('employee_id', $employee->id);
        $this->assertSame(AttendanceStatus::OnTime, $row->status);
        $this->assertFalse($row->is_off_schedule);
        $this->assertSame($this->morning->id, $row->shift_id);
    }

    public function test_cover_shift_on_a_normal_working_day_is_ignored(): void
    {
        $evening = $this->shift('Malam', '16:00', '23:00');
        $freelancer = $this->freelancer('Budi');
        ShiftSchedule::create(['freelancer_id' => $freelancer->id, 'date' => self::MONDAY, 'shift_id' => $this->morning->id]);
        $this->at('08:20:00');

        $this->checkIn($freelancer->user, ['cover_shift_id' => $evening->id])->assertOk()->assertJsonPath('requires_approval', false);

        $row = $this->rowOf('freelancer_id', $freelancer->id);
        $this->assertSame(AttendanceStatus::Late, $row->status);
        $this->assertSame(20, $row->late_minutes);
        $this->assertSame($this->morning->id, $row->shift_id);
        $this->assertFalse($row->is_off_schedule);
    }

    public function test_freelancer_cover_shift_judges_lateness_while_pending(): void
    {
        $freelancer = $this->freelancer('Citra');
        $this->at('08:25:30');

        $this->checkIn($freelancer->user, ['cover_shift_id' => $this->morning->id])->assertOk()->assertJsonPath('requires_approval', true);

        $row = $this->rowOf('freelancer_id', $freelancer->id);
        $this->assertSame(AttendanceStatus::PendingApproval, $row->status);
        $this->assertSame($this->morning->id, $row->shift_id);
        $this->assertSame(0, $row->late_minutes, 'Not late in reports before approval.');
        $this->assertSame(25, $row->check_in_late_minutes);
        $this->assertSame(25, $row->reportedLateMinutes());
    }

    public function test_cover_shift_window_refuses_a_check_in_hours_before_it_starts(): void
    {
        $evening = $this->shift('Malam', '16:00', '23:00');
        $freelancer = $this->freelancer('Citra');
        $this->at('09:00:00');

        $this->checkIn($freelancer->user, ['cover_shift_id' => $evening->id])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Shift dimulai pukul 16:00. Absen masuk dibuka pukul 15:00.');

        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_cover_shift_of_another_company_or_inactive_is_refused(): void
    {
        $other = Company::create(['name' => 'PT Lain', 'code' => 'LAIN', 'is_active' => true]);
        $foreign = Shift::create(['company_id' => $other->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '16:00', 'late_tolerance_minutes' => 0, 'is_active' => true]);
        $inactive = $this->shift('Lama', '08:00', '16:00', active: false);
        $freelancer = $this->freelancer('Citra');
        $this->at('08:00:00');

        foreach ([$foreign->id, $inactive->id, 999999] as $shiftId) {
            $this->checkIn($freelancer->user, ['cover_shift_id' => $shiftId])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('cover_shift_id');
        }

        $this->checkIn($freelancer->user, ['cover_shift_id' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors('cover_shift_id');
        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_replaced_worker_must_be_a_rostered_colleague_of_the_same_company(): void
    {
        $freelancer = $this->freelancer('Citra');
        $notRostered = $this->employee('Dewi');
        $offToday = $this->employee('Eko');
        ShiftSchedule::create(['employee_id' => $offToday->id, 'date' => self::MONDAY, 'is_day_off' => true]);
        $other = Company::create(['name' => 'PT Lain', 'code' => 'LAIN', 'is_active' => true]);
        $stranger = $this->employee('Fajar', company: $other);
        ShiftSchedule::create(['employee_id' => $stranger->id, 'date' => self::MONDAY, 'shift_id' => $this->morning->id]);
        $this->at('08:00:00');

        foreach (['employee:'.$notRostered->id, 'employee:'.$offToday->id, 'employee:'.$stranger->id, 'freelancer:'.$freelancer->id, 'employee:999999'] as $key) {
            $this->checkIn($freelancer->user, ['cover_shift_id' => $this->morning->id, 'replaces_worker' => $key])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('replaces_worker');
        }

        $this->checkIn($freelancer->user, ['replaces_worker' => 'boss:1'])->assertUnprocessable()->assertJsonValidationErrors('replaces_worker');
        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_replacing_a_colleague_records_them_and_uses_their_outlet(): void
    {
        $budi = $this->employee('Budi');
        ShiftSchedule::create(['employee_id' => $budi->id, 'date' => self::MONDAY, 'shift_id' => $this->morning->id, 'company_location_id' => $this->outlet->id]);
        $freelancer = $this->freelancer('Citra');
        $this->at('08:05:00');

        $this->checkIn($freelancer->user, ['cover_shift_id' => $this->morning->id, 'replaces_worker' => 'employee:'.$budi->id])
            ->assertOk()
            ->assertJsonPath('approval_reasons.0', 'masuk di luar jadwal (menggantikan Budi)');

        $row = $this->rowOf('freelancer_id', $freelancer->id);
        $this->assertSame('employee', $row->replaced_worker_type);
        $this->assertSame($budi->id, $row->replaced_worker_id);
        $this->assertSame($this->outlet->id, $row->company_location_id);
        $this->assertSame('Masuk di luar jadwal (menggantikan Budi)', $row->notes);
        $this->assertTrue($row->replacedWorker()->is($budi));
    }

    public function test_geofence_of_an_off_schedule_check_in_is_the_replaced_colleagues_outlet(): void
    {
        $this->company->policy()->update(['require_gps' => true]);
        $budi = $this->employee('Budi');
        ShiftSchedule::create(['employee_id' => $budi->id, 'date' => self::MONDAY, 'shift_id' => $this->morning->id, 'company_location_id' => $this->outlet->id]);
        $freelancer = $this->freelancer('Citra');
        $this->at('08:00:00');

        $this->checkIn($freelancer->user, ['cover_shift_id' => $this->morning->id, 'replaces_worker' => 'employee:'.$budi->id, 'latitude' => -6.9, 'longitude' => 107.6])
            ->assertOk()
            ->assertJsonPath('approval_reasons.0', 'masuk di luar jadwal (menggantikan Budi)')
            ->assertJsonPath('approval_reasons.1', 'di luar area outlet jadwal (Outlet Mall)');
    }

    // ── Approval ────────────────────────────────────────────────────────────

    public function test_approval_rosters_the_backup_and_gives_the_replaced_colleague_the_day_off(): void
    {
        $budi = $this->employee('Budi');
        $budiSchedule = ShiftSchedule::create(['employee_id' => $budi->id, 'date' => self::MONDAY, 'shift_id' => $this->morning->id, 'company_location_id' => $this->outlet->id]);
        $freelancer = $this->freelancer('Citra');
        $this->at('08:20:00');
        $this->checkIn($freelancer->user, ['cover_shift_id' => $this->morning->id, 'replaces_worker' => 'employee:'.$budi->id])->assertOk();
        $row = $this->rowOf('freelancer_id', $freelancer->id);

        $this->approve($row);

        $row->refresh();
        $this->assertSame(AttendanceStatus::Late, $row->status);
        $this->assertSame(20, $row->late_minutes);

        $own = ShiftSchedule::query()->where('freelancer_id', $freelancer->id)->whereDate('date', self::MONDAY)->sole();
        $this->assertFalse($own->is_day_off);
        $this->assertSame($this->morning->id, $own->shift_id);
        $this->assertSame($this->outlet->id, $own->company_location_id);

        $budiSchedule->refresh();
        $this->assertTrue($budiSchedule->is_day_off);
        $this->assertSame('Digantikan oleh Citra', $budiSchedule->notes);
    }

    /**
     * Real-device QA (17 Nov 2026): the PIC's mobile approval inbox failed with "Attempted to lazy load
     * [shift] on model Attendance" as soon as a pending backup check-in was in it.
     */
    public function test_pic_approval_inbox_lists_a_pending_backup_with_its_label(): void
    {
        $budi = $this->employee('Budi');
        ShiftSchedule::create(['employee_id' => $budi->id, 'date' => self::MONDAY, 'shift_id' => $this->morning->id, 'company_location_id' => $this->outlet->id]);
        $freelancer = $this->freelancer('Citra');
        $this->at('08:05:00');
        $this->checkIn($freelancer->user, ['cover_shift_id' => $this->morning->id, 'replaces_worker' => 'employee:'.$budi->id])->assertOk();
        // A second pending backup: lazy loading is only refused for models loaded together with others.
        $dewi = $this->freelancer('Dewi');
        $this->checkIn($dewi->user, ['cover_shift_id' => $this->morning->id])->assertOk();

        $pic = User::factory()->create(['is_active' => true, 'data_scope' => 'all']);
        $pic->forceFill(['tenant_id' => $this->company->tenant_id])->save();
        $pic->assignRole(['Admin', 'PIC']);
        Sanctum::actingAs($pic);

        $response = $this->getJson('/api/v1/approvals/pending')->assertOk();

        $this->assertStringContainsString(
            'Masuk di luar jadwal · menggantikan Budi · shift Pagi',
            json_encode($response->json(), JSON_UNESCAPED_UNICODE),
        );
    }

    public function test_cover_approval_logs_its_roster_writes_with_the_source_cover(): void
    {
        $budi = $this->employee('Budi');
        $budiSchedule = ShiftSchedule::create(['employee_id' => $budi->id, 'date' => self::MONDAY, 'shift_id' => $this->morning->id, 'company_location_id' => $this->outlet->id]);
        $freelancer = $this->freelancer('Citra');
        $this->at('08:05:00');
        $this->checkIn($freelancer->user, ['cover_shift_id' => $this->morning->id, 'replaces_worker' => 'employee:'.$budi->id])->assertOk();

        $this->approve($this->rowOf('freelancer_id', $freelancer->id));

        $citraSchedule = ShiftSchedule::query()->where('freelancer_id', $freelancer->id)->sole();
        $this->assertSame($citraSchedule->id, $this->rowOf('freelancer_id', $freelancer->id)->shift_schedule_id);
        $this->assertTrue($budiSchedule->fresh()->is_day_off);

        $changes = ShiftScheduleChange::query()->where('source', 'cover')->orderBy('id')->get();
        $this->assertSame([[$citraSchedule->id, 'created'], [$budiSchedule->id, 'updated']], $changes->map(fn (ShiftScheduleChange $change): array => [$change->shift_schedule_id, $change->action])->all());
        $this->assertSame($this->morning->id, $changes[1]->before['shift_id']);
        $this->assertTrue($changes[1]->after['is_day_off']);
    }

    public function test_approval_turns_the_day_off_into_the_cover_shift_for_an_employee(): void
    {
        $employee = $this->employee('Andi');
        $dayOff = ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::MONDAY, 'is_day_off' => true]);
        $this->at('08:00:00');
        $this->checkIn($employee->user, ['cover_shift_id' => $this->morning->id])->assertOk();

        $this->approve($this->rowOf('employee_id', $employee->id));

        $dayOff->refresh();
        $this->assertFalse($dayOff->is_day_off);
        $this->assertSame($this->morning->id, $dayOff->shift_id);
        $this->assertSame(AttendanceStatus::OnTime, $this->rowOf('employee_id', $employee->id)->status);
    }

    public function test_approved_backup_is_judged_against_its_cover_shift_after_a_recalculation(): void
    {
        $closing = $this->shift('Closing', '11:00', '19:00');
        $employee = $this->employee('Andi', $this->morning);
        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::MONDAY, 'is_day_off' => true]);
        $this->at('11:00:00');
        $this->checkIn($employee->user, ['cover_shift_id' => $closing->id])->assertOk();
        $this->approve($this->rowOf('employee_id', $employee->id));

        app(AttendanceLatenessService::class)->recalculate($this->rowOf('employee_id', $employee->id));

        $row = $this->rowOf('employee_id', $employee->id);
        $this->assertSame(AttendanceStatus::OnTime, $row->status);
        $this->assertSame(0, $row->late_minutes, 'The default 08:00 shift must not make the 11:00 backup late.');
        $this->assertSame($closing->id, ShiftSchedule::query()->where('employee_id', $employee->id)->sole()->shift_id);

        // Early leave is measured against the cover shift's 19:00 end, not the default 16:00.
        $this->at('18:00:00');
        $this->postJson('/api/v1/attendance/check-out')->assertOk();
        $this->assertSame(60, $this->rowOf('employee_id', $employee->id)->early_leave_minutes);
    }

    public function test_pending_freelancer_backup_leaves_early_against_the_cover_shift(): void
    {
        $freelancer = $this->freelancer('Citra');
        $this->at('08:00:00');
        $this->checkIn($freelancer->user, ['cover_shift_id' => $this->morning->id])->assertOk();

        $this->at('15:00:00');
        $this->postJson('/api/v1/attendance/check-out')->assertOk();

        $this->assertSame(60, $this->rowOf('freelancer_id', $freelancer->id)->early_leave_minutes);
    }

    public function test_legacy_backup_without_a_shift_is_never_judged_against_the_default_shift_or_company_hours(): void
    {
        // What an approval wrote before the cover shift became required: a roster with no shift and no day off.
        $employee = $this->employee('Andi', $this->morning);
        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::MONDAY, 'is_day_off' => false]);
        $row = Attendance::create([
            'employee_id' => $employee->id, 'date' => self::MONDAY, 'segment' => 1,
            'check_in' => self::MONDAY.' 11:00:00', 'check_out' => self::MONDAY.' 15:00:00',
            'status' => AttendanceStatus::OnTime, 'late_minutes' => 0, 'is_off_schedule' => true,
        ]);
        $this->assertSame(0, $row->early_leave_minutes);

        app(AttendanceLatenessService::class)->recalculate($row);

        $row->refresh();
        $this->assertSame(AttendanceStatus::OnTime, $row->status);
        $this->assertSame(0, $row->late_minutes);
        $this->assertSame(0, $row->early_leave_minutes);

        // An unrostered freelancer's row without a shift has no company-hours end to leave early from.
        $freelancer = $this->freelancer('Citra');
        $freelancerRow = Attendance::create([
            'freelancer_id' => $freelancer->id, 'date' => self::MONDAY, 'segment' => 1,
            'check_in' => self::MONDAY.' 10:00:00', 'check_out' => self::MONDAY.' 16:00:00', 'status' => AttendanceStatus::OnTime,
        ]);
        $this->assertSame(0, $freelancerRow->early_leave_minutes);
    }

    public function test_approving_a_legacy_backup_without_a_shift_writes_no_shiftless_roster(): void
    {
        $employee = $this->employee('Andi', $this->morning);
        $dayOff = ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::MONDAY, 'is_day_off' => true]);
        $row = Attendance::create([
            'employee_id' => $employee->id, 'date' => self::MONDAY, 'segment' => 1, 'check_in' => self::MONDAY.' 11:00:00',
            'status' => AttendanceStatus::PendingApproval, 'late_minutes' => 0, 'check_in_late_minutes' => 0, 'is_off_schedule' => true,
        ]);

        $row->applyGeofenceApproval();

        $this->assertTrue($dayOff->fresh()->is_day_off);
        $this->assertSame(AttendanceStatus::OnTime, $row->fresh()->status);
    }

    public function test_replaced_colleague_who_came_anyway_keeps_their_roster(): void
    {
        $budi = $this->employee('Budi');
        $budiSchedule = ShiftSchedule::create(['employee_id' => $budi->id, 'date' => self::MONDAY, 'shift_id' => $this->morning->id]);
        $freelancer = $this->freelancer('Citra');
        $this->at('08:00:00');
        $this->checkIn($freelancer->user, ['cover_shift_id' => $this->morning->id, 'replaces_worker' => 'employee:'.$budi->id])->assertOk();

        $this->at('08:30:00');
        $this->checkIn($budi->user)->assertOk();

        $this->approve($this->rowOf('freelancer_id', $freelancer->id));

        $this->assertFalse($budiSchedule->fresh()->is_day_off);
        $this->assertSame(AttendanceStatus::Late, $this->rowOf('employee_id', $budi->id)->status);
    }

    public function test_replaced_colleague_checking_in_after_approval_is_off_schedule(): void
    {
        $budi = $this->employee('Budi');
        ShiftSchedule::create(['employee_id' => $budi->id, 'date' => self::MONDAY, 'shift_id' => $this->morning->id]);
        $freelancer = $this->freelancer('Citra');
        $this->at('08:00:00');
        $this->checkIn($freelancer->user, ['cover_shift_id' => $this->morning->id, 'replaces_worker' => 'employee:'.$budi->id])->assertOk();
        $this->approve($this->rowOf('freelancer_id', $freelancer->id));

        $this->at('09:00:00');
        $this->checkIn($budi->user, ['cover_shift_id' => $this->morning->id])->assertOk()->assertJsonPath('requires_approval', true);

        $this->assertTrue($this->rowOf('employee_id', $budi->id)->is_off_schedule);
    }

    public function test_colleague_check_in_racing_the_approval_is_refused_once_the_day_off_landed(): void
    {
        $budi = $this->employee('Budi');
        ShiftSchedule::create(['employee_id' => $budi->id, 'date' => self::MONDAY, 'shift_id' => $this->morning->id]);
        $this->at('08:30:00');

        // Budi's check-in reads his working roster, then the approval giving him the day off commits
        // before his check-in is saved.
        $flipped = false;
        ShiftSchedule::retrieved(function (ShiftSchedule $schedule) use ($budi, &$flipped): void {
            if (! $flipped && (int) $schedule->employee_id === $budi->id) {
                $flipped = true;
                DB::table('shift_schedules')->where('id', $schedule->id)->update(['is_day_off' => true, 'shift_id' => null, 'notes' => 'Digantikan oleh Citra']);
            }
        });

        $this->checkIn($budi->user)->assertStatus(409)->assertJsonPath('message', AttendanceCheckService::ROSTER_CHANGED_MESSAGE);
        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_approval_keeps_the_roster_of_a_colleague_whose_check_in_landed_first(): void
    {
        $budi = $this->employee('Budi');
        $budiSchedule = ShiftSchedule::create(['employee_id' => $budi->id, 'date' => self::MONDAY, 'shift_id' => $this->morning->id]);
        $freelancer = $this->freelancer('Citra');
        $this->at('08:00:00');
        $this->checkIn($freelancer->user, ['cover_shift_id' => $this->morning->id, 'replaces_worker' => 'employee:'.$budi->id])->assertOk();
        $row = $this->rowOf('freelancer_id', $freelancer->id);

        // Budi's check-in commits right after the approval started (rows written directly, as a concurrent request would).
        Attendance::create(['employee_id' => $budi->id, 'date' => self::MONDAY, 'segment' => 1, 'check_in' => self::MONDAY.' 08:00:00', 'status' => AttendanceStatus::OnTime, 'late_minutes' => 0]);
        $this->approve($row);

        $this->assertFalse($budiSchedule->fresh()->is_day_off);
        $this->assertNull($budiSchedule->fresh()->notes);
    }

    public function test_rejection_changes_no_roster(): void
    {
        $budi = $this->employee('Budi');
        $budiSchedule = ShiftSchedule::create(['employee_id' => $budi->id, 'date' => self::MONDAY, 'shift_id' => $this->morning->id]);
        $freelancer = $this->freelancer('Citra');
        $this->at('08:00:00');
        $this->checkIn($freelancer->user, ['cover_shift_id' => $this->morning->id, 'replaces_worker' => 'employee:'.$budi->id])->assertOk();
        $row = $this->rowOf('freelancer_id', $freelancer->id);

        app(ApprovalFlowService::class)->rejectStep($row, $this->approver(), 'Tidak diminta');

        $this->assertSame(AttendanceStatus::Rejected, $row->fresh()->status);
        $this->assertFalse($budiSchedule->fresh()->is_day_off);
        $this->assertNull($budiSchedule->fresh()->notes);
        $this->assertSame(0, ShiftSchedule::query()->where('freelancer_id', $freelancer->id)->count());
    }

    public function test_roster_given_by_the_pic_while_pending_is_not_overwritten_by_the_approval(): void
    {
        $evening = $this->shift('Sore', '09:00', '17:00');
        $freelancer = $this->freelancer('Citra');
        $this->at('08:30:00');
        $this->checkIn($freelancer->user, ['cover_shift_id' => $this->morning->id])->assertOk();
        $row = $this->rowOf('freelancer_id', $freelancer->id);
        $this->assertSame(30, $row->check_in_late_minutes);

        // The PIC rosters the freelancer for 09:00 meanwhile: D4, the check-in stays judged against the
        // cover shift it was made for, and the roster change does not touch it.
        ShiftSchedule::create(['freelancer_id' => $freelancer->id, 'date' => self::MONDAY, 'shift_id' => $evening->id]);
        $this->assertSame(30, $row->fresh()->check_in_late_minutes);
        $this->assertSame($this->morning->id, $row->fresh()->shift_id);

        $this->approve($row);

        $this->assertSame($evening->id, ShiftSchedule::query()->where('freelancer_id', $freelancer->id)->sole()->shift_id);
        $this->assertSame(AttendanceStatus::Late, $row->fresh()->status);
        $this->assertSame(30, $row->fresh()->late_minutes);
    }

    public function test_second_approval_round_of_the_same_row_is_harmless(): void
    {
        $freelancer = $this->freelancer('Citra');
        $this->at('08:00:00');
        $this->checkIn($freelancer->user, ['cover_shift_id' => $this->morning->id])->assertOk();
        $row = $this->rowOf('freelancer_id', $freelancer->id);
        $this->approve($row);

        $row->fresh()->applyGeofenceApproval();

        $this->assertSame(1, ShiftSchedule::query()->where('freelancer_id', $freelancer->id)->count());
        $this->assertSame(AttendanceStatus::OnTime, $row->fresh()->status);
    }

    // ── Overnight and split cover shifts ────────────────────────────────────

    public function test_overnight_cover_after_midnight_belongs_to_the_evening_it_started(): void
    {
        $night = $this->shift('Malam', '22:00', '06:00');
        $freelancer = $this->freelancer('Citra');
        $this->travelTo(Carbon::parse('2026-11-17 00:30:00'));

        $this->checkIn($freelancer->user, ['cover_shift_id' => $night->id])->assertOk()->assertJsonPath('requires_approval', true);

        $row = Attendance::query()->where('freelancer_id', $freelancer->id)->sole();
        $this->assertSame(self::MONDAY, $row->date->toDateString());
        $this->assertSame(150, $row->check_in_late_minutes);

        $this->approve($row);
        $this->assertSame(150, $row->fresh()->late_minutes);
        $this->assertSame($night->id, ShiftSchedule::query()->where('freelancer_id', $freelancer->id)->whereDate('date', self::MONDAY)->sole()->shift_id);

        // Check-out the next morning closes the same row.
        $this->travelTo(Carbon::parse('2026-11-17 06:05:00'));
        $this->postJson('/api/v1/attendance/check-out')->assertOk();
        $this->assertNotNull($row->fresh()->check_out);
        $this->assertSame(0, $row->fresh()->early_leave_minutes);
    }

    public function test_overnight_cover_before_midnight_stays_on_today(): void
    {
        $night = $this->shift('Malam', '22:00', '06:00');
        $freelancer = $this->freelancer('Citra');
        $this->at('21:30:00');

        $this->checkIn($freelancer->user, ['cover_shift_id' => $night->id])->assertOk();

        $row = $this->rowOf('freelancer_id', $freelancer->id);
        $this->assertSame(0, $row->check_in_late_minutes);
    }

    public function test_split_cover_shift_second_segment_is_also_off_schedule(): void
    {
        $split = Shift::create(['company_id' => $this->company->id, 'name' => 'Split', 'start_time' => '08:00', 'end_time' => '12:00', 'second_start_time' => '17:00', 'second_end_time' => '21:00', 'late_tolerance_minutes' => 0, 'is_active' => true]);
        $freelancer = $this->freelancer('Citra');
        $this->at('08:00:00');
        $this->checkIn($freelancer->user, ['cover_shift_id' => $split->id])->assertOk();
        $this->at('12:00:00');
        $this->postJson('/api/v1/attendance/check-out')->assertOk();

        $this->at('15:00:00');
        $this->checkIn($freelancer->user)->assertUnprocessable();

        $this->at('17:10:00');
        $this->checkIn($freelancer->user)->assertOk()->assertJsonPath('requires_approval', true);

        $second = Attendance::query()->where('freelancer_id', $freelancer->id)->where('segment', 2)->sole();
        $this->assertTrue($second->is_off_schedule);
        $this->assertSame($split->id, $second->shift_id);
        $this->assertSame(10, $second->check_in_late_minutes);
    }

    // ── Cover options ───────────────────────────────────────────────────────

    public function test_cover_options_list_company_shifts_and_rostered_colleagues(): void
    {
        $this->shift('Lama', '07:00', '15:00', active: false);
        $other = Company::create(['name' => 'PT Lain', 'code' => 'LAIN', 'is_active' => true]);
        Shift::create(['company_id' => $other->id, 'name' => 'Asing', 'start_time' => '08:00', 'end_time' => '16:00', 'is_active' => true]);
        $budi = $this->employee('Budi');
        ShiftSchedule::create(['employee_id' => $budi->id, 'date' => self::MONDAY, 'shift_id' => $this->morning->id, 'company_location_id' => $this->outlet->id]);
        $dewi = $this->employee('Dewi');
        ShiftSchedule::create(['employee_id' => $dewi->id, 'date' => self::MONDAY, 'is_day_off' => true]);
        $freelancer = $this->freelancer('Citra');
        $this->at('07:30:00');
        $this->checkIn($budi->user)->assertOk();

        Sanctum::actingAs($freelancer->user);
        $response = $this->getJson('/api/v1/attendance/cover-options')->assertOk();

        $response->assertJsonPath('is_off_schedule', true)
            ->assertJsonPath('off_schedule_reason', 'no_schedule')
            ->assertJsonCount(1, 'shifts')
            ->assertJsonPath('shifts.0.id', $this->morning->id)
            ->assertJsonPath('shifts.0.start_time', '08:00')
            ->assertJsonCount(1, 'colleagues')
            ->assertJsonPath('colleagues.0.key', 'employee:'.$budi->id)
            ->assertJsonPath('colleagues.0.name', 'Budi')
            ->assertJsonPath('colleagues.0.outlet.name', 'Outlet Mall')
            ->assertJsonPath('colleagues.0.shift.name', 'Pagi')
            ->assertJsonPath('colleagues.0.has_checked_in', true);
    }

    public function test_cover_options_read_only_the_workers_company_rosters(): void
    {
        $other = Company::create(['name' => 'PT Lain', 'code' => 'LAIN', 'is_active' => true]);
        $otherShift = Shift::create(['company_id' => $other->id, 'name' => 'Asing', 'start_time' => '08:00', 'end_time' => '16:00', 'is_active' => true]);

        foreach (['Fajar', 'Gita', 'Hadi'] as $name) {
            $stranger = $this->employee($name, company: $other);
            ShiftSchedule::create(['employee_id' => $stranger->id, 'date' => self::MONDAY, 'shift_id' => $otherShift->id]);
        }

        $budi = $this->employee('Budi');
        ShiftSchedule::create(['employee_id' => $budi->id, 'date' => self::MONDAY, 'shift_id' => $this->morning->id]);
        $freelancer = $this->freelancer('Citra');

        $loaded = [];
        ShiftSchedule::retrieved(function (ShiftSchedule $schedule) use (&$loaded): void {
            $loaded[] = $schedule->id;
        });

        Sanctum::actingAs($freelancer->user);
        $this->getJson('/api/v1/attendance/cover-options')->assertOk()->assertJsonCount(1, 'colleagues');

        $this->assertSame(
            [ShiftSchedule::query()->where('employee_id', $budi->id)->value('id')],
            array_values(array_unique(array_diff($loaded, ShiftSchedule::query()->where('freelancer_id', $freelancer->id)->pluck('id')->all()))),
            'Rosters of other companies must be filtered out in SQL, not hydrated.',
        );
    }

    public function test_cover_options_for_a_night_shift_after_midnight_list_the_evening_rosters(): void
    {
        $night = $this->shift('Malam', '22:00', '06:00');
        $budi = $this->employee('Budi');
        ShiftSchedule::create(['employee_id' => $budi->id, 'date' => self::MONDAY, 'shift_id' => $night->id]);
        $dewi = $this->employee('Dewi');
        ShiftSchedule::create(['employee_id' => $dewi->id, 'date' => '2026-11-17', 'shift_id' => $this->morning->id]);
        $freelancer = $this->freelancer('Citra');
        $this->travelTo(Carbon::parse('2026-11-17 00:30:00'));
        Sanctum::actingAs($freelancer->user);

        $this->getJson('/api/v1/attendance/cover-options')->assertOk()
            ->assertJsonPath('date', '2026-11-17')
            ->assertJsonPath('colleagues.0.name', 'Dewi');

        $this->getJson('/api/v1/attendance/cover-options?cover_shift_id='.$night->id)->assertOk()
            ->assertJsonPath('date', self::MONDAY)
            ->assertJsonCount(1, 'colleagues')
            ->assertJsonPath('colleagues.0.name', 'Budi');

        // The pick from that list is the one the check-in accepts.
        $this->checkIn($freelancer->user, ['cover_shift_id' => $night->id, 'replaces_worker' => 'employee:'.$budi->id])->assertOk();
        $this->assertSame('Masuk di luar jadwal · menggantikan Budi · shift Malam', Attendance::query()->where('freelancer_id', $freelancer->id)->sole()->offScheduleLabel());

        $this->getJson('/api/v1/attendance/cover-options?cover_shift_id=abc')->assertUnprocessable()->assertJsonValidationErrors('cover_shift_id');
    }

    public function test_attendance_api_exposes_the_off_schedule_details_to_approvers(): void
    {
        $budi = $this->employee('Budi');
        ShiftSchedule::create(['employee_id' => $budi->id, 'date' => self::MONDAY, 'shift_id' => $this->morning->id]);
        $freelancer = $this->freelancer('Citra');
        $this->at('08:00:00');
        $this->checkIn($freelancer->user, ['cover_shift_id' => $this->morning->id, 'replaces_worker' => 'employee:'.$budi->id])->assertOk();
        $row = $this->rowOf('freelancer_id', $freelancer->id);

        Sanctum::actingAs($this->approver());
        $this->getJson('/api/v1/approvals/pending')->assertOk()
            ->assertJsonPath('geofence_attendances.0.is_off_schedule', true)
            ->assertJsonPath('geofence_attendances.0.replaced_worker.key', 'employee:'.$budi->id)
            ->assertJsonPath('geofence_attendances.0.replaced_worker.name', 'Budi')
            ->assertJsonPath('geofence_attendances.0.off_schedule_label', 'Masuk di luar jadwal · menggantikan Budi · shift Pagi');

        $this->assertNull(Attendance::create(['employee_id' => $budi->id, 'date' => self::MONDAY, 'segment' => 1, 'status' => AttendanceStatus::OnTime])->offScheduleLabel());
        $this->assertNotNull($row->offScheduleLabel());
    }

    public function test_cover_options_say_a_rostered_day_is_not_off_schedule(): void
    {
        $employee = $this->employee('Andi');
        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::MONDAY, 'shift_id' => $this->morning->id]);
        Sanctum::actingAs($employee->user);

        $this->getJson('/api/v1/attendance/cover-options')->assertOk()
            ->assertJsonPath('is_off_schedule', false)
            ->assertJsonPath('off_schedule_reason', null);

        ShiftSchedule::query()->where('employee_id', $employee->id)->update(['is_day_off' => true, 'shift_id' => null]);
        $this->getJson('/api/v1/attendance/cover-options')->assertOk()->assertJsonPath('off_schedule_reason', 'day_off');
    }

    public function test_cover_options_need_a_worker_profile(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/attendance/cover-options')->assertUnprocessable();
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

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

    private function rowOf(string $column, int $id): Attendance
    {
        return Attendance::query()->where($column, $id)->whereDate('date', self::MONDAY)->where('segment', 1)->sole();
    }

    private function approve(Attendance $attendance): void
    {
        app(ApprovalFlowService::class)->approveStep($attendance->fresh(), $this->approver());
    }

    private function approver(): User
    {
        $approver = User::factory()->create(['is_active' => true]);
        $approver->forceFill(['tenant_id' => $this->company->tenant_id])->save();
        $approver->assignRole('Approver');
        $this->grantDataAccessTo($approver, $this->company->id, $this->division->id);

        return $approver;
    }

    private function shift(string $name, string $start, string $end, bool $active = true): Shift
    {
        return Shift::create(['company_id' => $this->company->id, 'name' => $name, 'start_time' => $start, 'end_time' => $end, 'late_tolerance_minutes' => 0, 'is_active' => $active]);
    }

    private function employee(string $name, ?Shift $defaultShift = null, ?Company $company = null): Employee
    {
        $company ??= $this->company;
        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['tenant_id' => $this->company->tenant_id])->save();
        $user->assignRole('Employee');
        $division = $company->is($this->company) ? $this->division : Division::create(['company_id' => $company->id, 'name' => 'Lain', 'code' => 'LN'.$user->id, 'is_active' => true]);

        return Employee::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'division_id' => $division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_number' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Kasir'])->id,
            'nip' => 'EMP-'.$user->id,
            'full_name' => $name,
            'join_date' => '2026-01-01',
            'status' => 'permanent',
            'shift_id' => $defaultShift?->id,
        ]);
    }

    private function freelancer(string $name): Freelancer
    {
        $user = User::factory()->create(['is_active' => true]);
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
}
