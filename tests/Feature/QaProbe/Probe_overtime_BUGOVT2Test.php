<?php

namespace Tests\Feature\QaProbe;

use App\Models\ApprovalFlow;
use App\Models\Company;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\OvertimeRequest;
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
 * BUG-OVT-2: one worker should not be able to hold two overtime requests for the same / overlapping time.
 */
class Probe_overtime_BUGOVT2Test extends TestCase
{
    use RefreshDatabase;

    private User $freelancerUser;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.onesignal.app_id' => null, 'services.whatsapp_gateway.url' => null]);
        Http::preventStrayRequests();
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-11-16 20:00:00'));
        $this->seed(RoleSeeder::class);

        $tenant = Tenant::factory()->create(['name' => 'Klien OV2', 'code' => 'OV2']);
        $company = new Company(['name' => 'PT OV2', 'code' => 'OV2C', 'is_active' => true]);
        $company->tenant_id = $tenant->id;
        $company->save();
        $division = Division::create(['company_id' => $company->id, 'name' => 'Outlet', 'is_active' => true]);

        ApprovalFlow::create([
            'company_id' => $company->id, 'request_type' => 'overtime', 'step_number' => 1, 'step_order' => 1,
            'name' => 'Persetujuan lembur', 'approver_type' => 'role', 'approver_role' => 'Admin',
        ]);

        $this->freelancerUser = User::factory()->create(['is_active' => true]);
        $this->freelancerUser->forceFill(['tenant_id' => $tenant->id])->save();
        $this->freelancerUser->assignRole('Freelancer');
        Freelancer::create([
            'user_id' => $this->freelancerUser->id, 'company_id' => $company->id,
            'division_id' => $division->id, 'full_name' => 'Fre Lance', 'status' => 'active',
        ]);
    }

    public function test_identical_overtime_request_is_rejected(): void
    {
        Sanctum::actingAs($this->freelancerUser);
        $payload = ['date' => '2026-11-16', 'start_time' => '17:00', 'end_time' => '20:00', 'reason' => 'stok'];

        $this->postJson('/api/v1/overtime-requests', $payload)->assertCreated();
        $second = $this->postJson('/api/v1/overtime-requests', $payload);

        $this->assertSame(422, $second->status(), 'Duplicate 17:00-20:00 overtime on the same day was accepted with '.$second->status());
        $this->assertSame(1, OvertimeRequest::count());
    }

    public function test_overlapping_overtime_request_is_rejected(): void
    {
        Sanctum::actingAs($this->freelancerUser);
        $this->postJson('/api/v1/overtime-requests', ['date' => '2026-11-16', 'start_time' => '17:00', 'end_time' => '20:00', 'reason' => 'stok'])->assertCreated();
        $overlap = $this->postJson('/api/v1/overtime-requests', ['date' => '2026-11-16', 'start_time' => '18:00', 'end_time' => '19:00', 'reason' => 'stok']);

        $this->assertSame(422, $overlap->status(), '18:00-19:00 inside an existing 17:00-20:00 overtime was accepted with '.$overlap->status());
        $this->assertSame(180, (int) OvertimeRequest::sum('duration_minutes'));
    }
}
