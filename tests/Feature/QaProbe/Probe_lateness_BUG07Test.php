<?php

namespace Tests\Feature\QaProbe;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyPolicy;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * BUG-07 probe: a check-out 6 hours before the shift end must be marked as leaving early (pulang cepat).
 */
class Probe_lateness_BUG07Test extends TestCase
{
    use RefreshDatabase;

    public function test_check_out_six_hours_before_shift_end_is_flagged_as_early_leave(): void
    {
        $this->seed(RoleSeeder::class);
        Notification::fake();
        Queue::fake();
        Http::fake();

        $company = Company::create(['name' => 'PT Early', 'code' => 'EARLY', 'latitude' => -6.2, 'longitude' => 106.8166, 'is_active' => true]);
        $division = Division::create(['company_id' => $company->id, 'name' => 'Ops', 'code' => 'OPS', 'is_active' => true]);
        $company->policy->update([
            ...CompanyPolicy::flagsForSelfieMode('none'),
            'require_gps' => false,
            'work_start_time' => '08:00:00',
            'work_end_time' => '16:00:00',
            'late_tolerance_minutes' => 15,
        ]);

        $this->travelTo(Carbon::parse('2026-11-16 07:00:00'));
        $shift = Shift::create(['company_id' => $company->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '16:00', 'late_tolerance_minutes' => 15]);
        $user = User::factory()->create();
        $user->assignRole('Employee');
        $worker = Freelancer::create([
            'user_id' => $user->id, 'company_id' => $company->id, 'division_id' => $division->id,
            'full_name' => 'FL Early', 'start_date' => now()->subMonth(), 'end_date' => now()->addMonth(), 'status' => 'active',
        ]);
        ShiftSchedule::create(['date' => '2026-11-16', 'freelancer_id' => $worker->id, 'shift_id' => $shift->id]);
        Sanctum::actingAs($user);

        $this->travelTo(Carbon::parse('2026-11-16 08:00:00'));
        $this->postJson('/api/v1/attendance/check-in')->assertSuccessful();

        $this->travelTo(Carbon::parse('2026-11-16 10:00:00'));
        $checkOut = $this->postJson('/api/v1/attendance/check-out');

        $row = Attendance::where('freelancer_id', $worker->id)->firstOrFail();
        $earlyColumns = array_values(array_filter(Schema::getColumnListing('attendances'), fn (string $c): bool => str_contains($c, 'early')));

        fwrite(STDERR, "\nBUG07: ".json_encode([
            'http' => $checkOut->status(),
            'status' => $row->status?->value,
            'shift_id' => $row->shift_id,
            'check_out' => $row->check_out?->toDateTimeString(),
            'worked_minutes' => $row->workedMinutes(),
            'early_columns' => $earlyColumns,
        ])."\n");

        $checkOut->assertSuccessful();
        $this->assertSame('2026-11-16 10:00:00', $row->check_out?->toDateTimeString());

        // Correct business behaviour: leaving 6h before a 16:00 shift end is recorded somewhere (a column or a status).
        $this->assertTrue(
            $earlyColumns !== [] || $row->status !== AttendanceStatus::OnTime,
            'Check-out at 10:00 on a 08:00-16:00 shift keeps status Tepat Waktu with no early-leave field'
        );
    }
}
