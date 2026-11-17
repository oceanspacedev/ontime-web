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
use App\Models\ShiftSchedule;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalFlowService;
use App\Services\WorkScheduleService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * BUG-COR-2: an approved correction on a scheduled day off is recorded as on_time / 0 late minutes.
 * The live check-in path does exactly the same (AttendanceCheckService::performCheckIn status match),
 * so the probe asserts parity between the two paths rather than a different status.
 */
class Probe_overtime_BUGCOR2Test extends TestCase
{
    use RefreshDatabase;

    public function test_day_off_correction_matches_day_off_check_in_rule(): void
    {
        config(['services.onesignal.app_id' => null, 'services.whatsapp_gateway.url' => null]);
        Http::preventStrayRequests();
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-11-16 20:00:00'));
        $this->seed(RoleSeeder::class);

        $tenant = Tenant::factory()->create(['name' => 'Klien C2', 'code' => 'C2']);
        $company = new Company(['name' => 'PT C2', 'code' => 'C2C', 'is_active' => true]);
        $company->tenant_id = $tenant->id;
        $company->save();
        $division = Division::create(['company_id' => $company->id, 'name' => 'Outlet', 'is_active' => true]);
        ApprovalFlow::create([
            'company_id' => $company->id, 'request_type' => 'correction', 'step_number' => 1, 'step_order' => 1,
            'name' => 'Persetujuan correction', 'approver_type' => 'role', 'approver_role' => 'Admin',
        ]);

        $freelancerUser = User::factory()->create(['is_active' => true]);
        $freelancerUser->forceFill(['tenant_id' => $tenant->id])->save();
        $freelancerUser->assignRole('Freelancer');
        $shift = Shift::create([
            'company_id' => $company->id, 'division_id' => $division->id, 'name' => 'Pagi',
            'start_time' => '08:00', 'end_time' => '17:00', 'late_tolerance_minutes' => 10,
        ]);
        $freelancer = Freelancer::create([
            'user_id' => $freelancerUser->id, 'company_id' => $company->id, 'division_id' => $division->id,
            'full_name' => 'Fre Lance', 'status' => 'active', 'shift_id' => $shift->id,
        ]);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->forceFill(['tenant_id' => $tenant->id])->save();
        $admin->assignRole('Admin');
        $admin->grantDataAccess(DataScope::All);

        ShiftSchedule::create(['date' => '2026-11-15', 'freelancer_id' => $freelancer->id, 'is_day_off' => true]);

        Sanctum::actingAs($freelancerUser);
        $id = $this->postJson('/api/v1/attendance-corrections', [
            'date' => '2026-11-15', 'corrected_check_in' => '11:00', 'corrected_check_out' => '17:00', 'reason' => 'lupa',
        ])->assertCreated()->json('data.id');
        app(ApprovalFlowService::class)->approveStep(AttendanceCorrection::find($id), $admin);

        $attendance = Attendance::where('freelancer_id', $freelancer->id)->whereDate('date', '2026-11-15')->firstOrFail();
        fwrite(STDERR, "\nDAYOFF correction status={$attendance->status->value} late={$attendance->late_minutes}\n");

        // What a live check-in at 11:00 on the same day off would record (AttendanceCheckService:244-250).
        $service = app(WorkScheduleService::class);
        $hours = $service->resolve($freelancer->fresh(), Carbon::parse('2026-11-15'));
        $liveLate = $service->lateMinutes($hours, Carbon::parse('2026-11-15 11:00'));
        $liveStatus = $liveLate > 0 ? 'late' : 'on_time';

        $this->assertTrue($hours['is_day_off']);
        $this->assertSame($liveStatus, $attendance->status->value);
        $this->assertSame($liveLate, $attendance->late_minutes);
    }
}
