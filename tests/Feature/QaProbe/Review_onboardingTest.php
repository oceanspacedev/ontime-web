<?php

namespace Tests\Feature\QaProbe;

use App\Enums\AttendanceStatus;
use App\Enums\DataScope;
use App\Filament\Resources\EmployeeResource\Pages\EditEmployee;
use App\Filament\Resources\FreelanceResource\Pages\CreateFreelancer;
use App\Filament\Resources\FreelanceResource\Pages\EditFreelancer;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\Tenant;
use App\Models\User;
use App\Services\WorkerAccountService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Review probes for the onboarding group (PFO-B1/B3/B4/B6/B8 fixes). Each test asserts the behaviour the
 * decisions call for; a failing test proves a finding.
 */
class Review_onboardingTest extends TestCase
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

        $this->tenant = Tenant::factory()->create(['name' => 'Klien Review', 'code' => 'RV']);
        $company = new Company(['name' => 'PT RVQ', 'code' => 'RVQ', 'is_active' => true]);
        $company->tenant_id = $this->tenant->id;
        $company->save();
        $this->company = $company->refresh();
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Outlet', 'is_active' => true]);

        $this->pic = User::factory()->create(['is_active' => true]);
        $this->pic->forceFill(['tenant_id' => $this->tenant->id])->save();
        $this->pic->assignRole('PIC');
        $this->pic->assignRole('Admin');
        $this->pic->grantDataAccess(DataScope::All);
    }

    /**
     * A worker without history may be deleted (decision b). Their login account stays active with the
     * number, so re-creating the same person (the usual fix after a wrong entry) is refused as "taken".
     */
    public function test_recreating_a_deleted_worker_with_the_same_number_is_allowed(): void
    {
        $freelancer = $this->freelancer(['phone' => '081277770000']);
        app(WorkerAccountService::class)->createAccount($freelancer);

        $this->actingAs($this->pic);
        Livewire::test(EditFreelancer::class, ['record' => $freelancer->id])->callAction('delete');
        $this->assertNull(Freelancer::find($freelancer->id));

        Livewire::test(CreateFreelancer::class)
            ->fillForm($this->freelancerForm(['phone' => '081277770000']))
            ->call('create')
            ->assertHasNoFormErrors();
    }

    /**
     * Two active freelancers that already share a number (data entered before this rule existed): the PIC
     * cannot save any other change, not even ending one of the contracts through the edit form.
     */
    public function test_legacy_duplicate_number_does_not_block_unrelated_edits(): void
    {
        $first = $this->freelancer(['phone' => '081288880000']);
        $this->freelancer(['phone' => '081288880000']);

        $this->actingAs($this->pic);
        Livewire::test(EditFreelancer::class, ['record' => $first->id])
            ->fillForm(['status' => 'completed', 'end_date' => '2026-11-16'])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    /**
     * An employee set to Non-Aktif in the panel has left (isActive false), but the account is not switched
     * off, so the same person cannot be taken on as a freelancer with their own number.
     */
    public function test_number_of_an_employee_set_inactive_in_the_panel_can_be_reused(): void
    {
        $employee = $this->employee(['phone' => '081299990000']);
        app(WorkerAccountService::class)->createAccount($employee);

        $this->actingAs($this->pic);
        Livewire::test(EditEmployee::class, ['record' => $employee->id])
            ->fillForm(['status' => 'inactive'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertFalse(app(WorkerAccountService::class)->isActive($employee->fresh()));

        Livewire::test(CreateFreelancer::class)
            ->fillForm($this->freelancerForm(['phone' => '081299990000']))
            ->call('create')
            ->assertHasNoFormErrors();
    }

    /**
     * Decision (b) asks for a server-side guard. The Talenta webhook "deleted" event deletes the employee
     * directly and the cascade wipes the attendance used for payroll.
     */
    public function test_webhook_delete_keeps_an_employee_with_attendance_history(): void
    {
        config(['services.core.webhook_secret' => 'review-secret']);
        $employee = $this->employee();
        Attendance::create(['employee_id' => $employee->id, 'date' => '2026-11-15', 'check_in' => '2026-11-15 08:00:00', 'status' => AttendanceStatus::cases()[0]]);

        $body = json_encode(['action' => 'deleted', 'employee' => ['id' => $employee->id, 'name' => 'Andi', 'status' => 'inactive']]);
        $this->call('POST', '/api/webhooks/employees', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, 'review-secret'),
        ], $body);

        $this->assertSame(1, Attendance::where('employee_id', $employee->id)->count(), 'Payroll history was cascaded away');
    }

    private function nextPhone(): string
    {
        return sprintf('0815%08d', ++$this->sequence);
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
}
