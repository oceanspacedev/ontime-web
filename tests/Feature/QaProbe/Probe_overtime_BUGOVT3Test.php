<?php

namespace Tests\Feature\QaProbe;

use App\Filament\Resources\OvertimeRequests\Pages\CreateOvertimeRequest;
use App\Models\ApprovalFlow;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\OvertimeRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * BUG-OVT-3: the web create form saves a requester-typed duration_minutes and actual_duration_minutes
 * instead of deriving duration from start/end like the API does.
 */
class Probe_overtime_BUGOVT3Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Division $division;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Employee']);
        Role::create(['name' => 'Approver']);

        $this->company = Company::create(['name' => 'PT Lembur', 'code' => 'LMB', 'is_active' => true]);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Operasional', 'is_active' => true]);

        ApprovalFlow::create(['company_id' => $this->company->id, 'request_type' => 'overtime', 'step_number' => 1, 'step_order' => 1, 'name' => 'Approver', 'approver_type' => 'role', 'approver_role' => 'Approver']);
        User::factory()->create()->assignRole('Approver');
    }

    public function test_api_derives_duration_from_start_and_end(): void
    {
        Sanctum::actingAs($this->makeWorker());

        $this->postJson('/api/v1/overtime-requests', ['date' => '2026-11-10', 'start_time' => '17:00', 'end_time' => '18:00', 'reason' => 'Stok opname'])
            ->assertCreated();

        $this->assertSame(60, OvertimeRequest::sole()->duration_minutes);
    }

    public function test_web_create_must_not_trust_typed_duration_or_prefilled_actual_duration(): void
    {
        $this->actingAs($this->makeWorker());

        Livewire::test(CreateOvertimeRequest::class)
            ->fillForm([
                'date' => '2026-11-10',
                'start_time' => '17:00',
                'end_time' => '18:00',
                'reason' => 'Stok opname',
            ])
            ->fillForm([
                'duration_minutes' => 600,
                'actual_duration_minutes' => 600,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $overtime = OvertimeRequest::sole();

        $this->assertSame('pending', $overtime->status);
        $this->assertSame(60, $overtime->duration_minutes, 'A 17:00-18:00 overtime must be stored as 60 minutes, not the typed value.');
        $this->assertNull($overtime->actual_duration_minutes, 'The requester must not pre-fill the actual duration on a new pending request.');
    }

    private function makeWorker(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Employee');

        Employee::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Staff Operasional'])->id,
            'nip' => 'LMB-'.$user->id,
            'full_name' => "Pekerja {$user->id}",
            'status' => 'active',
        ]);

        return $user->fresh();
    }
}
