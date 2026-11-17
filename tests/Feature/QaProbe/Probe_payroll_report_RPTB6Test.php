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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Probe RPT-B6: overnight check-in after midnight must be judged against the evening shift it belongs to.
 */
class Probe_payroll_report_RPTB6Test extends TestCase
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

        $this->company = Company::create(['name' => 'PT Late', 'code' => 'LATE', 'latitude' => -6.2, 'longitude' => 106.8166, 'is_active' => true]);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Ops', 'code' => 'OPS', 'is_active' => true]);
        $this->company->policy->update([
            ...CompanyPolicy::flagsForSelfieMode('none'),
            'require_gps' => false,
            'work_start_time' => '08:00:00',
            'work_end_time' => '17:00:00',
            'late_tolerance_minutes' => 15,
        ]);
    }

    private function freelancer(?Shift $default = null): Freelancer
    {
        $user = User::factory()->create();
        $user->assignRole('Employee');

        return Freelancer::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'full_name' => 'FL '.$user->id,
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonth(),
            'status' => 'active',
            'shift_id' => $default?->id,
        ]);
    }

    public function test_late_overnight_check_in_after_midnight_is_late_on_the_shift_date(): void
    {
        $this->travelTo(Carbon::parse('2026-11-16 12:00:00'));
        $night = Shift::create(['company_id' => $this->company->id, 'name' => 'Malam', 'start_time' => '22:00', 'end_time' => '06:00', 'late_tolerance_minutes' => 15]);
        $worker = $this->freelancer();
        ShiftSchedule::create(['date' => '2026-11-16', 'freelancer_id' => $worker->id, 'shift_id' => $night->id]);

        $this->travelTo(Carbon::parse('2026-11-17 00:10:00'));
        Sanctum::actingAs(User::findOrFail($worker->user_id));
        $this->postJson('/api/v1/attendance/check-in')->assertSuccessful();

        $rows = Attendance::where('freelancer_id', $worker->id)->get();
        $this->assertCount(1, $rows);
        $row = $rows->first();
        fwrite(STDERR, "\nRPT-B6: ".json_encode(['date' => $row->date->toDateString(), 'status' => $row->status?->value, 'late' => $row->late_minutes, 'shift_id' => $row->shift_id])."\n");

        $this->assertSame('2026-11-16', $row->date->toDateString());
        $this->assertSame(AttendanceStatus::Late, $row->status);
        $this->assertSame(130, (int) $row->late_minutes);
        $this->assertSame($night->id, $row->shift_id);
    }
}
