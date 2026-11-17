<?php

namespace Tests\Feature\QaProbe;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalFlowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * BUG-02: an overnight shift (22:00 - 06:00) checked in after midnight via the mobile API.
 */
class Probe_checkout_mobile_BUG02Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Division $division;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp_gateway.url' => null,
            'services.whatsapp_gateway.token' => null,
            'services.face.driver' => 'basic',
            'services.onesignal.app_id' => null,
            'services.onesignal.rest_api_key' => null,
        ]);
        Http::fake();

        foreach (['Superadmin', 'Admin', 'PIC', 'Koordinator', 'Approver', 'BOD', 'Employee', 'Intern', 'Freelancer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->travelTo(Carbon::parse('2026-11-16 08:00:00'));

        $tenant = Tenant::factory()->create(['name' => 'Klien A', 'code' => 'KA', 'is_active' => true]);
        $this->company = new Company(['name' => 'PT Absen', 'code' => 'ABSEN', 'is_active' => true]);
        $this->company->tenant_id = $tenant->id;
        $this->company->save();
        app(ApprovalFlowService::class)->createDefaultFlows($this->company);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Operasional', 'code' => 'OPS', 'is_active' => true]);
        $this->company->policy()->update([
            'require_gps' => false,
            'require_photo' => false,
            'require_face_recognition' => false,
            'require_liveness' => false,
        ]);
        $this->company->unsetRelation('policy');
    }

    private function nightWorker(): Employee
    {
        $shift = Shift::create(['company_id' => $this->company->id, 'name' => 'Malam', 'code' => 'MLM', 'start_time' => '22:00', 'end_time' => '06:00', 'late_tolerance_minutes' => 0]);
        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['tenant_id' => $this->company->tenant_id])->save();
        $user->assignRole('Employee');

        return Employee::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'job_level_id' => JobLevel::create(['name' => 'Staff', 'level_number' => 1])->id,
            'job_title_id' => JobTitle::create(['name' => 'Satpam'])->id,
            'nip' => 'EMP-9001',
            'full_name' => 'Satpam Malam',
            'join_date' => '2026-01-01',
            'status' => 'permanent',
            'shift_id' => $shift->id,
        ]);
    }

    public function test_check_in_at_0030_for_a_2200_shift_is_late_on_the_shift_day(): void
    {
        $employee = $this->nightWorker();
        $this->travelTo(Carbon::parse('2026-11-17 00:30:00'));
        Sanctum::actingAs($employee->user);

        $this->postJson('/api/v1/attendance/check-in')->assertOk();

        $row = Attendance::where('employee_id', $employee->id)->firstOrFail();
        dump(['date' => $row->date->toDateString(), 'status' => $row->status->value, 'late_minutes' => $row->late_minutes, 'check_in_late_minutes' => $row->check_in_late_minutes]);

        // Business meaning: 2.5h after a 22:00 start is late, never "Tepat Waktu".
        $this->assertNotSame(AttendanceStatus::OnTime, $row->status, 'A 00:30 check-in for a 22:00 shift is reported Tepat Waktu');
        $this->assertSame(150, (int) $row->late_minutes);
        $this->assertSame('2026-11-16', $row->date->toDateString());
    }

    public function test_the_real_shift_of_the_same_evening_can_still_check_in(): void
    {
        $employee = $this->nightWorker();
        Sanctum::actingAs($employee->user);

        $this->travelTo(Carbon::parse('2026-11-17 00:30:00'));
        $this->postJson('/api/v1/attendance/check-in')->assertOk();
        $this->travelTo(Carbon::parse('2026-11-17 06:00:00'));
        $this->postJson('/api/v1/attendance/check-out')->assertOk();

        $this->travelTo(Carbon::parse('2026-11-17 21:55:00'));
        $response = $this->postJson('/api/v1/attendance/check-in');
        dump(['evening_status' => $response->status(), 'message' => $response->json('message')]);

        $response->assertOk();
    }
}
