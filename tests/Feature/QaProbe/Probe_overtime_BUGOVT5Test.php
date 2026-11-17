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
 * BUG-OVT-5: a PUT carrying only end_time equal to the stored start_time must not turn into a 24-hour overtime.
 */
class Probe_overtime_BUGOVT5Test extends TestCase
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

        $tenant = Tenant::factory()->create(['name' => 'Klien OV5', 'code' => 'OV5']);
        $company = new Company(['name' => 'PT OV5', 'code' => 'OV5C', 'is_active' => true]);
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

    public function test_put_with_only_end_time_equal_to_stored_start_is_rejected(): void
    {
        Sanctum::actingAs($this->freelancerUser);
        $id = $this->postJson('/api/v1/overtime-requests', ['date' => '2026-11-16', 'start_time' => '17:00', 'end_time' => '19:00', 'reason' => 'stok'])
            ->assertCreated()->json('data.id');
        $this->assertSame(120, (int) OvertimeRequest::find($id)->duration_minutes);

        $response = $this->putJson("/api/v1/overtime-requests/{$id}", ['end_time' => '17:00']);

        $stored = (int) OvertimeRequest::find($id)->duration_minutes;
        $this->assertSame(422, $response->status(), "PUT end_time=17:00 (== stored start) returned {$response->status()}, duration_minutes now {$stored}");
        $this->assertSame(120, $stored);
    }
}
