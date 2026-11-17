<?php

namespace Tests\Feature\QaProbe;

use App\Enums\DataScope;
use App\Filament\Resources\FreelanceResource\Pages\EditFreelancer;
use App\Models\Company;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\ShiftSchedule;
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
 * QA probe: PIC onboarding of freelancers (panel + mobile team API).
 */
class Probe_pic_onboarding_PFOB5Test extends TestCase
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

    public function test_setting_berhenti_via_edit_form_ends_contract_like_akhiri_kontrak(): void
    {
        $freelancer = $this->freelancer(['start_date' => '2026-10-01']);
        $account = app(WorkerAccountService::class)->createAccount($freelancer);
        $user = $account['user'];
        $user->createToken('mobile');
        ShiftSchedule::create(['date' => '2026-11-20', 'freelancer_id' => $freelancer->id, 'is_day_off' => true]);

        $this->assertSame(1, $user->tokens()->count());

        $this->actingAs($this->pic);
        Livewire::test(EditFreelancer::class, ['record' => $freelancer->id])
            ->fillForm(['status' => 'terminated'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('terminated', $freelancer->fresh()->status);
        $this->assertFalse(app(WorkerAccountService::class)->isActive($freelancer->fresh()), 'Worker counts as ended, so Akhiri Kontrak is hidden now');

        dump([
            'user_is_active' => $user->fresh()->is_active,
            'tokens' => $user->tokens()->count(),
            'future_schedules' => ShiftSchedule::where('freelancer_id', $freelancer->id)->whereDate('date', '>', '2026-11-16')->count(),
        ]);

        $this->assertFalse((bool) $user->fresh()->is_active, 'Account should be switched off when status set to Berhenti');
        $this->assertSame(0, $user->tokens()->count(), 'Mobile tokens should be revoked');
        $this->assertSame(0, ShiftSchedule::where('freelancer_id', $freelancer->id)->whereDate('date', '>', '2026-11-16')->count(), 'Future schedules should be removed');
    }
}
