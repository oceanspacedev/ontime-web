<?php

namespace Tests\Feature\QaProbe;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalFlowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * BUG-COR-3 probe: approving a time correction on an attendance that is still waiting for its
 * out-of-geofence approval must not silently settle that geofence approval as "Tepat Waktu".
 */
class Probe_overtime_BUGCOR3Test extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private Shift $morning;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp_gateway.url' => null,
            'services.whatsapp_gateway.token' => null,
            'services.onesignal.app_id' => null,
            'services.onesignal.rest_api_key' => null,
        ]);
        Http::fake();

        foreach (['Superadmin', 'Admin', 'PIC', 'Koordinator', 'Approver', 'BOD', 'Employee', 'Intern', 'Freelancer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->travelTo(Carbon::parse('2026-11-16 12:00:00'));

        $tenant = Tenant::factory()->create(['name' => 'Klien A', 'code' => 'KA', 'is_active' => true]);
        $company = new Company(['name' => 'PT Absen', 'code' => 'ABSEN', 'is_active' => true]);
        $company->tenant_id = $tenant->id;
        $company->save();
        app(ApprovalFlowService::class)->createDefaultFlows($company);
        $division = Division::create(['company_id' => $company->id, 'name' => 'Operasional', 'code' => 'OPS', 'is_active' => true]);

        $this->morning = Shift::create(['company_id' => $company->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '16:00', 'late_tolerance_minutes' => 10]);

        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['tenant_id' => $tenant->id])->save();
        $user->assignRole('Employee');

        $this->employee = Employee::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'division_id' => $division->id,
            'job_level_id' => JobLevel::create(['name' => 'Staff', 'level_number' => 1])->id,
            'job_title_id' => JobTitle::create(['name' => 'Kasir'])->id,
            'nip' => 'EMP-1001',
            'full_name' => 'Pekerja Absen',
            'join_date' => '2026-01-01',
            'status' => 'permanent',
        ]);

        ShiftSchedule::create(['employee_id' => $this->employee->id, 'date' => today(), 'shift_id' => $this->morning->id]);
    }

    public function test_correction_on_attendance_awaiting_geofence_approval_does_not_settle_it_as_on_time(): void
    {
        // Out-of-geofence check-in at 09:00, 50 minutes late after the 10 minute tolerance.
        $attendance = Attendance::create([
            'employee_id' => $this->employee->id,
            'date' => today(),
            'segment' => 1,
            'shift_id' => $this->morning->id,
            'check_in' => today()->setTime(9, 0),
            'status' => AttendanceStatus::PendingApproval,
            'late_minutes' => 0,
            'check_in_late_minutes' => 50,
        ]);
        app(ApprovalFlowService::class)->generateSteps($attendance, 'geofence');
        $this->assertSame(AttendanceStatus::PendingApproval, $attendance->fresh()->status);

        $correction = AttendanceCorrection::create([
            'employee_id' => $this->employee->id,
            'attendance_id' => $attendance->id,
            'date' => today()->toDateString(),
            'corrected_check_in' => '08:00',
            'reason' => 'Lupa absen tepat waktu',
            'status' => 'pending',
            'current_step' => 1,
        ]);

        $correction->applyCorrection();

        $attendance->refresh();
        $pendingGeofenceSteps = $attendance->approvalSteps()->where('status', 'pending')->count();

        fwrite(STDERR, sprintf(
            "\n[BUG-COR-3] after correction: status=%s late=%s check_in_late_minutes=%s pending_geofence_steps=%d\n",
            $attendance->status?->value ?? 'null',
            var_export($attendance->late_minutes, true),
            var_export($attendance->check_in_late_minutes, true),
            $pendingGeofenceSteps,
        ));

        // Correct business behaviour: the out-of-geofence approval is still undecided, so the row must
        // not become "Tepat Waktu" (counted as present / paid) while its geofence step is still pending.
        $this->assertSame(1, $pendingGeofenceSteps, 'geofence approval step should still be pending');
        $this->assertSame(
            AttendanceStatus::PendingApproval,
            $attendance->status,
            'Correction approval silently settled a pending out-of-geofence attendance',
        );
    }
}
