<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Filament\Pages\JadwalMingguan;
use App\Filament\Resources\ShiftSchedules\Pages\ManageShiftSchedules;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\CompanyPolicy;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\Resignation;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\ShiftScheduleChange;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalFlowService;
use App\Services\AttendanceLatenessService;
use App\Services\RosterChangeContext;
use App\Services\ScheduleCopyService;
use App\Services\ShiftAssignmentService;
use App\Services\ShiftScheduleSpreadsheet;
use App\Services\WorkerAccountService;
use App\Services\WorkScheduleService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * D4: a roster edit can no longer change an attendance result. A schedule with a checked-in attendance is
 * locked on every writer (service, app, week grid, "Atur Jadwal", resource edit, import, copy), only an
 * approved correction may change it, and every roster change is logged with who, when, from, to, source
 * and reason. Past days need a reason in the panel; the app may still send none.
 *
 * Today is Wednesday 25 Nov 2026, 10:00.
 */
class RosterLockAndLogTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-11-25';

    private const YESTERDAY = '2026-11-24';

    private const TOMORROW = '2026-11-26';

    private Company $company;

    private Division $brand;

    private CompanyLocation $outlet;

    private Shift $morning;

    private Shift $late;

    private Shift $night;

    private User $admin;

    private Employee $andi;

    private Freelancer $bayu;

    /** @var list<string> */
    private array $files = [];

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

        $this->travelTo(Carbon::parse(self::TODAY.' 10:00:00'));

        $tenant = Tenant::factory()->create(['name' => 'Klien A', 'code' => 'KA', 'is_active' => true]);
        $this->company = new Company(['name' => 'PT Franchise', 'code' => 'PFC', 'is_active' => true]);
        $this->company->tenant_id = $tenant->id;
        $this->company->save();
        $this->company->policy->update([...CompanyPolicy::flagsForSelfieMode('none'), 'require_gps' => false, 'late_tolerance_minutes' => 0]);
        app(ApprovalFlowService::class)->createDefaultFlows($this->company);

        $this->brand = Division::create(['company_id' => $this->company->id, 'name' => 'JCHICKEN', 'code' => 'JC', 'is_active' => true]);
        $this->outlet = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $this->brand->id, 'name' => 'JChicken Ciledug', 'latitude' => -6.2297, 'longitude' => 106.7112]);
        $this->morning = Shift::create(['company_id' => $this->company->id, 'division_id' => $this->brand->id, 'name' => 'Pagi', 'code' => 'P', 'start_time' => '08:00', 'end_time' => '17:00', 'late_tolerance_minutes' => 0]);
        $this->late = Shift::create(['company_id' => $this->company->id, 'division_id' => $this->brand->id, 'name' => 'Siang', 'code' => 'S', 'start_time' => '10:00', 'end_time' => '18:00', 'late_tolerance_minutes' => 0]);
        $this->night = Shift::create(['company_id' => $this->company->id, 'division_id' => $this->brand->id, 'name' => 'Malam', 'code' => 'M', 'start_time' => '22:00', 'end_time' => '06:00', 'late_tolerance_minutes' => 0]);

        $this->admin = User::factory()->create(['is_active' => true, 'name' => 'Admin HR']);
        $this->admin->forceFill(['tenant_id' => $tenant->id])->save();
        $this->admin->assignRole('Admin');
        $this->grantDataAccessTo($this->admin, $this->company->id);

        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['tenant_id' => $tenant->id])->save();
        $user->assignRole('Employee');
        $this->andi = Employee::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->brand->id,
            'job_level_id' => JobLevel::create(['name' => 'Staff', 'level_number' => 1])->id,
            'job_title_id' => JobTitle::create(['name' => 'Kasir'])->id,
            'nip' => 'JC-001',
            'full_name' => 'Andi',
            'join_date' => '2026-01-01',
            'status' => 'active',
        ]);
        $this->bayu = Freelancer::create(['company_id' => $this->company->id, 'division_id' => $this->brand->id, 'freelancer_number' => 'FL-01', 'full_name' => 'Bayu', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    // ── The lock ─────────────────────────────────────────────────────────────

    public function test_a_checked_in_schedule_is_refused_by_every_writer(): void
    {
        $schedule = $this->schedule(self::TODAY, $this->morning);
        $row = $this->linkedCheckIn($schedule, '08:30:00');
        $assignments = app(ShiftAssignmentService::class);

        // Service: shift, day off and clear.
        foreach ([ShiftAssignmentService::MODE_SHIFT, ShiftAssignmentService::MODE_DAY_OFF, ShiftAssignmentService::MODE_CLEAR] as $mode) {
            $this->assertLocked(fn () => $assignments->assignDay($this->admin, $this->andi, self::TODAY, $mode, $this->late->id));
        }

        // App.
        Sanctum::actingAs($this->admin);
        $this->putJson('/api/v1/team/schedules', ['worker_type' => 'employee', 'worker_id' => $this->andi->id, 'date' => self::TODAY, 'mode' => 'shift', 'shift_id' => $this->late->id])
            ->assertUnprocessable()
            ->assertJsonPath('message', ShiftSchedule::LOCKED_MESSAGE);

        // Week grid.
        $this->actingAs($this->admin);
        Livewire::test(JadwalMingguan::class)
            ->callAction('editCell', ['mode' => 'off'], $this->cellArguments(self::TODAY))
            ->assertNotified('Jadwal tidak disimpan');

        // "Atur Jadwal".
        Livewire::test(ManageShiftSchedules::class)
            ->callAction('assignSchedule', data: [
                'company_id' => $this->company->id,
                'workers' => ['employee:'.$this->andi->id],
                'date_from' => self::TODAY,
                'date_until' => self::TODAY,
                'weekdays' => [1, 2, 3, 4, 5, 6, 7],
                'mode' => 'shift',
                'shift_id' => $this->late->id,
                'overwrite' => true,
            ])
            ->assertHasNoFormErrors()
            ->assertNotified('Jadwal tidak disimpan');

        // Resource edit and delete are disabled on the row, and refused by the service behind them.
        Livewire::test(ManageShiftSchedules::class)
            ->assertActionDisabled(TestAction::make('edit')->table($schedule))
            ->assertActionDisabled(TestAction::make('delete')->table($schedule));
        $this->assertLocked(fn () => $assignments->updateSchedule($this->admin, $schedule, ['shift_id' => $this->late->id]));
        $this->assertLocked(fn () => $assignments->deleteSchedule($this->admin, $schedule));

        // Excel import: the cell is reported, not written.
        $result = app(ShiftScheduleSpreadsheet::class)->import($this->admin, $this->company, $this->workbook([self::TODAY => 'S']));
        $this->assertSame(0, $result['updated']);
        $this->assertSame('Sheet "JChicken Ciledug", '.self::TODAY.': '.ShiftSchedule::LOCKED_MESSAGE.' Sel dilewati.', $result['issues'][0]);

        // Copy week never overwrites a filled day.
        ShiftSchedule::create(['employee_id' => $this->andi->id, 'date' => '2026-11-18', 'shift_id' => $this->late->id]);
        $copied = app(ScheduleCopyService::class)->copyWeek($this->admin, Carbon::parse('2026-11-18'), Carbon::parse(self::TODAY));
        $this->assertSame(0, $copied['copied']);

        $this->assertSame($this->morning->id, $schedule->fresh()->shift_id);
        $this->assertFalse($schedule->fresh()->is_day_off);
        $this->assertSame(1, ShiftSchedule::query()->where('employee_id', $this->andi->id)->whereDate('date', self::TODAY)->count());
        $this->assertSame(30, $row->fresh()->late_minutes);
        $this->assertSame(0, ShiftScheduleChange::query()->where('shift_schedule_id', $schedule->id)->where('action', '!=', ShiftScheduleChange::ACTION_CREATED)->count());
    }

    public function test_a_late_check_in_stays_late_after_every_allowed_roster_activity(): void
    {
        $schedule = $this->schedule(self::TODAY, $this->morning);
        $this->travelTo(Carbon::parse(self::TODAY.' 08:30:00'));
        Sanctum::actingAs($this->andi->user);
        $this->postJson('/api/v1/attendance/check-in')->assertOk();
        $row = Attendance::query()->where('employee_id', $this->andi->id)->sole();
        $this->assertSame($schedule->id, $row->shift_schedule_id);
        $this->assertSame(30, $row->late_minutes);
        $this->travelTo(Carbon::parse(self::TODAY.' 10:00:00'));

        $assignments = app(ShiftAssignmentService::class);
        $assignments->assignDay($this->admin, $this->andi, self::TOMORROW, ShiftAssignmentService::MODE_SHIFT, $this->late->id);
        $assignments->assignDay($this->admin, $this->andi, self::TOMORROW, ShiftAssignmentService::MODE_DAY_OFF);
        $assignments->assignDay($this->admin, $this->bayu, self::TODAY, ShiftAssignmentService::MODE_SHIFT, $this->morning->id);
        $this->morning->update(['start_time' => '07:00', 'end_time' => '16:00']);
        app(ScheduleCopyService::class)->copyWeek($this->admin, Carbon::parse(self::TODAY), Carbon::parse('2026-12-02'));
        app(ShiftScheduleSpreadsheet::class)->import($this->admin, $this->company, $this->workbook(['2026-11-27' => 'S']));
        app(AttendanceLatenessService::class)->recalculate($row->fresh());

        $row->refresh();
        $this->assertSame(AttendanceStatus::Late, $row->status);
        $this->assertSame(30, $row->late_minutes);
        $this->assertSame($this->morning->id, $row->shift_id);
        $this->assertSame(self::TODAY.' 08:00:00', $row->sched_start_at->format('Y-m-d H:i:s'));
    }

    public function test_only_a_correction_may_change_a_locked_schedule_and_it_is_logged(): void
    {
        $schedule = $this->schedule(self::TODAY, $this->morning);
        $this->linkedCheckIn($schedule, '08:30:00');

        RosterChangeContext::run('correction', 'Salah shift', fn () => $schedule->update(['shift_id' => $this->late->id]));

        $this->assertSame($this->late->id, $schedule->fresh()->shift_id);
        $change = ShiftScheduleChange::query()->where('action', ShiftScheduleChange::ACTION_UPDATED)->sole();
        $this->assertSame('correction', $change->source);
        $this->assertSame('Salah shift', $change->reason);
        $this->assertSame(['system', null], array_values(RosterChangeContext::current()));
    }

    public function test_roster_change_context_restores_the_outer_values(): void
    {
        RosterChangeContext::run('panel', 'Luar', function (): void {
            RosterChangeContext::run('copy', null, fn () => $this->assertSame(['copy', null], array_values(RosterChangeContext::current())));
            $this->assertSame(['panel', 'Luar'], array_values(RosterChangeContext::current()));
        });

        $this->assertSame(['system', null], array_values(RosterChangeContext::current()));
    }

    public function test_today_and_future_schedules_without_attendance_are_free(): void
    {
        $assignments = app(ShiftAssignmentService::class);
        $this->schedule(self::TODAY, $this->morning);

        $assignments->assignDay($this->admin, $this->andi, self::TODAY, ShiftAssignmentService::MODE_SHIFT, $this->late->id, source: 'panel', requirePastReason: true);
        $assignments->assignDay($this->admin, $this->andi, self::TOMORROW, ShiftAssignmentService::MODE_DAY_OFF, source: 'panel', requirePastReason: true);

        $this->assertSame($this->late->id, ShiftSchedule::query()->whereDate('date', self::TODAY)->sole()->shift_id);
        $this->assertTrue(ShiftSchedule::query()->whereDate('date', self::TOMORROW)->sole()->is_day_off);
    }

    public function test_overlap_with_an_unlinked_default_shift_check_in_is_refused(): void
    {
        $this->andi->update(['shift_id' => $this->morning->id]);
        Attendance::create(['employee_id' => $this->andi->id, 'date' => self::TODAY, 'segment' => 1, 'shift_id' => $this->morning->id, 'check_in' => self::TODAY.' 08:05:00', 'status' => AttendanceStatus::Late, 'late_minutes' => 5]);
        Sanctum::actingAs($this->admin);
        $change = ['worker_type' => 'employee', 'worker_id' => $this->andi->id, 'date' => self::TODAY];

        $this->putJson('/api/v1/team/schedules', $change + ['mode' => 'shift', 'shift_id' => $this->late->id])
            ->assertUnprocessable()
            ->assertJsonPath('message', ShiftAssignmentService::UNLINKED_ATTENDANCE_MESSAGE);
        $this->assertSame(0, ShiftSchedule::query()->count());

        // Hours that do not touch the check-in's window are free, and so is a day off (it has no hours).
        $this->putJson('/api/v1/team/schedules', $change + ['mode' => 'shift', 'shift_id' => $this->night->id])->assertOk();
        $this->putJson('/api/v1/team/schedules', $change + ['mode' => 'off'])->assertOk();

        $this->assertSame(5, Attendance::query()->sole()->late_minutes);
    }

    public function test_two_identical_assign_calls_write_one_schedule(): void
    {
        $assignments = app(ShiftAssignmentService::class);

        $first = $assignments->assignDay($this->admin, $this->andi, self::TOMORROW, ShiftAssignmentService::MODE_SHIFT, $this->late->id);
        $second = $assignments->assignDay($this->admin, $this->andi, self::TOMORROW, ShiftAssignmentService::MODE_SHIFT, $this->late->id);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, ShiftSchedule::query()->count());
        $this->assertSame(1, ShiftScheduleChange::query()->count());
    }

    // ── The log ──────────────────────────────────────────────────────────────

    public function test_create_update_and_delete_each_write_one_log_entry(): void
    {
        $this->actingAs($this->admin);
        $assignments = app(ShiftAssignmentService::class);

        $schedule = $assignments->assignDay($this->admin, $this->andi, self::TOMORROW, ShiftAssignmentService::MODE_SHIFT, $this->morning->id, $this->outlet->id, 'Kasir');
        $assignments->assignDay($this->admin, $this->andi, self::TOMORROW, ShiftAssignmentService::MODE_SHIFT, $this->late->id, $this->outlet->id, 'Kasir', reason: 'Tukar jam');
        $assignments->assignDay($this->admin, $this->andi, self::TOMORROW, ShiftAssignmentService::MODE_CLEAR);

        $changes = ShiftScheduleChange::query()->orderBy('id')->get();
        $this->assertSame(['created', 'updated', 'deleted'], $changes->pluck('action')->all());
        $this->assertSame(['api', 'api', 'api'], $changes->pluck('source')->all());
        $this->assertSame([$this->admin->id, $this->admin->id, $this->admin->id], $changes->pluck('changed_by')->all());
        $this->assertSame([null, 'Tukar jam', null], $changes->pluck('reason')->all());
        $this->assertSame([$schedule->id], $changes->pluck('shift_schedule_id')->unique()->values()->all());
        $this->assertSame([$this->andi->id], $changes->pluck('employee_id')->unique()->values()->all());
        $this->assertSame(self::TOMORROW, $changes[0]->date->toDateString());

        $morning = ['is_day_off' => false, 'shift_id' => $this->morning->id, 'shift_code' => 'P', 'company_location_id' => $this->outlet->id, 'outlet_name' => 'JChicken Ciledug', 'section' => 'Kasir', 'sequence' => 1];
        $late = ['shift_id' => $this->late->id, 'shift_code' => 'S'] + $morning;
        $this->assertNull($changes[0]->before);
        $this->assertEquals($morning, $changes[0]->after);
        $this->assertEquals($morning, $changes[1]->before);
        $this->assertEquals($late, $changes[1]->after);
        $this->assertEquals($late, $changes[2]->before);
        $this->assertNull($changes[2]->after);
        $this->assertNull(ShiftSchedule::query()->find($schedule->id));
    }

    public function test_day_off_conversion_logs_the_previous_shift_and_outlet(): void
    {
        $this->actingAs($this->admin);
        $schedule = ShiftSchedule::create(['employee_id' => $this->andi->id, 'date' => self::TOMORROW, 'shift_id' => $this->morning->id, 'company_location_id' => $this->outlet->id, 'section' => 'Kasir']);

        app(ShiftAssignmentService::class)->assignDay($this->admin, $this->andi, self::TOMORROW, ShiftAssignmentService::MODE_DAY_OFF, source: 'panel', requirePastReason: true);

        $change = ShiftScheduleChange::query()->where('action', ShiftScheduleChange::ACTION_UPDATED)->sole();
        $this->assertSame($schedule->id, $change->shift_schedule_id);
        $this->assertSame('panel', $change->source);
        $this->assertSame($this->morning->id, $change->before['shift_id']);
        $this->assertSame('JChicken Ciledug', $change->before['outlet_name']);
        $this->assertSame('Kasir', $change->before['section']);
        $this->assertTrue($change->after['is_day_off']);
        $this->assertNull($change->after['shift_id']);
        $this->assertNull($change->after['company_location_id']);
        $this->assertSame($this->admin->id, $schedule->fresh()->updated_by);
    }

    public function test_panel_past_date_edit_needs_a_reason_which_is_logged(): void
    {
        $this->actingAs($this->admin);
        $schedule = $this->schedule(self::YESTERDAY, $this->morning);

        // Week grid: the reason field is required for a past day.
        Livewire::test(JadwalMingguan::class)
            ->callAction('editCell', ['mode' => 'off'], $this->cellArguments(self::YESTERDAY))
            ->assertHasActionErrors(['reason' => 'required']);
        $this->assertFalse($schedule->fresh()->is_day_off);

        // The service refuses it too when the panel flag is set.
        try {
            app(ShiftAssignmentService::class)->assignDay($this->admin, $this->andi, self::YESTERDAY, ShiftAssignmentService::MODE_DAY_OFF, source: 'panel', requirePastReason: true);
            $this->fail('A past day edited from the panel needs a reason.');
        } catch (ValidationException $exception) {
            $this->assertSame([ShiftAssignmentService::PAST_REASON_MESSAGE], $exception->errors()['reason']);
        }

        // Resource edit: required as well, then saved and logged with it.
        Livewire::test(ManageShiftSchedules::class)
            ->callAction(TestAction::make('edit')->table($schedule), ['shift_id' => $this->late->id])
            ->assertHasActionErrors(['reason' => 'required']);
        $this->assertSame($this->morning->id, $schedule->fresh()->shift_id);

        Livewire::test(ManageShiftSchedules::class)
            ->callAction(TestAction::make('edit')->table($schedule), ['shift_id' => $this->late->id, 'reason' => 'Lupa input kemarin'])
            ->assertHasNoActionErrors();

        $this->assertSame($this->late->id, $schedule->fresh()->shift_id);
        $change = ShiftScheduleChange::query()->where('action', ShiftScheduleChange::ACTION_UPDATED)->sole();
        $this->assertSame(['panel', 'Lupa input kemarin', $this->admin->id], [$change->source, $change->reason, $change->changed_by]);

        // Week grid with the reason.
        Livewire::test(JadwalMingguan::class)
            ->callAction('editCell', ['mode' => 'off', 'reason' => 'Ternyata libur'], $this->cellArguments(self::YESTERDAY))
            ->assertHasNoActionErrors();
        $this->assertTrue($schedule->fresh()->is_day_off);
        $this->assertSame('Ternyata libur', ShiftScheduleChange::query()->latest('id')->first()->reason);

        // The history action lists both changes.
        Livewire::test(ManageShiftSchedules::class)
            ->mountAction(TestAction::make('history')->table($schedule->fresh()))
            ->assertActionMounted(TestAction::make('history')->table($schedule->fresh()))
            ->assertMountedActionModalSee(['Lupa input kemarin', 'Ternyata libur', 'Admin HR', 'Diubah']);
    }

    public function test_app_past_date_edit_without_a_reason_still_works_and_is_logged(): void
    {
        $this->schedule(self::YESTERDAY, $this->morning);
        Sanctum::actingAs($this->admin);
        $change = ['worker_type' => 'employee', 'worker_id' => $this->andi->id, 'date' => self::YESTERDAY];

        $this->putJson('/api/v1/team/schedules', $change + ['mode' => 'shift', 'shift_id' => $this->late->id])->assertOk();
        $this->putJson('/api/v1/team/schedules', $change + ['mode' => 'off', 'reason' => 'Ternyata libur'])->assertOk();
        $this->putJson('/api/v1/team/schedules', $change + ['mode' => 'off', 'reason' => str_repeat('x', 256)])->assertJsonValidationErrors('reason');

        $logged = ShiftScheduleChange::query()->where('action', ShiftScheduleChange::ACTION_UPDATED)->orderBy('id')->get();
        $this->assertSame(['api', 'api'], $logged->pluck('source')->all());
        $this->assertSame([null, 'Ternyata libur'], $logged->pluck('reason')->all());
        $this->assertSame([$this->admin->id, $this->admin->id], $logged->pluck('changed_by')->all());
    }

    public function test_copy_and_import_skip_past_days_and_log_their_source(): void
    {
        ShiftSchedule::create(['employee_id' => $this->andi->id, 'date' => '2026-11-16', 'shift_id' => $this->morning->id]);
        ShiftSchedule::create(['employee_id' => $this->andi->id, 'date' => '2026-11-20', 'shift_id' => $this->morning->id]);

        // Copy last week onto this one: Monday 23 Nov is gone, Friday 27 Nov is copied.
        $result = app(ScheduleCopyService::class)->copyWeek($this->admin, Carbon::parse('2026-11-16'), Carbon::parse(self::TODAY));
        $this->assertSame([1, 1], [$result['copied'], $result['skipped_past']]);
        $this->assertStringContainsString('1 dilewati karena tanggalnya sudah lewat.', app(ScheduleCopyService::class)->summary($result));
        $this->assertSame(['2026-11-27'], $this->datesOf('2026-11-23'));

        // Import: yesterday is reported, tomorrow is written.
        $import = app(ShiftScheduleSpreadsheet::class)->import($this->admin, $this->company, $this->workbook([self::YESTERDAY => 'S', self::TOMORROW => 'S']));
        $this->assertSame(1, $import['created']);
        $this->assertSame('Sheet "JChicken Ciledug", '.self::YESTERDAY.': tanggal sudah lewat, jadwal tidak diubah lewat impor. Sel dilewati.', $import['issues'][0]);
        $this->assertSame(['2026-11-26', '2026-11-27'], $this->datesOf('2026-11-23'));

        $this->assertSame(['copy', 'import'], ShiftScheduleChange::query()->whereDate('date', '>=', '2026-11-23')->orderBy('id')->pluck('source')->all());
    }

    public function test_resignation_and_contract_end_log_their_deletions_as_lifecycle(): void
    {
        $this->schedule(self::TODAY, $this->morning);
        $this->schedule(self::TOMORROW, $this->morning);
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => self::TOMORROW, 'shift_id' => $this->morning->id]);
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-27', 'is_day_off' => true]);

        app(WorkerAccountService::class)->endContract($this->bayu, Carbon::parse(self::TODAY));
        Resignation::create(['employee_id' => $this->andi->id, 'resignation_date' => '2026-11-01', 'last_working_day' => self::YESTERDAY, 'reason' => 'Pindah kota', 'status' => 'approved'])->applyResignation();

        $this->assertSame(0, ShiftSchedule::query()->count());
        $deleted = ShiftScheduleChange::query()->where('action', ShiftScheduleChange::ACTION_DELETED)->get();
        $this->assertCount(4, $deleted);
        $this->assertSame(['lifecycle'], $deleted->pluck('source')->unique()->values()->all());
    }

    public function test_an_off_schedule_row_without_a_covered_shift_is_never_late_against_its_snapshot(): void
    {
        $row = Attendance::create([
            'employee_id' => $this->andi->id, 'date' => self::TODAY, 'segment' => 1, 'is_off_schedule' => true,
            'check_in' => self::TODAY.' 11:00:00', 'status' => AttendanceStatus::PendingApproval, 'late_minutes' => 0,
        ]);
        // The shape the backfill writes for a legacy cover without a shift: company hours, source cover.
        $row->forceFill(['sched_source' => 'cover', 'sched_start_at' => self::TODAY.' 08:00:00', 'sched_end_at' => self::TODAY.' 17:00:00', 'sched_tolerance_minutes' => 0])->saveQuietly();

        $this->assertSame(0, app(WorkScheduleService::class)->snapshotLateMinutes($row->fresh()));
        $this->assertSame(0, app(AttendanceLatenessService::class)->judge($row->fresh())['late_minutes']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function schedule(string $date, Shift $shift): ShiftSchedule
    {
        return ShiftSchedule::create(['employee_id' => $this->andi->id, 'date' => $date, 'shift_id' => $shift->id, 'company_location_id' => $this->outlet->id]);
    }

    private function linkedCheckIn(ShiftSchedule $schedule, string $time): Attendance
    {
        $date = $schedule->date->toDateString();
        $row = Attendance::create([
            'employee_id' => $this->andi->id, 'date' => $date, 'segment' => 1, 'shift_id' => $schedule->shift_id, 'company_location_id' => $schedule->company_location_id,
            'check_in' => "{$date} {$time}", 'status' => AttendanceStatus::Late, 'late_minutes' => 30,
        ]);
        $row->forceFill(['shift_schedule_id' => $schedule->id, 'sched_source' => 'schedule', 'sched_start_at' => "{$date} 08:00:00", 'sched_end_at' => "{$date} 17:00:00", 'sched_tolerance_minutes' => 0])->saveQuietly();

        return $row->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function cellArguments(string $date): array
    {
        return ['type' => 'employee', 'id' => $this->andi->id, 'company_id' => $this->company->id, 'name' => $this->andi->full_name, 'date' => $date];
    }

    private function assertLocked(callable $change): void
    {
        try {
            $change();
            $this->fail('A schedule that has attendance must not change.');
        } catch (ValidationException $exception) {
            $this->assertSame([ShiftSchedule::LOCKED_MESSAGE], $exception->errors()['date']);
        }
    }

    /**
     * @return list<string>
     */
    private function datesOf(string $from): array
    {
        return ShiftSchedule::query()->where('employee_id', $this->andi->id)->whereDate('date', '>=', $from)->orderBy('date')->get()
            ->map(fn (ShiftSchedule $schedule): string => $schedule->date->toDateString())->all();
    }

    /**
     * A one-sheet workbook for Andi at the outlet: date => cell text.
     *
     * @param  array<string, string>  $cells
     */
    private function workbook(array $cells): string
    {
        $path = $this->files[] = tempnam(sys_get_temp_dir(), 'lock').'.xlsx';
        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('JChicken Ciledug');
        $writer->addRow(Row::fromValues(['ID Pekerja', 'Nama', 'Jenis', ...array_keys($cells)]));
        $writer->addRow(Row::fromValues(['JC-001', 'Andi', '', ...array_values($cells)]));
        $writer->close();

        return $path;
    }
}
