<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Models\Company;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\ApprovalFlowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CoordinatorApprovalTest extends TestCase
{
    use RefreshDatabase;

    private ApprovalFlowService $service;

    private Company $company;

    private Division $jchicken;

    private Division $momoyo;

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
        $this->momoyo = Division::create(['company_id' => $this->company->id, 'name' => 'MOMOYO', 'is_active' => true]);
    }

    public function test_one_coordinator_approval_settles_every_stage(): void
    {
        $leave = $this->leaveFor($this->jchicken);

        $this->service->approveStep($leave, $this->coordinator(), 'Oke');

        $leave->refresh();
        $this->assertSame('approved', $leave->status instanceof \BackedEnum ? $leave->status->value : $leave->status);
        $this->assertSame(3, (int) $leave->current_step);
        $this->assertSame(['approved', 'approved', 'approved'], $leave->approvalSteps()->orderBy('step_order')->pluck('status')->all());
        $this->assertSame(0, $leave->approvalSteps()->where('actioned_by', '!=', $this->coordinator()->id)->count());
    }

    public function test_a_coordinator_can_settle_a_request_already_past_the_first_stage(): void
    {
        $leave = $this->leaveFor($this->jchicken);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $admin->grantDataAccess(DataScope::Companies, companyIds: [$this->company->id]);

        $this->service->approveStep($leave, $admin);
        $this->assertSame(2, (int) $leave->refresh()->current_step, 'Admins still act one stage at a time.');

        $this->service->approveStep($leave, $this->coordinator());

        $this->assertSame('approved', $leave->refresh()->status instanceof \BackedEnum ? $leave->status->value : $leave->status);
        $this->assertSame($admin->id, (int) $leave->approvalSteps()->where('step_order', 1)->value('actioned_by'));
    }

    public function test_a_coordinator_cannot_approve_outside_their_brands(): void
    {
        $leave = $this->leaveFor($this->momoyo);

        $this->expectException(ValidationException::class);

        $this->service->approveStep($leave, $this->coordinator());
    }

    private function coordinator(): User
    {
        $coordinator = User::where('email', 'loocita@example.com')->first();

        if (! $coordinator) {
            $coordinator = User::factory()->create(['email' => 'loocita@example.com']);
            $coordinator->assignRole('Koordinator');
            $coordinator->grantDataAccess(DataScope::Divisions, divisionIds: [$this->jchicken->id]);
        }

        return $coordinator;
    }

    private function leaveFor(Division $brand): LeaveRequest
    {
        $user = User::factory()->create();
        $user->assignRole('Freelancer');
        $crew = Freelancer::create(['user_id' => $user->id, 'company_id' => $this->company->id, 'division_id' => $brand->id, 'full_name' => 'Crew '.$brand->name, 'status' => 'active']);

        $leave = LeaveRequest::create([
            'freelancer_id' => $crew->id,
            'leave_type' => 'sick',
            'start_date' => now()->addDays(2)->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'days_count' => 1,
            'reason' => 'Sakit',
            'status' => 'pending',
        ]);
        $this->service->generateSteps($leave, 'leave');

        return $leave->refresh();
    }
}
