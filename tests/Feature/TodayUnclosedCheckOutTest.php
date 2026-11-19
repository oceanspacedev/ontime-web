<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Company;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Notifications\AttendanceReminderNotification;
use App\Services\AttendanceReminderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A day shift whose check-out was forgotten: once its check-out window (scheduled end + 6 h) has passed,
 * the Today card says "Kemarin belum absen pulang" and points to a correction, while today's check-in
 * stays allowed. Inside the window (a shift ending 23:00 until 05:00), and for an overnight shift still
 * running after midnight, the card and the reminder offer the check-out as usual instead.
 */
class TodayUnclosedCheckOutTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Division $division;

    private User $user;

    private Freelancer $worker;

    private Shift $evening;

    private Shift $night;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Notification::fake();
        config(['services.onesignal.app_id' => 'app-id', 'services.onesignal.rest_api_key' => 'rest-key', 'services.face.driver' => 'basic']);
        Role::findOrCreate('Freelancer', 'web');

        $this->company = Company::create(['name' => 'PT Franchise', 'code' => 'PFC', 'is_active' => true]);
        $this->company->policy()->update(['require_gps' => false, 'require_photo' => false, 'require_face_recognition' => false, 'require_liveness' => false]);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'JCHICKEN', 'is_active' => true]);
        $this->evening = Shift::create(['company_id' => $this->company->id, 'name' => 'Sore', 'start_time' => '15:00', 'end_time' => '23:00', 'late_tolerance_minutes' => 0]);
        $this->night = Shift::create(['company_id' => $this->company->id, 'name' => 'Malam', 'start_time' => '22:00', 'end_time' => '06:00', 'late_tolerance_minutes' => 0]);

        $this->user = User::factory()->create(['is_active' => true]);
        $this->user->assignRole('Freelancer');
        $this->worker = Freelancer::create(['user_id' => $this->user->id, 'company_id' => $this->company->id, 'division_id' => $this->division->id, 'full_name' => 'Bayu', 'status' => 'active']);
        Sanctum::actingAs($this->user);
    }

    public function test_forgotten_day_shift_check_out_shows_a_correction_notice_and_today_check_in_still_works(): void
    {
        $this->schedule('2026-11-16', $this->evening);
        $this->schedule('2026-11-17', $this->evening);
        $this->openRow('2026-11-16', '2026-11-16 15:00', $this->evening);

        // Past the check-out window of the 15:00 - 23:00 shift (23:00 + 6 h = 05:00).
        $this->travelTo(Carbon::parse('2026-11-17 05:30'));
        $this->getJson('/api/v1/attendance/today')
            ->assertOk()
            ->assertJsonPath('has_checked_in', false)
            ->assertJsonPath('attendance', null)
            ->assertJsonPath('unclosed_previous.date', '2026-11-16')
            ->assertJsonPath('unclosed_previous.check_in', '15:00')
            ->assertJsonPath('unclosed_previous.shift', 'Sore')
            ->assertJsonPath('unclosed_previous.correction_pending', false);

        // Today's check-in opens 60 minutes before today's 15:00 shift and is accepted then.
        $this->travelTo(Carbon::parse('2026-11-17 14:55'));
        $this->postJson('/api/v1/attendance/check-in', [])->assertSuccessful();

        $this->getJson('/api/v1/attendance/today')
            ->assertJsonPath('has_checked_in', true)
            ->assertJsonPath('attendance.date', '2026-11-17')
            ->assertJsonPath('unclosed_previous.date', '2026-11-16');
    }

    public function test_a_pending_correction_for_yesterday_is_flagged_so_the_app_does_not_offer_another(): void
    {
        $this->openRow('2026-11-16', '2026-11-16 15:00', $this->evening);
        AttendanceCorrection::create(['freelancer_id' => $this->worker->id, 'date' => '2026-11-16', 'corrected_check_out' => '2026-11-16 23:00:00', 'reason' => 'Lupa', 'status' => 'pending']);

        $this->travelTo(Carbon::parse('2026-11-17 09:00'));
        $this->getJson('/api/v1/attendance/today')->assertJsonPath('unclosed_previous.correction_pending', true);
    }

    public function test_no_notice_when_the_row_was_closed_rejected_or_is_older_than_a_week(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17 09:00'));
        Attendance::create(['freelancer_id' => $this->worker->id, 'date' => '2026-11-16', 'check_in' => '2026-11-16 15:00', 'check_out' => '2026-11-16 23:00', 'status' => 'on_time']);
        $this->getJson('/api/v1/attendance/today')->assertJsonPath('unclosed_previous', null);

        Attendance::query()->delete();
        Attendance::create(['freelancer_id' => $this->worker->id, 'date' => '2026-11-16', 'check_in' => '2026-11-16 15:00', 'status' => 'rejected']);
        $this->getJson('/api/v1/attendance/today')->assertJsonPath('unclosed_previous', null);

        Attendance::query()->delete();
        $this->openRow('2026-11-09', '2026-11-09 15:00', $this->evening);
        $this->getJson('/api/v1/attendance/today')->assertJsonPath('unclosed_previous', null);
    }

    public function test_a_shift_forgotten_before_a_day_off_still_shows_after_it(): void
    {
        $this->schedule('2026-11-14', $this->evening);
        ShiftSchedule::create(['freelancer_id' => $this->worker->id, 'date' => '2026-11-15', 'is_day_off' => true]);
        $this->openRow('2026-11-14', '2026-11-14 15:00', $this->evening);

        $this->travelTo(Carbon::parse('2026-11-16 09:00'));
        $this->getJson('/api/v1/attendance/today')
            ->assertJsonPath('unclosed_previous.date', '2026-11-14')
            ->assertJsonPath('unclosed_previous.check_in', '15:00');
    }

    public function test_a_pending_correction_gives_way_to_an_older_unclosed_row(): void
    {
        $this->openRow('2026-11-15', '2026-11-15 15:00', $this->evening);
        $this->openRow('2026-11-16', '2026-11-16 15:05', $this->evening);
        AttendanceCorrection::create(['freelancer_id' => $this->worker->id, 'date' => '2026-11-16', 'corrected_check_out' => '2026-11-16 23:00:00', 'reason' => 'Lupa', 'status' => 'pending']);

        $this->travelTo(Carbon::parse('2026-11-17 09:00'));
        $this->getJson('/api/v1/attendance/today')
            ->assertJsonPath('unclosed_previous.date', '2026-11-15')
            ->assertJsonPath('unclosed_previous.correction_pending', false);
    }

    public function test_an_unclosed_first_segment_shows_while_the_overnight_second_segment_is_offered_for_check_out(): void
    {
        $split = Shift::create(['company_id' => $this->company->id, 'name' => 'Split Malam', 'start_time' => '10:00', 'end_time' => '14:00', 'second_start_time' => '20:00', 'second_end_time' => '02:00', 'late_tolerance_minutes' => 0]);
        $this->schedule('2026-11-16', $split);
        $this->openRow('2026-11-16', '2026-11-16 10:00', $split);
        Attendance::create(['freelancer_id' => $this->worker->id, 'date' => '2026-11-16', 'segment' => 2, 'check_in' => '2026-11-16 20:00', 'status' => 'on_time', 'shift_id' => $split->id]);

        $this->travelTo(Carbon::parse('2026-11-17 01:00'));
        $this->getJson('/api/v1/attendance/today')
            ->assertJsonPath('attendance.segment', 2)
            ->assertJsonPath('unclosed_previous.date', '2026-11-16')
            ->assertJsonPath('unclosed_previous.check_in', '10:00');
    }

    public function test_today_says_whether_the_worker_is_off_schedule(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17 09:00'));
        $this->getJson('/api/v1/attendance/today')->assertJsonPath('schedule.is_off_schedule', true);

        $this->schedule('2026-11-17', $this->evening);
        $this->getJson('/api/v1/attendance/today')->assertJsonPath('schedule.is_off_schedule', false);

        ShiftSchedule::query()->update(['shift_id' => null, 'is_day_off' => true]);
        $this->getJson('/api/v1/attendance/today')->assertJsonPath('schedule.is_off_schedule', true);
    }

    public function test_an_overnight_shift_still_running_is_offered_for_check_out_not_as_a_notice(): void
    {
        $this->schedule('2026-11-16', $this->night);
        $this->openRow('2026-11-16', '2026-11-16 22:00', $this->night);

        $this->travelTo(Carbon::parse('2026-11-17 05:30'));
        $this->getJson('/api/v1/attendance/today')
            ->assertOk()
            ->assertJsonPath('has_checked_in', true)
            ->assertJsonPath('has_checked_out', false)
            ->assertJsonPath('attendance.date', '2026-11-16')
            ->assertJsonPath('unclosed_previous', null);
    }

    public function test_forgot_check_out_reminder_matches_the_card(): void
    {
        $service = app(AttendanceReminderService::class);

        // Day shift ending 23:00: the reminder at 01:00 lands on the next day, still inside the check-out
        // window (until 05:00), so it offers the check-out, as the card does.
        $this->schedule('2026-11-16', $this->evening);
        $this->openRow('2026-11-16', '2026-11-16 15:00', $this->evening);
        $this->travelTo(Carbon::parse('2026-11-17 01:00'));
        $this->assertSame(1, $service->sendDue(now())[AttendanceReminderService::FORGOT_CHECK_OUT]);
        Notification::assertSentTo($this->user, AttendanceReminderNotification::class, fn (AttendanceReminderNotification $n): bool => $n->title === 'Anda belum absen pulang'
            && $n->body === 'Shift Sore selesai 23:00. Absen pulang sekarang, atau ajukan koreksi bila sudah pulang.'
            && $n->data['action'] === 'check_out'
            && $n->data['date'] === '2026-11-16');
        $this->getJson('/api/v1/attendance/today')
            ->assertJsonPath('has_checked_in', true)
            ->assertJsonPath('attendance.date', '2026-11-16')
            ->assertJsonPath('unclosed_previous', null);
    }

    public function test_forgot_check_out_of_a_night_shift_still_asks_for_check_out(): void
    {
        $service = app(AttendanceReminderService::class);

        // Night shift ending 06:00: the reminder at 08:00 is the same day the shift ended; check-out is still offered.
        $this->schedule('2026-11-16', $this->night);
        $this->openRow('2026-11-16', '2026-11-16 22:00', $this->night);
        $this->travelTo(Carbon::parse('2026-11-17 08:00'));
        $this->assertSame(1, $service->sendDue(now())[AttendanceReminderService::FORGOT_CHECK_OUT]);
        Notification::assertSentTo($this->user, AttendanceReminderNotification::class, fn (AttendanceReminderNotification $n): bool => $n->title === 'Anda belum absen pulang'
            && $n->data['action'] === 'check_out');
        $this->getJson('/api/v1/attendance/today')->assertJsonPath('has_checked_in', true)->assertJsonPath('unclosed_previous', null);
    }

    private function schedule(string $date, Shift $shift): void
    {
        ShiftSchedule::create(['freelancer_id' => $this->worker->id, 'date' => $date, 'shift_id' => $shift->id]);
    }

    private function openRow(string $date, string $checkIn, Shift $shift): Attendance
    {
        return Attendance::create(['freelancer_id' => $this->worker->id, 'date' => $date, 'check_in' => $checkIn, 'status' => 'on_time', 'shift_id' => $shift->id]);
    }
}
