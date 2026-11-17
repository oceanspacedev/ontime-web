<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalFlowService;
use App\Services\WorkerAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Check-in rules shared with other areas: the specific reason a worker may not work yet, and the
 * approval of a night-shift row judged on its own business date.
 */
class AttendanceEngineRulesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Division $division;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.whatsapp_gateway.url' => null, 'services.onesignal.app_id' => null]);
        Http::fake();
        Role::findOrCreate('Freelancer', 'web');

        $tenant = Tenant::factory()->create(['name' => 'Klien A', 'code' => 'KA', 'is_active' => true]);
        $this->company = new Company(['name' => 'PT Resto', 'code' => 'RESTO', 'is_active' => true]);
        $this->company->tenant_id = $tenant->id;
        $this->company->save();
        app(ApprovalFlowService::class)->createDefaultFlows($this->company);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Dapur', 'code' => 'DPR', 'is_active' => true]);
        $this->company->policy()->update(['require_gps' => false, 'require_photo' => false, 'require_face_recognition' => false, 'require_liveness' => false]);
    }

    public function test_contract_not_started_gives_the_specific_reason(): void
    {
        $this->travelTo(Carbon::parse('2026-11-16 08:00:00'));
        $freelancer = $this->freelancer('2026-11-20', '2026-12-31');

        Sanctum::actingAs($freelancer->user);
        $this->postJson('/api/v1/attendance/check-in')
            ->assertForbidden()
            ->assertJsonPath('message', 'Kontrak Anda baru mulai 20 November 2026. Absen baru bisa dilakukan mulai tanggal itu.');
    }

    public function test_ended_contract_keeps_the_generic_inactive_message(): void
    {
        $this->travelTo(Carbon::parse('2026-11-16 08:00:00'));
        $freelancer = $this->freelancer('2026-10-01', '2026-11-10');

        Sanctum::actingAs($freelancer->user);
        $this->postJson('/api/v1/attendance/check-in')
            ->assertForbidden()
            ->assertJsonPath('message', WorkerAccountService::INACTIVE_MESSAGE);
    }

    public function test_night_shift_on_the_last_contract_day_can_still_be_checked_out_the_next_morning(): void
    {
        $night = Shift::create(['company_id' => $this->company->id, 'name' => 'Malam', 'start_time' => '22:00', 'end_time' => '06:00', 'late_tolerance_minutes' => 0, 'is_active' => true]);
        $freelancer = $this->freelancer('2026-10-01', '2026-11-16');
        $row = Attendance::create(['freelancer_id' => $freelancer->id, 'shift_id' => $night->id, 'date' => '2026-11-16', 'segment' => 1, 'check_in' => '2026-11-16 22:00:00', 'status' => AttendanceStatus::OnTime, 'late_minutes' => 0]);
        $this->travelTo(Carbon::parse('2026-11-17 06:00:00'));

        Sanctum::actingAs($freelancer->user);
        $this->postJson('/api/v1/attendance/check-out')->assertOk();

        $this->assertNotNull($row->fresh()->check_out);
    }

    public function test_approving_a_night_row_without_a_snapshot_judges_it_from_its_own_evening(): void
    {
        $night = Shift::create(['company_id' => $this->company->id, 'name' => 'Malam', 'start_time' => '22:00', 'end_time' => '06:00', 'late_tolerance_minutes' => 0, 'is_active' => true]);
        $freelancer = $this->freelancer('2026-10-01', '2026-12-31', $night);
        $row = Attendance::create([
            'freelancer_id' => $freelancer->id, 'shift_id' => $night->id, 'date' => '2026-11-16', 'segment' => 1,
            'check_in' => '2026-11-17 00:30:00', 'status' => AttendanceStatus::PendingApproval, 'late_minutes' => 0, 'check_in_late_minutes' => null,
        ]);
        $this->travelTo(Carbon::parse('2026-11-17 01:00:00'));

        $row->applyGeofenceApproval();

        $row->refresh();
        $this->assertSame(AttendanceStatus::Late, $row->status);
        $this->assertSame(150, $row->late_minutes, 'Judged from 22:00 on the row\'s date, not 22:00 of the check-in day.');
    }

    private function freelancer(string $start, string $end, ?Shift $defaultShift = null): Freelancer
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['tenant_id' => $this->company->tenant_id])->save();
        $user->assignRole('Freelancer');

        return Freelancer::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'full_name' => 'Citra',
            'start_date' => $start,
            'end_date' => $end,
            'status' => 'active',
            'shift_id' => $defaultShift?->id,
        ]);
    }
}
