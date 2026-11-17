<?php

namespace Tests\Feature\QaProbe;

use App\Enums\AttendanceStatus;
use App\Enums\DataScope;
use App\Filament\Resources\FreelanceResource\Pages\EditFreelancer;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\WorkerAccountService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * QA probe: PIC onboarding of freelancers (panel + mobile team API).
 */
class PFOProbeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Division $division;

    private User $pic;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.whatsapp_gateway.url' => null, 'services.whatsapp_gateway.token' => null]);
        Http::preventStrayRequests();
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-11-16 07:50:00'));
        $this->seed(RoleSeeder::class);

        $this->tenant = Tenant::factory()->create(['name' => 'Klien PF', 'code' => 'PF']);
        $this->company = new Company(['name' => 'PT PF', 'code' => 'PFQ', 'is_active' => true]);
        $this->company->tenant_id = $this->tenant->id;
        $this->company->save();
        $this->company->refresh();
        $this->company->policy->update(['require_gps' => false, 'require_photo' => false, 'require_face_recognition' => false, 'require_liveness' => false, 'work_start_time' => '08:00:00', 'work_end_time' => '17:00:00', 'late_tolerance_minutes' => 0]);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Outlet', 'is_active' => true]);

        $this->pic = User::factory()->create(['is_active' => true]);
        $this->pic->forceFill(['tenant_id' => $this->tenant->id])->save();
        $this->pic->assignRole('PIC');
        $this->pic->assignRole('Admin');
        $this->pic->grantDataAccess(DataScope::All);
    }

    private function freelancer(array $attributes = []): Freelancer
    {
        return Freelancer::create([
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'full_name' => 'Budi Freelance',
            'phone' => '081234567890',
            'status' => 'active',
            ...$attributes,
        ]);
    }

    public function test_future_start_date_is_not_active_and_cannot_check_in(): void
    {
        $freelancer = $this->freelancer(['start_date' => '2026-12-01']);
        $account = app(WorkerAccountService::class)->createAccount($freelancer);

        $this->assertFalse(app(WorkerAccountService::class)->isActive($freelancer->fresh()));

        Sanctum::actingAs($account['user']);
        $this->postJson('/api/v1/attendance/check-in', [])->assertForbidden();
        $this->assertSame(0, Attendance::where('freelancer_id', $freelancer->id)->count(), 'Check-in before contract start must be refused');
    }

    public function test_extending_contract_via_edit_form_switches_account_back_on(): void
    {
        $freelancer = $this->freelancer(['start_date' => '2026-10-01', 'end_date' => '2026-11-10']);
        $account = app(WorkerAccountService::class)->createAccount($freelancer);
        app(WorkerAccountService::class)->endContract($freelancer->fresh(), Carbon::parse('2026-11-10'));
        $this->assertFalse($account['user']->fresh()->is_active);

        $this->actingAs($this->pic);
        Livewire::test(EditFreelancer::class, ['record' => $freelancer->id])
            ->fillForm(['status' => 'extended', 'end_date' => '2026-12-31'])
            ->call('save')
            ->assertHasNoFormErrors();

        $freelancer->refresh();
        $this->assertSame('extended', $freelancer->status);
        $this->assertTrue(app(WorkerAccountService::class)->isActive($freelancer));
        $this->assertTrue($account['user']->fresh()->is_active, 'Extending via the form switches the same account back on');
    }

    public function test_terminating_via_edit_form_switches_account_off(): void
    {
        $freelancer = $this->freelancer(['start_date' => '2026-10-01']);
        $account = app(WorkerAccountService::class)->createAccount($freelancer);

        $this->actingAs($this->pic);
        Livewire::test(EditFreelancer::class, ['record' => $freelancer->id])
            ->fillForm(['status' => 'terminated'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($account['user']->fresh()->is_active, 'Berhenti via the form switches the account off');
    }

    public function test_end_date_before_start_date_is_rejected_by_panel_form(): void
    {
        $freelancer = $this->freelancer(['start_date' => '2026-11-01']);

        $this->actingAs($this->pic);
        Livewire::test(EditFreelancer::class, ['record' => $freelancer->id])
            ->fillForm(['start_date' => '2026-11-20', 'end_date' => '2026-11-05'])
            ->call('save')
            ->assertHasFormErrors(['end_date']);

        $this->assertNull($freelancer->fresh()->end_date);
    }

    public function test_deleting_freelancer_with_history_is_blocked(): void
    {
        $freelancer = $this->freelancer(['start_date' => '2026-10-01']);
        Attendance::create(['freelancer_id' => $freelancer->id, 'date' => '2026-11-15', 'check_in' => '2026-11-15 08:00:00', 'status' => AttendanceStatus::cases()[0]]);

        $this->actingAs($this->pic);
        Livewire::test(EditFreelancer::class, ['record' => $freelancer->id])->assertActionDisabled('delete');

        $this->assertNotNull(Freelancer::find($freelancer->id));
        $this->assertSame(1, Attendance::where('freelancer_id', $freelancer->id)->count(), 'Attendance history is kept');
    }

    public function test_mobile_add_freelancer_rejects_duplicate_phone_and_keeps_whatsapp_login_lookup(): void
    {
        $outlet = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $this->division->id, 'name' => 'Outlet 1', 'latitude' => -6.2, 'longitude' => 106.8, 'radius_meters' => 100, 'is_active' => true]);
        Sanctum::actingAs($this->pic);

        $this->postJson('/api/v1/team/freelancers', ['full_name' => 'Budi', 'phone' => '081299990000', 'company_location_id' => $outlet->id])->assertCreated();
        $this->postJson('/api/v1/team/freelancers', ['full_name' => 'Budi', 'phone' => '6281299990000', 'company_location_id' => $outlet->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');

        $this->assertSame(1, Freelancer::count());
        $this->assertNotNull(User::findByPhone('081299990000'), 'WhatsApp OTP login still resolves the one account');
    }
}
