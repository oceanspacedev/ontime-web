<?php

namespace Tests\Feature\QaProbe;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Company;
use App\Models\CompanyPolicy;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * BUG-08 probe: approving a correction must not silently turn a geofence-rejected (Ditolak) or a
 * still-pending (Menunggu Persetujuan) attendance into Tepat Waktu.
 */
class Probe_lateness_BUG08Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Division $division;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Notification::fake();
        Queue::fake();
        Http::fake();

        $this->travelTo(Carbon::parse('2026-11-16 12:00:00'));

        $this->company = Company::create(['name' => 'PT Late', 'code' => 'LATE', 'latitude' => -6.2, 'longitude' => 106.8166, 'is_active' => true]);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Ops', 'code' => 'OPS', 'is_active' => true]);
        $this->company->policy->update([
            ...CompanyPolicy::flagsForSelfieMode('none'),
            'require_gps' => true,
            'work_start_time' => '08:00:00',
            'work_end_time' => '17:00:00',
            'late_tolerance_minutes' => 15,
        ]);
    }

    private function freelancer(): Freelancer
    {
        $user = User::factory()->create();
        $user->assignRole('Freelancer');

        return Freelancer::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'full_name' => 'FL '.$user->id,
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonth(),
            'status' => 'active',
        ]);
    }

    private function attendanceWithStatus(Freelancer $worker, AttendanceStatus $status): Attendance
    {
        $attendance = new Attendance;
        $attendance->forceFill([
            'freelancer_id' => $worker->id,
            'date' => '2026-11-16',
            'segment' => 1,
            'check_in' => Carbon::parse('2026-11-16 08:05:00'),
            'status' => $status,
            'late_minutes' => 0,
            'rejection_reason' => $status === AttendanceStatus::Rejected ? 'Di luar area kantor' : null,
        ])->save();

        return $attendance;
    }

    private function approveCorrection(Freelancer $worker, Attendance $attendance): void
    {
        $correction = AttendanceCorrection::create([
            'freelancer_id' => $worker->id,
            'attendance_id' => $attendance->id,
            'date' => '2026-11-16',
            'corrected_check_in' => '08:05',
            'reason' => 'Lupa / GPS bermasalah',
            'status' => 'pending',
            'current_step' => 1,
        ]);

        $correction->applyCorrection();
    }

    public function test_correction_does_not_erase_a_geofence_rejection(): void
    {
        $worker = $this->freelancer();
        $attendance = $this->attendanceWithStatus($worker, AttendanceStatus::Rejected);

        $this->approveCorrection($worker, $attendance);
        $attendance->refresh();

        fwrite(STDERR, "\nBUG08 rejected -> ".json_encode(['status' => $attendance->status?->value, 'late' => $attendance->late_minutes, 'rejection_reason' => $attendance->rejection_reason])."\n");

        $this->assertSame(AttendanceStatus::Rejected, $attendance->status, 'A geofence-rejected check-in must stay Ditolak; the correction only changes the time');
    }

    public function test_correction_does_not_bypass_a_pending_geofence_approval(): void
    {
        $worker = $this->freelancer();
        $attendance = $this->attendanceWithStatus($worker, AttendanceStatus::PendingApproval);

        $this->approveCorrection($worker, $attendance);
        $attendance->refresh();

        fwrite(STDERR, "\nBUG08 pending -> ".json_encode(['status' => $attendance->status?->value, 'late' => $attendance->late_minutes])."\n");

        $this->assertSame(AttendanceStatus::PendingApproval, $attendance->status, 'An out-of-area check-in still waiting for the approver must stay pending, not drop out of the approval inbox as Tepat Waktu');
    }
}
