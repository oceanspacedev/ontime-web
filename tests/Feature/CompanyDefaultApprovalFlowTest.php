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

        $this->assertSame(['correction', 'geofence', 'leave', 'overtime', 'resignation'], $flows->pluck('request_type')->sort()->values()->all(), 'One stage per request type.');
        $this->assertSame(['Approver'], $flows->pluck('approver_role')->unique()->values()->all());
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

    public function test_companies_on_the_old_three_stage_default_are_trimmed_to_one_stage(): void
    {
        $company = Company::create(['name' => 'PT Lama', 'code' => 'LAMA', 'is_active' => true]);
        foreach (['leave', 'overtime'] as $type) {
            foreach (['Approver', 'BOD', 'Superadmin'] as $index => $role) {
                ApprovalFlow::create(['company_id' => $company->id, 'request_type' => $type, 'step_number' => $index + 1, 'step_order' => $index + 1, 'name' => "{$role} {$type}", 'approver_type' => 'role', 'approver_role' => $role]);
            }
        }
        foreach (['Approver', 'BOD'] as $index => $role) {
            ApprovalFlow::create(['company_id' => $company->id, 'request_type' => 'correction', 'step_number' => $index + 1, 'step_order' => $index + 1, 'name' => "Custom {$role}", 'approver_type' => 'role', 'approver_role' => $role]);
        }

        (require database_path('migrations/2026_11_06_161246_trim_default_approval_flows_to_a_single_approver_stage.php'))->up();

        $roles = fn (string $type): array => ApprovalFlow::where('company_id', $company->id)->where('request_type', $type)->orderBy('step_order')->pluck('approver_role')->all();
        $this->assertSame(['Approver'], $roles('leave'));
        $this->assertSame(['Approver'], $roles('overtime'));
        $this->assertSame('Persetujuan Cuti / Izin', ApprovalFlow::where('company_id', $company->id)->where('request_type', 'leave')->value('name'));
        $this->assertSame(['Approver', 'BOD'], $roles('correction'), 'A customised flow is left alone.');
    }

    public function test_existing_flows_are_never_duplicated(): void
    {
        $company = Company::create(['name' => 'PT Baru', 'code' => 'BARU', 'is_active' => true]);
        $service = app(ApprovalFlowService::class);

        $service->createDefaultFlows($company);
        $service->createDefaultFlows($company);

        $this->assertSame(5, ApprovalFlow::where('company_id', $company->id)->count());
    }
}
