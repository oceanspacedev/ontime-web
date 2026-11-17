<?php

namespace Tests\Feature\QaProbe;

use App\Enums\AttendanceStatus;
use App\Filament\Resources\Attendances\Pages\EditAttendance;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyPolicy;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * BUG-02: editing only the check-in time in the Data Absensi "Override" form keeps the old status and lateness.
 */
class Probe_lateness_BUG02Test extends TestCase
{
    use RefreshDatabase;

    public function test_moving_check_in_late_through_override_form_marks_attendance_late(): void
    {
        $this->seed(RoleSeeder::class);
        Notification::fake();
        Queue::fake();
        Http::fake();

        $company = Company::create(['name' => 'PT Late', 'code' => 'LATE', 'latitude' => -6.2, 'longitude' => 106.8166, 'is_active' => true]);
        $division = Division::create(['company_id' => $company->id, 'name' => 'Ops', 'code' => 'OPS', 'is_active' => true]);
        $company->policy->update([...CompanyPolicy::flagsForSelfieMode('none'), 'require_gps' => false, 'late_tolerance_minutes' => 15]);
        $shift = Shift::create(['company_id' => $company->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '16:00', 'late_tolerance_minutes' => 15]);

        $workerUser = User::factory()->create();
        $workerUser->assignRole('Employee');
        $worker = Freelancer::create([
            'user_id' => $workerUser->id, 'company_id' => $company->id, 'division_id' => $division->id,
            'full_name' => 'FL Probe', 'start_date' => '2026-10-01', 'end_date' => '2026-12-31',
            'status' => 'active', 'shift_id' => $shift->id,
        ]);

        $this->travelTo(Carbon::parse('2026-11-17 07:55:00'));
        Sanctum::actingAs($workerUser);
        $this->postJson('/api/v1/attendance/check-in')->assertSuccessful();

        $row = Attendance::where('freelancer_id', $worker->id)->firstOrFail();
        $this->assertSame(AttendanceStatus::OnTime, $row->status, 'precondition: 07:55 check-in is on time');

        $admin = User::factory()->create();
        $admin->assignRole('Superadmin');
        $this->actingAs($admin);

        Livewire::test(EditAttendance::class, ['record' => $row->id])
            ->fillForm(['check_in' => '2026-11-17 09:30:00'])
            ->call('save')
            ->assertHasNoFormErrors();

        $row->refresh();
        fwrite(STDERR, "\nBUG-02 after override: ".json_encode(['check_in' => (string) $row->check_in, 'status' => $row->status?->value, 'late' => $row->late_minutes, 'snap' => $row->check_in_late_minutes])."\n");

        $this->assertSame('2026-11-17 09:30:00', $row->check_in->format('Y-m-d H:i:s'));
        $this->assertSame(AttendanceStatus::Late, $row->status, 'A 09:30 arrival on an 08:00 shift must not stay Tepat Waktu.');
        $this->assertGreaterThan(0, (int) $row->late_minutes);
    }
}
