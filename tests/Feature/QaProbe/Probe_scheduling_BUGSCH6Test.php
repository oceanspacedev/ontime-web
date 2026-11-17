<?php

namespace Tests\Feature\QaProbe;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyPolicy;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * BUG-SCH-6: a freelancer with no dated schedule and no default shift is judged against the
 * company policy hours (WorkScheduleService::resolve() fallback, documented at lines 15-19).
 * This probe characterises the behaviour; whether it is wrong is a product decision.
 */
class Probe_scheduling_BUGSCH6Test extends TestCase
{
    use RefreshDatabase;

    private function makeFreelancer(string $code): array
    {
        $company = Company::create(['name' => 'PT '.$code, 'code' => $code, 'latitude' => -6.2, 'longitude' => 106.8, 'is_active' => true]);
        $division = Division::create(['company_id' => $company->id, 'name' => 'Ops', 'code' => 'OPS', 'is_active' => true]);
        $company->policy->update([
            ...CompanyPolicy::flagsForSelfieMode('none'),
            'require_gps' => false,
            'work_start_time' => '08:00:00',
            'work_end_time' => '17:00:00',
            'late_tolerance_minutes' => 15,
        ]);
        $user = User::factory()->create();
        $user->assignRole('Employee');
        $worker = Freelancer::create([
            'user_id' => $user->id, 'company_id' => $company->id, 'division_id' => $division->id,
            'full_name' => 'Freelance '.$code, 'start_date' => '2026-10-01', 'end_date' => '2026-12-31', 'status' => 'active',
        ]);

        return [$user, $worker];
    }

    public function test_unscheduled_freelancer_is_judged_against_policy_hours(): void
    {
        $this->seed(RoleSeeder::class);
        Notification::fake();
        Queue::fake();

        // Evening outlet worker (real shift 17:00) arrives exactly on time, but no schedule was entered.
        [$userA, $workerA] = $this->makeFreelancer('SCH6A');
        $this->assertNull($workerA->shift_id);
        Sanctum::actingAs($userA);
        $this->travelTo(Carbon::parse('2026-11-18 17:00'));
        $this->postJson('/api/v1/attendance/check-in', [])->assertUnprocessable()->assertJsonValidationErrors('cover_shift_id');
        $eveningA = Shift::create(['company_id' => $workerA->company_id, 'name' => 'Sore', 'start_time' => '17:00', 'end_time' => '23:00', 'late_tolerance_minutes' => 15, 'is_active' => true]);
        $this->postJson('/api/v1/attendance/check-in', ['cover_shift_id' => $eveningA->id])->assertSuccessful();
        $rowA = Attendance::query()->where('freelancer_id', $workerA->id)->firstOrFail();

        // A worker arriving at 08:15 for whatever shift, no schedule entered.
        [$userB, $workerB] = $this->makeFreelancer('SCH6B');
        Sanctum::actingAs($userB);
        $this->travelTo(Carbon::parse('2026-11-18 08:15'));
        $morningB = Shift::create(['company_id' => $workerB->company_id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '16:00', 'late_tolerance_minutes' => 15, 'is_active' => true]);
        $this->postJson('/api/v1/attendance/check-in', ['cover_shift_id' => $morningB->id])->assertSuccessful();
        $rowB = Attendance::query()->where('freelancer_id', $workerB->id)->firstOrFail();

        dump(['17:00' => [$rowA->status->value, $rowA->late_minutes, $rowA->shift_id], '08:15' => [$rowB->status->value, $rowB->late_minutes, $rowB->shift_id]]);

        // Decision "Masuk di luar jadwal": no roster means no company-hours judgement; both wait for
        // approval and are judged against the shift they named as covered (both on time here).
        $this->assertSame('pending_approval', $rowA->status->value);
        $this->assertSame(0, $rowA->check_in_late_minutes);
        $this->assertTrue($rowA->is_off_schedule);
        $this->assertSame('pending_approval', $rowB->status->value);
        $this->assertSame(0, $rowB->check_in_late_minutes);
        $this->assertSame($eveningA->id, $rowA->shift_id);
        $this->assertSame($morningB->id, $rowB->shift_id);
    }
}
