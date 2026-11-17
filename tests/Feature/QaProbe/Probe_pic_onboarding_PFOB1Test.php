<?php

namespace Tests\Feature\QaProbe;

use App\Enums\DataScope;
use App\Models\Attendance;
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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * QA probe PFO-B1: a freelancer whose contract has not started yet must not be able to check in.
 */
class Probe_pic_onboarding_PFOB1Test extends TestCase
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

    public function test_freelancer_cannot_check_in_before_contract_start_date(): void
    {
        $freelancer = $this->freelancer(['start_date' => '2026-12-01']);
        $account = app(WorkerAccountService::class)->createAccount($freelancer);

        Sanctum::actingAs($account['user']);
        $response = $this->postJson('/api/v1/attendance/check-in', []);

        $this->assertSame(0, Attendance::where('freelancer_id', $freelancer->id)->count(), 'Check-in before contract start was accepted: '.$response->status().' '.$response->json('message'));
        $this->assertContains($response->status(), [403, 422]);

        $this->assertFalse(
            app(WorkerAccountService::class)->isActive($freelancer->fresh()),
            'A contract starting 2026-12-01 should not count as active on 2026-11-16'
        );
    }
}
