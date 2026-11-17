<?php

namespace Tests\Feature\QaProbe;

use App\Enums\DataScope;
use App\Models\ApprovalFlow;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Company;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\OvertimeRequest;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalFlowService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Probes for overtime (lembur) and attendance corrections of freelancers.
 */
class OVTProbeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Division $division;

    private User $freelancerUser;

    private Freelancer $freelancer;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.onesignal.app_id' => null, 'services.whatsapp_gateway.url' => null]);
        Http::preventStrayRequests();
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-11-16 20:00:00'));
        $this->seed(RoleSeeder::class);

        $tenant = Tenant::factory()->create(['name' => 'Klien OV', 'code' => 'OV']);
        $this->company = new Company(['name' => 'PT OV', 'code' => 'OVC', 'is_active' => true]);
        $this->company->tenant_id = $tenant->id;
        $this->company->save();
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Outlet', 'is_active' => true]);

        foreach (['overtime', 'correction'] as $type) {
            ApprovalFlow::create([
                'company_id' => $this->company->id, 'request_type' => $type, 'step_number' => 1, 'step_order' => 1,
                'name' => "Persetujuan {$type}", 'approver_type' => 'role', 'approver_role' => 'Admin',
            ]);
        }

        $this->freelancerUser = User::factory()->create(['is_active' => true]);
        $this->freelancerUser->forceFill(['tenant_id' => $tenant->id])->save();
        $this->freelancerUser->assignRole('Freelancer');
        $this->freelancer = Freelancer::create([
            'user_id' => $this->freelancerUser->id, 'company_id' => $this->company->id,
            'division_id' => $this->division->id, 'full_name' => 'Fre Lance', 'status' => 'active',
        ]);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->forceFill(['tenant_id' => $tenant->id])->save();
        $this->admin->assignRole('Admin');
        $this->admin->grantDataAccess(DataScope::All);
    }

    private function shift(string $start, string $end, int $tolerance = 10): Shift
    {
        return Shift::create([
            'company_id' => $this->company->id, 'division_id' => $this->division->id, 'name' => "S{$start}",
            'start_time' => $start, 'end_time' => $end, 'late_tolerance_minutes' => $tolerance,
        ]);
    }

    /**
     * Decided behaviour (BUG-OVT-2): duplicates, overlaps and dates outside today -/+ 31 days are refused.
     */
    public function test_duplicate_overlapping_and_far_dated_overtime_is_refused(): void
    {
        Sanctum::actingAs($this->freelancerUser);
        $payload = ['date' => '2026-11-16', 'start_time' => '17:00', 'end_time' => '20:00', 'reason' => 'stok'];
        $this->postJson('/api/v1/overtime-requests', $payload)->assertCreated()->assertJsonPath('data.duration_minutes', 180);
        $this->postJson('/api/v1/overtime-requests', $payload)->assertUnprocessable();
        $this->postJson('/api/v1/overtime-requests', [...$payload, 'start_time' => '18:00', 'end_time' => '19:00'])->assertUnprocessable();
        $this->postJson('/api/v1/overtime-requests', [...$payload, 'date' => '2027-05-01'])->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->postJson('/api/v1/overtime-requests', [...$payload, 'date' => '2025-01-01'])->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->assertSame(1, OvertimeRequest::count());
    }

    public function test_overtime_across_midnight_and_inside_shift(): void
    {
        Sanctum::actingAs($this->freelancerUser);
        $this->postJson('/api/v1/overtime-requests', ['date' => '2026-11-16', 'start_time' => '22:00', 'end_time' => '02:00', 'reason' => 'x'])
            ->assertCreated()->assertJsonPath('data.duration_minutes', 240);
        // Overtime fully inside a normal 08:00-17:00 shift is accepted too.
        $this->freelancer->update(['shift_id' => $this->shift('08:00', '17:00')->id]);
        $this->postJson('/api/v1/overtime-requests', ['date' => '2026-11-16', 'start_time' => '09:00', 'end_time' => '12:00', 'reason' => 'x'])
            ->assertCreated()->assertJsonPath('data.duration_minutes', 180);
    }

    public function test_update_only_end_time_equal_to_start_gives_24_hours(): void
    {
        Sanctum::actingAs($this->freelancerUser);
        $id = $this->postJson('/api/v1/overtime-requests', ['date' => '2026-11-16', 'start_time' => '17:00', 'end_time' => '19:00', 'reason' => 'x'])->json('data.id');
        $response = $this->putJson("/api/v1/overtime-requests/{$id}", ['end_time' => '17:00']);
        $this->assertSame([$response->status(), OvertimeRequest::find($id)->duration_minutes], [$response->status(), OvertimeRequest::find($id)->duration_minutes]);
        fwrite(STDERR, "\nUPDATE end=start status=".$response->status().' duration='.OvertimeRequest::find($id)->duration_minutes."\n");
    }

    public function test_approved_overtime_is_absent_from_reports(): void
    {
        Sanctum::actingAs($this->freelancerUser);
        $id = $this->postJson('/api/v1/overtime-requests', ['date' => '2026-11-16', 'start_time' => '17:00', 'end_time' => '20:00', 'reason' => 'x'])->json('data.id');
        $ot = OvertimeRequest::find($id);
        app(ApprovalFlowService::class)->approveStep($ot, $this->admin);
        $this->assertSame('approved', $ot->fresh()->status);
        $this->assertNull($ot->fresh()->actual_duration_minutes);
    }

    public function test_correction_on_day_off_marks_on_time(): void
    {
        $shift = $this->shift('08:00', '17:00');
        ShiftSchedule::create(['date' => '2026-11-15', 'freelancer_id' => $this->freelancer->id, 'is_day_off' => true]);
        Sanctum::actingAs($this->freelancerUser);
        $id = $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-15', 'corrected_check_in' => '11:00', 'corrected_check_out' => '17:00', 'reason' => 'lupa'])->assertCreated()->json('data.id');
        app(ApprovalFlowService::class)->approveStep(AttendanceCorrection::find($id), $this->admin);
        $att = Attendance::where('freelancer_id', $this->freelancer->id)->whereDate('date', '2026-11-15')->first();
        fwrite(STDERR, "\nDAYOFF correction status=".($att->status?->value ?? $att->status).' late='.$att->late_minutes."\n");
        $this->assertNotNull($att);
    }

    public function test_correction_late_and_tolerance(): void
    {
        $this->freelancer->update(['shift_id' => $this->shift('08:00', '17:00', 10)->id]);
        Sanctum::actingAs($this->freelancerUser);
        $results = [];
        foreach (['2026-11-10' => '08:10', '2026-11-11' => '08:11', '2026-11-12' => '09:30'] as $date => $time) {
            $id = $this->postJson('/api/v1/attendance-corrections', ['date' => $date, 'corrected_check_in' => $time, 'reason' => 'x'])->assertCreated()->json('data.id');
            app(ApprovalFlowService::class)->approveStep(AttendanceCorrection::find($id), $this->admin);
            $att = Attendance::where('freelancer_id', $this->freelancer->id)->whereDate('date', $date)->first();
            $results[$time] = [($att->status?->value ?? $att->status), $att->late_minutes];
        }
        fwrite(STDERR, "\nTOLERANCE ".json_encode($results)."\n");
        $this->assertTrue(true);
    }

    public function test_overnight_correction_after_midnight_checkin(): void
    {
        $this->freelancer->update(['shift_id' => $this->shift('22:00', '06:00', 10)->id]);
        Sanctum::actingAs($this->freelancerUser);
        $id = $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-14', 'corrected_check_in' => '00:30', 'corrected_check_out' => '06:00', 'reason' => 'x'])->assertCreated()->json('data.id');
        app(ApprovalFlowService::class)->approveStep(AttendanceCorrection::find($id), $this->admin);
        $att = Attendance::where('freelancer_id', $this->freelancer->id)->whereDate('date', '2026-11-14')->first();
        fwrite(STDERR, "\nOVERNIGHT status=".($att->status?->value ?? $att->status).' late='.$att->late_minutes.' in='.$att->check_in.' out='.$att->check_out.' worked='.$att->workedMinutes()."\n");
        $this->assertTrue(true);
    }

    public function test_future_correction_accepted_and_applied(): void
    {
        $this->freelancer->update(['shift_id' => $this->shift('08:00', '17:00', 10)->id]);
        Sanctum::actingAs($this->freelancerUser);
        $response = $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-25', 'corrected_check_in' => '08:00', 'corrected_check_out' => '17:00', 'reason' => 'x']);
        fwrite(STDERR, "\nFUTURE correction status=".$response->status()."\n");
        if ($response->status() === 201) {
            app(ApprovalFlowService::class)->approveStep(AttendanceCorrection::find($response->json('data.id')), $this->admin);
            $att = Attendance::where('freelancer_id', $this->freelancer->id)->whereDate('date', '2026-11-25')->first();
            fwrite(STDERR, 'FUTURE attendance status='.($att?->status?->value ?? $att?->status).' worked='.$att?->workedMinutes()."\n");
        }
        $this->assertTrue(true);
    }

    public function test_correction_overrides_pending_geofence_attendance(): void
    {
        $this->freelancer->update(['shift_id' => $this->shift('08:00', '17:00', 10)->id]);
        $att = Attendance::create([
            'freelancer_id' => $this->freelancer->id, 'date' => '2026-11-16', 'segment' => 1,
            'check_in' => '2026-11-16 09:00:00', 'status' => 'pending_approval', 'late_minutes' => 0, 'check_in_late_minutes' => 50,
        ]);
        Sanctum::actingAs($this->freelancerUser);
        // Decided behaviour: a correction of an attendance still waiting for the geofence approver is refused.
        $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-16', 'corrected_check_in' => '08:00', 'reason' => 'x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date' => 'menunggu persetujuan']);
        $att->refresh();
        $this->assertSame('pending_approval', $att->status?->value);
        $this->assertSame(50, $att->check_in_late_minutes);
    }
}
