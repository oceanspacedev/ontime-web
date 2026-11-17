<?php

namespace Tests\Feature\QaProbe;

use App\Enums\DataScope;
use App\Models\ApprovalFlow;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Company;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalFlowService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * BUG-COR-1: an approved correction of a 22:00-06:00 night shift with an after-midnight check-in (00:30)
 * must be late (150 minutes), and its check-in must fall after the shift start, not 21.5 hours before it.
 */
class Probe_overtime_BUGCOR1Test extends TestCase
{
    use RefreshDatabase;

    public function test_overnight_correction_with_after_midnight_check_in_is_late(): void
    {
        config(['services.onesignal.app_id' => null, 'services.whatsapp_gateway.url' => null]);
        Http::preventStrayRequests();
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-11-16 20:00:00'));
        $this->seed(RoleSeeder::class);

        $tenant = Tenant::factory()->create(['name' => 'Klien C1', 'code' => 'C1']);
        $company = new Company(['name' => 'PT C1', 'code' => 'C1C', 'is_active' => true]);
        $company->tenant_id = $tenant->id;
        $company->save();
        $division = Division::create(['company_id' => $company->id, 'name' => 'Outlet', 'is_active' => true]);
        ApprovalFlow::create([
            'company_id' => $company->id, 'request_type' => 'correction', 'step_number' => 1, 'step_order' => 1,
            'name' => 'Persetujuan correction', 'approver_type' => 'role', 'approver_role' => 'Admin',
        ]);

        $shift = Shift::create([
            'company_id' => $company->id, 'division_id' => $division->id, 'name' => 'Malam',
            'start_time' => '22:00', 'end_time' => '06:00', 'late_tolerance_minutes' => 10,
        ]);

        $workerUser = User::factory()->create(['is_active' => true]);
        $workerUser->forceFill(['tenant_id' => $tenant->id])->save();
        $workerUser->assignRole('Freelancer');
        $freelancer = Freelancer::create([
            'user_id' => $workerUser->id, 'company_id' => $company->id, 'division_id' => $division->id,
            'full_name' => 'Fre Lance', 'status' => 'active', 'shift_id' => $shift->id,
        ]);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->forceFill(['tenant_id' => $tenant->id])->save();
        $admin->assignRole('Admin');
        $admin->grantDataAccess(DataScope::All);

        Sanctum::actingAs($workerUser);
        $id = $this->postJson('/api/v1/attendance-corrections', [
            'date' => '2026-11-14', 'corrected_check_in' => '00:30', 'corrected_check_out' => '06:00', 'reason' => 'lupa absen',
        ])->assertCreated()->json('data.id');

        app(ApprovalFlowService::class)->approveStep(AttendanceCorrection::find($id), $admin);

        $attendance = Attendance::where('freelancer_id', $freelancer->id)->whereDate('date', '2026-11-14')->firstOrFail();
        $status = $attendance->status?->value ?? $attendance->status;

        fwrite(STDERR, "\nBUG-COR-1 status={$status} late={$attendance->late_minutes} in={$attendance->check_in} out={$attendance->check_out}\n");

        // A check-in 2.5 hours after a 22:00 start (tolerance 10) is late, never "Tepat Waktu".
        $this->assertSame('late', $status);
        $this->assertSame(150, (int) $attendance->late_minutes);
        // The check-in belongs after the shift start (night of 14 -> 15), not the morning of the 14th.
        $this->assertTrue($attendance->check_in->greaterThan(Carbon::parse('2026-11-14 22:00:00')));
    }
}
