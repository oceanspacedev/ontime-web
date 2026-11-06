<?php

namespace Tests\Feature;

use App\Filament\Resources\CompanyResource\Pages\CreateCompany;
use App\Models\ApprovalFlow;
use App\Models\Company;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\User;
use App\Services\ApprovalFlowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyDefaultApprovalFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_company_created_in_the_panel_gets_the_default_approval_flows(): void
    {
        Role::findOrCreate('Superadmin', 'web');
        $superadmin = User::factory()->create();
        $superadmin->assignRole('Superadmin');
        $this->actingAs($superadmin);

        Livewire::test(CreateCompany::class)
            ->fillForm(['name' => 'PT Baru', 'code' => 'BARU', 'is_active' => true, 'locations' => []])
            ->call('create')
            ->assertHasNoFormErrors();

        $flows = ApprovalFlow::where('company_id', Company::where('code', 'BARU')->value('id'))->get();

        $this->assertSame(13, $flows->count());
        $this->assertSame(['Approver', 'BOD', 'Superadmin'], $flows->where('request_type', 'leave')->sortBy('step_order')->pluck('approver_role')->values()->all());
        $this->assertSame(['Approver'], $flows->where('request_type', 'geofence')->pluck('approver_role')->values()->all());
    }

    public function test_workers_of_a_company_with_default_flows_can_file_requests(): void
    {
        Role::findOrCreate('Freelancer', 'web');
        $company = Company::create(['name' => 'PT Baru', 'code' => 'BARU', 'is_active' => true]);
        app(ApprovalFlowService::class)->createDefaultFlows($company);
        $user = User::factory()->create();
        $user->assignRole('Freelancer');
        Freelancer::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'division_id' => Division::create(['company_id' => $company->id, 'name' => 'OUTLET', 'is_active' => true])->id,
            'full_name' => 'Crew Baru',
            'status' => 'active',
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/overtime-requests', [
            'date' => now()->addDays(3)->toDateString(),
            'start_time' => '18:00',
            'end_time' => '20:00',
            'reason' => 'Lembur closing',
        ])->assertCreated();
    }

    public function test_existing_flows_are_never_duplicated(): void
    {
        $company = Company::create(['name' => 'PT Baru', 'code' => 'BARU', 'is_active' => true]);
        $service = app(ApprovalFlowService::class);

        $service->createDefaultFlows($company);
        $service->createDefaultFlows($company);

        $this->assertSame(13, ApprovalFlow::where('company_id', $company->id)->count());
    }
}
