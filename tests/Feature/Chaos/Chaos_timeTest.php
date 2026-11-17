<?php

namespace Tests\Feature\Chaos;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\OvertimeRequest;
use App\Models\Resignation;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\ShiftSwapRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalFlowService;
use App\Services\AttendanceLatenessService;
use App\Services\OvertimeRequestService;
use App\Services\TeamRecapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Time chaos: midnight crossings, month and year boundaries, tolerance and early-window boundaries to the
 * second, nightly commands running in the middle of a night shift. The server clock is the only clock.
 *
 * Methods prefixed "test_finding_" fail on purpose (they prove a defect); the others are regression tests
 * of scenarios that survived.
 */
class Chaos_timeTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-11-16';

    private const TUESDAY = '2026-11-17';

    private Company $company;

    private Division $division;

    private Shift $morning;

    private Shift $night;

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

        $tenant = Tenant::factory()->create(['name' => 'Klien Waktu', 'code' => 'KW', 'is_active' => true]);
        $this->company = new Company(['name' => 'PT Resto Waktu', 'code' => 'RW', 'is_active' => true]);
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
        $this->night = $this->shift('Malam', '22:00', '06:00');
    }

    // ── Findings (failing) ──────────────────────────────────────────────────

    /**
     * A shift that starts at midnight (00:00 - 08:00) opens its check-in at 23:00 the evening before. A tap at
     * 23:59:59 is booked to the evening's own date and judged against that date's 00:00 start, i.e. almost a
     * full day late, and the real shift row (the next date) is left to be opened again later.
     */
    public function test_finding_midnight_start_shift_checked_in_at_235959_is_booked_to_the_shift_day_on_time(): void
    {
        $graveyard = $this->shift('Dini Hari', '00:00', '08:00');
        $andi = $this->employee('Andi', $graveyard);

        $this->travelTo(Carbon::parse(self::MONDAY.' 23:59:59'));
        $this->checkIn($andi->user)->assertOk();

        $row = Attendance::query()->where('employee_id', $andi->id)->sole();
        $this->assertSame(self::TUESDAY, $row->date->toDateString(), 'The 00:00 shift checked in one second early belongs to Tuesday.');
        $this->assertSame(0, (int) $row->late_minutes);
        $this->assertSame(AttendanceStatus::OnTime, $row->status);
    }

    /**
     * Split shift 16:00 - 20:00 & 22:00 - 02:00: segment 2 runs until 02:00. A worker who arrives late for
     * segment 2 at 00:10 is treated as starting the NEXT day's segment 1 and refused ("Absen masuk dibuka
     * pukul 15:00"), although segment 2 of Monday is still running for almost two hours.
     */
    public function test_finding_late_check_in_to_split_segment_two_after_midnight_is_accepted_on_segment_two(): void
    {
        $split = Shift::create([
            'company_id' => $this->company->id, 'name' => 'Split Malam',
            'start_time' => '16:00', 'end_time' => '20:00',
            'second_start_time' => '22:00', 'second_end_time' => '02:00',
            'late_tolerance_minutes' => 0, 'is_active' => true,
        ]);
        $andi = $this->employee('Andi', $split);

        $this->travelTo(Carbon::parse(self::MONDAY.' 16:00:00'));
        $this->checkIn($andi->user)->assertOk();
        $this->travelTo(Carbon::parse(self::MONDAY.' 20:00:00'));
        $this->checkOut($andi->user)->assertOk();

        // Stuck in traffic: arrives for segment 2 ten minutes after midnight.
        $this->travelTo(Carbon::parse(self::TUESDAY.' 00:10:00'));
        $this->checkIn($andi->user)->assertOk();

        $second = Attendance::query()->where('employee_id', $andi->id)->where('segment', 2)->first();
        $this->assertNotNull($second, 'The late check-in lands on segment 2 of Monday.');
        $this->assertSame(self::MONDAY, $second->date->toDateString());
        $this->assertSame(130, (int) $second->late_minutes);
    }

    /**
     * Lateness is judged against now() with its microseconds, but the check-in is stored to the second. A tap
     * at 08:00:00.400 on a 0-minute tolerance shift is stored as 08:00:00 yet reported 1 minute late, and the
     * very same stored row reads as on time the moment anything (a roster save) judges it again.
     */
    public function test_finding_check_in_within_the_first_second_of_the_start_minute_agrees_with_its_stored_time(): void
    {
        $exact = $this->shift('Pagi Tepat', '08:00', '16:00');
        $andi = $this->employee('Andi', $exact);

        $this->travelTo(Carbon::parse(self::MONDAY.' 08:00:00.400'));
        $this->checkIn($andi->user)->assertOk();

        $row = Attendance::query()->where('employee_id', $andi->id)->sole();
        $this->assertSame('08:00:00', $row->check_in->format('H:i:s'));
        $judgedAgain = app(AttendanceLatenessService::class)->judge($row)['late_minutes'];

        $this->assertSame($judgedAgain, (int) $row->late_minutes, 'Stored verdict and a re-judgement of the stored row disagree.');
        $this->assertSame(AttendanceStatus::OnTime, $row->status);
    }

    /**
     * A freelancer's contract ends on Monday and they work Monday's night shift (22:00 - 06:00), which the
     * check-out code explicitly allows ("a night shift on the last contract day closes the next morning").
     * workers:close-expired-contracts runs at 00:15, in the middle of that shift, ends the contract and
     * switches the account off, so the 06:00 check-out is refused and the night is left open.
     */
    public function test_finding_nightly_contract_close_at_0015_lets_the_last_night_shift_check_out(): void
    {
        $freelancer = $this->freelancer('Citra', endDate: self::MONDAY);
        ShiftSchedule::create(['freelancer_id' => $freelancer->id, 'date' => self::MONDAY, 'shift_id' => $this->night->id, 'is_day_off' => false]);

        $this->travelTo(Carbon::parse(self::MONDAY.' 21:55:00'));
        $this->checkIn($freelancer->user)->assertOk();

        $this->travelTo(Carbon::parse(self::TUESDAY.' 00:15:00'));
        $this->artisan('workers:close-expired-contracts')->assertSuccessful();

        $this->travelTo(Carbon::parse(self::TUESDAY.' 06:00:00'));
        $this->checkOut($freelancer->user->fresh())->assertOk();

        $row = Attendance::query()->where('freelancer_id', $freelancer->id)->sole();
        $this->assertSame(self::TUESDAY.' 06:00:00', $row->check_out?->format('Y-m-d H:i:s'));
    }

    /**
     * Same as above for an employee: last working day Monday on the night shift. employees:apply-resignations
     * at 00:20 sets the employee inactive mid-shift, so the 06:00 check-out of Monday's night is refused.
     */
    public function test_finding_nightly_resignation_at_0020_lets_the_last_night_shift_check_out(): void
    {
        $andi = $this->employee('Andi', $this->night);
        $resignation = new Resignation;
        $resignation->forceFill([
            'employee_id' => $andi->id,
            'resignation_date' => '2026-11-01',
            'last_working_day' => self::MONDAY,
            'reason' => 'Pindah kota',
            'status' => 'approved',
        ])->saveQuietly();

        $this->travelTo(Carbon::parse(self::MONDAY.' 21:55:00'));
        $this->checkIn($andi->user)->assertOk();

        $this->travelTo(Carbon::parse(self::TUESDAY.' 00:20:00'));
        $this->artisan('employees:apply-resignations')->assertSuccessful();

        $this->travelTo(Carbon::parse(self::TUESDAY.' 06:00:00'));
        $this->checkOut($andi->user->fresh())->assertOk();

        $row = Attendance::query()->where('employee_id', $andi->id)->sole();
        $this->assertNotNull($row->check_out);
    }

    // ── Survived (regression) ───────────────────────────────────────────────

    public function test_night_shift_check_in_at_235959_and_000000_both_belong_to_the_evening_date(): void
    {
        $andi = $this->employee('Andi', $this->night);
        $budi = $this->employee('Budi', $this->night);

        $this->travelTo(Carbon::parse(self::MONDAY.' 23:59:59'));
        $this->checkIn($andi->user)->assertOk();
        $this->travelTo(Carbon::parse(self::TUESDAY.' 00:00:00'));
        $this->checkIn($budi->user)->assertOk();

        $andiRow = Attendance::query()->where('employee_id', $andi->id)->sole();
        $budiRow = Attendance::query()->where('employee_id', $budi->id)->sole();
        $this->assertSame(self::MONDAY, $andiRow->date->toDateString());
        $this->assertSame(self::MONDAY, $budiRow->date->toDateString());
        $this->assertSame(119, (int) $andiRow->late_minutes);
        $this->assertSame(120, (int) $budiRow->late_minutes);
    }

    public function test_night_shift_check_out_after_midnight_closes_the_evening_row_without_early_leave(): void
    {
        $andi = $this->employee('Andi', $this->night);

        $this->travelTo(Carbon::parse(self::MONDAY.' 21:50:00'));
        $this->checkIn($andi->user)->assertOk();
        $this->travelTo(Carbon::parse(self::TUESDAY.' 06:00:00'));
        $this->checkOut($andi->user)->assertOk();

        $row = Attendance::query()->where('employee_id', $andi->id)->sole();
        $this->assertSame(self::MONDAY, $row->date->toDateString());
        $this->assertSame(0, (int) $row->early_leave_minutes);
        $this->assertSame(490, $row->workedMinutes());
    }

    public function test_year_end_night_shift_checked_in_after_midnight_on_new_year_belongs_to_31_december(): void
    {
        $andi = $this->employee('Andi', $this->night);

        $this->travelTo(Carbon::parse('2027-01-01 00:30:00'));
        $this->checkIn($andi->user)->assertOk();

        $row = Attendance::query()->where('employee_id', $andi->id)->sole();
        $this->assertSame('2026-12-31', $row->date->toDateString());
        $this->assertSame(150, (int) $row->late_minutes);
    }

    public function test_early_window_boundary_is_exact_to_the_second(): void
    {
        $andi = $this->employee('Andi', $this->morning);

        $this->travelTo(Carbon::parse(self::MONDAY.' 06:59:59'));
        $this->checkIn($andi->user)->assertUnprocessable();

        $this->travelTo(Carbon::parse(self::MONDAY.' 07:00:00'));
        $this->checkIn($andi->user)->assertOk();
        $this->assertSame(AttendanceStatus::OnTime, Attendance::query()->where('employee_id', $andi->id)->sole()->status);
    }

    public function test_tolerance_boundary_is_exact_to_the_second(): void
    {
        $tolerant = Shift::create(['company_id' => $this->company->id, 'name' => 'Pagi 15', 'start_time' => '08:00', 'end_time' => '16:00', 'late_tolerance_minutes' => 15, 'is_active' => true]);
        $andi = $this->employee('Andi', $tolerant);
        $budi = $this->employee('Budi', $tolerant);

        $this->travelTo(Carbon::parse(self::MONDAY.' 08:15:00'));
        $this->checkIn($andi->user)->assertOk();
        $this->travelTo(Carbon::parse(self::MONDAY.' 08:15:01'));
        $this->checkIn($budi->user)->assertOk();

        $this->assertSame(0, (int) Attendance::query()->where('employee_id', $andi->id)->sole()->late_minutes);
        $this->assertSame(15, (int) Attendance::query()->where('employee_id', $budi->id)->sole()->late_minutes);
    }

    public function test_client_sent_times_are_ignored_and_the_server_clock_decides(): void
    {
        $andi = $this->employee('Andi', $this->morning);

        $this->travelTo(Carbon::parse(self::MONDAY.' 09:00:00'));
        $this->checkIn($andi->user, [
            'check_in' => self::MONDAY.' 07:55:00',
            'timestamp' => Carbon::parse(self::MONDAY.' 07:55:00')->timestamp,
            'date' => '2026-11-15',
            'device_time' => '07:55',
        ])->assertOk();

        $row = Attendance::query()->where('employee_id', $andi->id)->sole();
        $this->assertSame(self::MONDAY.' 09:00:00', $row->check_in->format('Y-m-d H:i:s'));
        $this->assertSame(self::MONDAY, $row->date->toDateString());
        $this->assertSame(60, (int) $row->late_minutes);
    }

    public function test_roster_changed_exactly_at_shift_start_rejudges_the_check_in(): void
    {
        $andi = $this->employee('Andi');
        $schedule = ShiftSchedule::create(['employee_id' => $andi->id, 'date' => self::MONDAY, 'shift_id' => $this->morning->id, 'is_day_off' => false]);

        $this->travelTo(Carbon::parse(self::MONDAY.' 08:00:00'));
        $this->checkIn($andi->user)->assertOk();
        $this->assertSame(AttendanceStatus::OnTime, Attendance::query()->where('employee_id', $andi->id)->sole()->status);

        $early = $this->shift('Subuh', '07:30', '15:30');
        $schedule->update(['shift_id' => $early->id]);

        $row = Attendance::query()->where('employee_id', $andi->id)->sole();
        $this->assertSame($early->id, $row->shift_id);
        $this->assertSame(30, (int) $row->late_minutes);
        $this->assertSame(AttendanceStatus::Late, $row->status);
    }

    public function test_backup_cover_of_a_night_shift_after_midnight_books_to_the_evening_and_frees_the_colleague(): void
    {
        $budi = $this->employee('Budi');
        ShiftSchedule::create(['employee_id' => $budi->id, 'date' => self::MONDAY, 'shift_id' => $this->night->id, 'is_day_off' => false]);
        $citra = $this->freelancer('Citra');

        $this->travelTo(Carbon::parse(self::TUESDAY.' 00:30:00'));
        $this->checkIn($citra->user, ['cover_shift_id' => $this->night->id, 'replaces_worker' => 'employee:'.$budi->id])
            ->assertOk()
            ->assertJsonPath('requires_approval', true);

        $row = Attendance::query()->where('freelancer_id', $citra->id)->sole();
        $this->assertSame(self::MONDAY, $row->date->toDateString());
        $this->assertSame(150, (int) $row->check_in_late_minutes);

        app(ApprovalFlowService::class)->approveStep($row->fresh(), $this->approver());

        $this->assertSame(150, (int) $row->fresh()->late_minutes);
        $this->assertTrue((bool) ShiftSchedule::query()->where('employee_id', $budi->id)->whereDate('date', self::MONDAY)->sole()->is_day_off);
        $this->assertSame($this->night->id, ShiftSchedule::query()->where('freelancer_id', $citra->id)->whereDate('date', self::MONDAY)->sole()->shift_id);
    }

    public function test_expire_command_at_0005_keeps_a_swap_for_today_and_expires_yesterdays(): void
    {
        $andi = $this->employee('Andi');
        $budi = $this->employee('Budi');
        $today = $this->openSwap($andi, $budi, self::TUESDAY);
        $yesterday = $this->openSwap($andi, $budi, self::MONDAY);

        $this->travelTo(Carbon::parse(self::TUESDAY.' 00:05:00'));
        $this->artisan('shift-swaps:expire')->assertSuccessful();

        $this->assertSame(ShiftSwapRequest::STATUS_AWAITING_COLLEAGUE, $today->fresh()->status);
        $this->assertSame(ShiftSwapRequest::STATUS_EXPIRED, $yesterday->fresh()->status);
    }

    public function test_overtime_crossing_midnight_at_month_end_is_derived_server_side_and_blocks_overlap_on_the_1st(): void
    {
        $andi = $this->employee('Andi');
        $this->travelTo(Carbon::parse('2026-11-01 09:00:00'));

        Sanctum::actingAs($andi->user);
        $this->postJson('/api/v1/overtime-requests', [
            'date' => '2026-10-31', 'start_time' => '22:00', 'end_time' => '02:00',
            'duration_minutes' => 30, 'reason' => 'Stok opname akhir bulan',
        ])->assertCreated();

        $overtime = OvertimeRequest::query()->where('employee_id', $andi->id)->sole();
        $this->assertSame(240, (int) $overtime->duration_minutes);

        $this->postJson('/api/v1/overtime-requests', [
            'date' => '2026-11-01', 'start_time' => '01:00', 'end_time' => '03:00', 'reason' => 'Lanjut',
        ])->assertUnprocessable();

        $this->assertNull(app(OvertimeRequestService::class)->findOverlap($andi, '2026-11-01', '02:00', '04:00'));
    }

    public function test_night_shift_of_31_october_is_recapped_in_october_only(): void
    {
        $andi = $this->employee('Andi', $this->night);
        $this->travelTo(Carbon::parse('2026-10-31 21:55:00'));
        $this->checkIn($andi->user)->assertOk();
        $this->travelTo(Carbon::parse('2026-11-01 06:05:00'));
        $this->checkOut($andi->user)->assertOk();

        $overtime = new OvertimeRequest(['date' => '2026-10-31', 'start_time' => '22:00', 'end_time' => '02:00', 'duration_minutes' => 240, 'reason' => 'x', 'status' => 'approved']);
        $overtime->forceFill(['employee_id' => $andi->id])->saveQuietly();

        $this->travelTo(Carbon::parse('2026-11-16 10:00:00'));
        $pic = $this->approver();
        $october = collect(app(TeamRecapService::class)->recap($pic, Carbon::parse('2026-10-01')))->firstWhere('worker.name', 'Andi');
        $november = collect(app(TeamRecapService::class)->recap($pic, Carbon::parse('2026-11-01')))->firstWhere('worker.name', 'Andi');

        $this->assertNotNull($october);
        $this->assertSame(1, $october['present_days']);
        $this->assertEquals(8.17, $october['worked_hours']);
        $this->assertEquals(4.0, $october['overtime_hours']);
        $this->assertSame(0, $november['present_days'] ?? 0);
    }

    public function test_check_out_only_correction_of_an_overnight_row_lands_the_next_morning(): void
    {
        $andi = $this->employee('Andi', $this->night);
        $this->travelTo(Carbon::parse(self::MONDAY.' 21:55:00'));
        $this->checkIn($andi->user)->assertOk();

        // Forgot to check out; files the correction on Wednesday.
        $this->travelTo(Carbon::parse('2026-11-18 10:00:00'));
        Sanctum::actingAs($andi->user);
        $id = $this->postJson('/api/v1/attendance-corrections', [
            'date' => self::MONDAY, 'corrected_check_out' => '06:00', 'reason' => 'Lupa absen pulang',
        ])->assertCreated()->json('data.id');

        $correction = AttendanceCorrection::findOrFail($id);
        $correction->applyCorrection();

        $row = Attendance::query()->where('employee_id', $andi->id)->sole();
        $this->assertSame(self::TUESDAY.' 06:00:00', $row->check_out->format('Y-m-d H:i:s'));
        $this->assertSame(0, (int) $row->early_leave_minutes);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $data
     */
    private function checkIn(User $user, array $data = []): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->postJson('/api/v1/attendance/check-in', $data);
    }

    private function checkOut(User $user): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->postJson('/api/v1/attendance/check-out', []);
    }

    private function openSwap(Employee $requester, Employee $counterpart, string $date): ShiftSwapRequest
    {
        $swap = new ShiftSwapRequest;
        $swap->forceFill([
            'company_id' => $this->company->id,
            'employee_id' => $requester->id,
            'counterpart_employee_id' => $counterpart->id,
            'type' => ShiftSwapRequest::TYPE_SWAP,
            'requester_date' => $date,
            'counterpart_date' => $date,
            'status' => ShiftSwapRequest::STATUS_AWAITING_COLLEAGUE,
            'schedule_snapshot' => [],
        ])->saveQuietly();

        return $swap;
    }

    private function approver(): User
    {
        $approver = User::factory()->create(['is_active' => true]);
        $approver->forceFill(['tenant_id' => $this->company->tenant_id])->save();
        $approver->assignRole('Approver');
        $this->grantDataAccessTo($approver, $this->company->id, $this->division->id);

        return $approver;
    }

    private function shift(string $name, string $start, string $end): Shift
    {
        return Shift::create(['company_id' => $this->company->id, 'name' => $name, 'start_time' => $start, 'end_time' => $end, 'late_tolerance_minutes' => 0, 'is_active' => true]);
    }

    private function employee(string $name, ?Shift $defaultShift = null): Employee
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['tenant_id' => $this->company->tenant_id])->save();
        $user->assignRole('Employee');

        return Employee::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_number' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Kasir'])->id,
            'nip' => 'EMP-'.$user->id,
            'full_name' => $name,
            'join_date' => '2026-01-01',
            'status' => 'permanent',
            'shift_id' => $defaultShift?->id,
        ]);
    }

    private function freelancer(string $name, string $endDate = '2026-12-31'): Freelancer
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
            'end_date' => $endDate,
            'status' => 'active',
        ]);
    }
}
