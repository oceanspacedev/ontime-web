<?php

namespace Tests\Feature\Chaos;

use App\Enums\AttendanceStatus;
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
use App\Models\OvertimeRequest;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\ShiftSwapRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalFlowService;
use App\Services\ShiftSwapService;
use App\Services\TeamRecapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Chaos lens "F&B real-world combinations": several workflows (leave, backup check-in, shift swap,
 * overtime, correction, contract end) hitting the same worker and date, and the reports that must
 * stay consistent afterwards (never a day paid twice, never a worked day lost).
 */
class Chaos_fnb_combosTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-11-16';

    private const TUESDAY = '2026-11-17';

    private const WEDNESDAY = '2026-11-18';

    private Company $company;

    private Division $division;

    private Shift $morning;

    private Shift $evening;

    private Shift $night;

    private CompanyLocation $outlet;

    private User $approver;

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

        $this->travelTo(Carbon::parse(self::MONDAY.' 06:00:00'));

        $tenant = Tenant::factory()->create(['name' => 'Klien FnB', 'code' => 'FNB', 'is_active' => true]);
        $this->company = new Company(['name' => 'PT Resto Chaos', 'code' => 'RCH', 'is_active' => true]);
        $this->company->tenant_id = $tenant->id;
        $this->company->save();
        app(ApprovalFlowService::class)->createDefaultFlows($this->company);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Outlet', 'code' => 'OUT', 'is_active' => true]);
        $this->company->policy()->update([
            'require_gps' => false,
            'require_photo' => false,
            'require_face_recognition' => false,
            'require_liveness' => false,
            'work_start_time' => '08:00:00',
            'work_end_time' => '17:00:00',
            'late_tolerance_minutes' => 0,
        ]);
        $this->company->unsetRelation('policy');

        $this->morning = $this->shift('Pagi', '08:00', '16:00');
        $this->evening = $this->shift('Sore', '16:00', '23:00');
        $this->night = $this->shift('Malam', '22:00', '06:00');
        $this->outlet = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $this->division->id, 'name' => 'Outlet Mall', 'latitude' => -6.2, 'longitude' => 106.8, 'radius_meters' => 100, 'is_active' => true]);

        $this->approver = User::factory()->create(['name' => 'Rina Approver', 'is_active' => true]);
        $this->approver->forceFill(['tenant_id' => $tenant->id])->save();
        $this->approver->assignRole('Approver');
        $this->grantDataAccessTo($this->approver, $this->company->id, $this->division->id);
    }

    // ── Findings ────────────────────────────────────────────────────────────

    /**
     * Sick leave approved for Tuesday, then a correction adding check-in/out on the same Tuesday is approved:
     * check-in itself refuses an approved-leave day (ON_LEAVE_MESSAGE) and a leave is refused on a worked day,
     * but the correction silently turns the Leave row into a worked day while the leave stays approved.
     */
    public function test_correction_on_an_approved_leave_day_does_not_turn_the_leave_into_a_worked_day(): void
    {
        $andi = $this->employee('Andi');
        $this->roster($andi, self::TUESDAY, $this->morning);
        $leave = $this->leave($andi, self::TUESDAY, 'sick', 'approved');
        $leave->applyLeave();

        $this->travelTo(Carbon::parse(self::WEDNESDAY.' 09:00:00'));
        Sanctum::actingAs($andi->user);
        $response = $this->postJson('/api/v1/attendance-corrections', [
            'date' => self::TUESDAY,
            'corrected_check_in' => '08:00',
            'corrected_check_out' => '16:00',
            'reason' => 'Lupa absen',
        ]);

        if ($response->status() === 422) {
            $this->assertSame(AttendanceStatus::Leave, $this->rowOf('employee_id', $andi->id, self::TUESDAY)->status);

            return;
        }

        $response->assertCreated();
        $correction = AttendanceCorrection::query()->findOrFail($response->json('data.id'));

        try {
            app(ApprovalFlowService::class)->approveStep($correction, $this->approver);
        } catch (ValidationException) {
            // Refused at approval: the leave day stays a leave day.
        }

        $row = $this->rowOf('employee_id', $andi->id, self::TUESDAY);
        $leaveStillApproved = $leave->fresh()->status === 'approved';

        $this->assertFalse(
            $leaveStillApproved && $row->check_in !== null && $row->status !== AttendanceStatus::Leave,
            "Tuesday is now both an approved sick leave and a worked day ({$row->status->value}, {$row->check_in}): paid twice.",
        );

        $this->travelTo(Carbon::parse('2026-11-20 09:00:00'));
        $recap = $this->recapOf('Andi');
        $this->assertSame(0, $recap['present_days'], 'A day on approved leave must not also be a present (paid) day.');
    }

    /**
     * Andi gives his Tuesday night shift (22:00-06:00) away to Fani, a freelancer whose contract ends Tuesday.
     * The swap is allowed (she is active on Tuesday) and she checks in at 22:00, but the nightly
     * workers:close-expired-contracts (00:15) ends her contract mid-shift: the 06:00 check-out is refused and
     * the worked night is left open (0 worked hours in the recap).
     */
    public function test_night_shift_given_away_to_a_freelancer_on_her_last_contract_day_can_be_checked_out(): void
    {
        $andi = $this->employee('Andi');
        $fani = $this->freelancer('Fani', endDate: self::TUESDAY);
        $this->roster($andi, self::TUESDAY, $this->night);

        $this->approvedSwap($andi, $fani, ShiftSwapRequest::TYPE_GIVE_AWAY);
        $this->assertSame($this->night->id, $this->scheduleOf('freelancer_id', $fani->id, self::TUESDAY)?->shift_id);

        $this->travelTo(Carbon::parse(self::TUESDAY.' 21:55:00'));
        $this->checkIn($fani->user)->assertOk();

        $this->travelTo(Carbon::parse(self::WEDNESDAY.' 00:15:00'));
        $this->artisan('workers:close-expired-contracts')->assertSuccessful();

        $this->travelTo(Carbon::parse(self::WEDNESDAY.' 06:00:00'));
        Sanctum::actingAs($fani->user->fresh());
        $checkOut = $this->postJson('/api/v1/attendance/check-out');

        $this->assertSame(200, $checkOut->status(), 'The last contract night shift must be closable: '.$checkOut->json('message'));
        $this->assertNotNull($this->rowOf('freelancer_id', $fani->id, self::TUESDAY)->check_out);
    }

    /**
     * Budi is rostered Pagi. Two backups (Citra, Dewi) both check in "menggantikan Budi" while the first is
     * still pending; both are approved. Two people are now booked and paid as the replacement of one shift.
     */
    public function test_two_backups_cannot_both_replace_the_same_colleague(): void
    {
        $budi = $this->employee('Budi');
        $this->roster($budi, self::TUESDAY, $this->morning);
        $citra = $this->freelancer('Citra');
        $dewi = $this->freelancer('Dewi');

        $this->travelTo(Carbon::parse(self::TUESDAY.' 07:55:00'));
        $this->checkIn($citra->user, ['cover_shift_id' => $this->morning->id, 'replaces_worker' => 'employee:'.$budi->id])->assertOk();

        $this->travelTo(Carbon::parse(self::TUESDAY.' 08:00:00'));
        $second = $this->checkIn($dewi->user, ['cover_shift_id' => $this->morning->id, 'replaces_worker' => 'employee:'.$budi->id]);

        if ($second->status() === 422) {
            $second->assertJsonValidationErrors('replaces_worker');

            return;
        }

        $this->approve($this->rowOf('freelancer_id', $citra->id, self::TUESDAY));
        $this->approve($this->rowOf('freelancer_id', $dewi->id, self::TUESDAY));

        $claims = Attendance::query()
            ->whereDate('date', self::TUESDAY)
            ->where('replaced_worker_type', 'employee')
            ->where('replaced_worker_id', $budi->id)
            ->whereIn('status', [AttendanceStatus::OnTime, AttendanceStatus::Late])
            ->count();

        $this->assertSame(1, $claims, 'Only one approved backup may stand as the replacement of Budi\'s single shift.');
    }

    /**
     * Andi has approved overtime 16:00-18:00 on Tuesday, then gives Tuesday away to Citra (day off). Andi is now
     * off, never checks in, yet the Team Recap still pays him the overtime; the swap never looked at it.
     */
    public function test_overtime_on_a_given_away_date_is_not_paid_to_the_worker_who_gave_it_away(): void
    {
        $andi = $this->employee('Andi');
        $citra = $this->employee('Citra');
        $this->roster($andi, self::TUESDAY, $this->morning);
        $this->roster($citra, self::TUESDAY, null);
        OvertimeRequest::query()->forceCreate([
            'employee_id' => $andi->id, 'date' => self::TUESDAY, 'start_time' => '16:00', 'end_time' => '18:00',
            'duration_minutes' => 120, 'reason' => 'Stok opname', 'status' => 'approved',
        ]);

        try {
            $this->approvedSwap($andi, $citra, ShiftSwapRequest::TYPE_GIVE_AWAY);
        } catch (ValidationException $refused) {
            $this->assertStringContainsStringIgnoringCase('lembur', $refused->getMessage());

            return;
        }

        $this->assertTrue($this->scheduleOf('employee_id', $andi->id, self::TUESDAY)->is_day_off);

        $this->travelTo(Carbon::parse('2026-11-20 09:00:00'));
        $andiRecap = $this->recapOf('Andi');

        $this->assertSame(0, $andiRecap['present_days']);
        $this->assertSame(0, $andiRecap['overtime_count'], 'Overtime on a day given away (off, never worked) is still paid.');
        $this->assertSame(0.0, (float) $andiRecap['overtime_hours']);
    }

    /**
     * After an approved same-day swap Andi works Sore and Citra Pagi. Backup Budi (mobile options loaded before
     * the swap) checks in covering Pagi "menggantikan Andi". The check-in is accepted and its approval gives
     * Andi's Sore shift the day off: nobody covers Sore, and Pagi has two people.
     */
    public function test_backup_covering_a_different_shift_than_the_named_colleague_does_not_free_that_colleague(): void
    {
        $andi = $this->employee('Andi');
        $citra = $this->employee('Citra');
        $budi = $this->freelancer('Budi');
        $this->roster($andi, self::TUESDAY, $this->morning);
        $this->roster($citra, self::TUESDAY, $this->evening);

        $this->approvedSwap($andi, $citra, ShiftSwapRequest::TYPE_SWAP);
        $this->assertSame($this->evening->id, $this->scheduleOf('employee_id', $andi->id, self::TUESDAY)->shift_id);

        $this->travelTo(Carbon::parse(self::TUESDAY.' 07:55:00'));
        $response = $this->checkIn($budi->user, ['cover_shift_id' => $this->morning->id, 'replaces_worker' => 'employee:'.$andi->id]);

        if ($response->status() === 422) {
            return;
        }

        $this->approve($this->rowOf('freelancer_id', $budi->id, self::TUESDAY));

        $andiSchedule = $this->scheduleOf('employee_id', $andi->id, self::TUESDAY);
        $this->assertFalse(
            $andiSchedule->is_day_off,
            'Budi covered Pagi, yet Andi\'s Sore shift became "'.$andiSchedule->notes.'": Sore is uncovered and Andi is neither absent nor working.',
        );
    }

    /**
     * Andi is on approved sick leave Tuesday; backup Budi replaces him and is approved. The approval rewrites
     * Andi's leave day into a "Digantikan" day off: Team Recap books it as Libur and the team roster as libur,
     * while laporan / the leave keep it as an approved leave.
     */
    public function test_backup_replacing_a_colleague_on_approved_leave_keeps_the_leave_day(): void
    {
        $andi = $this->employee('Andi');
        $budi = $this->freelancer('Budi');
        $this->roster($andi, self::TUESDAY, $this->morning);
        $this->leave($andi, self::TUESDAY, 'sick', 'approved')->applyLeave();

        $this->travelTo(Carbon::parse(self::TUESDAY.' 07:55:00'));
        $this->checkIn($budi->user, ['cover_shift_id' => $this->morning->id, 'replaces_worker' => 'employee:'.$andi->id])->assertOk();
        $this->approve($this->rowOf('freelancer_id', $budi->id, self::TUESDAY));

        $this->assertSame(AttendanceStatus::Leave, $this->rowOf('employee_id', $andi->id, self::TUESDAY)->status);
        $this->assertFalse($this->scheduleOf('employee_id', $andi->id, self::TUESDAY)->is_day_off, 'A leave day was rewritten into a day off.');

        $this->travelTo(Carbon::parse('2026-11-20 09:00:00'));
        $this->assertSame(0, $this->recapOf('Andi')['off_days'], 'An approved leave day is not also a Libur day.');
    }

    // ── Survived (regression) ───────────────────────────────────────────────

    /**
     * Andi gives his Sore shift away to Citra (accepted, waiting for the approver). Andi falls sick; Budi
     * checks in as his backup and is approved before the swap is decided. The swap must then fail without
     * touching the rosters.
     */
    public function test_backup_approved_before_a_pending_swap_of_the_replaced_worker_makes_the_swap_fail(): void
    {
        $andi = $this->employee('Andi');
        $citra = $this->employee('Citra');
        $budi = $this->freelancer('Budi');
        $this->roster($andi, self::TUESDAY, $this->evening);
        $this->roster($citra, self::TUESDAY, null);

        $swap = $this->acceptedSwap($andi, $citra, ShiftSwapRequest::TYPE_GIVE_AWAY);

        $this->travelTo(Carbon::parse(self::TUESDAY.' 15:05:00'));
        $this->checkIn($budi->user, ['cover_shift_id' => $this->evening->id, 'replaces_worker' => 'employee:'.$andi->id])->assertOk();
        $this->approve($this->rowOf('freelancer_id', $budi->id, self::TUESDAY));

        try {
            app(ApprovalFlowService::class)->approveStep($swap->fresh(), $this->approver);
            $this->fail('The swap must not be applied.');
        } catch (ValidationException) {
        }

        $this->assertSame(ShiftSwapRequest::STATUS_FAILED, $swap->fresh()->status);
        $this->assertTrue($this->scheduleOf('employee_id', $andi->id, self::TUESDAY)->is_day_off);
        $this->assertSame('Digantikan oleh Budi', $this->scheduleOf('employee_id', $andi->id, self::TUESDAY)->notes);
        $this->assertTrue($this->scheduleOf('employee_id', $citra->id, self::TUESDAY)->is_day_off);
        $this->assertSame($this->evening->id, $this->scheduleOf('freelancer_id', $budi->id, self::TUESDAY)->shift_id);
    }

    /**
     * The swap is accepted, then Andi files sick leave for the same date: the approver's approval fails the
     * swap instead of handing Citra's shift to someone on leave.
     */
    public function test_leave_filed_after_a_swap_was_accepted_makes_the_swap_fail(): void
    {
        $andi = $this->employee('Andi');
        $citra = $this->employee('Citra');
        $this->roster($andi, self::TUESDAY, $this->morning);
        $this->roster($citra, self::TUESDAY, $this->evening);
        $swap = $this->acceptedSwap($andi, $citra, ShiftSwapRequest::TYPE_SWAP);

        $this->leave($andi, self::TUESDAY, 'sick', 'pending');

        try {
            app(ApprovalFlowService::class)->approveStep($swap->fresh(), $this->approver);
            $this->fail('The swap must not be applied.');
        } catch (ValidationException) {
        }

        $this->assertSame(ShiftSwapRequest::STATUS_FAILED, $swap->fresh()->status);
        $this->assertSame($this->morning->id, $this->scheduleOf('employee_id', $andi->id, self::TUESDAY)->shift_id);
        $this->assertSame($this->evening->id, $this->scheduleOf('employee_id', $citra->id, self::TUESDAY)->shift_id);
    }

    /**
     * Give away of a day shift to a freelancer on her last contract day: she works it, the nightly close runs,
     * and the recap counts the day once for her (present) and once for Andi (off), nobody absent.
     */
    public function test_day_shift_given_away_to_a_freelancer_on_her_last_day_is_counted_once(): void
    {
        $andi = $this->employee('Andi');
        $fani = $this->freelancer('Fani', endDate: self::TUESDAY);
        $this->roster($andi, self::TUESDAY, $this->morning);

        $this->approvedSwap($andi, $fani, ShiftSwapRequest::TYPE_GIVE_AWAY);

        $this->travelTo(Carbon::parse(self::TUESDAY.' 07:55:00'));
        $this->checkIn($fani->user)->assertOk()->assertJsonPath('requires_approval', false);
        $this->travelTo(Carbon::parse(self::TUESDAY.' 16:00:00'));
        Sanctum::actingAs($fani->user);
        $this->postJson('/api/v1/attendance/check-out')->assertOk();

        $this->travelTo(Carbon::parse(self::WEDNESDAY.' 00:15:00'));
        $this->artisan('workers:close-expired-contracts')->assertSuccessful();

        $this->travelTo(Carbon::parse('2026-11-20 09:00:00'));
        $fRecap = $this->recapOf('Fani');
        $aRecap = $this->recapOf('Andi');

        $this->assertSame([1, 1, 0, 0], [$fRecap['scheduled_days'], $fRecap['present_days'], $fRecap['absent_days'], $fRecap['off_days']]);
        $this->assertSame(8.08, (float) $fRecap['worked_hours']);
        $this->assertSame([0, 0, 0, 1], [$aRecap['scheduled_days'], $aRecap['present_days'], $aRecap['absent_days'], $aRecap['off_days']]);
    }

    /**
     * Andi files sick leave, Budi backs him up (approved, Andi gets the day off), then the leave is rejected.
     * Andi stays off (not absent), Budi is present once, and the day is never counted twice.
     */
    public function test_backup_approved_then_leave_rejected_never_double_counts(): void
    {
        $andi = $this->employee('Andi');
        $budi = $this->freelancer('Budi');
        $this->roster($andi, self::TUESDAY, $this->morning);
        $leave = $this->leave($andi, self::TUESDAY, 'sick', 'pending');
        app(ApprovalFlowService::class)->generateSteps($leave, 'leave');

        $this->travelTo(Carbon::parse(self::TUESDAY.' 08:10:00'));
        $this->checkIn($budi->user, ['cover_shift_id' => $this->morning->id, 'replaces_worker' => 'employee:'.$andi->id])->assertOk();
        $this->approve($this->rowOf('freelancer_id', $budi->id, self::TUESDAY));
        app(ApprovalFlowService::class)->rejectStep($leave->fresh(), $this->approver, 'Tidak ada surat dokter');

        $this->travelTo(Carbon::parse('2026-11-20 09:00:00'));
        $a = $this->recapOf('Andi');
        $b = $this->recapOf('Budi');

        $this->assertSame('rejected', $leave->fresh()->status);
        $this->assertSame([0, 0, 0, 1], [$a['scheduled_days'], $a['present_days'], $a['absent_days'], $a['off_days']]);
        $this->assertSame([1, 1, 0, 0, 1, 10], [$b['scheduled_days'], $b['present_days'], $b['absent_days'], $b['off_days'], $b['late_days'], $b['late_minutes']]);
    }

    /**
     * A correction for a swapped day is judged against the shift the swap gave (Sore), not the old one.
     */
    public function test_correction_for_a_swapped_day_is_judged_against_the_swapped_shift(): void
    {
        $andi = $this->employee('Andi');
        $citra = $this->employee('Citra');
        $this->roster($andi, self::TUESDAY, $this->morning);
        $this->roster($citra, self::TUESDAY, $this->evening);
        $this->approvedSwap($andi, $citra, ShiftSwapRequest::TYPE_SWAP);

        $this->travelTo(Carbon::parse(self::WEDNESDAY.' 09:00:00'));
        Sanctum::actingAs($andi->user);
        $id = $this->postJson('/api/v1/attendance-corrections', [
            'date' => self::TUESDAY, 'corrected_check_in' => '16:10', 'corrected_check_out' => '23:00', 'reason' => 'HP mati',
        ])->assertCreated()->json('data.id');

        app(ApprovalFlowService::class)->approveStep(AttendanceCorrection::query()->findOrFail($id), $this->approver);

        $row = $this->rowOf('employee_id', $andi->id, self::TUESDAY);
        $this->assertSame(AttendanceStatus::Late, $row->status);
        $this->assertSame(10, $row->late_minutes);
        $this->assertSame($this->evening->id, $row->shift_id);
    }

    /**
     * Team roster after an approved backup: the replaced colleague is libur, the backup is listed once at the
     * outlet (not also as "Tanpa jadwal"), and the header counts agree with the rows.
     */
    public function test_team_roster_counts_after_an_approved_backup(): void
    {
        $andi = $this->employee('Andi');
        $dodi = $this->employee('Dodi');
        $budi = $this->freelancer('Budi');
        $this->roster($andi, self::TUESDAY, $this->morning);
        $this->roster($dodi, self::TUESDAY, $this->morning);

        $this->travelTo(Carbon::parse(self::TUESDAY.' 07:55:00'));
        $this->checkIn($dodi->user)->assertOk();
        $this->travelTo(Carbon::parse(self::TUESDAY.' 08:05:00'));
        $this->checkIn($budi->user, ['cover_shift_id' => $this->morning->id, 'replaces_worker' => 'employee:'.$andi->id])->assertOk();
        $this->approve($this->rowOf('freelancer_id', $budi->id, self::TUESDAY));

        $this->travelTo(Carbon::parse(self::TUESDAY.' 10:00:00'));
        $coordinator = User::factory()->create(['is_active' => true]);
        $coordinator->forceFill(['tenant_id' => $this->company->tenant_id])->save();
        $coordinator->assignRole('Koordinator');
        $this->grantDataAccessTo($coordinator, $this->company->id, $this->division->id);
        Sanctum::actingAs($coordinator);

        $outlets = $this->getJson('/api/v1/team/roster?date='.self::TUESDAY)->assertOk()->json('outlets');

        $this->assertNotContains('unscheduled', array_column($outlets, 'kind'), 'The backup is not listed again as "Tanpa jadwal".');
        $entries = collect($outlets)->flatMap(fn (array $outlet): array => $outlet['entries']);
        $this->assertCount(3, $entries, 'Every person is listed exactly once.');
        $byName = $entries->keyBy(fn (array $entry): string => $entry['worker']['name']);
        $this->assertSame('libur', $byName['Andi']['state']);
        $this->assertSame('terlambat', $byName['Budi']['state']);
        $this->assertSame($this->outlet->id, $byName['Budi']['location_id']);

        $sum = fn (string $key): int => (int) collect($outlets)->sum(fn (array $outlet): int => $outlet['counts'][$key] ?? 0);
        $this->assertSame(2, $sum('scheduled'));
        $this->assertSame(2, $sum('checked_in'));
        $this->assertSame(1, $sum('libur'));
        $this->assertSame(0, $sum('belum'));
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function acceptedSwap(Employee|Freelancer $requester, Employee|Freelancer $counterpart, string $type): ShiftSwapRequest
    {
        $service = app(ShiftSwapService::class);
        $swap = $service->create($requester, $counterpart, $type, Carbon::parse(self::TUESDAY), $type === ShiftSwapRequest::TYPE_SWAP ? Carbon::parse(self::TUESDAY) : null, 'Ada acara keluarga');

        return $service->accept($swap, $counterpart->user);
    }

    private function approvedSwap(Employee|Freelancer $requester, Employee|Freelancer $counterpart, string $type): ShiftSwapRequest
    {
        $swap = $this->acceptedSwap($requester, $counterpart, $type);
        app(ApprovalFlowService::class)->approveStep($swap->fresh(), $this->approver);

        $swap = $swap->fresh();
        $this->assertNotNull($swap->applied_at, 'The swap was applied.');

        return $swap;
    }

    /**
     * @return array<string, mixed>
     */
    private function recapOf(string $name): array
    {
        $rows = collect(app(TeamRecapService::class)->recap($this->approver, Carbon::parse('2026-11-01')))
            ->filter(fn (array $row): bool => $row['worker']['name'] === $name)
            ->values();

        $this->assertCount(1, $rows, "{$name} has one recap row.");

        return $rows->first();
    }

    private function leave(Employee|Freelancer $worker, string $date, string $type, string $status): LeaveRequest
    {
        $leave = new LeaveRequest([
            'leave_type' => $type,
            'start_date' => $date,
            'end_date' => $date,
            'days_count' => 1,
            'reason' => 'Demam',
            'status' => $status,
        ]);
        $leave->forceFill([$worker instanceof Employee ? 'employee_id' : 'freelancer_id' => $worker->id]);
        $leave->saveQuietly();

        return $leave;
    }

    private function roster(Employee|Freelancer $worker, string $date, ?Shift $shift): ShiftSchedule
    {
        return ShiftSchedule::create([
            $worker instanceof Employee ? 'employee_id' : 'freelancer_id' => $worker->id,
            'date' => $date,
            'shift_id' => $shift?->id,
            'is_day_off' => $shift === null,
            'company_location_id' => $shift ? $this->outlet->id : null,
        ]);
    }

    private function scheduleOf(string $column, int $id, string $date): ?ShiftSchedule
    {
        return ShiftSchedule::query()->where($column, $id)->whereDate('date', $date)->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function checkIn(User $user, array $data = []): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->postJson('/api/v1/attendance/check-in', $data);
    }

    private function rowOf(string $column, int $id, string $date): Attendance
    {
        return Attendance::query()->where($column, $id)->whereDate('date', $date)->where('segment', 1)->sole();
    }

    private function approve(Attendance $attendance): void
    {
        app(ApprovalFlowService::class)->approveStep($attendance->fresh(), $this->approver);
    }

    private function shift(string $name, string $start, string $end): Shift
    {
        return Shift::create(['company_id' => $this->company->id, 'name' => $name, 'start_time' => $start, 'end_time' => $end, 'late_tolerance_minutes' => 0, 'is_active' => true]);
    }

    private function employee(string $name): Employee
    {
        $user = User::factory()->create(['name' => $name, 'is_active' => true]);
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
            'status' => 'active',
        ]);
    }

    private function freelancer(string $name, string $endDate = '2026-12-31'): Freelancer
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
            'end_date' => $endDate,
            'status' => 'active',
        ]);
    }
}
