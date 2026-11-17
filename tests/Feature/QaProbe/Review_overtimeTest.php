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
 * Reviewer probes for the overtime group: paths the fix left open.
 */
class Review_overtimeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $freelancerUser;

    private Freelancer $freelancer;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.onesignal.app_id' => null, 'services.whatsapp_gateway.url' => null]);
        Http::preventStrayRequests();
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-11-16 20:00:00'));
        $this->seed(RoleSeeder::class);

        $tenant = Tenant::factory()->create(['name' => 'Klien Review', 'code' => 'KRV']);
        $this->company = new Company(['name' => 'PT Review', 'code' => 'RVW', 'is_active' => true]);
        $this->company->tenant_id = $tenant->id;
        $this->company->save();
        $division = Division::create(['company_id' => $this->company->id, 'name' => 'Outlet', 'is_active' => true]);

        ApprovalFlow::create([
            'company_id' => $this->company->id, 'request_type' => 'overtime', 'step_number' => 1, 'step_order' => 1,
            'name' => 'Persetujuan lembur', 'approver_type' => 'role', 'approver_role' => 'Admin',
        ]);

        $this->freelancerUser = User::factory()->create(['is_active' => true]);
        $this->freelancerUser->forceFill(['tenant_id' => $tenant->id])->save();
        $this->freelancerUser->assignRole('Freelancer');
        $this->freelancer = Freelancer::create([
            'user_id' => $this->freelancerUser->id, 'company_id' => $this->company->id,
            'division_id' => $division->id, 'full_name' => 'Fre Lance', 'status' => 'active',
            'end_date' => '2026-11-20',
        ]);
    }

    /**
     * Store refuses a date after the contract end; PUT must not let the worker move the date there.
     */
    public function test_put_cannot_move_overtime_past_the_contract_end_date(): void
    {
        Sanctum::actingAs($this->freelancerUser);

        $this->postJson('/api/v1/overtime-requests', [
            'date' => '2026-11-25', 'start_time' => '17:00', 'end_time' => '20:00', 'reason' => 'x',
        ])->assertStatus(422);

        $id = $this->postJson('/api/v1/overtime-requests', [
            'date' => '2026-11-18', 'start_time' => '17:00', 'end_time' => '20:00', 'reason' => 'x',
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/v1/overtime-requests/{$id}", ['date' => '2026-11-25'])->assertStatus(422);

        $this->assertSame('2026-11-18', OvertimeRequest::findOrFail($id)->date->toDateString());
    }

    /**
     * A worker whose contract ended may not keep editing pending overtime through the API either.
     */
    public function test_put_is_refused_for_a_worker_whose_status_ended(): void
    {
        Sanctum::actingAs($this->freelancerUser);

        $id = $this->postJson('/api/v1/overtime-requests', [
            'date' => '2026-11-18', 'start_time' => '17:00', 'end_time' => '20:00', 'reason' => 'x',
        ])->assertCreated()->json('data.id');

        $this->freelancer->forceFill(['status' => 'resigned'])->save();

        $this->putJson("/api/v1/overtime-requests/{$id}", ['start_time' => '16:00'])->assertStatus(422);
    }
}
