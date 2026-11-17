<?php

namespace Tests\Feature\QaProbe;

use App\Enums\DataScope;
use App\Exports\AttendanceRecapSheet;
use App\Models\ApprovalFlow;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\OvertimeRequest;
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
 * BUG-OVT-1: approved overtime should be visible in the attendance recap used for freelancer payroll.
 */
class Probe_overtime_BUGOVT1Test extends TestCase
{
    use RefreshDatabase;

    public function test_approved_overtime_appears_in_attendance_recap(): void
    {
        config(['services.onesignal.app_id' => null, 'services.whatsapp_gateway.url' => null]);
        Http::preventStrayRequests();
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-11-16 21:00:00'));
        $this->seed(RoleSeeder::class);

        $tenant = Tenant::factory()->create(['name' => 'Klien OV', 'code' => 'OV']);
        $company = new Company(['name' => 'PT OV', 'code' => 'OVC', 'is_active' => true]);
        $company->tenant_id = $tenant->id;
        $company->save();
        $division = Division::create(['company_id' => $company->id, 'name' => 'Outlet', 'is_active' => true]);
        ApprovalFlow::create([
            'company_id' => $company->id, 'request_type' => 'overtime', 'step_number' => 1, 'step_order' => 1,
            'name' => 'Persetujuan lembur', 'approver_type' => 'role', 'approver_role' => 'Admin',
        ]);

        $freelancerUser = User::factory()->create(['is_active' => true]);
        $freelancerUser->forceFill(['tenant_id' => $tenant->id])->save();
        $freelancerUser->assignRole('Freelancer');
        $freelancer = Freelancer::create([
            'user_id' => $freelancerUser->id, 'company_id' => $company->id,
            'division_id' => $division->id, 'full_name' => 'Fre Lance', 'status' => 'active',
        ]);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->forceFill(['tenant_id' => $tenant->id])->save();
        $admin->assignRole('Admin');
        $admin->grantDataAccess(DataScope::All);

        Sanctum::actingAs($freelancerUser);
        $id = $this->postJson('/api/v1/overtime-requests', ['date' => '2026-11-16', 'start_time' => '17:00', 'end_time' => '20:00', 'reason' => 'stok'])
            ->assertCreated()->json('data.id');
        app(ApprovalFlowService::class)->approveStep(OvertimeRequest::find($id), $admin);
        $this->assertSame('approved', OvertimeRequest::find($id)->status);

        Attendance::create([
            'freelancer_id' => $freelancer->id, 'date' => '2026-11-16', 'segment' => 1,
            'check_in' => '2026-11-16 08:00:00', 'check_out' => '2026-11-16 20:00:00',
            'status' => 'on_time', 'late_minutes' => 0,
        ]);

        $records = Attendance::with('freelancer')->get();
        $rows = (new AttendanceRecapSheet($records, Carbon::parse('2026-11-16'), Carbon::parse('2026-11-16')))->array();

        $headings = array_merge($rows[0], $rows[1]);
        fwrite(STDERR, "\nRECAP headings=".json_encode(array_values(array_filter($headings)))."\nRECAP row=".json_encode($rows[2])."\n");

        $hasOvertimeColumn = collect($headings)->contains(fn ($heading): bool => is_string($heading) && str_contains(strtolower($heading), 'lembur'));
        $this->assertTrue($hasOvertimeColumn, 'Approved overtime (180 min) is not represented anywhere in the attendance recap export.');
    }
}
