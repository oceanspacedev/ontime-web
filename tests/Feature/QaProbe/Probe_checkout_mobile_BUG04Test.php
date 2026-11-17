<?php

namespace Tests\Feature\QaProbe;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\Shift;
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
 * BUG-04: a 15:00-23:00 shift whose check-out was forgotten. Decided: at 01:00 the next day the
 * forgot_check_out push and the Today card both say "Kemarin belum absen pulang" and point to a
 * correction; today's check-in stays allowed, but only from 60 minutes before today's shift.
 */
class Probe_checkout_mobile_BUG04Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Division $division;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.onesignal.app_id' => null, 'services.onesignal.rest_api_key' => null, 'services.face.driver' => 'basic']);
        Http::fake();
        Role::findOrCreate('Employee', 'web');
        $this->company = Company::create(['name' => 'PT P', 'code' => 'PP', 'is_active' => true]);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Ops', 'is_active' => true]);
        $this->company->policy()->update(['require_gps' => false, 'require_photo' => false, 'require_face_recognition' => false, 'require_liveness' => false]);
    }

    private function employee(): Employee
    {
        $shift = Shift::create(['company_id' => $this->company->id, 'name' => 'Sore', 'code' => 'SORE', 'start_time' => '15:00', 'end_time' => '23:00', 'late_tolerance_minutes' => 0]);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('Employee');

        return Employee::create(['user_id' => $user->id, 'company_id' => $this->company->id, 'division_id' => $this->division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Staff'])->id,
            'nip' => 'N'.$user->id, 'full_name' => 'E'.$user->id, 'status' => 'active', 'shift_id' => $shift->id]);
    }

    public function test_forgotten_evening_check_out_points_to_a_correction_and_no_early_check_in_is_recorded(): void
    {
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-11-16 15:00'));
        $employee = $this->employee();
        Sanctum::actingAs($employee->user);
        $this->postJson('/api/v1/attendance/check-in', [])->assertSuccessful();

        // 01:00 the next day: the forgot_check_out push (end 23:00 + 120 min) asks for a correction.
        $this->travelTo(Carbon::parse('2026-11-17 01:00'));
        config(['services.onesignal.app_id' => 'app-id', 'services.onesignal.rest_api_key' => 'rest-key']);
        $sent = app(AttendanceReminderService::class)->sendDue(now());
        $this->assertSame(1, $sent[AttendanceReminderService::FORGOT_CHECK_OUT]);
        Notification::assertSentTo($employee->user, AttendanceReminderNotification::class, fn (AttendanceReminderNotification $notification): bool => $notification->title === 'Kemarin belum absen pulang'
            && $notification->data['action'] === 'correction');

        // The Today card agrees with that push: a notice about yesterday, and today's check-in stays allowed.
        $this->getJson('/api/v1/attendance/today')
            ->assertOk()
            ->assertJsonPath('has_checked_in', false)
            ->assertJsonPath('unclosed_previous.date', '2026-11-16')
            ->assertJsonPath('unclosed_previous.check_in', '15:00')
            ->assertJsonPath('unclosed_previous.correction_pending', false);

        // A check-in at 01:00 for today's 15:00 shift is refused (check-in opens 14:00), so nothing is recorded on time.
        $this->postJson('/api/v1/attendance/check-in', [])->assertStatus(422);
        $this->assertNull(Attendance::where('employee_id', $employee->id)->whereDate('date', '2026-11-17')->first());
    }
}
