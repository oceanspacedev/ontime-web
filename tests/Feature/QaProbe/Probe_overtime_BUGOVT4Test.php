<?php

namespace Tests\Feature\QaProbe;

use App\Filament\Resources\OvertimeRequests\Pages\ListOvertimeRequests;
use App\Models\Company;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\OvertimeRequest;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Probe_overtime_BUGOVT4Test extends TestCase
{
    use RefreshDatabase;

    public function test_freelancer_cannot_set_actual_duration_on_own_approved_overtime(): void
    {
        Role::firstOrCreate(['name' => 'Freelancer']);

        $company = Company::create(['name' => 'PT Probe', 'code' => 'PRB', 'is_active' => true]);
        $division = Division::create(['company_id' => $company->id, 'name' => 'Ops', 'code' => 'OPS', 'is_active' => true]);

        $user = User::factory()->create();
        $user->assignRole('Freelancer');

        $freelancer = Freelancer::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'division_id' => $division->id,
            'freelancer_number' => 'FL-PRB-001',
            'full_name' => 'Freelancer Probe',
            'email' => $user->email,
            'phone' => '628111222333',
            'start_date' => now(),
            'end_date' => now()->addMonths(6),
            'status' => 'active',
        ]);

        $overtime = OvertimeRequest::create([
            'freelancer_id' => $freelancer->id,
            'date' => today(),
            'start_time' => '18:00',
            'end_time' => '21:00',
            'duration_minutes' => 180,
            'reason' => 'Stock opname',
            'status' => 'approved',
            'current_step' => 1,
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        // Precondition: the freelancer can see their own approved request in the list.
        $component = Livewire::test(ListOvertimeRequests::class)
            ->assertCanSeeTableRecords([$overtime]);

        // Correct behaviour: the requester must not be able to set their own payable minutes, so the action is hidden.
        $component->assertActionHidden(TestAction::make('fillActualDuration')->table($overtime));

        $this->assertNotSame(600, $overtime->fresh()->actual_duration_minutes,
            'Freelancer set own approved overtime actual_duration_minutes to 600 (payable minutes) via Isi Durasi Aktual.');
    }
}
