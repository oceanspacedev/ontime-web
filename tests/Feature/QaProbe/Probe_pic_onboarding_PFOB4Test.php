<?php

namespace Tests\Feature\QaProbe;

use App\Enums\DataScope;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Probe PFO-B4: duplicate phone on mobile add-freelancer breaks WhatsApp OTP login.
 */
class Probe_pic_onboarding_PFOB4Test extends TestCase
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

    public function test_mobile_add_freelancer_rejects_phone_already_used_by_an_active_account(): void
    {
        $outlet = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $this->division->id, 'name' => 'Outlet 1', 'latitude' => -6.2, 'longitude' => 106.8, 'radius_meters' => 100, 'is_active' => true]);
        Sanctum::actingAs($this->pic);

        $first = $this->postJson('/api/v1/team/freelancers', ['full_name' => 'Budi', 'phone' => '081299990000', 'company_location_id' => $outlet->id]);
        $first->assertCreated();
        $firstUserId = Freelancer::query()->value('user_id');
        $this->assertNotNull($firstUserId);
        $this->assertSame($firstUserId, User::findByPhone('081299990000')?->id, 'Before duplicate: login resolves');

        $second = $this->postJson('/api/v1/team/freelancers', ['full_name' => 'Budi', 'phone' => '6281299990000', 'company_location_id' => $outlet->id]);

        $this->assertSame(422, $second->status(), 'Same WhatsApp number already belongs to an active freelancer account; got '.$second->status());
        $this->assertSame(1, Freelancer::count());
        $this->assertSame($firstUserId, User::findByPhone('081299990000')?->id, 'Existing freelancer must still be able to log in by WhatsApp OTP');
    }
}
