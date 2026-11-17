<?php

namespace Tests\Feature\QaProbe;

use App\Enums\DataScope;
use App\Filament\Resources\FreelanceResource\Pages\EditFreelancer;
use App\Models\Company;
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
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PFO-B2: a PIC extends a closed freelancer's contract through the Edit form (Diperpanjang + new end date).
 * The worker is active again, so the login account should be usable again too.
 */
class Probe_pic_onboarding_PFOB2Test extends TestCase
{
    use RefreshDatabase;

    public function test_extending_closed_contract_via_edit_form_lets_freelancer_sign_in_again(): void
    {
        config(['services.whatsapp_gateway.url' => null, 'services.whatsapp_gateway.token' => null]);
        Http::preventStrayRequests();
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-11-16 07:50:00'));
        $this->seed(RoleSeeder::class);

        $tenant = Tenant::factory()->create(['name' => 'Klien PF', 'code' => 'PF']);
        $company = new Company(['name' => 'PT PF', 'code' => 'PFQ', 'is_active' => true]);
        $company->tenant_id = $tenant->id;
        $company->save();
        $division = Division::create(['company_id' => $company->id, 'name' => 'Outlet', 'is_active' => true]);

        $pic = User::factory()->create(['is_active' => true]);
        $pic->forceFill(['tenant_id' => $tenant->id])->save();
        $pic->assignRole('PIC');
        $pic->assignRole('Admin');
        $pic->grantDataAccess(DataScope::All);

        $freelancer = Freelancer::create([
            'company_id' => $company->id,
            'division_id' => $division->id,
            'full_name' => 'Budi Freelance',
            'phone' => '081234567890',
            'status' => 'active',
            'start_date' => '2026-10-01',
            'end_date' => '2026-11-10',
        ]);

        $accounts = app(WorkerAccountService::class);
        $account = $accounts->createAccount($freelancer);
        $accounts->endContract($freelancer->fresh(), Carbon::parse('2026-11-10'));
        $this->assertFalse($account['user']->fresh()->is_active);

        $this->actingAs($pic);
        Livewire::test(EditFreelancer::class, ['record' => $freelancer->id])
            ->fillForm(['status' => 'extended', 'end_date' => '2026-12-31'])
            ->call('save')
            ->assertHasNoFormErrors();

        $freelancer->refresh();
        $this->assertSame('extended', $freelancer->status);
        $this->assertTrue($accounts->isActive($freelancer), 'Extended freelancer counts as active');

        // Business expectation: an extended (active) contract means the worker can sign in and work again.
        $this->assertTrue($account['user']->fresh()->is_active, 'Login account should be switched back on after extending the contract');

        auth()->guard('web')->logout();
        $this->postJson('/api/v1/auth/login', [
            'email' => $account['email'],
            'password' => $account['password'],
            'device_name' => 'qa',
        ])->assertOk();
    }
}
