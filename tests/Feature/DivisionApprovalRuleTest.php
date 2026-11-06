<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Filament\Resources\ApprovalFlows\Pages\EditApprovalFlow;
use App\Filament\Resources\ApprovalFlows\RelationManagers\DivisionApprovalFlowsRelationManager;
use App\Models\ApprovalFlow;
use App\Models\Company;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\ApprovalFlowService;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Repeater;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DivisionApprovalRuleTest extends TestCase
{
    use RefreshDatabase;

    private ApprovalFlowService $service;

    private Company $company;

    private Division $jchicken;

    private Division $jchickenCiledug;

    private Division $finance;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Superadmin', 'Admin', 'Koordinator', 'BOD', 'Approver', 'Freelancer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->service = app(ApprovalFlowService::class);
        $this->company = Company::create(['name' => 'PT Franchise', 'code' => 'PFC', 'is_active' => true]);
        $this->service->createDefaultFlows($this->company);
        $this->jchicken = Division::create(['company_id' => $this->company->id, 'name' => 'JCHICKEN', 'is_active' => true]);
        $this->jchickenCiledug = Division::create(['company_id' => $this->company->id, 'parent_id' => $this->jchicken->id, 'name' => 'JCHICKEN CILEDUG', 'is_active' => true]);
        $this->finance = Division::create(['company_id' => $this->company->id, 'name' => 'KEUANGAN', 'is_active' => true]);

        $this->superadmin = User::factory()->create();
        $this->superadmin->assignRole('Superadmin');
    }

    public function test_a_division_rule_applies_to_the_division_and_below_and_only_for_its_request_types(): void
    {
        $this->service->saveDivisionRule($this->company, $this->jchicken->id, ['leave'], [$this->step('Koordinator')]);
        $this->service->saveDivisionRule($this->company, $this->finance->id, ['leave', 'overtime'], [$this->step('Approver'), $this->step('BOD')]);

        $roles = fn (?Division $division, string $type): array => $this->service->flowsFor($this->company->id, $division?->id, $type)->pluck('approver_role')->all();

        $this->assertSame(['Koordinator'], $roles($this->jchicken, 'leave'));
        $this->assertSame(['Koordinator'], $roles($this->jchickenCiledug, 'leave'), 'Sub-divisions inherit the rule.');
        $this->assertSame(['Approver'], $roles($this->jchickenCiledug, 'overtime'), 'Other request types keep the company flow.');
        $this->assertSame(['Approver', 'BOD'], $roles($this->finance, 'overtime'));
        $this->assertSame(['Approver'], $roles(null, 'leave'), 'Workers without a division use the company flow.');
    }

    public function test_the_nearest_division_rule_wins(): void
    {
        $this->service->saveDivisionRule($this->company, $this->jchicken->id, ['leave'], [$this->step('Koordinator')]);
        $this->service->saveDivisionRule($this->company, $this->jchickenCiledug->id, ['leave'], [$this->step('BOD')]);

        $this->assertSame(['BOD'], $this->service->flowsFor($this->company->id, $this->jchickenCiledug->id, 'leave')->pluck('approver_role')->all());
    }

    public function test_a_request_gets_the_stages_of_its_division_rule(): void
    {
        $this->service->saveDivisionRule($this->company, $this->finance->id, ['leave'], [$this->step('Approver'), $this->step('BOD')]);

        $leave = $this->leaveIn($this->finance);

        $this->assertSame(['Approver', 'BOD'], $leave->approvalSteps()->orderBy('step_order')->pluck('approver_role')->all());
    }

    public function test_a_specific_user_can_be_a_divisions_manager(): void
    {
        $manager = User::factory()->create(['is_active' => true]);
        $manager->assignRole('Approver');
        $manager->grantDataAccess(DataScope::Divisions, divisionIds: [$this->finance->id]);
        $this->service->saveDivisionRule($this->company, $this->finance->id, ['leave'], [$this->step('Approver'), ['name' => 'Manager Keuangan', 'approver_type' => 'user', 'user_id' => $manager->id]]);

        $leave = $this->leaveIn($this->finance);
        $leave->update(['current_step' => 2]);

        $this->assertTrue($this->service->isUserAuthorizedToApprove($leave, $manager));
    }

    public function test_a_division_of_another_company_is_rejected(): void
    {
        $other = Company::create(['name' => 'PT Lain', 'code' => 'LAIN', 'is_active' => true]);
        $foreign = Division::create(['company_id' => $other->id, 'name' => 'LAIN', 'is_active' => true]);

        $this->expectException(ModelNotFoundException::class);

        $this->service->saveDivisionRule($this->company, $foreign->id, ['leave'], [$this->step('Approver')]);
    }

    public function test_only_users_who_reach_the_company_can_be_named_approver(): void
    {
        $other = Company::create(['name' => 'PT Lain', 'code' => 'LAIN', 'is_active' => true]);
        $inside = User::factory()->create(['name' => 'Manager Dalam', 'is_active' => true]);
        $inside->assignRole('Approver');
        $inside->grantDataAccess(DataScope::Divisions, divisionIds: [$this->finance->id]);
        $outside = User::factory()->create(['name' => 'Manager Luar', 'is_active' => true]);
        $outside->assignRole('Approver');
        $outside->grantDataAccess(DataScope::Companies, companyIds: [$other->id]);

        $options = ApprovalFlowService::approverUserOptions($this->company->id);

        $this->assertArrayHasKey($inside->id, $options);
        $this->assertArrayHasKey($this->superadmin->id, $options);
        $this->assertArrayNotHasKey($outside->id, $options);

        $this->expectException(ValidationException::class);
        $this->service->saveDivisionRule($this->company, $this->finance->id, ['leave'], [['name' => 'Luar', 'approver_type' => 'user', 'user_id' => $outside->id]]);
    }

    public function test_the_company_flow_rejects_a_user_from_another_company(): void
    {
        $other = Company::create(['name' => 'PT Lain', 'code' => 'LAIN', 'is_active' => true]);
        $outside = User::factory()->create(['is_active' => true]);
        $outside->assignRole('Approver');
        $outside->grantDataAccess(DataScope::Companies, companyIds: [$other->id]);
        $this->actingAs($this->superadmin);
        $undoRepeaterFake = Repeater::fake();

        Livewire::test(EditApprovalFlow::class, ['record' => $this->company->id])
            ->fillForm(['leave_steps' => [['name' => 'Luar', 'approver_type' => 'user', 'approver_role' => null, 'user_id' => $outside->id]]])
            ->call('save')
            ->assertHasFormErrors(['leave_steps.0.user_id']);

        $this->assertFalse(ApprovalFlow::where('user_id', $outside->id)->exists());
        $undoRepeaterFake();
    }

    public function test_rules_are_added_edited_and_removed_in_the_panel(): void
    {
        $this->actingAs($this->superadmin);
        $undoRepeaterFake = Repeater::fake();
        $component = Livewire::test(DivisionApprovalFlowsRelationManager::class, ['ownerRecord' => $this->company, 'pageClass' => EditApprovalFlow::class]);

        $component->callAction(TestAction::make('addDivisionRule')->table(), [
            'division_id' => $this->jchicken->id,
            'request_types' => ['leave', 'overtime'],
            'steps' => [['name' => 'Koordinator Outlet', 'approver_type' => 'role', 'approver_role' => 'Koordinator']],
        ])->assertHasNoActionErrors()->assertNotified('Aturan divisi disimpan');

        $this->assertSame(2, ApprovalFlow::where('division_id', $this->jchicken->id)->count());

        $leaveRule = ApprovalFlow::where('division_id', $this->jchicken->id)->where('request_type', 'leave')->first();
        $component->callAction(TestAction::make('editDivisionRule')->table($leaveRule), [
            'steps' => [
                ['name' => 'Koordinator Outlet', 'approver_type' => 'role', 'approver_role' => 'Koordinator'],
                ['name' => 'Area Manager', 'approver_type' => 'role', 'approver_role' => 'BOD'],
            ],
        ])->assertHasNoActionErrors();

        $this->assertSame(['Koordinator', 'BOD'], ApprovalFlow::where('division_id', $this->jchicken->id)->where('request_type', 'leave')->orderBy('step_order')->pluck('approver_role')->all());

        $overtimeRule = ApprovalFlow::where('division_id', $this->jchicken->id)->where('request_type', 'overtime')->first();
        $component->callAction(TestAction::make('deleteDivisionRule')->table($overtimeRule));

        $this->assertFalse(ApprovalFlow::where('division_id', $this->jchicken->id)->where('request_type', 'overtime')->exists());
        $this->assertTrue(ApprovalFlow::where('division_id', $this->jchicken->id)->where('request_type', 'leave')->exists());

        $undoRepeaterFake();
    }

    public function test_saving_the_company_flow_keeps_division_rules(): void
    {
        $this->service->saveDivisionRule($this->company, $this->jchicken->id, ['leave'], [$this->step('Koordinator')]);
        $this->actingAs($this->superadmin);

        Livewire::test(EditApprovalFlow::class, ['record' => $this->company->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['Koordinator'], ApprovalFlow::where('division_id', $this->jchicken->id)->pluck('approver_role')->all());
        $this->assertSame(5, ApprovalFlow::companyWide()->where('company_id', $this->company->id)->count());
    }

    public function test_the_warning_checks_who_reaches_the_division(): void
    {
        $approver = User::factory()->create(['is_active' => true]);
        $approver->assignRole('Approver');
        $approver->grantDataAccess(DataScope::Divisions, divisionIds: [$this->jchicken->id]);

        $this->assertTrue(ApprovalFlowService::hasHolderForRole('Approver', $this->company->id, $this->jchickenCiledug->id));
        $this->assertFalse(ApprovalFlowService::hasHolderForRole('Approver', $this->company->id, $this->finance->id));
    }

    /**
     * @return array{name: string, approver_type: string, approver_role: string}
     */
    private function step(string $role): array
    {
        return ['name' => "Persetujuan {$role}", 'approver_type' => 'role', 'approver_role' => $role];
    }

    private function leaveIn(Division $division): LeaveRequest
    {
        $user = User::factory()->create();
        $user->assignRole('Freelancer');
        $worker = Freelancer::create(['user_id' => $user->id, 'company_id' => $this->company->id, 'division_id' => $division->id, 'full_name' => 'Pekerja', 'status' => 'active']);
        $leave = LeaveRequest::create(['freelancer_id' => $worker->id, 'leave_type' => 'sick', 'start_date' => now()->addDays(2)->toDateString(), 'end_date' => now()->addDays(2)->toDateString(), 'days_count' => 1, 'reason' => 'Sakit', 'status' => 'pending']);
        $this->service->generateSteps($leave, 'leave');

        return $leave->refresh();
    }
}
