<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\DataScope;
use App\Filament\Imports\FreelancerImporter;
use App\Filament\Resources\EmployeeResource;
use App\Filament\Resources\EmployeeResource\Pages\EditEmployee;
use App\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use App\Filament\Resources\FreelanceResource;
use App\Filament\Resources\FreelanceResource\Pages\CreateFreelancer;
use App\Filament\Resources\FreelanceResource\Pages\EditFreelancer;
use App\Filament\Resources\FreelanceResource\Pages\ListFreelancers;
use App\Filament\Resources\InternResource\Pages\CreateIntern;
use App\Filament\Resources\InternResource\Pages\EditIntern;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\Resignation;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TalentaEmployeeImportService;
use App\Services\WorkerAccountService;
use App\Services\WorkerDeletionBlockedException;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\Models\Import;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PIC onboarding guards for employees, interns and freelancers: no check-in before the contract or join
 * date, no delete once there is payroll history, one active worker per phone number, a contract end
 * not before its start, and one worker profile per login account.
 */
class WorkerOnboardingGuardsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Division $division;

    private User $pic;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.whatsapp_gateway.url' => null, 'services.whatsapp_gateway.token' => null]);
        Http::preventStrayRequests();
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-11-16 07:50:00'));
        $this->seed(RoleSeeder::class);

        $this->tenant = Tenant::factory()->create(['name' => 'Klien Guard', 'code' => 'GD']);
        $this->company = $this->companyFor($this->tenant, 'GDQ');
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Outlet', 'is_active' => true]);

        $this->pic = User::factory()->create(['is_active' => true]);
        $this->pic->forceFill(['tenant_id' => $this->tenant->id])->save();
        $this->pic->assignRole('PIC');
        $this->pic->assignRole('Admin');
        $this->pic->grantDataAccess(DataScope::All);
    }

    // ── (a) No check-in before the contract or join date ──────────────────────

    public function test_contract_starting_tomorrow_is_not_active_today_and_explains_when_it_starts(): void
    {
        $service = app(WorkerAccountService::class);
        $freelancer = $this->freelancer(['start_date' => '2026-11-17']);

        $this->assertFalse($service->isActive($freelancer));
        $this->assertFalse($service->hasEnded($freelancer), 'A contract that has not started has not ended either');
        $this->assertSame('Kontrak Anda baru mulai 17 November 2026. Absen baru bisa dilakukan mulai tanggal itu.', $service->inactiveReason($freelancer));
    }

    public function test_contract_counts_as_active_from_its_first_day_and_without_a_start_date(): void
    {
        $service = app(WorkerAccountService::class);

        $this->assertTrue($service->isActive($this->freelancer(['start_date' => '2026-11-16'])));
        $this->assertTrue($service->isActive($this->freelancer(['start_date' => null])));
        $this->assertNull($service->inactiveReason($this->intern(['start_date' => '2026-11-01'])));
    }

    public function test_one_minute_before_midnight_the_next_day_contract_is_still_not_active(): void
    {
        $this->travelTo(Carbon::parse('2026-11-16 23:59:00'));
        $freelancer = $this->freelancer(['start_date' => '2026-11-17']);

        $this->assertFalse(app(WorkerAccountService::class)->isActive($freelancer));

        $this->travelTo(Carbon::parse('2026-11-17 00:00:00'));
        $this->assertTrue(app(WorkerAccountService::class)->isActive($freelancer->fresh()));
    }

    public function test_work_date_of_an_overnight_shift_decides_instead_of_today(): void
    {
        $this->travelTo(Carbon::parse('2026-11-16 23:30:00'));
        $freelancer = $this->freelancer(['start_date' => '2026-11-17']);

        $this->assertTrue(app(WorkerAccountService::class)->isActive($freelancer, Carbon::parse('2026-11-17')));
        $this->assertFalse(app(WorkerAccountService::class)->isActive($freelancer, Carbon::parse('2026-11-16')));
    }

    public function test_employee_with_a_future_join_date_is_not_active(): void
    {
        $employee = $this->employee(['join_date' => '2026-12-01']);

        $this->assertFalse(app(WorkerAccountService::class)->isActive($employee));
        $this->assertStringStartsWith('Tanggal bergabung Anda 01 Desember 2026.', (string) app(WorkerAccountService::class)->inactiveReason($employee));
    }

    public function test_ended_status_wins_over_a_future_start_date(): void
    {
        $freelancer = $this->freelancer(['start_date' => '2026-12-01', 'status' => 'terminated']);

        $this->assertTrue(app(WorkerAccountService::class)->hasEnded($freelancer));
        $this->assertSame(WorkerAccountService::INACTIVE_MESSAGE, app(WorkerAccountService::class)->inactiveReason($freelancer));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function workerTypes(): array
    {
        return ['employee' => ['employee'], 'intern' => ['intern'], 'freelancer' => ['freelancer']];
    }

    #[DataProvider('workerTypes')]
    public function test_worker_cannot_check_in_before_the_start_date_but_can_on_it(string $type): void
    {
        $startColumn = $type === 'employee' ? 'join_date' : 'start_date';
        $worker = $this->{$type}([$startColumn => '2026-11-17']);
        $account = app(WorkerAccountService::class)->createAccount($worker)['user'];

        if ($worker instanceof Freelancer) {
            // A freelancer without a schedule is an off-schedule check-in that needs approval; schedule the first day.
            $shift = Shift::create(['company_id' => $this->company->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '16:00', 'late_tolerance_minutes' => 0, 'is_active' => true]);
            ShiftSchedule::create(['date' => '2026-11-17', 'freelancer_id' => $worker->id, 'shift_id' => $shift->id]);
        }

        Sanctum::actingAs($account);
        $this->postJson('/api/v1/attendance/check-in', [])->assertForbidden();
        $this->assertSame(0, Attendance::count());

        $this->travelTo(Carbon::parse('2026-11-17 07:50:00'));
        Sanctum::actingAs($account->fresh());
        $this->postJson('/api/v1/attendance/check-in', [])->assertSuccessful();
        $this->assertSame(1, Attendance::count());
    }

    public function test_not_started_freelancer_offers_end_contract_not_reactivate(): void
    {
        $freelancer = $this->freelancer(['start_date' => '2026-12-01']);

        $this->actingAs($this->pic);
        Livewire::test(EditFreelancer::class, ['record' => $freelancer->id])
            ->assertActionVisible('endContract')
            ->assertActionHidden('reactivate');
    }

    // ── (b) No delete once there is payroll history ───────────────────────────

    /**
     * @return array<string, array{string}>
     */
    public static function historyKinds(): array
    {
        return [
            'attendance' => ['attendance'],
            'overtime' => ['overtime'],
            'leave' => ['leave'],
            'correction' => ['correction'],
        ];
    }

    #[DataProvider('historyKinds')]
    public function test_freelancer_with_any_kind_of_history_cannot_be_deleted(string $kind): void
    {
        $freelancer = $this->freelancer();
        $this->history($kind, 'freelancer_id', $freelancer->id);

        $this->assertNotNull(app(WorkerAccountService::class)->deletionBlockedReason($freelancer));

        $this->actingAs($this->pic);
        Livewire::test(EditFreelancer::class, ['record' => $freelancer->id])
            ->assertActionDisabled('delete')
            ->callAction('delete');

        $this->assertNotNull(Freelancer::find($freelancer->id));
    }

    public function test_employee_with_only_a_resignation_cannot_be_deleted(): void
    {
        $employee = $this->employee();
        $this->history('resignation', 'employee_id', $employee->id);

        $this->actingAs($this->pic);
        Livewire::test(EditEmployee::class, ['record' => $employee->id])->assertActionDisabled('delete');

        $this->assertNotNull(app(WorkerAccountService::class)->deletionBlockedReason($employee));
    }

    public function test_employee_and_intern_with_history_cannot_be_deleted_from_the_edit_page(): void
    {
        $employee = $this->employee();
        $intern = $this->intern();
        $this->history('attendance', 'employee_id', $employee->id);
        $this->history('overtime', 'intern_id', $intern->id);

        $this->actingAs($this->pic);
        Livewire::test(EditEmployee::class, ['record' => $employee->id])->assertActionDisabled('delete')->callAction('delete');
        Livewire::test(EditIntern::class, ['record' => $intern->id])->assertActionDisabled('delete')->callAction('delete');

        $this->assertNotNull(Employee::find($employee->id));
        $this->assertNotNull(Intern::find($intern->id));
        $this->assertSame(1, Attendance::where('employee_id', $employee->id)->count());
        $this->assertSame(1, OvertimeRequest::where('intern_id', $intern->id)->count());
        $this->assertStringContainsString('Non-Aktif atau Resign', (string) app(WorkerAccountService::class)->deletionBlockedReason($employee));
    }

    public function test_history_added_after_the_page_loaded_still_blocks_the_delete(): void
    {
        $freelancer = $this->freelancer();

        $this->actingAs($this->pic);
        $page = Livewire::test(EditFreelancer::class, ['record' => $freelancer->id])->assertActionEnabled('delete');

        // The freelancer checks in while the PIC has the page open.
        $this->history('attendance', 'freelancer_id', $freelancer->id);
        $page->callAction('delete');

        $this->assertNotNull(Freelancer::find($freelancer->id));
        $this->assertSame(1, Attendance::where('freelancer_id', $freelancer->id)->count());
    }

    public function test_worker_without_history_can_still_be_deleted(): void
    {
        $freelancer = $this->freelancer();
        $employee = $this->employee();

        $this->actingAs($this->pic);
        Livewire::test(EditFreelancer::class, ['record' => $freelancer->id])->assertActionEnabled('delete')->callAction('delete');
        Livewire::test(EditEmployee::class, ['record' => $employee->id])->callAction('delete');

        $this->assertNull(Freelancer::find($freelancer->id));
        $this->assertNull(Employee::find($employee->id));
    }

    public function test_table_row_delete_is_disabled_only_for_workers_with_history(): void
    {
        $withHistory = $this->freelancer(['full_name' => 'Punya Riwayat']);
        $clean = $this->freelancer(['full_name' => 'Baru']);
        $this->history('attendance', 'freelancer_id', $withHistory->id);

        $this->actingAs($this->pic);
        Livewire::test(ListFreelancers::class)
            ->assertActionDisabled(TestAction::make('delete')->table($withHistory))
            ->assertActionEnabled(TestAction::make('delete')->table($clean));
    }

    public function test_bulk_delete_skips_workers_with_history_and_deletes_the_rest(): void
    {
        $withHistory = $this->freelancer(['full_name' => 'Punya Riwayat']);
        $clean = $this->freelancer(['full_name' => 'Baru']);
        $this->history('leave', 'freelancer_id', $withHistory->id);

        $this->actingAs($this->pic);
        Livewire::test(ListFreelancers::class)
            ->selectTableRecords([$withHistory->id, $clean->id])
            ->callAction(TestAction::make('delete')->table()->bulk());

        $this->assertNotNull(Freelancer::find($withHistory->id));
        $this->assertNull(Freelancer::find($clean->id));
        $this->assertSame(1, LeaveRequest::where('freelancer_id', $withHistory->id)->count());
    }

    public function test_employee_bulk_delete_keeps_employees_with_history(): void
    {
        $withHistory = $this->employee();
        $clean = $this->employee();
        $this->history('resignation', 'employee_id', $withHistory->id);

        $this->actingAs($this->pic);
        Livewire::test(ListEmployees::class)
            ->set('tableFilters.status.value', null)
            ->selectTableRecords([$withHistory->id, $clean->id])
            ->callAction(TestAction::make('delete')->table()->bulk());

        $this->assertNotNull(Employee::find($withHistory->id));
        $this->assertNull(Employee::find($clean->id));
    }

    // ── (c) One active worker per phone number ────────────────────────────────

    public function test_phone_formats_normalise_to_one_number(): void
    {
        $this->assertSame('6281234567890', WorkerAccountService::normalizePhone('081234567890'));
        $this->assertSame('6281234567890', WorkerAccountService::normalizePhone('6281234567890'));
        $this->assertSame('6281234567890', WorkerAccountService::normalizePhone('+62 812-3456-7890'));
        $this->assertSame('6281234567890', WorkerAccountService::normalizePhone('81234567890'));
        $this->assertNull(WorkerAccountService::normalizePhone(' - '));
        $this->assertNull(WorkerAccountService::normalizePhone(null));
    }

    public function test_api_rejects_a_number_of_an_active_worker_in_any_format(): void
    {
        $this->employee(['phone' => '081299990000']);
        $outlet = $this->outlet();
        Sanctum::actingAs($this->pic);

        foreach (['081299990000', '6281299990000', '+62 812-9999-0000'] as $format) {
            $this->postJson('/api/v1/team/freelancers', ['full_name' => 'Sari', 'phone' => $format, 'company_location_id' => $outlet->id])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['phone' => WorkerAccountService::PHONE_TAKEN_MESSAGE]);
        }

        $this->assertSame(0, Freelancer::count());
    }

    public function test_api_rejects_a_number_held_by_an_active_account_of_another_client(): void
    {
        $otherTenant = Tenant::factory()->create(['name' => 'Klien Lain', 'code' => 'LN']);
        $account = User::factory()->create(['is_active' => true, 'phone' => '081277770000']);
        $account->forceFill(['tenant_id' => $otherTenant->id])->save();
        Sanctum::actingAs($this->pic);

        $this->postJson('/api/v1/team/freelancers', ['full_name' => 'Sari', 'phone' => '081277770000', 'company_location_id' => $this->outlet()->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }

    public function test_number_of_an_ended_worker_or_switched_off_account_can_be_reused(): void
    {
        $this->freelancer(['phone' => '081211110000', 'status' => 'completed']);
        $this->intern(['phone' => '081211110001', 'end_date' => '2026-11-15']);
        User::factory()->create(['is_active' => false, 'phone' => '081211110002']);
        Sanctum::actingAs($this->pic);
        $outlet = $this->outlet();

        foreach (['081211110000', '081211110001', '081211110002'] as $phone) {
            $this->postJson('/api/v1/team/freelancers', ['full_name' => "Kru {$phone}", 'phone' => $phone, 'company_location_id' => $outlet->id])->assertCreated();
        }

        $this->assertSame(3, Freelancer::where('status', 'active')->count());
    }

    public function test_panel_create_rejects_a_taken_number_for_intern_and_freelancer(): void
    {
        $this->freelancer(['phone' => '081233330000']);

        $this->actingAs($this->pic);
        Livewire::test(CreateIntern::class)
            ->fillForm($this->internForm(['phone' => '+62 812 3333 0000']))
            ->call('create')
            ->assertHasFormErrors(['phone']);

        Livewire::test(CreateFreelancer::class)
            ->fillForm($this->freelancerForm(['phone' => '6281233330000']))
            ->call('create')
            ->assertHasFormErrors(['phone']);

        $this->assertSame(0, Intern::count());
        $this->assertSame(1, Freelancer::count());
    }

    public function test_panel_edit_keeps_the_workers_own_number_and_account_number(): void
    {
        $freelancer = $this->freelancer(['phone' => '081244440000']);
        app(WorkerAccountService::class)->createAccount($freelancer);

        $this->actingAs($this->pic);
        Livewire::test(EditFreelancer::class, ['record' => $freelancer->id])
            ->fillForm(['full_name' => 'Nama Baru', 'phone' => '0812 4444 0000'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Nama Baru', $freelancer->fresh()->full_name);
    }

    public function test_panel_edit_rejects_switching_to_another_workers_number(): void
    {
        $this->employee(['phone' => '081255550000']);
        $intern = $this->intern(['phone' => '081255550001']);

        $this->actingAs($this->pic);
        Livewire::test(EditIntern::class, ['record' => $intern->id])
            ->fillForm(['phone' => '081255550000'])
            ->call('save')
            ->assertHasFormErrors(['phone']);

        $this->assertSame('6281255550001', $intern->fresh()->phone);
    }

    public function test_panel_reactivation_is_refused_when_the_number_went_to_someone_else(): void
    {
        $ended = $this->freelancer(['phone' => '081266660000', 'status' => 'completed', 'end_date' => '2026-11-10']);
        $this->freelancer(['phone' => '081266660000']);

        $this->actingAs($this->pic);
        Livewire::test(EditFreelancer::class, ['record' => $ended->id])->callAction('reactivate');

        $this->assertSame('completed', $ended->fresh()->status);
    }

    // ── (d) Contract end not before its start ─────────────────────────────────

    public function test_panel_rejects_an_intern_end_date_before_the_start_date(): void
    {
        $this->actingAs($this->pic);
        Livewire::test(CreateIntern::class)
            ->fillForm($this->internForm(['start_date' => '2026-11-20', 'end_date' => '2026-11-19']))
            ->call('create')
            ->assertHasFormErrors(['end_date']);

        $this->assertSame(0, Intern::count());
    }

    public function test_panel_accepts_a_one_day_contract_and_an_end_date_without_a_start(): void
    {
        $this->actingAs($this->pic);
        Livewire::test(CreateIntern::class)
            ->fillForm($this->internForm(['nis' => 'NIS-1', 'start_date' => '2026-11-20', 'end_date' => '2026-11-20']))
            ->call('create')
            ->assertHasNoFormErrors();

        Livewire::test(CreateFreelancer::class)
            ->fillForm($this->freelancerForm(['start_date' => null, 'end_date' => '2026-12-31']))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, Intern::count());
        $this->assertSame(1, Freelancer::count());
    }

    // ── (e) One worker profile per login account ──────────────────────────────

    public function test_account_picker_only_offers_unlinked_accounts_of_the_own_client(): void
    {
        $employeeAccount = app(WorkerAccountService::class)->createAccount($this->employee())['user'];
        $internAccount = app(WorkerAccountService::class)->createAccount($this->intern())['user'];
        $freelancerAccount = app(WorkerAccountService::class)->createAccount($this->freelancer())['user'];

        $unlinked = User::factory()->create(['is_active' => true, 'name' => 'Akun Kosong']);
        $unlinked->forceFill(['tenant_id' => $this->tenant->id])->save();

        $otherTenant = Tenant::factory()->create(['name' => 'Klien Lain', 'code' => 'LN']);
        $foreign = User::factory()->create(['is_active' => true]);
        $foreign->forceFill(['tenant_id' => $otherTenant->id])->save();

        $this->actingAs($this->pic);
        $options = FreelanceResource::assignableUserOptions($this->company->id);

        $this->assertArrayHasKey($unlinked->id, $options);
        foreach ([$employeeAccount, $internAccount, $freelancerAccount, $foreign] as $unavailable) {
            $this->assertArrayNotHasKey($unavailable->id, $options);
        }
        $this->assertSame($options, EmployeeResource::assignableUserOptions($this->company->id));
    }

    public function test_server_rejects_linking_an_account_already_linked_to_another_worker(): void
    {
        $account = app(WorkerAccountService::class)->createAccount($this->employee())['user'];
        $intern = $this->intern();

        $this->actingAs($this->pic);
        Livewire::test(EditIntern::class, ['record' => $intern->id])
            ->fillForm(['user_id' => $account->id])
            ->call('save')
            ->assertHasFormErrors(['user_id']);

        $this->assertNull($intern->fresh()->user_id);
        $this->assertSame(WorkerAccountService::ACCOUNT_LINKED_MESSAGE, app(WorkerAccountService::class)->accountLinkBlockedReason($account->id, $intern));
    }

    public function test_server_rejects_linking_an_account_of_another_client(): void
    {
        $otherTenant = Tenant::factory()->create(['name' => 'Klien Lain', 'code' => 'LN']);
        $foreign = User::factory()->create(['is_active' => true]);
        $foreign->forceFill(['tenant_id' => $otherTenant->id])->save();
        $freelancer = $this->freelancer();

        $this->actingAs($this->pic);
        Livewire::test(EditFreelancer::class, ['record' => $freelancer->id])
            ->fillForm(['user_id' => $foreign->id])
            ->call('save')
            ->assertHasFormErrors(['user_id']);

        $this->assertNull($freelancer->fresh()->user_id);
    }

    public function test_linking_a_free_account_and_keeping_the_current_one_both_save(): void
    {
        $free = User::factory()->create(['is_active' => true]);
        $free->forceFill(['tenant_id' => $this->tenant->id])->save();
        $freelancer = $this->freelancer();

        $this->actingAs($this->pic);
        Livewire::test(EditFreelancer::class, ['record' => $freelancer->id])
            ->fillForm(['user_id' => $free->id])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame($free->id, $freelancer->fresh()->user_id);

        // Saving again with the same (now linked) account is not a conflict with itself.
        Livewire::test(EditFreelancer::class, ['record' => $freelancer->id])
            ->fillForm(['full_name' => 'Nama Baru'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame($free->id, $freelancer->fresh()->user_id);
    }

    // ── Review fixes: freeing numbers, model-level guard, imports, API ─────────

    public function test_deleting_a_worker_without_history_frees_the_number_for_a_new_entry(): void
    {
        $freelancer = $this->freelancer(['phone' => '081277770000']);
        $account = app(WorkerAccountService::class)->createAccount($freelancer)['user'];

        $this->actingAs($this->pic);
        Livewire::test(EditFreelancer::class, ['record' => $freelancer->id])->callAction('delete');

        $this->assertNull(Freelancer::find($freelancer->id));
        $this->assertFalse($account->fresh()->is_active, 'The orphan worker account is switched off');

        Livewire::test(CreateFreelancer::class)
            ->fillForm($this->freelancerForm(['phone' => '081277770000']))
            ->call('create')
            ->assertHasNoFormErrors();
    }

    public function test_orphan_worker_account_from_an_earlier_delete_does_not_hold_the_number(): void
    {
        $orphan = User::factory()->create(['is_active' => true, 'phone' => '081277771111']);
        $orphan->assignRole('Freelancer');
        $admin = User::factory()->create(['is_active' => true, 'phone' => '081277772222']);
        $admin->assignRole('Admin');

        $service = app(WorkerAccountService::class);
        $this->assertFalse($service->phoneIsTaken('081277771111'));
        $this->assertTrue($service->phoneIsTaken('081277772222'), 'An Admin account still holds its number');
    }

    public function test_deleting_does_not_switch_off_an_account_that_is_also_an_admin(): void
    {
        $freelancer = $this->freelancer(['phone' => '081277773333']);
        $account = app(WorkerAccountService::class)->createAccount($freelancer)['user'];
        $account->assignRole('Admin');

        $freelancer->delete();

        $this->assertTrue($account->fresh()->is_active);
    }

    public function test_model_delete_of_a_worker_with_history_throws_and_keeps_the_history(): void
    {
        $employee = $this->employee();
        $this->history('attendance', 'employee_id', $employee->id);

        try {
            $employee->delete();
            $this->fail('Deleting a worker with history must throw.');
        } catch (WorkerDeletionBlockedException $exception) {
            $this->assertStringContainsString('jangan dihapus', $exception->getMessage());
        }

        $this->assertNotNull(Employee::find($employee->id));
        $this->assertSame(1, Attendance::where('employee_id', $employee->id)->count());
    }

    public function test_legacy_duplicate_number_does_not_block_an_unrelated_edit(): void
    {
        $first = $this->freelancer(['phone' => '081288880000']);
        $this->freelancer(['phone' => '081288880000']);

        $this->actingAs($this->pic);
        Livewire::test(EditFreelancer::class, ['record' => $first->id])
            ->fillForm(['full_name' => 'Nama Dibetulkan'])
            ->call('save')
            ->assertHasNoFormErrors();

        Livewire::test(EditFreelancer::class, ['record' => $first->id])
            ->fillForm(['status' => 'completed', 'end_date' => '2026-11-16'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['Nama Dibetulkan', 'completed'], [$first->fresh()->full_name, $first->fresh()->status]);
    }

    public function test_panel_edit_refuses_setting_an_ended_worker_back_to_active_on_a_taken_number(): void
    {
        $employee = $this->employee(['phone' => '081288881111', 'status' => 'inactive']);
        $this->freelancer(['phone' => '081288881111']);

        $this->actingAs($this->pic);
        Livewire::test(EditEmployee::class, ['record' => $employee->id])
            ->fillForm(['status' => 'active'])
            ->call('save')
            ->assertHasFormErrors(['phone']);

        $this->assertSame('inactive', $employee->fresh()->status);
    }

    public function test_employee_set_inactive_in_the_panel_frees_the_number(): void
    {
        $employee = $this->employee(['phone' => '081299990000']);
        app(WorkerAccountService::class)->createAccount($employee);

        $this->actingAs($this->pic);
        Livewire::test(EditEmployee::class, ['record' => $employee->id])
            ->fillForm(['status' => 'inactive'])
            ->call('save')
            ->assertHasNoFormErrors();

        Livewire::test(CreateFreelancer::class)
            ->fillForm($this->freelancerForm(['phone' => '081299990000']))
            ->call('create')
            ->assertHasNoFormErrors();
    }

    public function test_webhook_delete_sets_an_employee_with_history_inactive_instead(): void
    {
        $employee = $this->employee();
        $account = app(WorkerAccountService::class)->createAccount($employee)['user'];
        $this->history('attendance', 'employee_id', $employee->id);

        $this->employeeWebhook(['action' => 'deleted', 'employee' => ['id' => $employee->id, 'name' => 'Andi', 'status' => 'inactive']])->assertOk();

        $this->assertSame('inactive', $employee->fresh()->status);
        $this->assertFalse($account->fresh()->is_active);
        $this->assertSame(1, Attendance::where('employee_id', $employee->id)->count());
    }

    public function test_webhook_delete_removes_an_employee_without_history(): void
    {
        $employee = $this->employee();

        $this->employeeWebhook(['action' => 'deleted', 'employee' => ['id' => $employee->id, 'name' => 'Andi', 'status' => 'inactive']])->assertOk();

        $this->assertNull(Employee::find($employee->id));
    }

    public function test_webhook_update_keeps_the_old_number_when_the_new_one_is_taken(): void
    {
        $this->freelancer(['phone' => '081200001111']);
        $employee = $this->employee(['phone' => '081200002222']);

        $this->employeeWebhook(['action' => 'updated', 'employee' => ['id' => $employee->id, 'name' => 'Andi Baru', 'phone' => '081200001111', 'status' => 'active']])->assertOk();

        $this->assertSame(['Andi Baru', '6281200002222'], [$employee->fresh()->full_name, $employee->fresh()->phone]);
    }

    public function test_team_api_reports_a_not_started_freelancer_as_not_ended(): void
    {
        $freelancer = $this->freelancer(['start_date' => '2026-11-20']);
        Sanctum::actingAs($this->pic);

        $row = collect($this->getJson('/api/v1/team/freelancers')->assertOk()->json('data'))->firstWhere('id', $freelancer->id);

        $this->assertTrue($row['is_active'], 'The mobile list must offer "Akhiri", not "Aktifkan lagi"');
        $this->assertFalse($row['has_started']);
        $this->assertSame(['not_started', 'Belum mulai'], [$row['contract_state'], $row['contract_state_label']]);
    }

    public function test_team_api_reactivation_is_refused_when_the_number_went_to_someone_else(): void
    {
        $ended = $this->freelancer(['phone' => '081266661111', 'status' => 'completed', 'end_date' => '2026-11-10']);
        $this->freelancer(['phone' => '081266661111']);
        Sanctum::actingAs($this->pic);

        $this->postJson("/api/v1/team/freelancers/{$ended->id}/reactivate")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone']);

        $this->assertSame('completed', $ended->fresh()->status);
    }

    public function test_create_account_refuses_a_number_held_by_another_active_worker(): void
    {
        $this->employee(['phone' => '081233334444']);
        $legacyDuplicate = $this->freelancer(['phone' => '081233334444']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(WorkerAccountService::PHONE_TAKEN_MESSAGE);

        app(WorkerAccountService::class)->createAccount($legacyDuplicate);
    }

    public function test_freelancer_import_fails_a_row_with_a_taken_number(): void
    {
        $this->employee(['phone' => '081244445555']);
        $import = Import::create(['file_name' => 'freelance.csv', 'file_path' => 'freelance.csv', 'importer' => FreelancerImporter::class, 'total_rows' => 1, 'user_id' => $this->pic->id]);
        $columns = ['freelancer_number' => 'Nomor Freelance', 'full_name' => 'Nama Lengkap', 'company' => 'Badan Usaha', 'division' => 'Divisi', 'phone' => 'Telepon'];

        try {
            ($import->getImporter($columns, []))(['Nomor Freelance' => 'FL-IMP-1', 'Nama Lengkap' => 'Kru Import', 'Badan Usaha' => 'GDQ', 'Divisi' => 'Outlet', 'Telepon' => '0812 4444 5555']);
            $this->fail('The row must fail.');
        } catch (RowImportFailedException $exception) {
            $this->assertSame(WorkerAccountService::PHONE_TAKEN_MESSAGE, $exception->getMessage());
        }

        ($import->getImporter($columns, []))(['Nomor Freelance' => 'FL-IMP-2', 'Nama Lengkap' => 'Kru Lain', 'Badan Usaha' => 'GDQ', 'Divisi' => 'Outlet', 'Telepon' => '081244446666']);

        $this->assertSame(['FL-IMP-2'], Freelancer::pluck('freelancer_number')->all());
    }

    public function test_talenta_import_skips_and_reports_a_row_with_a_taken_number(): void
    {
        $this->freelancer(['phone' => '081255556666']);
        $rows = [
            ['id_employee' => 'TL-1', 'first_name' => 'Andi', 'branch' => $this->company->name, 'organization' => 'Outlet', 'job' => 'Kasir', 'mobile_phone' => '081255556666'],
            ['id_employee' => 'TL-2', 'first_name' => 'Budi', 'branch' => $this->company->name, 'organization' => 'Outlet', 'job' => 'Kasir', 'mobile_phone' => '081255557777'],
        ];

        $result = app(TalentaEmployeeImportService::class)->importFromContent((string) json_encode($rows), tenantId: $this->tenant->id);

        $this->assertSame(1, $result['skipped']);
        $this->assertStringContainsString(WorkerAccountService::PHONE_TAKEN_MESSAGE, implode(' ', $result['errors']));
        $this->assertSame(['TL-2'], Employee::pluck('nip')->all());
    }

    public function test_worker_table_loads_the_delete_guard_without_a_query_per_row(): void
    {
        $withHistory = $this->freelancer();
        $this->history('attendance', 'freelancer_id', $withHistory->id);
        foreach (range(1, 5) as $ignored) {
            $this->freelancer();
        }

        $this->actingAs($this->pic);
        DB::enableQueryLog();
        Livewire::test(ListFreelancers::class)
            ->assertActionDisabled(TestAction::make('delete')->table($withHistory));
        $historyLookups = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql): bool => preg_match('/^select exists\s*\(select \* from ["`]?(attendances|overtime_requests|leave_requests|attendance_corrections)/i', $sql) === 1);
        DB::disableQueryLog();

        $this->assertCount(0, $historyLookups, 'History is read from the table query, not per row');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function employeeWebhook(array $payload): TestResponse
    {
        config(['services.core.webhook_secret' => 'guard-secret']);
        $body = (string) json_encode($payload);

        return $this->call('POST', '/api/webhooks/employees', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, 'guard-secret'),
        ], $body);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function companyFor(Tenant $tenant, string $code): Company
    {
        $company = new Company(['name' => "PT {$code}", 'code' => $code, 'is_active' => true]);
        $company->tenant_id = $tenant->id;
        $company->save();
        $company->refresh();
        $company->policy->update(['require_gps' => false, 'require_photo' => false, 'require_face_recognition' => false, 'require_liveness' => false, 'work_start_time' => '08:00:00', 'work_end_time' => '17:00:00', 'late_tolerance_minutes' => 0]);

        return $company;
    }

    private function nextPhone(): string
    {
        return sprintf('0813%08d', ++$this->sequence);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function freelancer(array $attributes = []): Freelancer
    {
        return Freelancer::create([
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'full_name' => 'Budi Freelance',
            'phone' => $this->nextPhone(),
            'status' => 'active',
            'start_date' => '2026-10-01',
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function intern(array $attributes = []): Intern
    {
        return Intern::create([
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'nis' => 'NIS-'.(++$this->sequence),
            'full_name' => 'Ani Magang',
            'institution' => 'SMK 1',
            'phone' => $this->nextPhone(),
            'status' => 'active',
            'start_date' => '2026-10-01',
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function employee(array $attributes = []): Employee
    {
        return Employee::create([
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'nip' => 'EMP-'.(++$this->sequence),
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_number' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Kasir'])->id,
            'full_name' => 'Andi Karyawan',
            'phone' => $this->nextPhone(),
            'status' => 'active',
            'join_date' => '2026-01-01',
            ...$attributes,
        ]);
    }

    private function outlet(): CompanyLocation
    {
        return CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $this->division->id, 'name' => 'Outlet 1', 'latitude' => -6.2, 'longitude' => 106.8, 'radius_meters' => 100, 'is_active' => true]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function internForm(array $overrides = []): array
    {
        return [
            'nis' => 'NIS-FORM',
            'full_name' => 'Ani Magang',
            'institution' => 'SMK 1',
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'status' => 'active',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function freelancerForm(array $overrides = []): array
    {
        return [
            'full_name' => 'Budi Freelance',
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'status' => 'active',
            'create_account' => false,
            ...$overrides,
        ];
    }

    private function history(string $kind, string $column, int $workerId): void
    {
        match ($kind) {
            'attendance' => Attendance::create([$column => $workerId, 'date' => '2026-11-15', 'check_in' => '2026-11-15 08:00:00', 'status' => AttendanceStatus::cases()[0]]),
            'overtime' => OvertimeRequest::create([$column => $workerId, 'date' => '2026-11-15', 'start_time' => '17:00', 'end_time' => '19:00', 'duration_minutes' => 120, 'reason' => 'Stok', 'status' => 'approved', 'current_step' => 1]),
            'leave' => LeaveRequest::create([$column => $workerId, 'leave_type' => 'sick', 'start_date' => '2026-11-14', 'end_date' => '2026-11-14', 'days_count' => 1, 'reason' => 'Demam', 'status' => 'pending']),
            'correction' => AttendanceCorrection::create([$column => $workerId, 'date' => '2026-11-10', 'corrected_check_in' => '08:00', 'reason' => 'Lupa absen', 'status' => 'pending']),
            'resignation' => Resignation::create([$column => $workerId, 'resignation_date' => '2026-11-16', 'last_working_day' => '2026-12-16', 'reason' => 'Studi', 'status' => 'pending']),
        };
    }
}
