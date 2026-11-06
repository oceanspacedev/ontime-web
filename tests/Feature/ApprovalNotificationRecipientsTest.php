<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Filament\Resources\ApprovalFlows\ApprovalFlowResource;
use App\Filament\Resources\ApprovalFlows\Pages\EditApprovalFlow;
use App\Models\ApprovalFlow;
use App\Models\Company;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\ApprovalRequestedNotification;
use App\Services\ApprovalFlowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ApprovalNotificationRecipientsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Division $jchicken;

    private Division $momoyo;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.onesignal.app_id' => 'app-id', 'services.onesignal.rest_api_key' => 'rest-key']);

        foreach (['Superadmin', 'Admin', 'Koordinator', 'BOD', 'Approver', 'Employee', 'Freelancer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->company = Company::create(['name' => 'PT Franchise', 'code' => 'PFC', 'is_active' => true]);
        $this->jchicken = Division::create(['company_id' => $this->company->id, 'name' => 'JCHICKEN', 'is_active' => true]);
        $this->momoyo = Division::create(['company_id' => $this->company->id, 'name' => 'MOMOYO', 'is_active' => true]);
    }

    public function test_the_coordinator_of_the_brand_hears_about_a_new_request(): void
    {
        $this->flow(['Approver']);
        $coordinator = $this->user('Koordinator', DataScope::Divisions, divisionIds: [$this->jchicken->id]);
        $otherBrand = $this->user('Koordinator', DataScope::Divisions, divisionIds: [$this->momoyo->id]);
        $admin = $this->user('Admin', DataScope::Companies, companyIds: [$this->company->id]);
        Notification::fake();

        $this->leave();

        Notification::assertSentTo($coordinator, ApprovalRequestedNotification::class);
        Notification::assertNotSentTo($otherBrand, ApprovalRequestedNotification::class);
        Notification::assertNotSentTo($admin, ApprovalRequestedNotification::class, 'Admins only hear about stages set to them.');
    }

    public function test_a_stage_set_to_admin_notifies_the_admins_in_scope(): void
    {
        $this->flow(['Admin']);
        $admin = $this->user('Admin', DataScope::Companies, companyIds: [$this->company->id]);
        $inactiveAdmin = $this->user('Admin', DataScope::Companies, companyIds: [$this->company->id]);
        $inactiveAdmin->update(['is_active' => false]);
        Notification::fake();

        $this->leave();

        Notification::assertSentTo($admin, ApprovalRequestedNotification::class);
        Notification::assertNotSentTo($inactiveAdmin, ApprovalRequestedNotification::class);
    }

    public function test_a_stage_set_to_a_specific_user_notifies_that_user(): void
    {
        $bod = $this->user('BOD', DataScope::Companies, companyIds: [$this->company->id]);
        ApprovalFlow::create(['company_id' => $this->company->id, 'request_type' => 'leave', 'step_number' => 1, 'step_order' => 1, 'name' => 'Owner', 'approver_type' => 'user', 'user_id' => $bod->id]);
        Notification::fake();

        $this->leave();

        Notification::assertSentTo($bod, ApprovalRequestedNotification::class);
    }

    public function test_the_panel_offers_only_roles_that_can_approve_and_warns_about_empty_ones(): void
    {
        $this->flow(['Approver']);
        foreach (['overtime', 'correction', 'resignation', 'geofence'] as $type) {
            $this->flow(['Approver'], $type);
        }
        $this->actingAs($this->user('Superadmin', DataScope::All));

        $options = array_keys(ApprovalFlowResource::approverRoleOptions());
        sort($options);
        $this->assertSame(['Admin', 'Approver', 'BOD', 'Koordinator', 'Superadmin'], $options);

        $this->assertFalse(ApprovalFlowService::hasHolderForRole('BOD', $this->company->id));
        $this->user('BOD', DataScope::Companies, companyIds: [$this->company->id]);
        $this->assertTrue(ApprovalFlowService::hasHolderForRole('BOD', $this->company->id));
        $this->assertFalse(ApprovalFlowService::hasHolderForRole('Approver', $this->company->id));

        Livewire::test(EditApprovalFlow::class, ['record' => $this->company->id])
            ->assertSee('Belum ada pengguna aktif dengan role ini, pengajuan akan tertahan.');
    }

    /** @param list<string> $roles */
    private function flow(array $roles, string $type = 'leave'): void
    {
        foreach ($roles as $index => $role) {
            ApprovalFlow::create(['company_id' => $this->company->id, 'request_type' => $type, 'step_number' => $index + 1, 'step_order' => $index + 1, 'name' => $role, 'approver_type' => 'role', 'approver_role' => $role]);
        }
    }

    /**
     * @param  list<int>  $companyIds
     * @param  list<int>  $divisionIds
     */
    private function user(string $role, DataScope $scope, array $companyIds = [], array $divisionIds = []): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);
        $user->grantDataAccess($scope, companyIds: $companyIds, divisionIds: $divisionIds);

        return $user;
    }

    private function leave(): LeaveRequest
    {
        $crewUser = User::factory()->create();
        $crewUser->assignRole('Freelancer');
        $crew = Freelancer::create(['user_id' => $crewUser->id, 'company_id' => $this->company->id, 'division_id' => $this->jchicken->id, 'full_name' => 'Crew', 'status' => 'active']);
        $leave = LeaveRequest::create(['freelancer_id' => $crew->id, 'leave_type' => 'sick', 'start_date' => now()->addDays(2)->toDateString(), 'end_date' => now()->addDays(2)->toDateString(), 'days_count' => 1, 'reason' => 'Sakit', 'status' => 'pending']);
        app(ApprovalFlowService::class)->generateSteps($leave, 'leave');

        return $leave;
    }
}
