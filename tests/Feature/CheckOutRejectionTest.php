<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\DataScope;
use App\Filament\Resources\Attendances\Pages\EditAttendance;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Notifications\ApprovalResolvedNotification;
use App\Services\ApprovalFlowService;
use App\Services\AttendanceCheckService;
use App\Services\AttendanceLatenessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A check-out tapped outside the outlet waits for approval on its own: rejecting it voids only the
 * check-out (the settled check-in and its verdict stay, the day is present with a missing check-out),
 * approving it gives the check-in's verdict back. A check-out tapped while the check-in itself still
 * waits joins that round, and a rejection then rejects the whole row, as do rows pending from before.
 */
class CheckOutRejectionTest extends TestCase
{
    use RefreshDatabase;

    private const OUTLET_LAT = -6.2;

    private const OUTLET_LNG = 106.7;

    private const FAR_LAT = -6.3;

    private const FAR_LNG = 106.9;

    private Company $company;

    private Division $division;

    private CompanyLocation $outlet;

    private Employee $employee;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        config(['services.face.driver' => 'basic']);

        foreach (['Employee', 'Approver', 'Admin'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->company = Company::create(['name' => 'PT Outlet', 'code' => 'POT', 'is_active' => true]);
        $this->company->policy()->update(['require_gps' => true, 'geofence_radius_meters' => 100, 'require_photo' => false, 'require_face_recognition' => false, 'require_liveness' => false]);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'JCHICKEN', 'is_active' => true]);
        $this->outlet = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $this->division->id, 'name' => 'JChicken Ciledug', 'latitude' => self::OUTLET_LAT, 'longitude' => self::OUTLET_LNG, 'radius_meters' => 100]);
        $morning = Shift::create(['company_id' => $this->company->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '17:00', 'late_tolerance_minutes' => 5]);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('Employee');
        $this->employee = Employee::create([
            'user_id' => $user->id, 'company_id' => $this->company->id, 'division_id' => $this->division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Crew'])->id,
            'nip' => 'JC-001', 'full_name' => 'Yuyun', 'status' => 'active', 'join_date' => '2025-01-01',
        ]);
        ShiftSchedule::create(['employee_id' => $this->employee->id, 'date' => '2026-11-17', 'shift_id' => $morning->id, 'company_location_id' => $this->outlet->id]);

        $this->approver = User::factory()->create();
        $this->approver->assignRole('Approver');
        $this->grantDataAccessTo($this->approver, $this->company->id, $this->division->id);
    }

    public function test_rejecting_an_out_of_area_check_out_keeps_the_check_in(): void
    {
        $row = $this->settledCheckInThenFarCheckOut('2026-11-17 07:55:00');

        $this->assertSame(AttendanceStatus::PendingApproval, $row->status);
        $this->assertSame(Attendance::PUNCH_CHECK_OUT, $row->pending_punch);
        $this->assertSame('on_time', $row->status_before_check_out);
        $this->assertStringStartsWith('Absen pulang Yuyun menunggu persetujuan Anda.', (string) ($this->approver->notifications()->latest()->first()?->data['body'] ?? ''));

        app(ApprovalFlowService::class)->rejectStep($row, $this->approver, 'Pulang dari outlet lain');

        $row->refresh();
        $this->assertSame(AttendanceStatus::OnTime, $row->status);
        $this->assertSame('2026-11-17 07:55:00', $row->check_in->toDateTimeString());
        $this->assertNull($row->check_out);
        $this->assertNull($row->check_out_lat);
        $this->assertNull($row->check_out_lng);
        $this->assertNull($row->early_leave_minutes);
        $this->assertNotNull($row->check_out_rejected_at);
        $this->assertSame('Pulang dari outlet lain', $row->rejection_reason);
        $this->assertNull($row->pending_punch);
        $this->assertNull($row->status_before_check_out);

        // A new check-out tap is refused: the real end time goes through a correction.
        Sanctum::actingAs($this->employee->user);
        $this->checkOut(self::OUTLET_LAT, self::OUTLET_LNG)
            ->assertStatus(422)
            ->assertJsonPath('message', AttendanceCheckService::CHECK_OUT_REFUSED_MESSAGE);

        // The day is present, with a missing check-out, in the recap read the same evening and the next day.
        foreach (['2026-11-17 20:00:00', '2026-11-18 09:00:00'] as $moment) {
            $this->travelTo(Carbon::parse($moment));
            $line = $this->recapLine();
            $this->assertSame([1, 1, 0], [$line['present_days'], $line['missing_check_out_days'], $line['pending_days']], "Recap at {$moment}.");
        }
    }

    public function test_rejecting_a_check_out_also_clears_the_flags_it_wrote_and_approving_keeps_them(): void
    {
        $row = $this->settledCheckInThenFarCheckOut('2026-11-17 07:55:00');

        $this->assertTrue($row->is_out_of_bounds, 'The waiting check-out is flagged out of area for the approver.');
        $this->assertSame(['is_out_of_bounds' => false], array_intersect_key($row->checks_before_check_out, ['is_out_of_bounds' => true]));

        app(ApprovalFlowService::class)->rejectStep($row, $this->approver, 'Pulang dari outlet lain');

        $row->refresh();
        $this->assertFalse($row->is_out_of_bounds, 'Only the check-in remains, and it was inside the outlet.');
        $this->assertFalse($row->is_mock_location);
        $this->assertNull($row->checks_before_check_out);

        // Approved instead: the out-of-area check-out stays recorded with its flag.
        $this->travelTo(Carbon::parse('2026-11-17 07:00:00'));
        $row->forceFill(['check_in' => null, 'status' => AttendanceStatus::OnTime, 'check_out_rejected_at' => null, 'rejection_reason' => null])->save();
        $row->delete();
        $approved = $this->settledCheckInThenFarCheckOut('2026-11-17 07:55:00');
        app(ApprovalFlowService::class)->approveStep($approved, $this->approver);

        $approved->refresh();
        $this->assertTrue($approved->is_out_of_bounds);
        $this->assertNull($approved->checks_before_check_out);
    }

    public function test_a_rejected_check_out_gives_a_late_check_in_its_verdict_back(): void
    {
        $row = $this->settledCheckInThenFarCheckOut('2026-11-17 08:20:00');
        $this->assertSame('late', $row->status_before_check_out);

        app(ApprovalFlowService::class)->rejectStep($row, $this->approver, 'Lokasi tidak sesuai');

        $row->refresh();
        $this->assertSame(AttendanceStatus::Late, $row->status);
        $this->assertSame(20, (int) $row->late_minutes);
        $this->assertNull($row->check_out);
    }

    public function test_approving_an_out_of_area_check_out_restores_the_verdict(): void
    {
        $row = $this->settledCheckInThenFarCheckOut('2026-11-17 08:20:00');

        app(ApprovalFlowService::class)->approveStep($row, $this->approver);

        $row->refresh();
        $this->assertSame(AttendanceStatus::Late, $row->status);
        $this->assertSame(20, (int) $row->late_minutes);
        $this->assertSame('2026-11-17 17:05:00', $row->check_out->toDateTimeString());
        $this->assertNull($row->pending_punch);
        $this->assertNull($row->status_before_check_out);
        $this->assertNull($row->check_out_rejected_at);
    }

    public function test_an_admin_moving_the_check_in_during_a_check_out_round_is_kept_when_the_check_out_is_rejected(): void
    {
        $row = $this->settledCheckInThenFarCheckOut('2026-11-17 08:00:00');
        $this->assertSame('on_time', $row->status_before_check_out);

        $this->adminMovesCheckIn($row, '2026-11-17 08:30:00');

        $stored = Attendance::findOrFail($row->id);
        $this->assertSame(AttendanceStatus::PendingApproval, $stored->status);
        $this->assertSame('late', $stored->status_before_check_out);
        $this->assertSame(30, (int) $stored->late_minutes, 'The settled check-in reports its new lateness at once.');

        // The approver acts on the model loaded before the admin edit.
        app(ApprovalFlowService::class)->rejectStep($row, $this->approver, 'Lokasi tidak sesuai');

        $row->refresh();
        $this->assertSame(AttendanceStatus::Late, $row->status);
        $this->assertSame(30, (int) $row->late_minutes);
        $this->assertNull($row->check_out);
    }

    public function test_an_admin_moving_a_late_check_in_to_on_time_during_a_check_out_round_is_kept_when_it_is_approved(): void
    {
        $row = $this->settledCheckInThenFarCheckOut('2026-11-17 08:20:00');
        $this->assertSame('late', $row->status_before_check_out);

        $this->adminMovesCheckIn($row, '2026-11-17 07:58:00');

        app(ApprovalFlowService::class)->approveStep($row, $this->approver);

        $row->refresh();
        $this->assertSame(AttendanceStatus::OnTime, $row->status);
        $this->assertSame(0, (int) $row->late_minutes);
        $this->assertSame('2026-11-17 17:05:00', $row->check_out->toDateTimeString());
    }

    public function test_a_check_out_tapped_the_next_day_after_a_rejected_check_out_says_it_was_refused(): void
    {
        $row = $this->settledCheckInThenFarCheckOut('2026-11-17 07:55:00');
        app(ApprovalFlowService::class)->rejectStep($row, $this->approver, 'Pulang dari outlet lain');

        Sanctum::actingAs($this->employee->user);
        $this->travelTo(Carbon::parse('2026-11-18 00:15:00'));
        $this->checkOut(self::OUTLET_LAT, self::OUTLET_LNG)
            ->assertStatus(422)
            ->assertJsonPath('message', AttendanceCheckService::CHECK_OUT_REFUSED_MESSAGE);
    }

    public function test_the_worker_is_told_only_the_check_out_was_rejected(): void
    {
        $row = $this->settledCheckInThenFarCheckOut('2026-11-17 07:55:00');
        $this->fakePushNotifications();

        app(ApprovalFlowService::class)->rejectStep($row, $this->approver, 'Pulang dari outlet lain');

        $worker = $this->employee->user;
        Notification::assertSentTo($worker, ApprovalResolvedNotification::class, function (ApprovalResolvedNotification $notification) use ($worker): bool {
            $message = $notification->toOneSignal($worker)->toArray('test-app', [(string) $worker->id]);

            return $message['headings']['id'] === 'Absen pulang ditolak'
                && str_starts_with($message['contents']['id'], 'Absen pulang Anda ditolak: Pulang dari outlet lain.')
                && str_contains($message['contents']['id'], 'Absen masuk tetap tercatat')
                && $message['data']['kind'] === 'approval_resolved'
                && $message['data']['status'] === 'rejected';
        });
    }

    public function test_a_whole_row_rejection_keeps_the_generic_wording(): void
    {
        Sanctum::actingAs($this->employee->user);
        $this->travelTo(Carbon::parse('2026-11-17 07:55:00'));
        $rowId = $this->checkIn(self::FAR_LAT, self::FAR_LNG)->assertOk()->json('attendance.id');
        $this->fakePushNotifications();

        app(ApprovalFlowService::class)->rejectStep(Attendance::findOrFail($rowId), $this->approver, 'Lokasi tidak sesuai');

        $worker = $this->employee->user;
        Notification::assertSentTo($worker, ApprovalResolvedNotification::class, function (ApprovalResolvedNotification $notification) use ($worker): bool {
            $message = $notification->toOneSignal($worker)->toArray('test-app', [(string) $worker->id]);

            return str_starts_with($message['headings']['id'], 'Pengajuan ') && ! str_contains($message['contents']['id'], 'Absen masuk tetap tercatat');
        });
    }

    public function test_a_check_out_while_the_check_in_still_waits_joins_that_round_and_a_rejection_rejects_the_row(): void
    {
        Sanctum::actingAs($this->employee->user);
        $this->travelTo(Carbon::parse('2026-11-17 07:55:00'));
        $rowId = $this->checkIn(self::FAR_LAT, self::FAR_LNG)->assertOk()->assertJsonPath('attendance.status', 'pending_approval')->json('attendance.id');
        $this->assertSame(Attendance::PUNCH_CHECK_IN, Attendance::findOrFail($rowId)->pending_punch);

        $this->travelTo(Carbon::parse('2026-11-17 17:05:00'));
        $this->checkOut(self::FAR_LAT, self::FAR_LNG)->assertOk();

        $row = Attendance::findOrFail($rowId);
        $this->assertSame(Attendance::PUNCH_CHECK_IN, $row->pending_punch);
        $this->assertNull($row->status_before_check_out);
        $this->assertCount(1, $row->approvalSteps, 'The check-out waits in the round of the check-in.');

        app(ApprovalFlowService::class)->rejectStep($row, $this->approver, 'Lokasi tidak sesuai');

        $row->refresh();
        $this->assertSame(AttendanceStatus::Rejected, $row->status);
        $this->assertNotNull($row->check_out, 'The whole row is rejected, as before.');
        $this->assertNull($row->check_out_rejected_at);
        $this->assertNull($row->pending_punch);
    }

    public function test_a_legacy_pending_row_without_a_round_kind_is_rejected_whole(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17 18:00:00'));
        $row = Attendance::create([
            'employee_id' => $this->employee->id, 'date' => '2026-11-17',
            'check_in' => '2026-11-17 07:55:00', 'check_out' => '2026-11-17 17:05:00',
            'status' => AttendanceStatus::PendingApproval, 'is_out_of_bounds' => true, 'late_minutes' => 0,
            'company_location_id' => $this->outlet->id,
        ]);
        app(ApprovalFlowService::class)->generateSteps($row, 'geofence');

        app(ApprovalFlowService::class)->rejectStep($row, $this->approver, 'Lokasi tidak sesuai');

        $row->refresh();
        $this->assertSame(AttendanceStatus::Rejected, $row->status);
        $this->assertSame('2026-11-17 17:05:00', $row->check_out->toDateTimeString());
        $this->assertNull($row->check_out_rejected_at);
    }

    public function test_a_legacy_pending_row_without_a_round_kind_is_approved_as_before(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17 18:00:00'));
        $row = Attendance::create([
            'employee_id' => $this->employee->id, 'date' => '2026-11-17',
            'check_in' => '2026-11-17 08:20:00', 'check_out' => '2026-11-17 17:05:00',
            'status' => AttendanceStatus::PendingApproval, 'is_out_of_bounds' => true, 'late_minutes' => 0, 'check_in_late_minutes' => 20,
            'company_location_id' => $this->outlet->id,
        ]);
        app(ApprovalFlowService::class)->generateSteps($row, 'geofence');

        app(ApprovalFlowService::class)->approveStep($row, $this->approver);

        $row->refresh();
        $this->assertSame(AttendanceStatus::Late, $row->status);
        $this->assertSame(20, (int) $row->late_minutes);
    }

    /**
     * Record notifications instead of sending them, with the push channel configured so they are sent at all.
     */
    private function fakePushNotifications(): void
    {
        config(['services.onesignal.app_id' => 'test-app', 'services.onesignal.rest_api_key' => 'test-key']);
        Notification::fake();
    }

    /**
     * An admin moves the check-in in the attendance resource, which saves the time and then judges the
     * row again by force ({@see EditAttendance::afterSave()}).
     */
    private function adminMovesCheckIn(Attendance $row, string $checkIn): void
    {
        $edited = Attendance::findOrFail($row->id);
        $edited->update(['check_in' => $checkIn]);
        app(AttendanceLatenessService::class)->recalculate($edited, force: true);
    }

    /**
     * Check in at the outlet (settled at once), then check out at 17:05 far from it.
     */
    private function settledCheckInThenFarCheckOut(string $checkInAt): Attendance
    {
        Sanctum::actingAs($this->employee->user);
        $this->travelTo(Carbon::parse($checkInAt));
        $rowId = $this->checkIn(self::OUTLET_LAT, self::OUTLET_LNG)->assertOk()->json('attendance.id');
        $this->assertNull(Attendance::findOrFail($rowId)->pending_punch);

        $this->travelTo(Carbon::parse('2026-11-17 17:05:00'));
        $this->checkOut(self::FAR_LAT, self::FAR_LNG)->assertOk()->assertJsonPath('attendance.status', 'pending_approval');

        $row = Attendance::findOrFail($rowId);
        $this->assertCount(1, $row->approvalSteps);

        return $row;
    }

    /**
     * The worker's line of the November team recap, read by an admin who sees everyone.
     *
     * @return array<string, mixed>
     */
    private function recapLine(): array
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $admin->grantDataAccess(DataScope::All);
        Sanctum::actingAs($admin);

        $line = collect($this->getJson('/api/v1/team/recap?month=2026-11')->assertOk()->json('data'))->firstWhere('worker.name', 'Yuyun');
        $this->assertNotNull($line);

        return $line;
    }

    private function checkIn(float $latitude, float $longitude): TestResponse
    {
        return $this->postJson('/api/v1/attendance/check-in', ['latitude' => $latitude, 'longitude' => $longitude]);
    }

    private function checkOut(float $latitude, float $longitude): TestResponse
    {
        return $this->postJson('/api/v1/attendance/check-out', ['latitude' => $latitude, 'longitude' => $longitude]);
    }
}
