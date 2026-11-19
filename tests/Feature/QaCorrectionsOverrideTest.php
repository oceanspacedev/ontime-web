<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\DataScope;
use App\Filament\Resources\AttendanceCorrections\Pages\CreateAttendanceCorrection;
use App\Filament\Resources\Attendances\Pages\EditAttendance;
use App\Models\ApprovalFlow;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalFlowService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * QA-STAGING ATT-049/051/053: the segment a correction closes, refusing a second correction for the
 * same worker/date/segment, and the integrity checks of the Admin Override form.
 */
class QaCorrectionsOverrideTest extends TestCase
{
    use RefreshDatabase;

    private const D = '2026-11-13';

    private Tenant $tenant;

    private Company $company;

    private Division $division;

    private Shift $pagi;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.onesignal.app_id' => null, 'services.whatsapp_gateway.url' => null]);
        Http::fake();
        Notification::fake();

        $this->travelTo(Carbon::parse('2026-11-16 09:00:00'));
        $this->seed(RoleSeeder::class);

        $this->tenant = Tenant::factory()->create(['name' => 'Klien Koreksi', 'code' => 'KK', 'is_active' => true]);
        $this->company = new Company(['name' => 'PT Koreksi', 'code' => 'PKR', 'is_active' => true]);
        $this->company->tenant_id = $this->tenant->id;
        $this->company->save();
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Operasional', 'code' => 'OPS', 'is_active' => true]);
        $this->pagi = Shift::create(['company_id' => $this->company->id, 'name' => 'Pagi', 'code' => 'P', 'start_time' => '08:00', 'end_time' => '17:00', 'late_tolerance_minutes' => 0]);

        foreach (['correction', 'geofence'] as $type) {
            ApprovalFlow::create(['company_id' => $this->company->id, 'request_type' => $type, 'step_number' => 1, 'step_order' => 1, 'name' => 'Approver', 'approver_type' => 'role', 'approver_role' => 'Approver']);
        }

        $this->account(['Approver']);
    }

    public function test_check_out_only_correction_closes_the_open_second_segment(): void
    {
        [, $employee] = $this->makeWorker();
        $split = Shift::create(['company_id' => $this->company->id, 'name' => 'Split', 'code' => 'S', 'start_time' => '07:45', 'end_time' => '13:00', 'second_start_time' => '17:45', 'second_end_time' => '22:00', 'late_tolerance_minutes' => 0]);
        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::D, 'shift_id' => $split->id]);
        Attendance::create(['employee_id' => $employee->id, 'date' => self::D, 'segment' => 1, 'check_in' => self::D.' 07:45:00', 'check_out' => self::D.' 13:00:00', 'status' => 'on_time']);
        $segmentTwo = Attendance::create(['employee_id' => $employee->id, 'date' => self::D, 'segment' => 2, 'check_in' => self::D.' 17:45:00', 'status' => 'on_time']);

        AttendanceCorrection::create(['employee_id' => $employee->id, 'date' => self::D, 'corrected_check_out' => '22:00', 'reason' => 'Lupa absen pulang', 'status' => 'pending'])->applyCorrection();

        $this->assertSame(self::D.' 22:00:00', $segmentTwo->fresh()->check_out?->toDateTimeString());
        $this->assertSame(2, Attendance::where('employee_id', $employee->id)->count());
    }

    /**
     * WP12 (owner sign-off): a check-out alone without any check-in of the day creates no row (refused);
     * a correction with the check-in still follows the break midpoint to the second part.
     */
    public function test_correction_without_an_open_segment_still_follows_the_break_midpoint(): void
    {
        [, $employee] = $this->makeWorker();
        $split = Shift::create(['company_id' => $this->company->id, 'name' => 'Split', 'code' => 'S', 'start_time' => '07:45', 'end_time' => '13:00', 'second_start_time' => '17:45', 'second_end_time' => '22:00', 'late_tolerance_minutes' => 0]);
        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::D, 'shift_id' => $split->id]);

        try {
            AttendanceCorrection::create(['employee_id' => $employee->id, 'date' => self::D, 'corrected_check_out' => '22:00', 'reason' => 'Lupa absen', 'status' => 'pending'])->applyCorrection();
            $this->fail('A check-out alone must not create a row without a check-in.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Isi juga jam masuk', $exception->errors()['approve'][0]);
        }

        $this->assertSame(0, Attendance::where('employee_id', $employee->id)->count());

        AttendanceCorrection::create(['employee_id' => $employee->id, 'date' => self::D, 'corrected_check_in' => '17:45', 'corrected_check_out' => '22:00', 'reason' => 'Lupa absen', 'status' => 'pending'])->applyCorrection();

        $this->assertSame(2, Attendance::where('employee_id', $employee->id)->sole()->segment);
    }

    public function test_panel_refuses_a_second_correction_while_one_is_pending(): void
    {
        [$user, $employee] = $this->makeWorker();
        AttendanceCorrection::create(['employee_id' => $employee->id, 'date' => self::D, 'corrected_check_in' => '08:00', 'reason' => 'Pertama', 'status' => 'pending']);
        $this->actingAs($user);

        Livewire::test(CreateAttendanceCorrection::class)
            ->fillForm(['date' => self::D, 'reason' => 'Kedua', 'corrected_check_in' => '08:10'])
            ->call('create')
            ->assertHasFormErrors(['date']);

        $this->assertSame(1, AttendanceCorrection::count());
    }

    public function test_a_rejected_correction_may_be_submitted_again(): void
    {
        [, $employee] = $this->makeWorker();
        AttendanceCorrection::create(['employee_id' => $employee->id, 'date' => self::D, 'corrected_check_in' => '08:00', 'reason' => 'Pertama', 'status' => 'rejected']);

        $next = new AttendanceCorrection(['employee_id' => $employee->id, 'date' => self::D, 'corrected_check_in' => '08:10']);

        $this->assertNull($next->conflictingCorrection());
    }

    public function test_corrections_of_different_split_segments_do_not_conflict(): void
    {
        [, $employee] = $this->makeWorker();
        $split = Shift::create(['company_id' => $this->company->id, 'name' => 'Split', 'code' => 'S', 'start_time' => '07:45', 'end_time' => '13:00', 'second_start_time' => '17:45', 'second_end_time' => '22:00', 'late_tolerance_minutes' => 0]);
        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::D, 'shift_id' => $split->id]);
        AttendanceCorrection::create(['employee_id' => $employee->id, 'date' => self::D, 'corrected_check_in' => '07:45', 'reason' => 'Segmen 1', 'status' => 'pending']);

        $this->assertNull((new AttendanceCorrection(['employee_id' => $employee->id, 'date' => self::D, 'corrected_check_in' => '17:45']))->conflictingCorrection());
        $this->assertNotNull((new AttendanceCorrection(['employee_id' => $employee->id, 'date' => self::D, 'corrected_check_in' => '08:00']))->conflictingCorrection());
    }

    public function test_final_approval_refuses_to_overwrite_a_row_written_by_another_approved_correction(): void
    {
        [, $employee] = $this->makeWorker();
        $first = AttendanceCorrection::create(['employee_id' => $employee->id, 'date' => self::D, 'corrected_check_in' => '08:05', 'reason' => 'Pertama', 'status' => 'pending']);
        $second = AttendanceCorrection::create(['employee_id' => $employee->id, 'date' => self::D, 'corrected_check_in' => '08:20', 'reason' => 'Kedua', 'status' => 'pending']);
        $first->applyCorrection();

        try {
            $second->applyCorrection();
            $this->fail('The second approved correction overwrote the first one.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('approve', $exception->errors());
        }

        $this->assertSame('08:05', Attendance::where('employee_id', $employee->id)->sole()->check_in->format('H:i'));
        $this->assertSame('pending', $second->fresh()->status);
    }

    public function test_override_accepts_a_next_day_check_out_for_a_night_shift(): void
    {
        $attendance = $this->dayAttendance(['check_in' => self::D.' 22:00:00', 'check_out' => null]);
        $this->actingAs($this->account(['Admin']));

        Livewire::test(EditAttendance::class, ['record' => $attendance->getRouteKey()])
            ->fillForm(['check_in' => self::D.' 22:00', 'check_out' => '2026-11-14 06:00'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('2026-11-14 06:00:00', $attendance->fresh()->check_out->toDateTimeString());
    }

    public function test_override_refuses_a_check_out_two_days_later(): void
    {
        $attendance = $this->dayAttendance();
        $this->actingAs($this->account(['Admin']));

        Livewire::test(EditAttendance::class, ['record' => $attendance->getRouteKey()])
            ->fillForm(['check_out' => '2026-11-15 06:00'])
            ->call('save')
            ->assertHasFormErrors(['check_out']);
    }

    public function test_override_refuses_on_time_with_late_minutes(): void
    {
        $attendance = $this->dayAttendance();
        $this->actingAs($this->account(['Admin']));

        Livewire::test(EditAttendance::class, ['record' => $attendance->getRouteKey()])
            ->fillForm(['status' => AttendanceStatus::OnTime->value, 'late_minutes' => 15])
            ->call('save')
            ->assertHasFormErrors(['status']);
    }

    public function test_override_cannot_settle_a_pending_record_while_its_approval_round_is_open(): void
    {
        $attendance = $this->dayAttendance(['status' => AttendanceStatus::PendingApproval, 'is_out_of_bounds' => true, 'check_out' => null]);
        app(ApprovalFlowService::class)->generateSteps($attendance, 'geofence');
        $this->actingAs($this->account(['Admin']));

        Livewire::test(EditAttendance::class, ['record' => $attendance->getRouteKey()])
            ->fillForm(['status' => AttendanceStatus::OnTime->value])
            ->call('save')
            ->assertHasFormErrors(['status']);

        $this->assertSame(AttendanceStatus::PendingApproval, $attendance->fresh()->status);
        $this->assertSame(1, $attendance->approvalSteps()->where('status', 'pending')->count());
    }

    public function test_override_saving_unchanged_times_does_not_mark_the_row_corrected(): void
    {
        $attendance = $this->dayAttendance();
        $this->actingAs($this->account(['Admin']));

        Livewire::test(EditAttendance::class, ['record' => $attendance->getRouteKey()])
            ->fillForm(['notes' => 'Dicek ulang'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse((bool) $attendance->fresh()->is_corrected);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function dayAttendance(array $overrides = []): Attendance
    {
        [, $employee] = $this->makeWorker();

        return Attendance::create([
            'employee_id' => $employee->id, 'date' => self::D, 'segment' => 1, 'shift_id' => $this->pagi->id,
            'check_in' => self::D.' 08:00:00', 'check_out' => self::D.' 17:00:00', 'status' => 'on_time', 'late_minutes' => 0,
            ...$overrides,
        ]);
    }

    /**
     * @param  list<string>  $roles
     */
    private function account(array $roles): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['tenant_id' => $this->tenant->id])->save();
        $user->assignRole($roles);
        $user->grantDataAccess(DataScope::All);

        return $user;
    }

    /**
     * @return array{0: User, 1: Employee}
     */
    private function makeWorker(): array
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['tenant_id' => $this->tenant->id])->save();
        $user->assignRole('Employee');

        $employee = Employee::create([
            'user_id' => $user->id, 'company_id' => $this->company->id, 'division_id' => $this->division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_number' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Staff Operasional'])->id,
            'nip' => 'PKR-'.$user->id, 'full_name' => 'Pekerja '.$user->id, 'join_date' => '2026-01-01', 'status' => 'active',
        ]);

        return [$user->fresh(), $employee];
    }
}
