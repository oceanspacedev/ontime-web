<?php

namespace Tests\Feature\Chaos;

use App\Enums\AttendanceStatus;
use App\Enums\DataScope;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\Resignation;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\ShiftSwapRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalFlowService;
use App\Services\OutletRosterService;
use App\Services\ShiftSwapService;
use App\Services\TeamRecapService;
use App\Services\WorkerAccountService;
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
 * Chaos lens "state change": something about the worker, the shift, the outlet, the Klien or the approver
 * changes while a check-in, a backup, a swap or a month of attendance is in flight.
 */
class Chaos_state_changeTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-11-16';

    private const THURSDAY = '2026-11-19';

    private Tenant $tenant;

    private Company $company;

    private Division $kopi;

    private Division $mie;

    private CompanyLocation $kopiOutlet;

    private CompanyLocation $mieOutlet;

    private Shift $morning;

    private Shift $evening;

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

        $this->tenant = Tenant::factory()->create(['name' => 'Klien Chaos', 'code' => 'KC', 'is_active' => true]);
        $this->company = $this->makeCompany('PT Resto', 'RESTO');
        $this->kopi = Division::create(['company_id' => $this->company->id, 'name' => 'KOPI', 'code' => 'KOPI', 'is_active' => true]);
        $this->mie = Division::create(['company_id' => $this->company->id, 'name' => 'MIE', 'code' => 'MIE', 'is_active' => true]);
        $this->kopiOutlet = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $this->kopi->id, 'name' => 'Outlet Kopi', 'latitude' => -6.2, 'longitude' => 106.8, 'radius_meters' => 100, 'is_active' => true]);
        $this->mieOutlet = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $this->mie->id, 'name' => 'Outlet Mie', 'latitude' => -6.3, 'longitude' => 106.9, 'radius_meters' => 100, 'is_active' => true]);
        $this->morning = Shift::create(['company_id' => $this->company->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '16:00', 'late_tolerance_minutes' => 0, 'is_active' => true]);
        $this->evening = Shift::create(['company_id' => $this->company->id, 'name' => 'Malam', 'start_time' => '16:00', 'end_time' => '23:00', 'late_tolerance_minutes' => 0, 'is_active' => true]);
    }

    // ── Findings ────────────────────────────────────────────────────────────

    /**
     * A worker moved to another brand mid-month: the Koordinator of the old brand still sees the days they were
     * rostered at the old brand's outlet (schedules follow the outlet), but not the attendance of those days
     * (attendance only follows the worker's current brand). The recap turns worked days into absences.
     */
    public function test_recap_of_the_old_brand_counts_days_worked_before_a_division_move_as_absent(): void
    {
        $coordinator = $this->manager('Koordinator', DataScope::Divisions, divisionIds: [$this->kopi->id]);
        $bayu = $this->freelancer('Bayu', $this->kopi);

        ShiftSchedule::create(['freelancer_id' => $bayu->id, 'date' => '2026-11-02', 'shift_id' => $this->morning->id, 'company_location_id' => $this->kopiOutlet->id]);
        Attendance::create(['freelancer_id' => $bayu->id, 'date' => '2026-11-02', 'check_in' => '2026-11-02 07:58:00', 'check_out' => '2026-11-02 16:02:00', 'status' => 'on_time', 'late_minutes' => 0, 'company_location_id' => $this->kopiOutlet->id]);

        $before = collect(app(TeamRecapService::class)->recap($coordinator, Carbon::parse('2026-11-01')))->firstWhere('worker.name', 'Bayu');
        $this->assertSame([1, 0], [$before['present_days'], $before['absent_days']], 'Before the move the day is present.');

        // HR moves Bayu to the MIE brand on the 10th.
        $bayu->update(['division_id' => $this->mie->id]);

        $after = collect(app(TeamRecapService::class)->recap($coordinator, Carbon::parse('2026-11-01')))->firstWhere('worker.name', 'Bayu');

        $this->assertNotNull($after);
        $this->assertSame(
            ['present_days' => 1, 'absent_days' => 0],
            ['present_days' => $after['present_days'], 'absent_days' => $after['absent_days']],
            'The 2nd was worked at the KOPI outlet; moving brands later must not turn it into an absence in the KOPI recap.',
        );
    }

    /**
     * A worker moved to another company (badan usaha) mid-month disappears from the old company's recap: the
     * days they worked there before the move are no longer in that company's payroll report.
     */
    public function test_recap_of_the_old_company_keeps_days_worked_before_a_company_move(): void
    {
        $other = $this->makeCompany('PT Lain', 'LAIN');
        $otherDivision = Division::create(['company_id' => $other->id, 'name' => 'Dapur Lain', 'code' => 'DL', 'is_active' => true]);
        $pic = $this->manager('PIC', DataScope::Companies, companyIds: [$this->company->id]);
        $bayu = $this->freelancer('Bayu', $this->kopi);

        ShiftSchedule::create(['freelancer_id' => $bayu->id, 'date' => '2026-11-02', 'shift_id' => $this->morning->id, 'company_location_id' => $this->kopiOutlet->id]);
        Attendance::create(['freelancer_id' => $bayu->id, 'date' => '2026-11-02', 'check_in' => '2026-11-02 07:58:00', 'check_out' => '2026-11-02 16:02:00', 'status' => 'on_time', 'late_minutes' => 0, 'company_location_id' => $this->kopiOutlet->id]);

        $bayu->update(['company_id' => $other->id, 'division_id' => $otherDivision->id]);

        $row = collect(app(TeamRecapService::class)->recap($pic, Carbon::parse('2026-11-01')))->firstWhere('worker.name', 'Bayu');

        $this->assertNotNull($row, 'The 2nd was worked at an outlet of PT Resto, so it stays in PT Resto\'s November recap.');
        $this->assertSame(1, $row['present_days']);
    }

    /**
     * A give away accepted by a colleague, whose resignation is approved afterwards with a last working day
     * before the swap date. The final approval still hands the shift to someone who will have left.
     */
    public function test_swap_approved_after_the_counterpart_resignation_hands_the_shift_to_a_leaver(): void
    {
        [$andi, $budi, $approver] = [$this->employeeAccount('Andi'), $this->employeeAccount('Budi'), $this->approverAccount()];
        $this->rosterOn($andi->employee, self::THURSDAY, $this->morning);
        $this->rosterOn($budi->employee, self::THURSDAY, null);

        $swap = $this->acceptedSwap($andi, $budi, ShiftSwapRequest::TYPE_GIVE_AWAY);

        Resignation::create([
            'employee_id' => $budi->employee->id,
            'resignation_date' => self::MONDAY,
            'last_working_day' => '2026-11-18',
            'reason' => 'Pindah kota',
            'status' => 'approved',
        ]);

        $this->approveSwap($swap, $approver);

        $swap->refresh();
        $this->assertSame(ShiftSwapRequest::STATUS_FAILED, $swap->status, 'Budi leaves on the 18th, so the 19th shift cannot be handed to him.');
        $this->assertSame($this->morning->id, $this->scheduleOf('employee_id', $andi->employee->id, self::THURSDAY)->shift_id);
    }

    /**
     * A freelancer's contract end date is brought forward (still in the future, so nothing ends it yet) after
     * they accepted a swap on a later date. The final approval only checks "active today", not on the swap date.
     */
    public function test_swap_approved_after_the_contract_end_date_moved_before_the_swap_date_is_applied(): void
    {
        $andi = $this->employeeAccount('Andi');
        $approver = $this->approverAccount();
        $citra = $this->freelancer('Citra', $this->kopi, withAccount: true);
        $this->rosterOn($andi->employee, self::THURSDAY, $this->morning);
        ShiftSchedule::create(['freelancer_id' => $citra->id, 'date' => self::THURSDAY, 'is_day_off' => true]);

        $swap = $this->acceptedSwap($andi, $citra->user, ShiftSwapRequest::TYPE_GIVE_AWAY);

        // HR shortens the contract: the last day is now Tuesday the 17th.
        $previous = $citra->status;
        $citra->update(['end_date' => '2026-11-17']);
        app(WorkerAccountService::class)->syncContractStatus($citra->fresh(), $previous);

        $this->approveSwap($swap, $approver);

        $swap->refresh();
        $this->assertSame(ShiftSwapRequest::STATUS_FAILED, $swap->status, 'Citra\'s contract ends on the 17th; she cannot take over the 19th.');
        $this->assertSame($this->morning->id, $this->scheduleOf('employee_id', $andi->employee->id, self::THURSDAY)->shift_id);
    }

    /**
     * The counterpart is moved to another company (same Klien) after accepting. The final approval re-checks the
     * schedules but not the company, so it writes one company's shift onto a worker of another company.
     */
    public function test_swap_approved_after_the_counterpart_moved_to_another_company_is_applied_across_companies(): void
    {
        $other = $this->makeCompany('PT Lain', 'LAIN');
        $otherDivision = Division::create(['company_id' => $other->id, 'name' => 'Dapur Lain', 'code' => 'DL', 'is_active' => true]);
        [$andi, $budi, $approver] = [$this->employeeAccount('Andi'), $this->employeeAccount('Budi'), $this->approverAccount()];
        $this->rosterOn($andi->employee, self::THURSDAY, $this->morning);
        $this->rosterOn($budi->employee, self::THURSDAY, null);

        $swap = $this->acceptedSwap($andi, $budi, ShiftSwapRequest::TYPE_GIVE_AWAY);

        $budi->employee->update(['company_id' => $other->id, 'division_id' => $otherDivision->id]);

        $this->approveSwap($swap, $approver);

        $swap->refresh();
        $this->assertSame(ShiftSwapRequest::STATUS_FAILED, $swap->status, 'Tukar shift is only between workers of the same company.');
        $this->assertTrue($this->scheduleOf('employee_id', $budi->employee->id, self::THURSDAY)->is_day_off, 'PT Resto\'s Pagi shift is never written onto a PT Lain worker.');
    }

    /**
     * An off-schedule backup names Budi as replaced. While it waits, the PIC moves Budi to the evening shift (he
     * comes later instead). Approving the backup still turns Budi's (changed) evening shift into a day off.
     */
    public function test_backup_approval_wipes_the_replaced_colleagues_roster_the_pic_changed_meanwhile(): void
    {
        $approver = $this->approverAccount();
        $andi = $this->employeeAccount('Andi');
        $budi = $this->employeeAccount('Budi');
        ShiftSchedule::create(['employee_id' => $andi->employee->id, 'date' => self::MONDAY, 'is_day_off' => true]);
        $budiRoster = $this->rosterOn($budi->employee, self::MONDAY, $this->morning, $this->kopiOutlet);

        $this->travelTo(Carbon::parse(self::MONDAY.' 08:02:00'));
        Sanctum::actingAs($andi);
        $this->postJson('/api/v1/attendance/check-in', ['cover_shift_id' => $this->morning->id, 'replaces_worker' => 'employee:'.$budi->employee->id])
            ->assertOk()
            ->assertJsonPath('requires_approval', true);

        // The PIC moves Budi to the evening shift instead of giving him the day off.
        $budiRoster->update(['shift_id' => $this->evening->id]);

        app(ApprovalFlowService::class)->approveStep($this->rowOf('employee_id', $andi->employee->id), $approver);

        $budiRoster->refresh();
        $this->assertFalse($budiRoster->is_day_off, 'Budi was re-rostered on the evening shift by the PIC; the backup only covered the morning.');
        $this->assertSame($this->evening->id, $budiRoster->shift_id);
    }

    /**
     * An approved resignation that is applied ends the employee, but their rosters after the last working day
     * stay (ending a freelancer's contract removes them). The outlet roster keeps listing the leaver as
     * expected to work ("Belum absen").
     */
    public function test_roster_still_lists_an_employee_after_their_resignation_was_applied(): void
    {
        $pic = $this->manager('PIC', DataScope::Companies, companyIds: [$this->company->id]);
        $budi = $this->employeeAccount('Budi');
        $this->rosterOn($budi->employee, self::THURSDAY, $this->morning, $this->kopiOutlet);

        $resignation = Resignation::create([
            'employee_id' => $budi->employee->id,
            'resignation_date' => '2026-11-01',
            'last_working_day' => '2026-11-15',
            'reason' => 'Pindah kota',
            'status' => 'approved',
        ]);
        $resignation->applyResignation();
        $this->assertSame('inactive', $budi->employee->fresh()->status);

        $names = collect(app(OutletRosterService::class)->roster($pic, Carbon::parse(self::THURSDAY)))
            ->flatMap(fn (array $outlet): array => array_column(array_column($outlet['entries'], 'worker'), 'name'))
            ->all();

        $this->assertNotContains('Budi', $names, 'Budi left on the 15th; the 19th roster must not expect him.');
    }

    /**
     * "Akhiri Kontrak" tapped while the freelancer is on their last shift (checked in today): the open row of
     * today can no longer be checked out, so the last day records no hours worked.
     */
    public function test_ending_a_contract_mid_shift_blocks_the_check_out_of_the_last_day(): void
    {
        $citra = $this->freelancer('Citra', $this->kopi, withAccount: true);
        ShiftSchedule::create(['freelancer_id' => $citra->id, 'date' => self::MONDAY, 'shift_id' => $this->morning->id]);

        $this->travelTo(Carbon::parse(self::MONDAY.' 07:58:00'));
        Sanctum::actingAs($citra->user);
        $this->postJson('/api/v1/attendance/check-in')->assertOk();

        $this->travelTo(Carbon::parse(self::MONDAY.' 12:00:00'));
        app(WorkerAccountService::class)->endContract($citra->fresh(), today());

        $this->travelTo(Carbon::parse(self::MONDAY.' 16:01:00'));
        Sanctum::actingAs($citra->user->fresh());
        $this->postJson('/api/v1/attendance/check-out')->assertOk();

        $this->assertNotNull($this->rowOf('freelancer_id', $citra->id)->check_out);
    }

    // ── Survived (regression) ─────────────────────────────────────────────

    public function test_survived_swap_fails_when_the_counterpart_resignation_is_applied_after_acceptance(): void
    {
        [$andi, $budi, $approver] = [$this->employeeAccount('Andi'), $this->employeeAccount('Budi'), $this->approverAccount()];
        $this->rosterOn($andi->employee, self::THURSDAY, $this->morning);
        $this->rosterOn($budi->employee, self::THURSDAY, null);
        $swap = $this->acceptedSwap($andi, $budi, ShiftSwapRequest::TYPE_GIVE_AWAY);

        $budi->employee->update(['status' => 'inactive']);
        $budi->update(['is_active' => false]);

        $this->approveSwap($swap, $approver);

        $this->assertSame(ShiftSwapRequest::STATUS_FAILED, $swap->fresh()->status);
        $this->assertTrue($this->scheduleOf('employee_id', $budi->employee->id, self::THURSDAY)->is_day_off);
    }

    public function test_survived_ending_a_contract_fails_a_pending_swap_on_a_later_date(): void
    {
        $andi = $this->employeeAccount('Andi');
        $approver = $this->approverAccount();
        $citra = $this->freelancer('Citra', $this->kopi, withAccount: true);
        $this->rosterOn($andi->employee, self::THURSDAY, $this->morning);
        ShiftSchedule::create(['freelancer_id' => $citra->id, 'date' => self::THURSDAY, 'is_day_off' => true]);
        $swap = $this->acceptedSwap($andi, $citra->user, ShiftSwapRequest::TYPE_GIVE_AWAY);

        app(WorkerAccountService::class)->endContract($citra->fresh(), today());

        $this->approveSwap($swap, $approver);

        $this->assertSame(ShiftSwapRequest::STATUS_FAILED, $swap->fresh()->status);
        $this->assertSame($this->morning->id, $this->scheduleOf('employee_id', $andi->employee->id, self::THURSDAY)->shift_id);
    }

    public function test_survived_approver_who_lost_the_data_scope_cannot_approve_a_pending_backup(): void
    {
        $approver = $this->approverAccount();
        $andi = $this->employeeAccount('Andi');
        ShiftSchedule::create(['employee_id' => $andi->employee->id, 'date' => self::MONDAY, 'is_day_off' => true]);

        $this->travelTo(Carbon::parse(self::MONDAY.' 08:00:00'));
        Sanctum::actingAs($andi);
        $this->postJson('/api/v1/attendance/check-in', ['cover_shift_id' => $this->morning->id])->assertOk();

        $approver->grantDataAccess(DataScope::Divisions, divisionIds: [$this->mie->id]);

        $this->expectException(ValidationException::class);
        app(ApprovalFlowService::class)->approveStep($this->rowOf('employee_id', $andi->employee->id), $approver->fresh());
    }

    public function test_survived_deactivated_klien_signs_its_workers_out_mid_shift(): void
    {
        $andi = $this->employeeAccount('Andi');
        $this->rosterOn($andi->employee, self::MONDAY, $this->morning);
        $token = $andi->createToken('app')->plainTextToken;

        $this->travelTo(Carbon::parse(self::MONDAY.' 07:59:00'));
        $this->withToken($token)->postJson('/api/v1/attendance/check-in')->assertOk();

        $this->tenant->update(['is_active' => false]);
        $this->app['auth']->forgetGuards();

        $this->travelTo(Carbon::parse(self::MONDAY.' 16:00:00'));
        $this->withToken($token)->postJson('/api/v1/attendance/check-out')->assertUnauthorized();
    }

    public function test_survived_gps_switched_on_between_check_in_and_check_out_judges_the_check_out_only(): void
    {
        $andi = $this->employeeAccount('Andi');
        $this->rosterOn($andi->employee, self::MONDAY, $this->morning, $this->kopiOutlet);

        $this->travelTo(Carbon::parse(self::MONDAY.' 07:59:00'));
        Sanctum::actingAs($andi);
        $this->postJson('/api/v1/attendance/check-in')->assertOk()->assertJsonPath('requires_approval', false);

        $this->company->policy()->update(['require_gps' => true]);

        $this->travelTo(Carbon::parse(self::MONDAY.' 16:00:00'));
        Sanctum::actingAs($andi->fresh());
        $this->postJson('/api/v1/attendance/check-out')->assertUnprocessable()->assertJsonValidationErrors('latitude');
        $this->postJson('/api/v1/attendance/check-out', ['latitude' => -6.9, 'longitude' => 107.5])
            ->assertOk()
            ->assertJsonPath('requires_approval', true);

        $row = $this->rowOf('employee_id', $andi->employee->id);
        $this->assertSame(AttendanceStatus::PendingApproval, $row->status);
        $this->assertTrue($row->is_out_of_bounds);
    }

    public function test_survived_outlet_deactivated_mid_shift_still_judges_the_check_out_against_it(): void
    {
        $this->company->policy()->update(['require_gps' => true]);
        $andi = $this->employeeAccount('Andi');
        $this->rosterOn($andi->employee, self::MONDAY, $this->morning, $this->kopiOutlet);

        $this->travelTo(Carbon::parse(self::MONDAY.' 07:59:00'));
        Sanctum::actingAs($andi);
        $this->postJson('/api/v1/attendance/check-in', ['latitude' => -6.2, 'longitude' => 106.8])->assertOk()->assertJsonPath('requires_approval', false);

        $this->kopiOutlet->forceFill(['is_active' => false])->saveQuietly();

        $this->travelTo(Carbon::parse(self::MONDAY.' 16:00:00'));
        Sanctum::actingAs($andi->fresh());
        $this->postJson('/api/v1/attendance/check-out', ['latitude' => -6.2, 'longitude' => 106.8])
            ->assertOk()
            ->assertJsonPath('requires_approval', false);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function makeCompany(string $name, string $code): Company
    {
        $company = new Company(['name' => $name, 'code' => $code, 'is_active' => true]);
        $company->tenant_id = $this->tenant->id;
        $company->save();
        app(ApprovalFlowService::class)->createDefaultFlows($company);
        $company->policy()->update([
            'require_gps' => false,
            'require_photo' => false,
            'require_face_recognition' => false,
            'require_liveness' => false,
            'work_start_time' => '08:00:00',
            'work_end_time' => '17:00:00',
            'late_tolerance_minutes' => 0,
        ]);
        $company->unsetRelation('policy');

        return $company;
    }

    /**
     * @param  list<int>  $companyIds
     * @param  list<int>  $divisionIds
     */
    private function manager(string $role, DataScope $scope, array $companyIds = [], array $divisionIds = []): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['tenant_id' => $this->tenant->id])->save();
        $user->assignRole($role);
        $user->grantDataAccess($scope, companyIds: $companyIds, divisionIds: $divisionIds);

        return $user;
    }

    private function approverAccount(): User
    {
        return $this->manager('Approver', DataScope::All);
    }

    private function employeeAccount(string $name, ?Division $division = null): User
    {
        $user = User::factory()->create(['name' => $name, 'is_active' => true]);
        $user->forceFill(['tenant_id' => $this->tenant->id])->save();
        $user->assignRole('Employee');

        Employee::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => ($division ?? $this->kopi)->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_number' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Kasir'])->id,
            'nip' => 'EMP-'.$user->id,
            'full_name' => $name,
            'join_date' => '2026-01-01',
            'status' => 'active',
        ]);

        return $user->fresh();
    }

    private function freelancer(string $name, Division $division, bool $withAccount = false): Freelancer
    {
        $userId = null;

        if ($withAccount) {
            $user = User::factory()->create(['name' => $name, 'is_active' => true]);
            $user->forceFill(['tenant_id' => $this->tenant->id])->save();
            $user->assignRole('Freelancer');
            $userId = $user->id;
        }

        return Freelancer::create([
            'user_id' => $userId,
            'company_id' => $this->company->id,
            'division_id' => $division->id,
            'full_name' => $name,
            'start_date' => '2026-10-01',
            'end_date' => '2026-12-31',
            'status' => 'active',
        ]);
    }

    private function rosterOn(Employee $employee, string $date, ?Shift $shift, ?CompanyLocation $outlet = null): ShiftSchedule
    {
        return ShiftSchedule::create([
            'employee_id' => $employee->id,
            'date' => $date,
            'shift_id' => $shift?->id,
            'is_day_off' => $shift === null,
            'company_location_id' => $outlet?->id,
        ]);
    }

    private function scheduleOf(string $column, int $id, string $date): ?ShiftSchedule
    {
        return ShiftSchedule::query()->where($column, $id)->whereDate('date', $date)->first();
    }

    private function rowOf(string $column, int $id): Attendance
    {
        return Attendance::query()->where($column, $id)->whereDate('date', self::MONDAY)->where('segment', 1)->sole();
    }

    private function acceptedSwap(User $requester, User $colleague, string $type): ShiftSwapRequest
    {
        $profile = $colleague->employee ?? $colleague->intern ?? $colleague->freelancer;

        Sanctum::actingAs($requester);
        $swapId = $this->fileSwap($profile, $type)->assertCreated()->json('data.id');

        Sanctum::actingAs($colleague);
        $this->postJson("/api/v1/shift-swaps/{$swapId}/accept")->assertOk();

        return ShiftSwapRequest::findOrFail($swapId);
    }

    private function fileSwap(Employee|Freelancer $colleague, string $type): TestResponse
    {
        return $this->postJson('/api/v1/shift-swaps', array_filter([
            'type' => $type,
            'requester_date' => self::THURSDAY,
            'counterpart_date' => $type === ShiftSwapRequest::TYPE_SWAP ? self::THURSDAY : null,
            'colleague_type' => ShiftSwapService::workerType($colleague),
            'colleague_id' => $colleague->id,
            'reason' => 'Ada keperluan keluarga',
        ], fn ($value): bool => $value !== null));
    }

    private function approveSwap(ShiftSwapRequest $swap, User $approver): void
    {
        try {
            app(ApprovalFlowService::class)->approveStep($swap->fresh(), $approver);
        } catch (ValidationException) {
            // A swap that can no longer be applied is closed as failed and the approver is told why.
        }
    }
}
