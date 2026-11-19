<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Filament\Pages\AbsenHariIni;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalFlowService;
use App\Services\AttendanceCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Regression tests for the attendance-check findings of the staging QA round: concurrent check-outs
 * and check-ins on an existing row (RSK-03, RSK-04), check-in over an approved leave, and lateness
 * of an approved check-in following a roster edited while it was pending (RSK-07).
 *
 * SQLite cannot run two requests at once, so a concurrent request is simulated by writing the row
 * right after the service first reads it, before it writes.
 */
class AttendanceCheckRaceAndLeaveTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-11-16';

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

        $this->travelTo(Carbon::parse(self::MONDAY.' 08:00:00'));

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

    private function employee(): Employee
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['tenant_id' => $this->company->tenant_id])->save();
        $user->assignRole('Employee');

        return Employee::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'job_level_id' => JobLevel::create(['name' => 'Staff', 'level_number' => 1])->id,
            'job_title_id' => JobTitle::create(['name' => 'Kasir'])->id,
            'nip' => 'EMP-'.fake()->unique()->numberBetween(1000, 9999),
            'full_name' => 'Pekerja Absen',
            'join_date' => '2026-01-01',
            'status' => 'permanent',
        ]);
    }

    /**
     * Run $write once, right after the service first loads the given row: the concurrent request wins the race.
     *
     * @param  callable(): void  $write
     */
    private function concurrentWriteAfterFirstRead(Attendance $row, callable $write): void
    {
        $done = false;

        Attendance::retrieved(function (Attendance $attendance) use ($row, $write, &$done): void {
            if (! $done && $attendance->is($row)) {
                $done = true;
                $write();
            }
        });
    }

    // ── RSK-03: two check-outs of the same row ───────────────────────────────

    public function test_check_out_that_loses_the_race_is_a_409_keeps_the_first_check_out_and_removes_its_photo(): void
    {
        Storage::fake(config('filesystems.default'));
        $employee = $this->employee();
        $row = Attendance::create([
            'employee_id' => $employee->id,
            'date' => today(),
            'check_in' => today()->setTime(7, 55),
            'status' => AttendanceStatus::OnTime,
        ]);
        $firstCheckOut = today()->setTime(17, 0);
        $this->travelTo($firstCheckOut->copy()->addMinute());
        Sanctum::actingAs($employee->user);

        $this->concurrentWriteAfterFirstRead($row, fn () => DB::table('attendances')->where('id', $row->id)->update([
            'check_out' => $firstCheckOut,
            'check_out_photo' => 'attendance/first-request.jpg',
        ]));

        $this->post('/api/v1/attendance/check-out', ['photo' => UploadedFile::fake()->image('selfie.jpg')], ['Accept' => 'application/json'])
            ->assertStatus(409)
            ->assertJsonPath('message', AttendanceCheckService::ALREADY_CHECKED_OUT_MESSAGE);

        $row->refresh();
        $this->assertTrue($row->check_out->equalTo($firstCheckOut));
        $this->assertSame('attendance/first-request.jpg', $row->check_out_photo);
        $this->assertSame([], Storage::disk(config('filesystems.default'))->allFiles());
    }

    // ── RSK-04: two check-ins on a row that has no check-in yet ──────────────

    public function test_check_in_on_an_existing_empty_row_that_loses_the_race_is_a_409_and_keeps_the_first(): void
    {
        Storage::fake(config('filesystems.default'));
        $employee = $this->employee();
        // A check-out-only correction leaves today's row without a check-in.
        $row = Attendance::create([
            'employee_id' => $employee->id,
            'date' => today(),
            'check_out' => today()->setTime(17, 0),
            'status' => AttendanceStatus::OnTime,
            'is_corrected' => true,
        ]);
        $firstCheckIn = today()->setTime(7, 59);
        Sanctum::actingAs($employee->user);

        $this->concurrentWriteAfterFirstRead($row, fn () => DB::table('attendances')->where('id', $row->id)->update([
            'check_in' => $firstCheckIn,
            'check_in_photo' => 'attendance/first-request.jpg',
        ]));

        $this->post('/api/v1/attendance/check-in', ['photo' => UploadedFile::fake()->image('selfie.jpg')], ['Accept' => 'application/json'])
            ->assertStatus(409)
            ->assertJsonPath('message', AttendanceCheckService::ALREADY_CHECKED_IN_MESSAGE);

        $row->refresh();
        $this->assertTrue($row->check_in->equalTo($firstCheckIn));
        $this->assertSame('attendance/first-request.jpg', $row->check_in_photo);
        $this->assertSame(1, Attendance::query()->count());
        $this->assertSame([], Storage::disk(config('filesystems.default'))->allFiles());
    }

    // ── Check-in over an approved leave ──────────────────────────────────────

    private function approvedLeaveRequestToday(Employee $employee): LeaveRequest
    {
        return LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type' => 'sick',
            'start_date' => today(),
            'end_date' => today(),
            'days_count' => 1,
            'reason' => 'Demam',
            'status' => 'approved',
        ]);
    }

    private function approvedLeaveToday(Employee $employee): LeaveRequest
    {
        $leave = $this->approvedLeaveRequestToday($employee);
        $leave->applyLeave();

        return $leave;
    }

    public function test_check_in_on_a_day_of_approved_leave_is_refused_and_keeps_the_leave(): void
    {
        $employee = $this->employee();
        $this->approvedLeaveToday($employee);
        Sanctum::actingAs($employee->user);

        $this->postJson('/api/v1/attendance/check-in')
            ->assertStatus(409)
            ->assertJsonPath('message', AttendanceCheckService::ON_LEAVE_MESSAGE);

        $row = Attendance::query()->where('employee_id', $employee->id)->sole();
        $this->assertSame(AttendanceStatus::Leave, $row->status);
        $this->assertNull($row->check_in);
        $this->assertSame('Cuti / Izin Disetujui: Demam', $row->notes);
    }

    public function test_presensi_page_titles_the_leave_refusal_as_leave_not_as_already_checked_in(): void
    {
        $employee = $this->employee();
        $this->approvedLeaveToday($employee);
        $this->actingAs($employee->user);

        Livewire::test(AbsenHariIni::class)
            ->call('processCheckIn')
            ->assertHasNoErrors()
            ->assertNotified('Sedang Cuti / Izin');

        $this->assertNull(Attendance::query()->where('employee_id', $employee->id)->sole()->check_in);
    }

    public function test_check_in_is_refused_when_the_leave_is_approved_while_the_check_in_runs(): void
    {
        $employee = $this->employee();
        $row = Attendance::create([
            'employee_id' => $employee->id,
            'date' => today(),
            'check_out' => today()->setTime(17, 0),
            'status' => AttendanceStatus::OnTime,
            'is_corrected' => true,
        ]);
        $this->approvedLeaveRequestToday($employee);
        Sanctum::actingAs($employee->user);

        // What LeaveRequest::applyLeave() writes on an existing row of the day.
        $this->concurrentWriteAfterFirstRead($row, fn () => DB::table('attendances')->where('id', $row->id)->update([
            'status' => AttendanceStatus::Leave->value,
            'notes' => 'Cuti / Izin Disetujui: Demam',
        ]));

        $this->postJson('/api/v1/attendance/check-in')
            ->assertStatus(409)
            ->assertJsonPath('message', AttendanceCheckService::ON_LEAVE_MESSAGE);

        $this->assertSame(AttendanceStatus::Leave, $row->fresh()->status);
        $this->assertNull($row->fresh()->check_in);
    }

    public function test_check_in_proceeds_over_the_leave_row_of_a_deleted_leave(): void
    {
        $employee = $this->employee();
        $this->approvedLeaveToday($employee)->delete();
        Sanctum::actingAs($employee->user);

        $this->postJson('/api/v1/attendance/check-in')->assertOk();

        $row = Attendance::query()->where('employee_id', $employee->id)->sole();
        $this->assertNotNull($row->check_in);
        $this->assertSame(AttendanceStatus::OnTime, $row->status);
    }

    public function test_check_in_proceeds_over_the_leave_row_of_a_leave_moved_off_today(): void
    {
        $employee = $this->employee();
        $this->approvedLeaveToday($employee)->update([
            'start_date' => today()->addDay(),
            'end_date' => today()->addDays(2),
        ]);
        Sanctum::actingAs($employee->user);

        $this->postJson('/api/v1/attendance/check-in')->assertOk();

        $row = Attendance::query()->where('employee_id', $employee->id)->whereDate('date', today())->sole();
        $this->assertNotNull($row->check_in);
        $this->assertSame(AttendanceStatus::OnTime, $row->status);
    }

    public function test_check_in_without_a_row_is_refused_as_leave_when_the_leave_row_is_inserted_while_it_runs(): void
    {
        $employee = $this->employee();
        $leave = $this->approvedLeaveRequestToday($employee);
        Sanctum::actingAs($employee->user);

        // The leave is applied right after the service finds no row of today, so its INSERT hits the unique index.
        $done = false;
        DB::listen(function ($query) use ($leave, &$done): void {
            if (! $done && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'from "attendances"')) {
                $done = true;
                $leave->applyLeave();
            }
        });

        $this->postJson('/api/v1/attendance/check-in')
            ->assertStatus(409)
            ->assertJsonPath('message', AttendanceCheckService::ON_LEAVE_MESSAGE);

        $row = Attendance::query()->where('employee_id', $employee->id)->sole();
        $this->assertSame(AttendanceStatus::Leave, $row->status);
        $this->assertNull($row->check_in);
    }

    // ── RSK-07, revised for D4: lateness of a pending check-in is the one of the schedule it was checked
    // in to; a roster edit of that schedule while it waits is refused ─

    public function test_approved_check_in_keeps_the_schedule_it_was_checked_in_to(): void
    {
        $this->company->policy()->update(['require_gps' => true, 'geofence_radius_meters' => 50]);
        CompanyLocation::create([
            'company_id' => $this->company->id,
            'name' => 'Outlet Satu',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'radius_meters' => 50,
            'is_active' => true,
        ]);
        $morning = Shift::create(['company_id' => $this->company->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '16:00', 'late_tolerance_minutes' => 0]);
        $late = Shift::create(['company_id' => $this->company->id, 'name' => 'Siang', 'start_time' => '10:00', 'end_time' => '18:00', 'late_tolerance_minutes' => 0]);
        $employee = $this->employee();
        $schedule = ShiftSchedule::create(['employee_id' => $employee->id, 'date' => today(), 'shift_id' => $morning->id]);
        $this->travelTo(today()->setTime(8, 30));
        Sanctum::actingAs($employee->user);

        // Outside the outlet: recorded and sent to the approver.
        $this->postJson('/api/v1/attendance/check-in', ['latitude' => -6.65, 'longitude' => 106.8])
            ->assertOk()
            ->assertJsonPath('requires_approval', true);

        $attendance = Attendance::query()->where('employee_id', $employee->id)->sole();
        $this->assertSame(AttendanceStatus::PendingApproval, $attendance->status);
        $this->assertSame(0, $attendance->late_minutes, 'A pending check-in is not late in reports before it is approved.');

        $this->assertSame(30, $attendance->check_in_late_minutes);

        // The roster edit while the check-in waits (today starting at 10:00) is refused: the schedule is locked.
        try {
            $schedule->update(['shift_id' => $late->id]);
            $this->fail('The schedule of a checked-in row must not change.');
        } catch (ValidationException $exception) {
            $this->assertSame([ShiftSchedule::LOCKED_MESSAGE], $exception->errors()['date']);
        }

        $this->assertSame(30, $attendance->fresh()->check_in_late_minutes);
        $this->assertSame($morning->id, $attendance->fresh()->shift_id);

        $approver = User::factory()->create();
        $approver->forceFill(['tenant_id' => $this->company->tenant_id])->save();
        $approver->assignRole('Approver');
        $this->grantDataAccessTo($approver, $this->company->id, $this->division->id);
        app(ApprovalFlowService::class)->approveStep($attendance, $approver);

        $attendance->refresh();
        $this->assertSame(AttendanceStatus::Late, $attendance->status);
        $this->assertSame(30, $attendance->late_minutes);
    }

    public function test_editing_the_check_in_drops_the_lateness_judged_at_check_in(): void
    {
        $employee = $this->employee();
        $attendance = Attendance::create([
            'employee_id' => $employee->id,
            'date' => today(),
            'check_in' => today()->setTime(8, 30),
            'status' => AttendanceStatus::PendingApproval,
            'check_in_late_minutes' => 30,
        ]);

        // A correction moves the check-in to the shift start; the approval judges that new time.
        $attendance->update(['check_in' => today()->setTime(8, 0)]);
        $this->assertNull($attendance->fresh()->check_in_late_minutes);

        $attendance->fresh()->applyGeofenceApproval();

        $this->assertSame(0, $attendance->fresh()->late_minutes);
        $this->assertSame(AttendanceStatus::OnTime, $attendance->fresh()->status);
    }
}
