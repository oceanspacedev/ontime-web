<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Filament\Resources\Attendances\Pages\EditAttendance;
use App\Models\Attendance;
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
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * RSK-07 residue: the lateness judged at check-in survives an edit that keeps the check-in on the
 * same minute (the admin form drops seconds), and is still dropped when the minute really changes.
 */
class AttendanceLateSnapshotEditTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Division $division;

    private Shift $morning;

    private Shift $late;

    private Employee $employee;

    private ShiftSchedule $schedule;

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

        $this->travelTo(Carbon::parse('2026-11-16 09:00:00'));

        $tenant = Tenant::factory()->create(['name' => 'Klien A', 'code' => 'KA', 'is_active' => true]);
        $this->company = new Company(['name' => 'PT Absen', 'code' => 'ABSEN', 'is_active' => true]);
        $this->company->tenant_id = $tenant->id;
        $this->company->save();
        app(ApprovalFlowService::class)->createDefaultFlows($this->company);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Operasional', 'code' => 'OPS', 'is_active' => true]);

        $this->morning = Shift::create(['company_id' => $this->company->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '16:00', 'late_tolerance_minutes' => 0]);
        $this->late = Shift::create(['company_id' => $this->company->id, 'name' => 'Siang', 'start_time' => '10:00', 'end_time' => '18:00', 'late_tolerance_minutes' => 0]);

        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['tenant_id' => $tenant->id])->save();
        $user->assignRole('Employee');

        $this->employee = Employee::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'job_level_id' => JobLevel::create(['name' => 'Staff', 'level_number' => 1])->id,
            'job_title_id' => JobTitle::create(['name' => 'Kasir'])->id,
            'nip' => 'EMP-1001',
            'full_name' => 'Pekerja Absen',
            'join_date' => '2026-01-01',
            'status' => 'permanent',
        ]);

        $this->schedule = ShiftSchedule::create(['employee_id' => $this->employee->id, 'date' => today(), 'shift_id' => $this->morning->id]);
    }

    private function pendingCheckInWithSeconds(): Attendance
    {
        return Attendance::create([
            'employee_id' => $this->employee->id,
            'date' => today(),
            'shift_id' => $this->morning->id,
            'check_in' => today()->setTime(8, 30, 27),
            'status' => AttendanceStatus::PendingApproval,
            'late_minutes' => 0,
            'check_in_late_minutes' => 30,
        ]);
    }

    public function test_saving_the_check_in_on_the_same_minute_keeps_the_lateness_judged_at_check_in(): void
    {
        $attendance = $this->pendingCheckInWithSeconds();

        $attendance->update(['check_in' => today()->setTime(8, 30)]);
        $this->assertSame(30, $attendance->fresh()->check_in_late_minutes);

        $attendance->fresh()->applyGeofenceApproval();

        $attendance->refresh();
        $this->assertSame(30, $attendance->late_minutes);
        $this->assertSame(AttendanceStatus::Late, $attendance->status);
    }

    public function test_saving_the_admin_edit_form_unchanged_keeps_the_lateness_judged_at_check_in(): void
    {
        $attendance = $this->pendingCheckInWithSeconds();
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('Superadmin');
        $this->actingAs($admin);

        Livewire::test(EditAttendance::class, ['record' => $attendance->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('08:30:00', $attendance->fresh()->check_in->format('H:i:s'));
        $this->assertSame(30, $attendance->fresh()->check_in_late_minutes);

        $attendance->fresh()->applyGeofenceApproval();

        $this->assertSame(30, $attendance->fresh()->late_minutes);
    }

    public function test_a_roster_edit_while_the_check_in_waits_is_refused_and_the_verdict_stands(): void
    {
        $attendance = $this->pendingCheckInWithSeconds();
        $attendance->forceFill(['shift_schedule_id' => $this->schedule->id])->saveQuietly();

        // D4: the schedule the worker checked in to cannot be moved to 10:00 any more.
        try {
            $this->schedule->update(['shift_id' => $this->late->id]);
            $this->fail('The schedule of a checked-in row must not change.');
        } catch (ValidationException $exception) {
            $this->assertSame([ShiftSchedule::LOCKED_MESSAGE], $exception->errors()['date']);
        }

        $this->assertSame(30, $attendance->fresh()->check_in_late_minutes);

        $attendance->fresh()->applyGeofenceApproval();

        $attendance->refresh();
        $this->assertSame(30, $attendance->late_minutes);
        $this->assertSame(AttendanceStatus::Late, $attendance->status);
    }

    public function test_moving_the_check_in_to_another_minute_drops_the_lateness_and_judges_the_new_time(): void
    {
        $attendance = $this->pendingCheckInWithSeconds();

        $attendance->update(['check_in' => today()->setTime(8, 45)]);
        $this->assertNull($attendance->fresh()->check_in_late_minutes);

        $attendance->fresh()->applyGeofenceApproval();

        $attendance->refresh();
        $this->assertSame(45, $attendance->late_minutes);
        $this->assertSame(AttendanceStatus::Late, $attendance->status);
    }
}
