<?php

namespace Tests\Feature;

use App\Filament\Resources\FreelanceResource\Pages\EditFreelancer;
use App\Filament\Resources\InternResource\Pages\EditIntern;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\Resignation;
use App\Models\ShiftSchedule;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalFlowService;
use App\Services\OneSignalPushService;
use App\Services\WorkerAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Worker lifecycle fixes (cluster G): contracts ended from the edit forms, resignations of rehired
 * employees, overtime after the contract, web selfie uploads of inactive accounts and push unlinking.
 */
class QaGLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $klien;

    private Company $company;

    private Division $division;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.whatsapp_gateway.url' => null, 'services.whatsapp_gateway.token' => null]);
        Http::preventStrayRequests();
        Notification::fake();

        foreach (['Superadmin', 'Approver', 'Employee', 'Intern', 'Freelancer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->superadmin = User::factory()->create(['is_active' => true]);
        $this->superadmin->assignRole('Superadmin');

        $this->klien = Tenant::factory()->create(['name' => 'Klien G', 'code' => 'KG']);
        $this->company = new Company(['name' => 'PT G', 'code' => 'PTG', 'is_active' => true]);
        $this->company->tenant_id = $this->klien->id;
        $this->company->save();
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Outlet', 'is_active' => true]);
    }

    public function test_an_intern_end_date_set_in_the_past_ends_the_contract_and_switches_the_account_off(): void
    {
        $intern = $this->intern();
        $intern->user->createToken('mobile');
        ShiftSchedule::create(['intern_id' => $intern->id, 'date' => today()->addDays(2)->toDateString(), 'is_day_off' => true]);

        $this->actingAs($this->superadmin);
        Livewire::test(EditIntern::class, ['record' => $intern->id])
            ->fillForm(['end_date' => today()->subDays(2)->toDateString()])
            ->call('save')
            ->assertHasNoFormErrors();

        $intern->refresh();
        $this->assertSame('completed', $intern->status);
        $this->assertSame(today()->subDays(2)->toDateString(), $intern->end_date->toDateString());
        $this->assertFalse($intern->user->fresh()->is_active);
        $this->assertSame(0, $intern->user->tokens()->count());
        $this->assertSame(0, ShiftSchedule::where('intern_id', $intern->id)->count());
    }

    public function test_taking_a_freelancer_back_on_through_the_edit_form_switches_the_account_on(): void
    {
        $freelancer = $this->freelancer();
        app(WorkerAccountService::class)->endContract($freelancer, today(), 'terminated');
        $this->assertFalse($freelancer->user->fresh()->is_active);

        $this->actingAs($this->superadmin);
        Livewire::test(EditFreelancer::class, ['record' => $freelancer->id])
            ->fillForm(['status' => 'active', 'end_date' => today()->addMonth()->toDateString()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('active', $freelancer->fresh()->status);
        $this->assertTrue($freelancer->user->fresh()->is_active);
    }

    public function test_saving_an_active_contract_without_status_changes_leaves_the_account_alone(): void
    {
        $freelancer = $this->freelancer(['end_date' => today()->addMonth()->toDateString()]);
        $freelancer->user->createToken('mobile');

        $this->actingAs($this->superadmin);
        Livewire::test(EditFreelancer::class, ['record' => $freelancer->id])
            ->fillForm(['end_date' => today()->addMonths(2)->toDateString()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('active', $freelancer->fresh()->status);
        $this->assertTrue($freelancer->user->fresh()->is_active);
        $this->assertSame(1, $freelancer->user->tokens()->count());
    }

    public function test_the_nightly_job_still_applies_a_resignation_whose_night_was_missed(): void
    {
        $employee = $this->employee('2024-01-08');
        Resignation::create(['employee_id' => $employee->id, 'resignation_date' => today()->subMonth(), 'last_working_day' => today()->subDays(3), 'reason' => 'Pindah', 'status' => 'approved']);

        $this->artisan('employees:apply-resignations')->assertSuccessful();

        $this->assertSame('inactive', $employee->fresh()->status);
        $this->assertFalse($employee->user->fresh()->is_active);
    }

    public function test_the_nightly_job_ignores_a_resignation_from_before_the_current_join_date(): void
    {
        $employee = $this->employee(today()->subDay()->toDateString());
        Resignation::create(['employee_id' => $employee->id, 'resignation_date' => today()->subMonth(), 'last_working_day' => today()->subDays(2), 'reason' => 'Pindah', 'status' => 'approved']);

        $this->artisan('employees:apply-resignations')->assertSuccessful();

        $this->assertSame('active', $employee->fresh()->status);
        $this->assertTrue($employee->user->fresh()->is_active);
    }

    public function test_only_a_pending_or_current_approved_resignation_counts_as_open(): void
    {
        $accounts = app(WorkerAccountService::class);
        $employee = $this->employee('2024-01-08');

        Resignation::create(['employee_id' => $employee->id, 'resignation_date' => '2025-06-01', 'last_working_day' => '2025-06-30', 'reason' => 'Lama', 'status' => 'approved']);
        $this->assertFalse($accounts->hasOpenResignation($employee), 'An applied resignation of an earlier employment blocks a new one.');

        $serving = Resignation::create(['employee_id' => $employee->id, 'resignation_date' => today(), 'last_working_day' => today()->addMonth(), 'reason' => 'Baru', 'status' => 'approved']);
        $this->assertTrue($accounts->hasOpenResignation($employee), 'An approved resignation still serving its notice must block a new one.');

        $serving->update(['status' => 'rejected']);
        Resignation::create(['employee_id' => $employee->id, 'resignation_date' => today(), 'last_working_day' => today()->addMonth(), 'reason' => 'Proses', 'status' => 'pending']);
        $this->assertTrue($accounts->hasOpenResignation($employee));
    }

    public function test_overtime_on_the_last_contract_day_is_accepted_and_a_left_worker_is_refused(): void
    {
        $approver = User::factory()->create(['is_active' => true]);
        $approver->forceFill(['tenant_id' => $this->klien->id])->save();
        $approver->assignRole('Approver');
        app(ApprovalFlowService::class)->createDefaultFlows($this->company);

        $freelancer = $this->freelancer(['end_date' => today()->addDay()->toDateString()]);
        Sanctum::actingAs($freelancer->user);

        $payload = ['start_time' => '17:00', 'end_time' => '19:00', 'reason' => 'Stok opname'];

        $this->postJson('/api/v1/overtime-requests', ['date' => today()->addDay()->toDateString(), ...$payload])->assertCreated();
        $this->postJson('/api/v1/overtime-requests', ['date' => today()->addDays(2)->toDateString(), ...$payload])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');

        $freelancer->update(['status' => 'terminated']);
        Sanctum::actingAs(User::findOrFail($freelancer->user_id));
        $this->postJson('/api/v1/overtime-requests', ['date' => today()->toDateString(), ...$payload])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');
    }

    public function test_a_web_session_of_an_inactive_klien_cannot_upload_selfies(): void
    {
        Storage::fake(config('filesystems.default'));
        $freelancer = $this->freelancer();
        $this->klien->forceFill(['is_active' => false])->save();

        $this->actingAs($freelancer->user)->post(route('upload.selfie'), [
            'photo' => UploadedFile::fake()->image('selfie.jpg', 64, 64),
        ], ['Accept' => 'application/json'])
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->assertSame([], Storage::disk(config('filesystems.default'))->allFiles('attendance-photos'));
    }

    public function test_ending_a_contract_unlinks_the_phone_from_push(): void
    {
        $this->withoutDefer();
        config(['services.onesignal.app_id' => 'app-1', 'services.onesignal.rest_api_key' => 'test-key', 'services.onesignal.api_url' => 'https://api.onesignal.com/notifications?c=push']);
        Http::fake(['api.onesignal.com/*' => Http::response([], 200)]);
        $freelancer = $this->freelancer();

        app(WorkerAccountService::class)->endContract($freelancer);

        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'DELETE'
            && $request->url() === "https://api.onesignal.com/apps/app-1/users/by/external_id/{$freelancer->user_id}"
            && $request->hasHeader('Authorization', 'Key test-key'));
    }

    public function test_unlinking_push_treats_an_unknown_user_as_done_and_does_nothing_unconfigured(): void
    {
        $user = User::factory()->create();

        $this->assertFalse(app(OneSignalPushService::class)->forgetUser($user));
        Http::assertNothingSent();

        config(['services.onesignal.app_id' => 'app-1', 'services.onesignal.rest_api_key' => 'test-key']);
        Http::fakeSequence()->push(['errors' => ['not found']], 404)->push([], 500);
        $this->assertTrue(app(OneSignalPushService::class)->forgetUser($user));
        $this->assertFalse(app(OneSignalPushService::class)->forgetUser($user));
    }

    private function account(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['tenant_id' => $this->klien->id])->save();
        $user->assignRole($role);

        return $user->fresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function freelancer(array $attributes = []): Freelancer
    {
        return Freelancer::create([
            'user_id' => $this->account('Freelancer')->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'full_name' => 'Crew '.fake()->unique()->firstName(),
            'status' => 'active',
            ...$attributes,
        ]);
    }

    private function intern(): Intern
    {
        return Intern::create([
            'user_id' => $this->account('Intern')->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'nis' => 'MG-'.fake()->unique()->numerify('###'),
            'full_name' => 'Magang G',
            'institution' => 'SMK',
            'status' => 'active',
            'end_date' => today()->addMonth()->toDateString(),
        ]);
    }

    private function employee(string $joinDate): Employee
    {
        return Employee::create([
            'user_id' => $this->account('Employee')->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Staff'])->id,
            'nip' => 'EMP-'.fake()->unique()->numerify('###'),
            'full_name' => 'Karyawan G',
            'join_date' => $joinDate,
            'status' => 'active',
        ]);
    }
}
