<?php

namespace Tests\Feature;

use App\Filament\Pages\AbsenHariIni;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Services\AttendanceCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A closing shift that ends late in the evening is checked out live after midnight, on its own business
 * date, until its scheduled end plus 6 hours. Past that window (or once the worker checked in to a later
 * shift) the row is a missing check-out: a check-out tap answers with the correction message, and the next
 * morning's check-in is never held back by it.
 */
class ClosingShiftCheckOutTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Division $division;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        config(['services.face.driver' => 'basic']);
        Role::findOrCreate('Employee', 'web');

        $this->company = Company::create(['name' => 'PT Closing', 'code' => 'PCL', 'is_active' => true]);
        $this->company->policy()->update(['require_gps' => false, 'require_photo' => false, 'require_face_recognition' => false, 'require_liveness' => false]);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'LUUCA', 'is_active' => true]);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('Employee');
        $this->employee = Employee::create([
            'user_id' => $user->id, 'company_id' => $this->company->id, 'division_id' => $this->division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Crew'])->id,
            'nip' => 'CL-001', 'full_name' => 'Silma', 'status' => 'active', 'join_date' => '2025-01-01',
        ]);
        Sanctum::actingAs($user);
    }

    public function test_a_closing_shift_is_checked_out_after_midnight_on_its_business_date(): void
    {
        $this->schedule('2026-11-17', $this->shift('Closing', '14:00', '22:30'));

        $this->at('2026-11-17 13:55:00');
        $rowId = $this->checkIn()->assertOk()->json('attendance.id');

        $this->at('2026-11-18 00:10:00');
        $this->getJson('/api/v1/attendance/today')
            ->assertOk()
            ->assertJsonPath('has_checked_in', true)
            ->assertJsonPath('has_checked_out', false)
            ->assertJsonPath('attendance.id', $rowId)
            ->assertJsonPath('unclosed_previous', null);
        $this->checkOut()->assertOk()->assertJsonPath('attendance.id', $rowId);

        $row = Attendance::findOrFail($rowId);
        $this->assertSame('2026-11-17', $row->date->toDateString());
        $this->assertSame('2026-11-18 00:10:00', $row->check_out->toDateTimeString());
        $this->assertSame(0, (int) $row->early_leave_minutes);
        $this->assertSame(615, $row->workedMinutes());
        $this->assertSame(1, Attendance::where('employee_id', $this->employee->id)->count());
    }

    public function test_the_window_closes_six_hours_after_the_scheduled_end_to_the_second(): void
    {
        $closing = $this->shift('Long Shift', '08:45', '22:30');
        $this->schedule('2026-11-17', $closing);
        $this->schedule('2026-11-18', $closing);

        $this->at('2026-11-17 08:40:00');
        $this->checkIn()->assertOk();
        $this->at('2026-11-18 04:30:00');
        $this->checkOut()->assertOk();

        // The next day the same shift, left open past 04:30:00.
        $this->at('2026-11-18 08:40:00');
        $this->checkIn()->assertOk();
        $this->at('2026-11-19 04:30:01');
        $this->checkOut()
            ->assertStatus(422)
            ->assertJsonPath('message', AttendanceCheckService::FORGOTTEN_CHECK_OUT_MESSAGE);
        $this->getJson('/api/v1/attendance/today')
            ->assertOk()
            ->assertJsonPath('has_checked_in', false)
            ->assertJsonPath('unclosed_previous.date', '2026-11-18')
            ->assertJsonPath('unclosed_previous.check_in', '08:40')
            ->assertJsonPath('unclosed_previous.shift', 'Long Shift');

        $this->assertNull(Attendance::where('employee_id', $this->employee->id)->whereDate('date', '2026-11-18')->sole()->check_out);
    }

    public function test_a_shift_ending_2330_closes_at_0530(): void
    {
        $this->schedule('2026-11-21', $this->shift('LM12', '11:30', '23:30'));

        $this->at('2026-11-21 11:30:00');
        $rowId = $this->checkIn()->assertOk()->json('attendance.id');
        $this->at('2026-11-22 05:30:00');
        $this->checkOut()->assertOk()->assertJsonPath('attendance.id', $rowId);

        $this->assertSame('2026-11-21', Attendance::findOrFail($rowId)->date->toDateString());
    }

    public function test_the_second_part_of_a_split_shift_is_checked_out_after_midnight(): void
    {
        $this->schedule('2026-11-17', $this->shift('Split GC', '07:45', '13:00', '20:00', '23:00'));

        $this->at('2026-11-17 07:40:00');
        $this->checkIn()->assertOk();
        $this->at('2026-11-17 13:00:00');
        $this->checkOut()->assertOk();
        $this->at('2026-11-17 19:55:00');
        $this->checkIn()->assertOk()->assertJsonPath('attendance.segment', 2);

        $this->at('2026-11-18 00:20:00');
        $this->checkOut()->assertOk()->assertJsonPath('attendance.segment', 2);

        $second = Attendance::where('employee_id', $this->employee->id)->where('segment', 2)->sole();
        $this->assertSame('2026-11-17', $second->date->toDateString());
        $this->assertSame('2026-11-18 00:20:00', $second->check_out->toDateTimeString());
        $this->assertSame(0, (int) $second->early_leave_minutes);
    }

    public function test_a_forgotten_day_shift_check_out_gets_the_correction_message_the_next_morning_and_later(): void
    {
        $day = $this->shift('Pagi', '08:00', '17:00');
        $this->schedule('2026-11-17', $day);

        $this->at('2026-11-17 08:00:00');
        $this->checkIn()->assertOk();

        $this->at('2026-11-18 07:30:00');
        $this->checkOut()->assertStatus(422)->assertJsonPath('message', AttendanceCheckService::FORGOTTEN_CHECK_OUT_MESSAGE);

        // 30 hours after the check-in, well past the old 20-hour look-back.
        $this->at('2026-11-18 14:00:00');
        $this->checkOut()->assertStatus(422)->assertJsonPath('message', AttendanceCheckService::FORGOTTEN_CHECK_OUT_MESSAGE);
        $this->getJson('/api/v1/attendance/today')->assertJsonPath('unclosed_previous.date', '2026-11-17');
    }

    public function test_the_next_mornings_check_in_is_not_blocked_and_ends_the_closing_window(): void
    {
        $this->schedule('2026-11-17', $this->shift('Closing', '14:00', '22:30'));
        $this->schedule('2026-11-18', $this->shift('Subuh', '05:00', '13:00'));

        $this->at('2026-11-17 14:00:00');
        $closingId = $this->checkIn()->assertOk()->json('attendance.id');

        // 04:10 is still inside the closing row's window (until 04:30), but its scheduled end has passed.
        $this->at('2026-11-18 04:10:00');
        $morningId = $this->checkIn()->assertOk()->assertJsonPath('attendance.date', '2026-11-18')->json('attendance.id');
        $this->assertNotSame($closingId, $morningId);

        // From now on the closing row is a missing check-out: a check-out tap closes the morning shift.
        $this->getJson('/api/v1/attendance/today')
            ->assertJsonPath('attendance.id', $morningId)
            ->assertJsonPath('unclosed_previous.date', '2026-11-17');
        $this->at('2026-11-18 13:00:00');
        $this->checkOut()->assertOk()->assertJsonPath('attendance.id', $morningId);
        $this->assertNull(Attendance::findOrFail($closingId)->check_out);
    }

    public function test_a_morning_check_in_after_a_closing_shift_closed_late_is_allowed(): void
    {
        $this->schedule('2026-11-17', $this->shift('Closing', '14:00', '22:30'));
        $this->schedule('2026-11-18', $this->shift('Pagi', '08:00', '17:00'));

        $this->at('2026-11-17 14:00:00');
        $this->checkIn()->assertOk();
        $this->at('2026-11-18 07:30:00');
        $this->checkIn()->assertOk()->assertJsonPath('attendance.date', '2026-11-18');
    }

    public function test_a_night_shift_is_unchanged(): void
    {
        $night = $this->shift('Malam', '22:00', '06:00');
        $this->schedule('2026-11-17', $night);
        $this->schedule('2026-11-18', $night);

        $this->at('2026-11-17 22:00:00');
        $rowId = $this->checkIn()->assertOk()->json('attendance.id');

        // After midnight the night row is still open: a new check-in is refused, the check-out is offered.
        $this->at('2026-11-18 01:00:00');
        $this->checkIn()->assertStatus(409)->assertJsonPath('message', AttendanceCheckService::OPEN_OVERNIGHT_MESSAGE);

        $this->at('2026-11-18 06:05:00');
        $this->checkOut()->assertOk()->assertJsonPath('attendance.id', $rowId);

        $row = Attendance::findOrFail($rowId);
        $this->assertSame('2026-11-17', $row->date->toDateString());
        $this->assertSame(485, $row->workedMinutes());
    }

    public function test_the_web_panel_offers_and_closes_the_same_row_after_midnight(): void
    {
        $this->schedule('2026-11-17', $this->shift('Closing', '14:00', '22:30'));

        $this->at('2026-11-17 13:55:00');
        $rowId = $this->checkIn()->assertOk()->json('attendance.id');

        $this->at('2026-11-18 00:10:00');
        $this->actingAs($this->employee->user);
        $component = Livewire::test(AbsenHariIni::class);
        $this->assertSame($rowId, $component->instance()->getTodayAttendanceProperty()?->id);

        $component->call('processCheckOut')
            ->assertReturned(fn (array $result): bool => $result['ok'] === true)
            ->assertNotified('Check Out Berhasil!');

        $this->assertSame('2026-11-18 00:10:00', Attendance::findOrFail($rowId)->check_out->toDateTimeString());
    }

    private function shift(string $name, string $start, string $end, ?string $secondStart = null, ?string $secondEnd = null): Shift
    {
        return Shift::create([
            'company_id' => $this->company->id, 'name' => $name, 'start_time' => $start, 'end_time' => $end,
            'second_start_time' => $secondStart, 'second_end_time' => $secondEnd, 'late_tolerance_minutes' => 5,
        ]);
    }

    private function schedule(string $date, Shift $shift): ShiftSchedule
    {
        return ShiftSchedule::create(['employee_id' => $this->employee->id, 'date' => $date, 'shift_id' => $shift->id]);
    }

    private function at(string $moment): void
    {
        $this->travelTo(Carbon::parse($moment));
    }

    private function checkIn(): TestResponse
    {
        return $this->postJson('/api/v1/attendance/check-in', []);
    }

    private function checkOut(): TestResponse
    {
        return $this->postJson('/api/v1/attendance/check-out', []);
    }
}
