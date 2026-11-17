<?php

namespace Tests\Feature\QaProbe;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalFlowService;
use App\Services\AttendanceLatenessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Reviewer probes for the "engine" group: an off-schedule check-in must name its cover shift, and is never
 * judged against the default shift or the company hours later (recalculation, early leave).
 */
class Review_engineTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-11-16';

    private Company $company;

    private Division $division;

    private Shift $morning;

    private CompanyLocation $outlet;

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

        $this->travelTo(Carbon::parse(self::MONDAY.' 06:00:00'));

        $tenant = Tenant::factory()->create(['name' => 'Klien A', 'code' => 'KA', 'is_active' => true]);
        $this->company = new Company(['name' => 'PT Resto', 'code' => 'RESTO', 'is_active' => true]);
        $this->company->tenant_id = $tenant->id;
        $this->company->save();
        app(ApprovalFlowService::class)->createDefaultFlows($this->company);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Dapur', 'code' => 'DPR', 'is_active' => true]);
        $this->company->policy()->update([
            'require_gps' => false,
            'require_photo' => false,
            'require_face_recognition' => false,
            'require_liveness' => false,
            'work_start_time' => '08:00:00',
            'work_end_time' => '17:00:00',
            'late_tolerance_minutes' => 15,
        ]);
        $this->company->unsetRelation('policy');

        $this->morning = $this->shift('Pagi', '08:00', '16:00');
        $this->outlet = CompanyLocation::create(['company_id' => $this->company->id, 'name' => 'Outlet Mall', 'latitude' => -6.2, 'longitude' => 106.8, 'radius_meters' => 100, 'is_active' => true]);
    }

    public function test_backup_without_cover_shift_is_refused_so_no_shiftless_day_can_be_approved(): void
    {
        $employee = $this->employee('Andi', $this->morning);
        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::MONDAY, 'is_day_off' => true]);
        $freelancer = $this->freelancer('Citra');
        $this->at('11:00:00');

        $this->checkIn($employee->user)->assertUnprocessable()->assertJsonValidationErrors('cover_shift_id');
        $this->checkIn($freelancer->user)->assertUnprocessable()->assertJsonValidationErrors('cover_shift_id');
        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_approved_backup_stays_on_time_after_an_admin_recalculation(): void
    {
        $closing = $this->shift('Closing', '11:00', '19:00');
        $employee = $this->employee('Andi', $this->morning);
        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::MONDAY, 'is_day_off' => true]);
        $this->at('11:00:00');
        $this->checkIn($employee->user, ['cover_shift_id' => $closing->id])->assertOk();
        $this->approve($this->rowOf('employee_id', $employee->id));
        $this->assertSame(0, $this->rowOf('employee_id', $employee->id)->late_minutes);

        // What EditAttendance::afterSave() runs when the admin moves the check-out to another minute.
        app(AttendanceLatenessService::class)->recalculate($this->rowOf('employee_id', $employee->id), force: true);

        $row = $this->rowOf('employee_id', $employee->id);
        $this->assertSame(0, $row->late_minutes, 'The 11:00 backup became late against the 08:00 default shift.');
        $this->assertSame(AttendanceStatus::OnTime, $row->status);
    }

    public function test_approved_backup_leaves_early_against_the_cover_shift_not_the_default_shift(): void
    {
        $closing = $this->shift('Closing', '10:00', '18:00');
        $employee = $this->employee('Andi', $this->morning);
        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::MONDAY, 'is_day_off' => true]);
        $this->at('10:00:00');
        $this->checkIn($employee->user, ['cover_shift_id' => $closing->id])->assertOk();
        $this->approve($this->rowOf('employee_id', $employee->id));

        $this->at('16:00:00');
        Sanctum::actingAs($employee->user);
        $this->postJson('/api/v1/attendance/check-out')->assertOk();

        $this->assertSame(120, (int) $this->rowOf('employee_id', $employee->id)->early_leave_minutes, 'Measured against the 18:00 end of the cover shift, not 16:00 of the default shift.');
    }

    public function test_pending_freelancer_backup_has_no_early_leave_against_company_hours(): void
    {
        $freelancer = $this->freelancer('Citra');
        $this->at('10:00:00');
        $this->checkIn($freelancer->user, ['cover_shift_id' => $this->morning->id])->assertOk()->assertJsonPath('requires_approval', true);

        $this->at('16:00:00');
        Sanctum::actingAs($freelancer->user);
        $this->postJson('/api/v1/attendance/check-out')->assertOk();

        $this->assertSame(0, (int) $this->rowOf('freelancer_id', $freelancer->id)->early_leave_minutes, 'Company hours (17:00) were used as the end of a 16:00 cover shift.');
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function at(string $time): void
    {
        $this->travelTo(Carbon::parse(self::MONDAY.' '.$time));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function checkIn(User $user, array $data = []): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->postJson('/api/v1/attendance/check-in', $data);
    }

    private function rowOf(string $column, int $id): Attendance
    {
        return Attendance::query()->where($column, $id)->whereDate('date', self::MONDAY)->where('segment', 1)->sole();
    }

    private function approve(Attendance $attendance): void
    {
        app(ApprovalFlowService::class)->approveStep($attendance->fresh(), $this->approver());
    }

    private function approver(): User
    {
        $approver = User::factory()->create(['is_active' => true]);
        $approver->forceFill(['tenant_id' => $this->company->tenant_id])->save();
        $approver->assignRole('Approver');
        $this->grantDataAccessTo($approver, $this->company->id, $this->division->id);

        return $approver;
    }

    private function shift(string $name, string $start, string $end, bool $active = true): Shift
    {
        return Shift::create(['company_id' => $this->company->id, 'name' => $name, 'start_time' => $start, 'end_time' => $end, 'late_tolerance_minutes' => 0, 'is_active' => $active]);
    }

    private function employee(string $name, ?Shift $defaultShift = null, ?Company $company = null): Employee
    {
        $company ??= $this->company;
        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['tenant_id' => $this->company->tenant_id])->save();
        $user->assignRole('Employee');
        $division = $company->is($this->company) ? $this->division : Division::create(['company_id' => $company->id, 'name' => 'Lain', 'code' => 'LN'.$user->id, 'is_active' => true]);

        return Employee::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'division_id' => $division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_number' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Kasir'])->id,
            'nip' => 'EMP-'.$user->id,
            'full_name' => $name,
            'join_date' => '2026-01-01',
            'status' => 'permanent',
            'shift_id' => $defaultShift?->id,
        ]);
    }

    private function freelancer(string $name): Freelancer
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['tenant_id' => $this->company->tenant_id])->save();
        $user->assignRole('Freelancer');

        return Freelancer::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'full_name' => $name,
            'start_date' => '2026-10-01',
            'end_date' => '2026-12-31',
            'status' => 'active',
        ]);
    }
}
