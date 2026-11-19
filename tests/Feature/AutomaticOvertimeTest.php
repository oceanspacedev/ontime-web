<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Filament\Resources\Shifts\Pages\ManageShifts;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\CompanyPolicy;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalFlowService;
use App\Services\Attendance\LedgerQuery;
use App\Services\AttendanceLedger;
use App\Services\OvertimeRequestService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Overtime above a shift's normal hours is automatic (D2): a shift carries its normal working hours, an
 * attendance keeps the ones that applied when it was checked in, and the reports add the automatic
 * overtime to the approved requests. From the company's netting date on, the part of a request inside
 * the shift is not counted a second time. No overtime is filed or approved on an approved leave day.
 *
 * The long shift is Luuca's 08:45-22:30 (825 minutes) with 8 normal hours. Tuesday 17 Nov 2026.
 */
class AutomaticOvertimeTest extends TestCase
{
    use RefreshDatabase;

    private const MON = '2026-11-16';

    private const TUE = '2026-11-17';

    private const WED = '2026-11-18';

    private Company $company;

    private Division $brand;

    private CompanyLocation $outlet;

    private Shift $long;

    private User $admin;

    private Freelancer $worker;

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

        $this->travelTo(Carbon::parse(self::MON.' 07:00:00'));

        $tenant = Tenant::factory()->create(['name' => 'Klien A', 'code' => 'KA', 'is_active' => true]);
        $this->company = new Company(['name' => 'PT Franchise', 'code' => 'PFC', 'is_active' => true]);
        $this->company->tenant_id = $tenant->id;
        $this->company->save();
        $this->company->policy->update([...CompanyPolicy::flagsForSelfieMode('none'), 'require_gps' => false]);
        app(ApprovalFlowService::class)->createDefaultFlows($this->company);

        $this->brand = Division::create(['company_id' => $this->company->id, 'name' => 'LUUCA', 'code' => 'LUC', 'is_active' => true]);
        $this->outlet = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $this->brand->id, 'name' => 'Luuca Ciledug', 'latitude' => -6.2297, 'longitude' => 106.7112]);
        $this->long = Shift::create(['company_id' => $this->company->id, 'name' => 'Long Shift', 'code' => 'LS', 'start_time' => '08:45', 'end_time' => '22:30']);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->forceFill(['tenant_id' => $tenant->id])->save();
        $this->admin->assignRole(['Admin', 'Approver']);
        $this->admin->grantDataAccess(DataScope::All);

        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['tenant_id' => $tenant->id])->save();
        $user->assignRole('Freelancer');
        $this->worker = Freelancer::create([
            'user_id' => $user->id, 'company_id' => $this->company->id, 'division_id' => $this->brand->id,
            'full_name' => 'Thia', 'start_date' => '2026-01-01', 'status' => 'active',
        ]);
    }

    // ── Normal hours and the snapshot ────────────────────────────────────────

    public function test_normal_hours_set_before_the_check_in_give_automatic_overtime_in_the_recap(): void
    {
        $this->long->update(['normal_minutes' => 480]);
        $this->schedule(self::TUE);

        $row = $this->workDay(self::TUE, '08:40', '22:35');

        $this->assertSame([825, 480], [$row->sched_scheduled_minutes, $row->sched_normal_minutes]);

        // An approved request after the shift counts next to the automatic overtime.
        $this->overtime(self::TUE, '22:30', '23:30');

        $total = $this->recapTotal();

        $this->assertEquals(5.75, $total['auto_overtime_hours']);
        $this->assertEquals(1.0, $total['request_overtime_hours']);
        $this->assertEquals(6.75, $total['overtime_hours']);
        $this->assertEquals(8.0, $total['normal_hours']);
        $this->assertSame(1, $total['overtime_count']);

        // The attendance row itself carries the automatic overtime, in minutes.
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/attendances/'.$row->id)->assertOk()->assertJsonPath('data.overtime_minutes', 345)->assertJsonPath('data.normal_minutes', 480);
    }

    public function test_each_part_of_a_split_shift_carries_its_own_share_of_the_normal_hours(): void
    {
        $split = Shift::create(['company_id' => $this->company->id, 'name' => 'Split', 'code' => 'SP', 'start_time' => '07:45', 'end_time' => '13:00', 'second_start_time' => '17:45', 'second_end_time' => '22:00', 'normal_minutes' => 480]);
        ShiftSchedule::create(['freelancer_id' => $this->worker->id, 'date' => self::TUE, 'shift_id' => $split->id, 'company_location_id' => $this->outlet->id]);

        $first = $this->workDay(self::TUE, '07:45', '13:00');
        $second = $this->workDay(self::TUE, '17:45', '22:00');
        $this->assertSame([1, 2], [(int) $first->segment, (int) $second->segment]);

        // Normal hours fill the parts in order (315 + 165 = 480), as the ledger lines do.
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/attendances/'.$first->id)->assertOk()->assertJsonPath('data.normal_minutes', 315)->assertJsonPath('data.overtime_minutes', 0);
        $this->getJson('/api/v1/attendances/'.$second->id)->assertOk()->assertJsonPath('data.normal_minutes', 165)->assertJsonPath('data.overtime_minutes', 90);
        $this->assertEquals(8.0, $this->recapTotal()['normal_hours']);
    }

    public function test_leaving_early_or_arriving_late_gives_less_automatic_overtime_and_never_more_than_the_shift(): void
    {
        $this->long->update(['normal_minutes' => 480]);

        foreach ([self::MON, self::TUE, self::WED] as $date) {
            $this->schedule($date);
        }

        // Left at 21:00: 735 minutes inside the shift, 255 above normal.
        $this->workDay(self::MON, '08:45', '21:00');
        // Arrived two hours late and stayed to the end: 705 inside the shift, 225 above normal.
        $this->workDay(self::TUE, '10:45', '22:30');
        // Left before the normal hours were worked: none.
        $this->workDay(self::WED, '08:45', '16:00');

        $this->assertEquals(round((255 + 225) / 60, 2), $this->recapTotal()['auto_overtime_hours']);
    }

    public function test_normal_hours_set_after_the_check_in_do_not_change_that_day(): void
    {
        $this->schedule(self::MON);
        $this->schedule(self::TUE);
        $monday = $this->workDay(self::MON, '08:40', '22:35');

        $this->assertNull($monday->sched_normal_minutes);
        $this->assertEquals(0, $this->recapTotal()['auto_overtime_hours']);

        // The shift already has attendance, so its hours are locked; the normal hours stay editable.
        $this->actingAs($this->admin);
        Livewire::test(ManageShifts::class)
            ->callAction(TestAction::make('edit')->table($this->long), ['normal_minutes' => 8])
            ->assertHasNoFormErrors();

        $this->assertSame(480, $this->long->fresh()->normal_minutes);
        $this->assertSame('08:45', substr((string) $this->long->fresh()->start_time, 0, 5));

        // Monday keeps what applied when it was checked in.
        $this->assertNull($monday->fresh()->sched_normal_minutes);
        $this->assertEquals(0, $this->recapTotal()['auto_overtime_hours']);

        // A check-in made afterwards gets the new normal hours.
        $tuesday = $this->workDay(self::TUE, '08:40', '22:35');

        $this->assertSame(480, $tuesday->sched_normal_minutes);
        $this->assertEquals(5.75, $this->recapTotal()['auto_overtime_hours']);
    }

    // ── Shift form ───────────────────────────────────────────────────────────

    public function test_shift_form_stores_normal_hours_in_minutes_and_keeps_them_inside_the_shift(): void
    {
        $this->actingAs($this->admin);
        $form = ['company_id' => $this->company->id, 'start_time' => '08:45', 'end_time' => '22:30', 'is_active' => true];
        $create = fn (string $name, mixed $normal) => Livewire::test(ManageShifts::class)->callAction('create', data: [...$form, 'name' => $name, 'normal_minutes' => $normal]);

        $create('Nol', 0)->assertHasFormErrors(['normal_minutes']);
        $create('Negatif', -1)->assertHasFormErrors(['normal_minutes']);
        // The shift is 13.75 hours long.
        $create('Terlalu panjang', 14)->assertHasFormErrors(['normal_minutes']);
        $this->assertSame(0, Shift::whereIn('name', ['Nol', 'Negatif', 'Terlalu panjang'])->count());

        $create('Delapan jam', 8)->assertHasNoFormErrors();
        $create('Tujuh setengah', 7.5)->assertHasNoFormErrors();
        $create('Seluruh shift', 13.75)->assertHasNoFormErrors();
        $create('Tanpa lembur otomatis', null)->assertHasNoFormErrors();

        $this->assertSame(
            ['Delapan jam' => 480, 'Seluruh shift' => 825, 'Tanpa lembur otomatis' => null, 'Tujuh setengah' => 450],
            Shift::whereIn('name', ['Delapan jam', 'Tujuh setengah', 'Seluruh shift', 'Tanpa lembur otomatis'])->orderBy('name')->pluck('normal_minutes', 'name')->all(),
        );

        // A split shift is measured over both of its parts (5.25 + 4.25 hours).
        $split = ['second_start_time' => '17:45', 'second_end_time' => '22:00', 'start_time' => '07:45', 'end_time' => '13:00'];
        Livewire::test(ManageShifts::class)->callAction('create', data: [...$form, ...$split, 'name' => 'Split panjang', 'normal_minutes' => 10])->assertHasFormErrors(['normal_minutes']);
        Livewire::test(ManageShifts::class)->callAction('create', data: [...$form, ...$split, 'name' => 'Split', 'normal_minutes' => 8])->assertHasNoFormErrors();

        // The form shows the stored minutes as hours, and emptying the field removes the setting.
        $shift = Shift::where('name', 'Tujuh setengah')->sole();
        Livewire::test(ManageShifts::class)
            ->mountAction(TestAction::make('edit')->table($shift))
            ->assertSchemaStateSet(['normal_minutes' => 7.5]);
        Livewire::test(ManageShifts::class)
            ->callAction(TestAction::make('edit')->table($shift), ['normal_minutes' => null])
            ->assertHasNoFormErrors();

        $this->assertNull($shift->fresh()->normal_minutes);
    }

    // ── Requests netted against the shift ────────────────────────────────────

    public function test_from_the_netting_date_a_request_inside_the_shift_counts_nothing_and_an_earlier_one_stays_full(): void
    {
        $this->long->update(['normal_minutes' => 480]);
        $this->schedule(self::MON);
        $this->schedule(self::TUE);
        $this->workDay(self::MON, '08:45', '22:30');
        $this->workDay(self::TUE, '08:45', '22:30');

        $monday = $this->overtime(self::MON, '18:00', '22:30');
        $tuesday = $this->overtime(self::TUE, '18:00', '22:30');
        $afterShift = $this->overtime(self::TUE, '22:30', '23:30');
        // Starts inside the shift and runs an hour past its end.
        $this->travelTo(Carbon::parse(self::WED.' 06:00:00'));

        // Without a netting date every request counts in full, next to the automatic overtime.
        $total = $this->recapTotal();
        $this->assertEquals(round((270 + 270 + 60) / 60, 2), $total['request_overtime_hours']);
        $this->assertEquals(round((345 + 345) / 60, 2), $total['auto_overtime_hours']);
        $this->assertNull(app(OvertimeRequestService::class)->netMinutes($tuesday));

        // The owner switches netting on from Tuesday through the policy form.
        $this->actingAs($this->admin);
        Livewire::test(ListCompanies::class)
            ->callAction(TestAction::make('managePolicy')->table($this->company), ['overtime_netting_from' => self::TUE])
            ->assertHasNoFormErrors();

        $this->assertSame(self::TUE, $this->company->policy->fresh()->overtime_netting_from->toDateString());

        $service = app(OvertimeRequestService::class);
        $this->assertNull($service->netMinutes($monday->fresh()), 'A request dated before the netting date is not netted.');
        $this->assertSame(0, $service->netMinutes($tuesday->fresh()));
        $this->assertSame(60, $service->netMinutes($afterShift->fresh()));

        $total = $this->recapTotal();
        $this->assertEquals(round((270 + 0 + 60) / 60, 2), $total['request_overtime_hours']);
        $this->assertEquals(round((345 + 345) / 60, 2), $total['auto_overtime_hours']);
        $this->assertEquals(round((345 + 345 + 270 + 60) / 60, 2), $total['overtime_hours']);
        $this->assertSame(3, $total['overtime_count']);

        // The worker sees what each request counts for.
        Sanctum::actingAs(User::findOrFail($this->worker->user_id));
        $counted = collect($this->getJson('/api/v1/overtime-requests')->assertOk()->json('data'))->pluck('counted_minutes', 'id');

        $this->assertSame([$monday->id => null, $tuesday->id => 0, $afterShift->id => 60], $counted->sortKeys()->all());
        $this->getJson('/api/v1/overtime-requests/'.$tuesday->id)->assertOk()->assertJsonPath('data.counted_minutes', 0)->assertJsonPath('data.duration_minutes', 270);
    }

    public function test_a_request_that_runs_past_the_shift_counts_only_the_part_after_it(): void
    {
        $this->company->policy->update(['overtime_netting_from' => self::MON]);
        $this->schedule(self::TUE);
        $overtime = $this->overtime(self::TUE, '22:00', '23:30');
        $actual = $this->overtime(self::MON, '20:00', '22:00', ['actual_duration_minutes' => 90]);

        $service = app(OvertimeRequestService::class);

        // 30 of the 90 minutes are inside the shift that ends at 22:30.
        $this->assertSame(60, $service->netMinutes($overtime));
        // No shift on Monday: the actual duration counts in full.
        $this->assertSame(90, $service->netMinutes($actual));
    }

    public function test_a_request_next_to_the_range_nets_against_the_stored_window_whatever_the_range_and_a_later_shift_edit(): void
    {
        $this->company->policy->update(['overtime_netting_from' => '2026-10-01']);
        $night = Shift::create(['company_id' => $this->company->id, 'name' => 'Malam Uji', 'code' => 'MU', 'start_time' => '22:00', 'end_time' => '06:00']);
        ShiftSchedule::create(['freelancer_id' => $this->worker->id, 'date' => '2026-10-31', 'shift_id' => $night->id, 'company_location_id' => $this->outlet->id]);

        Sanctum::actingAs(User::findOrFail($this->worker->user_id));
        $this->travelTo(Carbon::parse('2026-10-31 21:55:00'));
        $this->postJson('/api/v1/attendance/check-in')->assertOk();
        $this->travelTo(Carbon::parse('2026-11-01 06:05:00'));
        $this->postJson('/api/v1/attendance/check-out')->assertOk();

        // 05:00 - 06:00 lies inside the night session worked the day before: 120 of 180 minutes count.
        $request = $this->overtime('2026-11-01', '05:00', '08:00');
        $this->travelTo(Carbon::parse('2026-11-20 09:00:00'));
        $key = 'freelancer:'.$this->worker->id;
        $figures = fn (): array => [
            app(OvertimeRequestService::class)->netMinutes($request->fresh()),
            app(AttendanceLedger::class)->summary(LedgerQuery::forWorkers([$key], '2026-11-01', '2026-11-01'))['request_overtime_minutes'],
            app(AttendanceLedger::class)->summary(LedgerQuery::forWorkers([$key], '2026-10-31', '2026-11-01'))['request_overtime_minutes'],
            $this->recapTotal()['request_overtime_hours'],
        ];

        $this->assertEquals([120, 120, 120, 2.0], $figures());

        // D4: editing the shift after the check-in changes nothing that was worked.
        $night->update(['end_time' => '05:00']);

        $this->assertEquals([120, 120, 120, 2.0], $figures());
    }

    public function test_a_request_on_the_last_day_of_the_range_nets_against_a_session_starting_the_day_after(): void
    {
        $this->company->policy->update(['overtime_netting_from' => '2026-10-01']);
        $dawn = Shift::create(['company_id' => $this->company->id, 'name' => 'Subuh Uji', 'code' => 'SU', 'start_time' => '01:00', 'end_time' => '09:00']);
        ShiftSchedule::create(['freelancer_id' => $this->worker->id, 'date' => '2026-11-02', 'shift_id' => $dawn->id, 'company_location_id' => $this->outlet->id]);

        Sanctum::actingAs(User::findOrFail($this->worker->user_id));
        $this->travelTo(Carbon::parse('2026-11-02 00:55:00'));
        $this->postJson('/api/v1/attendance/check-in')->assertOk();
        $this->travelTo(Carbon::parse('2026-11-02 09:05:00'));
        $this->postJson('/api/v1/attendance/check-out')->assertOk();

        // Filed on Sunday 1 November, 23:00 - 03:00: 01:00 - 03:00 lies inside Monday's session, 120 of 240 minutes count.
        $request = $this->overtime('2026-11-01', '23:00', '03:00');
        $this->travelTo(Carbon::parse('2026-11-20 09:00:00'));
        $key = 'freelancer:'.$this->worker->id;

        $this->assertEquals([120, 120, 120, 2.0], [
            app(OvertimeRequestService::class)->netMinutes($request->fresh()),
            app(AttendanceLedger::class)->summary(LedgerQuery::forWorkers([$key], '2026-11-01', '2026-11-01'))['request_overtime_minutes'],
            app(AttendanceLedger::class)->summary(LedgerQuery::forWorkers([$key], '2026-11-01', '2026-11-02'))['request_overtime_minutes'],
            $this->recapTotal()['request_overtime_hours'],
        ]);
    }

    public function test_a_request_nets_against_a_pending_cover_of_the_day_before_that_has_no_roster_row(): void
    {
        $this->company->policy->update(['overtime_netting_from' => '2026-10-01']);
        $night = Shift::create(['company_id' => $this->company->id, 'name' => 'Malam Uji', 'code' => 'MU', 'start_time' => '22:00', 'end_time' => '06:00']);

        // No roster row on Saturday: the worker covers the night shift outside the schedule, still waiting for approval.
        Sanctum::actingAs(User::findOrFail($this->worker->user_id));
        $this->travelTo(Carbon::parse('2026-10-31 21:55:00'));
        $this->postJson('/api/v1/attendance/check-in', ['cover_shift_id' => $night->id, 'cover_location_id' => $this->outlet->id])->assertOk()->assertJsonPath('requires_approval', true);
        $this->travelTo(Carbon::parse('2026-11-01 06:05:00'));
        $this->postJson('/api/v1/attendance/check-out')->assertOk();
        $this->assertSame(0, ShiftSchedule::query()->where('freelancer_id', $this->worker->id)->count());

        // 05:00 - 06:00 lies inside that night session: 120 of 180 minutes count, whatever the range.
        $request = $this->overtime('2026-11-01', '05:00', '08:00');
        $this->travelTo(Carbon::parse('2026-11-20 09:00:00'));
        $key = 'freelancer:'.$this->worker->id;

        $this->assertEquals([120, 120, 120, 2.0], [
            app(OvertimeRequestService::class)->netMinutes($request->fresh()),
            app(AttendanceLedger::class)->summary(LedgerQuery::forWorkers([$key], '2026-11-01', '2026-11-01'))['request_overtime_minutes'],
            app(AttendanceLedger::class)->summary(LedgerQuery::forWorkers([$key], '2026-10-31', '2026-11-01'))['request_overtime_minutes'],
            $this->recapTotal()['request_overtime_hours'],
        ]);
    }

    public function test_the_app_can_set_the_netting_date_and_an_older_app_leaves_it_alone(): void
    {
        Sanctum::actingAs($this->admin);
        $payload = [
            'late_tolerance_minutes' => 10, 'require_gps' => false, 'geofence_radius_meters' => 100, 'mock_location_action' => 'reject',
            'selfie_mode' => 'none', 'face_match_threshold' => 60, 'face_fail_action' => 'reject', 'annual_leave_quota' => 12, 'default_approval_stages' => 1,
        ];
        $url = "/api/v1/client/companies/{$this->company->id}/policy";

        $this->putJson($url, [...$payload, 'overtime_netting_from' => 'besok'])->assertUnprocessable()->assertJsonValidationErrors('overtime_netting_from');
        $this->putJson($url, [...$payload, 'overtime_netting_from' => '2026-12-01'])->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('data.overtime_netting_from', '2026-12-01');

        // The released app does not know the field.
        $this->putJson($url, $payload)->assertOk();
        $this->assertSame('2026-12-01', $this->company->policy->fresh()->overtime_netting_from->toDateString());

        $this->putJson($url, [...$payload, 'overtime_netting_from' => null])->assertOk();
        $this->assertNull($this->company->policy->fresh()->overtime_netting_from);
    }

    // ── Overtime on a leave day ──────────────────────────────────────────────

    public function test_overtime_on_an_approved_leave_day_is_refused(): void
    {
        $this->leave(self::TUE, self::WED);
        Sanctum::actingAs(User::findOrFail($this->worker->user_id));

        $this->postJson('/api/v1/overtime-requests', ['date' => self::TUE, 'start_time' => '18:00', 'end_time' => '20:00', 'reason' => 'Stok opname'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date' => OvertimeRequestService::ON_LEAVE_MESSAGE]);
        $this->assertSame(0, OvertimeRequest::count());

        // The day before the leave is fine, and cannot be moved onto it afterwards.
        $id = $this->postJson('/api/v1/overtime-requests', ['date' => self::MON, 'start_time' => '18:00', 'end_time' => '20:00', 'reason' => 'Stok opname'])->assertCreated()->json('data.id');
        $this->putJson('/api/v1/overtime-requests/'.$id, ['date' => self::WED])->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->putJson('/api/v1/overtime-requests/'.$id, ['reason' => 'Stok opname bulanan'])->assertOk();

        $this->assertSame(self::MON, OvertimeRequest::findOrFail($id)->date->toDateString());
    }

    public function test_only_the_charged_dates_of_a_leave_refuse_overtime_and_a_pending_leave_does_not(): void
    {
        // Three calendar days, of which Tuesday was not charged (a day off on the roster).
        $this->leave(self::MON, self::WED, ['charged_dates' => [self::MON, self::WED]]);
        $this->leave('2026-11-19', '2026-11-19', ['status' => 'pending']);
        Sanctum::actingAs(User::findOrFail($this->worker->user_id));
        $request = fn (string $date) => $this->postJson('/api/v1/overtime-requests', ['date' => $date, 'start_time' => '18:00', 'end_time' => '20:00', 'reason' => 'Stok opname']);

        $request(self::MON)->assertUnprocessable()->assertJsonValidationErrors('date');
        $request(self::WED)->assertUnprocessable()->assertJsonValidationErrors('date');
        $request(self::TUE)->assertCreated();
        $request('2026-11-19')->assertCreated();
    }

    public function test_overtime_filed_before_the_leave_was_approved_cannot_be_approved_onto_it(): void
    {
        Sanctum::actingAs(User::findOrFail($this->worker->user_id));
        $id = $this->postJson('/api/v1/overtime-requests', ['date' => self::TUE, 'start_time' => '18:00', 'end_time' => '20:00', 'reason' => 'Stok opname'])->assertCreated()->json('data.id');
        $this->leave(self::TUE, self::TUE);

        Sanctum::actingAs($this->admin);
        $this->putJson("/api/v1/approvals/overtime/{$id}/process", ['action' => 'approved'])->assertUnprocessable();
        $this->assertSame('pending', OvertimeRequest::findOrFail($id)->status);

        try {
            app(ApprovalFlowService::class)->approveStep(OvertimeRequest::findOrFail($id), $this->admin);
            $this->fail('The approval of overtime on a leave day was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame(OvertimeRequestService::ON_LEAVE_MESSAGE, $exception->errors()['approve'][0]);
        }

        // It can still be rejected.
        $this->putJson("/api/v1/approvals/overtime/{$id}/process", ['action' => 'rejected', 'rejection_note' => 'Sedang cuti'])->assertOk();
        $this->assertSame('rejected', OvertimeRequest::findOrFail($id)->status);
    }

    public function test_a_leave_cannot_be_approved_onto_a_day_with_approved_overtime(): void
    {
        $this->schedule(self::TUE);
        $this->overtime(self::TUE, '22:30', '23:30');
        $leave = LeaveRequest::create([
            'freelancer_id' => $this->worker->id, 'leave_type' => 'sick', 'start_date' => self::TUE, 'end_date' => self::TUE,
            'days_count' => 1, 'reason' => 'Sakit', 'status' => 'pending',
        ]);
        app(ApprovalFlowService::class)->generateSteps($leave, 'leave');

        try {
            app(ApprovalFlowService::class)->approveStep($leave->fresh(), $this->admin);
            $this->fail('A leave was approved onto a day with approved overtime.');
        } catch (ValidationException $exception) {
            $this->assertSame('Thia sudah memiliki lembur yang disetujui pada tanggal 17/11/2026. Tolak pengajuan ini, atau batalkan lemburnya terlebih dahulu.', $exception->errors()['approve'][0]);
        }

        $this->assertSame('pending', $leave->fresh()->status);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function schedule(string $date): ShiftSchedule
    {
        return ShiftSchedule::create(['freelancer_id' => $this->worker->id, 'date' => $date, 'shift_id' => $this->long->id, 'company_location_id' => $this->outlet->id]);
    }

    /**
     * Check in and out through the API at the given times and return the stored row.
     */
    private function workDay(string $date, string $in, string $out): Attendance
    {
        Sanctum::actingAs(User::findOrFail($this->worker->user_id));

        $this->travelTo(Carbon::parse("{$date} {$in}:00"));
        $id = $this->postJson('/api/v1/attendance/check-in')->assertOk()->json('attendance.id');

        $this->travelTo(Carbon::parse("{$date} {$out}:00"));
        $this->postJson('/api/v1/attendance/check-out')->assertOk();

        return Attendance::findOrFail($id);
    }

    /**
     * An approved overtime request of the worker.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function overtime(string $date, string $start, string $end, array $attributes = []): OvertimeRequest
    {
        return OvertimeRequest::create($attributes + [
            'freelancer_id' => $this->worker->id,
            'date' => $date,
            'start_time' => $start,
            'end_time' => $end,
            'duration_minutes' => OvertimeRequestService::durationMinutes($start, $end),
            'reason' => 'Stok opname',
            'status' => 'approved',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function leave(string $from, string $to, array $attributes = []): LeaveRequest
    {
        return LeaveRequest::create($attributes + [
            'freelancer_id' => $this->worker->id,
            'leave_type' => 'sick',
            'start_date' => $from,
            'end_date' => $to,
            'days_count' => 1,
            'reason' => 'Sakit',
            'status' => 'approved',
        ]);
    }

    /**
     * The total line of the team recap of November for the admin.
     *
     * @return array<string, int|float>
     */
    private function recapTotal(): array
    {
        Sanctum::actingAs($this->admin);

        return $this->getJson('/api/v1/team/recap?month=2026-11')->assertOk()->json('total');
    }
}
