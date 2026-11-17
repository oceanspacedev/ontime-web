<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyPolicy;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Services\AttendanceCheckService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Check engine rules: a rejected attendance stays rejected, a night shift belongs to the date it starts
 * on, and a split shift whose second segment ends after midnight is handled like a night shift.
 */
class QaCheckEngineTest extends TestCase
{
    use RefreshDatabase;

    private const D = '2026-11-16';

    private const D1 = '2026-11-17';

    private const HQ_LAT = -6.2;

    private const HQ_LNG = 106.8166;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        config([
            'services.whatsapp_gateway.url' => null,
            'services.onesignal.app_id' => null,
        ]);

        Notification::fake();
        $this->travelTo(Carbon::parse(self::D.' 07:00:00'));
    }

    public function test_check_out_on_a_rejected_attendance_is_refused_and_the_day_stays_rejected(): void
    {
        $employee = $this->employee(['require_gps' => true, 'geofence_radius_meters' => 100]);
        $attendance = Attendance::create([
            'employee_id' => $employee->id,
            'date' => self::D,
            'segment' => 1,
            'check_in' => Carbon::parse(self::D.' 08:00:00'),
            'status' => AttendanceStatus::Rejected,
            'rejection_reason' => 'Lokasi palsu.',
            'check_in_late_minutes' => 0,
            'is_mock_location' => true,
        ]);

        $this->at(self::D.' 17:00:00');
        $this->checkOut($employee, ['latitude' => -6.3, 'longitude' => 106.9, 'accuracy' => 8.5, 'is_mocked' => '0'])
            ->assertStatus(409)
            ->assertJsonPath('message', AttendanceCheckService::REJECTED_CHECK_OUT_MESSAGE);

        $attendance->refresh();
        $this->assertSame(AttendanceStatus::Rejected, $attendance->status);
        $this->assertNull($attendance->check_out);
        $this->assertSame(0, $attendance->approvalSteps()->count());
    }

    public function test_check_out_inside_the_area_on_a_rejected_attendance_is_refused_too(): void
    {
        $employee = $this->employee();
        $attendance = Attendance::create([
            'employee_id' => $employee->id,
            'date' => self::D,
            'segment' => 1,
            'check_in' => Carbon::parse(self::D.' 08:00:00'),
            'status' => AttendanceStatus::Rejected,
        ]);

        $this->at(self::D.' 17:00:00');
        $this->checkOut($employee)->assertStatus(409);

        $this->assertSame(AttendanceStatus::Rejected, $attendance->fresh()->status);
    }

    public function test_first_night_shift_check_in_after_midnight_belongs_to_the_evening_before_and_is_late(): void
    {
        $employee = $this->employee(defaultShift: $this->shift(['start_time' => '22:00', 'end_time' => '06:00']));

        $this->at(self::D1.' 00:30:00');
        $rowId = $this->checkIn($employee)
            ->assertOk()
            ->assertJsonPath('attendance.date', self::D)
            ->json('attendance.id');

        $row = Attendance::findOrFail($rowId);
        $this->assertSame(AttendanceStatus::Late, $row->status);
        $this->assertSame(150, (int) $row->late_minutes);
        $this->assertSame(150, (int) $row->check_in_late_minutes);

        $this->at(self::D1.' 06:00:00');
        $this->checkOut($employee)->assertOk()->assertJsonPath('attendance.id', $rowId);

        $this->at(self::D1.' 21:55:00');
        $this->checkIn($employee)->assertOk()->assertJsonPath('attendance.date', self::D1);
        $this->assertSame(2, Attendance::where('employee_id', $employee->id)->count());
    }

    public function test_early_morning_check_in_after_a_day_shift_stays_on_today(): void
    {
        $employee = $this->employee(defaultShift: $this->shift(['start_time' => '05:00', 'end_time' => '13:00']));

        $this->at(self::D1.' 04:50:00');
        $this->checkIn($employee)->assertOk()->assertJsonPath('attendance.date', self::D1);
    }

    public function test_check_in_after_midnight_following_a_closed_night_shift_is_not_moved_to_yesterday(): void
    {
        $employee = $this->employee(defaultShift: $this->shift(['start_time' => '22:00', 'end_time' => '06:00']));

        $this->at(self::D.' 22:00:00');
        $this->checkIn($employee)->assertOk();
        $this->at(self::D.' 23:00:00');
        $this->checkOut($employee)->assertOk();

        // Not moved to yesterday: it is a check-in to today's 22:00 shift, which opens an hour before.
        $this->at(self::D1.' 00:30:00');
        $this->checkIn($employee)->assertUnprocessable()->assertJsonPath('message', 'Shift dimulai pukul 22:00. Absen masuk dibuka pukul 21:00.');
        $this->assertSame(1, Attendance::query()->where('employee_id', $employee->id)->count());

        $this->at(self::D1.' 21:30:00');
        $this->checkIn($employee)->assertOk()->assertJsonPath('attendance.date', self::D1);
    }

    public function test_split_shift_with_second_segment_past_midnight_keeps_segment_two_open_until_closed(): void
    {
        $employee = $this->employee(defaultShift: $this->shift(['start_time' => '10:00', 'end_time' => '14:00', 'second_start_time' => '18:00', 'second_end_time' => '00:30']));

        $this->at(self::D.' 10:00:00');
        $this->checkIn($employee)->assertOk();
        $this->at(self::D.' 14:00:00');
        $this->checkOut($employee)->assertOk();
        $this->at(self::D.' 18:00:00');
        $segmentTwoId = $this->checkIn($employee)->assertOk()->json('attendance.id');

        $this->at(self::D1.' 00:20:00');
        $this->actAs($employee);
        $this->getJson('/api/v1/attendance/today')
            ->assertOk()
            ->assertJsonPath('has_checked_in', true)
            ->assertJsonPath('attendance.id', $segmentTwoId);
        $this->checkIn($employee)->assertStatus(409)->assertJsonPath('message', AttendanceCheckService::OPEN_OVERNIGHT_MESSAGE);

        $this->at(self::D1.' 00:30:00');
        $this->checkOut($employee)->assertOk()->assertJsonPath('attendance.id', $segmentTwoId);

        $this->at(self::D1.' 10:00:00');
        $this->checkIn($employee)->assertOk()->assertJsonPath('attendance.date', self::D1)->assertJsonPath('attendance.segment', 1);
    }

    public function test_early_check_in_to_a_morning_shift_after_a_missed_night_shift_stays_on_today(): void
    {
        $night = $this->shift(['start_time' => '22:00', 'end_time' => '06:00']);
        $morning = $this->shift(['start_time' => '06:00', 'end_time' => '14:00']);
        $employee = $this->employee();
        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::D, 'shift_id' => $night->id]);
        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::D1, 'shift_id' => $morning->id]);

        $this->at(self::D1.' 05:50:00');
        $rowId = $this->checkIn($employee)
            ->assertOk()
            ->assertJsonPath('attendance.date', self::D1)
            ->json('attendance.id');

        $row = Attendance::findOrFail($rowId);
        $this->assertSame(AttendanceStatus::OnTime, $row->status);
        $this->assertSame(0, (int) $row->late_minutes);
        $this->assertSame(0, Attendance::where('employee_id', $employee->id)->whereDate('date', self::D)->count());
    }

    public function test_late_night_shift_check_in_still_goes_to_yesterday_when_today_has_a_morning_shift(): void
    {
        $night = $this->shift(['start_time' => '22:00', 'end_time' => '06:00']);
        $morning = $this->shift(['start_time' => '08:00', 'end_time' => '16:00']);
        $employee = $this->employee();
        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::D, 'shift_id' => $night->id]);
        ShiftSchedule::create(['employee_id' => $employee->id, 'date' => self::D1, 'shift_id' => $morning->id]);

        $this->at(self::D1.' 00:30:00');
        $this->checkIn($employee)->assertOk()->assertJsonPath('attendance.date', self::D);
    }

    public function test_split_shift_second_segment_can_be_checked_in_after_a_rejected_first_segment(): void
    {
        $employee = $this->employee(defaultShift: $this->shift(['start_time' => '08:00', 'end_time' => '12:00', 'second_start_time' => '17:00', 'second_end_time' => '21:00']));
        $first = Attendance::create([
            'employee_id' => $employee->id,
            'date' => self::D,
            'segment' => 1,
            'check_in' => Carbon::parse(self::D.' 08:00:00'),
            'status' => AttendanceStatus::Rejected,
            'rejection_reason' => 'Lokasi palsu.',
        ]);

        $this->at(self::D.' 12:00:00');
        $this->checkOut($employee)->assertStatus(409)->assertJsonPath('message', AttendanceCheckService::REJECTED_CHECK_OUT_MESSAGE);

        $this->at(self::D.' 17:00:00');
        $secondId = $this->checkIn($employee)
            ->assertOk()
            ->assertJsonPath('attendance.segment', 2)
            ->json('attendance.id');

        $this->at(self::D.' 21:00:00');
        $this->checkOut($employee)->assertOk()->assertJsonPath('attendance.id', $secondId);

        $first->refresh();
        $this->assertSame(AttendanceStatus::Rejected, $first->status);
        $this->assertNull($first->check_out);
    }

    /**
     * @param  array<string, mixed>  $policy
     */
    private function employee(array $policy = [], ?Shift $defaultShift = null): Employee
    {
        $company = Company::create([
            'name' => 'PT Check '.fake()->unique()->word(),
            'code' => strtoupper(fake()->unique()->lexify('?????')),
            'latitude' => self::HQ_LAT,
            'longitude' => self::HQ_LNG,
            'is_active' => true,
        ]);
        $company->policy->update([
            ...CompanyPolicy::flagsForSelfieMode('none'),
            'require_gps' => false,
            'work_start_time' => '08:00:00',
            'work_end_time' => '17:00:00',
            'late_tolerance_minutes' => 15,
            ...$policy,
        ]);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('Employee');
        $division = Division::create(['company_id' => $company->id, 'name' => 'OPS', 'code' => 'OPS'.$company->id, 'is_active' => true]);

        $employee = Employee::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'division_id' => $division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_number' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Kasir'])->id,
            'nip' => 'EMP'.$user->id,
            'full_name' => 'Pekerja Uji',
            'join_date' => '2026-01-01',
            'status' => 'permanent',
        ]);

        if ($defaultShift) {
            $defaultShift->update(['company_id' => $company->id]);
            $employee->update(['shift_id' => $defaultShift->id]);
        }

        return $employee->fresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function shift(array $attributes): Shift
    {
        $company = Company::create(['name' => 'PT Shift '.fake()->unique()->word(), 'code' => strtoupper(fake()->unique()->lexify('?????')), 'is_active' => true]);

        return Shift::create(['company_id' => $company->id, 'name' => 'S', 'code' => 'S'.fake()->unique()->numberBetween(1, 9999), 'is_active' => true, ...$attributes]);
    }

    private function actAs(Employee $employee): void
    {
        Sanctum::actingAs(User::findOrFail($employee->user_id));
    }

    private function at(string $dateTime): void
    {
        $this->travelTo(Carbon::parse($dateTime));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function checkIn(Employee $employee, array $data = []): TestResponse
    {
        $this->actAs($employee);

        return $this->post('/api/v1/attendance/check-in', $data, ['Accept' => 'application/json']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function checkOut(Employee $employee, array $data = []): TestResponse
    {
        $this->actAs($employee);

        return $this->post('/api/v1/attendance/check-out', $data, ['Accept' => 'application/json']);
    }
}
