<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\LeaveRequest;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Notifications\AttendanceReminderNotification;
use App\Notifications\Channels\OneSignalChannel;
use App\Services\AttendanceReminderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AttendanceReminderTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Division $jchicken;

    private Shift $morning;

    private CompanyLocation $outlet;

    private User $bayuUser;

    private Freelancer $bayu;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config(['services.onesignal.app_id' => 'app-id', 'services.onesignal.rest_api_key' => 'rest-key']);

        foreach (['Freelancer', 'Koordinator'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->company = Company::create(['name' => 'PT Franchise', 'code' => 'PFC', 'is_active' => true]);
        $this->jchicken = Division::create(['company_id' => $this->company->id, 'name' => 'JCHICKEN', 'is_active' => true]);
        $this->morning = Shift::create(['company_id' => $this->company->id, 'division_id' => $this->jchicken->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '17:00', 'late_tolerance_minutes' => 10]);
        $this->outlet = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $this->jchicken->id, 'name' => 'JChicken Ciledug', 'latitude' => -6.2, 'longitude' => 106.7]);

        [$this->bayuUser, $this->bayu] = $this->freelancer('Bayu Adillah');
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-06', 'shift_id' => $this->morning->id, 'company_location_id' => $this->outlet->id]);
    }

    public function test_each_reminder_goes_out_once_at_its_time(): void
    {
        $this->at('07:44');
        $this->assertSame(0, $this->sendDue()['shift_soon']);

        $this->at('07:45');
        $this->assertSame(1, $this->sendDue()['shift_soon']);
        $this->assertSame(0, $this->sendDue()['shift_soon'], 'Not sent twice.');
        $this->assertLastReminder('Shift Pagi mulai 08:00', 'Jangan lupa absen masuk di JChicken Ciledug.', 'check_in');

        $this->at('08:11');
        $this->assertSame(1, $this->sendDue()['not_checked_in']);
        $this->assertLastReminder('Anda belum absen masuk', 'Shift Pagi mulai 08:00. Absen sekarang di JChicken Ciledug.', 'check_in');

        Attendance::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-06', 'check_in' => '2026-11-06 08:20:00', 'status' => 'late', 'late_minutes' => 20]);

        $this->at('17:00');
        $this->assertSame(1, $this->sendDue()['time_to_leave']);
        $this->assertLastReminder('Waktunya pulang', 'Shift Pagi selesai 17:00. Jangan lupa absen pulang.', 'check_out');

        $this->at('19:05');
        $this->assertSame(1, $this->sendDue()['forgot_check_out']);
        $this->assertLastReminder('Anda belum absen pulang', 'Shift Pagi selesai 17:00. Absen pulang sekarang, atau ajukan koreksi bila sudah pulang.', 'check_out');
    }

    public function test_nothing_is_sent_once_checked_in_or_after_the_window(): void
    {
        Attendance::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-06', 'check_in' => '2026-11-06 07:40:00', 'status' => 'on_time']);

        $this->at('07:45');
        $this->assertSame(0, $this->sendDue()['shift_soon'], 'Already checked in.');

        Attendance::query()->update(['check_in' => null]);
        $this->at('08:45');
        $this->assertSame(0, $this->sendDue()['not_checked_in'], 'Too late to still remind: the window has passed.');
    }

    public function test_day_off_leave_ended_contract_and_office_hours_get_no_reminders(): void
    {
        [, $offDay] = $this->freelancer('Libur');
        ShiftSchedule::create(['freelancer_id' => $offDay->id, 'date' => '2026-11-06', 'is_day_off' => true]);

        [, $onLeave] = $this->freelancer('Cuti', ['shift_id' => $this->morning->id]);
        LeaveRequest::create(['freelancer_id' => $onLeave->id, 'leave_type' => 'sick', 'start_date' => '2026-11-05', 'end_date' => '2026-11-07', 'days_count' => 3, 'reason' => 'Sakit', 'status' => 'approved']);

        [, $ended] = $this->freelancer('Selesai', ['shift_id' => $this->morning->id, 'status' => 'completed']);
        [, $office] = $this->freelancer('Kantor');

        $this->at('08:11');
        $this->assertSame(1, $this->sendDue()['not_checked_in'], 'Only Bayu, whose shift is scheduled.');
    }

    public function test_a_default_shift_counts_when_there_is_no_schedule(): void
    {
        [$user] = $this->freelancer('Default', ['shift_id' => $this->morning->id]);

        $this->at('08:11');
        $this->sendDue();

        Notification::assertSentTo($user, AttendanceReminderNotification::class, fn (AttendanceReminderNotification $n): bool => $n->title === 'Anda belum absen masuk');
    }

    public function test_a_shift_after_midnight_is_reminded_the_next_morning(): void
    {
        $night = Shift::create(['company_id' => $this->company->id, 'name' => 'Malam', 'start_time' => '22:00', 'end_time' => '06:00']);
        [$user, $worker] = $this->freelancer('Malam');
        ShiftSchedule::create(['freelancer_id' => $worker->id, 'date' => '2026-11-05', 'shift_id' => $night->id]);
        Attendance::create(['freelancer_id' => $worker->id, 'date' => '2026-11-05', 'check_in' => '2026-11-05 21:58:00', 'status' => 'on_time']);

        $this->at('06:00');
        $this->assertSame(1, $this->sendDue()['time_to_leave']);
        Notification::assertSentTo($user, AttendanceReminderNotification::class, fn (AttendanceReminderNotification $n): bool => $n->body === 'Shift Malam selesai 06:00. Jangan lupa absen pulang.');
    }

    public function test_coordinators_hear_who_has_not_checked_in_in_their_brands(): void
    {
        $momoyo = Division::create(['company_id' => $this->company->id, 'name' => 'MOMOYO', 'is_active' => true]);
        $coordinator = User::factory()->create();
        $coordinator->assignRole('Koordinator');
        $coordinator->grantDataAccess(DataScope::Divisions, divisionIds: [$this->jchicken->id]);

        [, $other] = $this->freelancer('Orang Momoyo', ['division_id' => $momoyo->id, 'shift_id' => $this->morning->id]);
        [, $second] = $this->freelancer('David Saputra', ['shift_id' => $this->morning->id]);

        $this->at('08:11');
        $this->assertSame(1, $this->sendDue()['team_missing']);

        Notification::assertSentTo($coordinator, AttendanceReminderNotification::class, function (AttendanceReminderNotification $n): bool {
            return $n->title === '2 orang belum absen masuk'
                && str_contains($n->body, 'Bayu Adillah')
                && str_contains($n->body, 'David Saputra')
                && ! str_contains($n->body, 'Orang Momoyo')
                && $n->data === ['kind' => 'team_missing'];
        });
    }

    public function test_reminders_go_to_onesignal_by_user_id(): void
    {
        Http::fake(['api.onesignal.com/*' => Http::response(['id' => 'n-1'])]);
        $notification = new AttendanceReminderNotification('Waktunya pulang', 'Shift Pagi selesai 17:00.', ['kind' => 'attendance_reminder', 'action' => 'check_out']);

        $this->assertSame([OneSignalChannel::class], $notification->via($this->bayuUser));
        (new OneSignalChannel)->send($this->bayuUser, $notification);

        Http::assertSent(fn ($request): bool => $request['include_aliases'] === ['external_id' => [(string) $this->bayuUser->id]]
            && $request['headings']['id'] === 'Waktunya pulang'
            && $request['data'] === ['kind' => 'attendance_reminder', 'action' => 'check_out']);
    }

    public function test_the_command_runs_on_schedule(): void
    {
        $this->at('07:45');

        $this->artisan('attendance:send-reminders')->expectsOutputToContain('shift_soon=1')->assertSuccessful();
    }

    /**
     * @return array<string, int>
     */
    private function sendDue(): array
    {
        return app(AttendanceReminderService::class)->sendDue(now());
    }

    private function at(string $time): void
    {
        $this->travelTo(Carbon::parse("2026-11-06 {$time}:00"));
    }

    private function assertLastReminder(string $title, string $body, string $action): void
    {
        Notification::assertSentTo($this->bayuUser, AttendanceReminderNotification::class, fn (AttendanceReminderNotification $n): bool => $n->title === $title
            && $n->body === $body
            && $n->data['action'] === $action
            && $n->data['kind'] === 'attendance_reminder');
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: User, 1: Freelancer}
     */
    private function freelancer(string $name, array $attributes = []): array
    {
        $user = User::factory()->create(['name' => $name, 'is_active' => true]);
        $user->assignRole('Freelancer');
        $worker = Freelancer::create($attributes + [
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->jchicken->id,
            'full_name' => $name,
            'status' => 'active',
        ]);

        return [$user, $worker];
    }
}
