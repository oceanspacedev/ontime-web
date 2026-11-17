<?php

namespace Tests\Feature\QaProbe;

use App\Enums\AttendanceStatus;
use App\Enums\DataScope;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\Tenant;
use App\Models\User;
use App\Services\WorkerAccountService;
use App\Services\WorkScheduleService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * PFO-B7: a freelancer added from the mobile team screen gets no default shift, so an unscheduled day
 * is judged against company policy hours.
 */
class Probe_pic_onboarding_PFOB7Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private CompanyLocation $outlet;

    private User $pic;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.whatsapp_gateway.url' => null, 'services.whatsapp_gateway.token' => null]);
        Http::preventStrayRequests();
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-11-16 05:00:00'));
        $this->seed(RoleSeeder::class);

        $tenant = Tenant::factory()->create(['name' => 'Klien B7', 'code' => 'B7']);
        $this->company = new Company(['name' => 'PT B7', 'code' => 'B7Q', 'is_active' => true]);
        $this->company->tenant_id = $tenant->id;
        $this->company->save();
        $this->company->refresh();
        $this->company->policy->update(['require_gps' => false, 'require_photo' => false, 'require_face_recognition' => false, 'require_liveness' => false, 'work_start_time' => '08:00:00', 'work_end_time' => '17:00:00', 'late_tolerance_minutes' => 15]);
        $division = Division::create(['company_id' => $this->company->id, 'name' => 'Brand', 'is_active' => true]);
        $this->outlet = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $division->id, 'name' => 'Outlet 1', 'latitude' => -6.2, 'longitude' => 106.8, 'radius_meters' => 100, 'is_active' => true]);

        $this->pic = User::factory()->create(['is_active' => true]);
        $this->pic->forceFill(['tenant_id' => $tenant->id])->save();
        $this->pic->assignRole('PIC');
        $this->pic->assignRole('Admin');
        $this->pic->grantDataAccess(DataScope::All);
    }

    private function mobileFreelancer(): array
    {
        Sanctum::actingAs($this->pic);
        $response = $this->postJson('/api/v1/team/freelancers', ['full_name' => 'Siti Pagi', 'phone' => '081277770001', 'company_location_id' => $this->outlet->id]);
        $response->assertCreated();
        $freelancer = Freelancer::findOrFail($response->json('data.id'));
        $user = $freelancer->user_id ? User::find($freelancer->user_id) : app(WorkerAccountService::class)->createAccount($freelancer)['user'];

        return [$freelancer->fresh(), $user];
    }

    /** Decision "Masuk di luar jadwal": no shift, no schedule -> the freelancer's check-in waits for approval, never late without a cover shift. */
    public function test_unscheduled_mobile_freelancer_must_name_the_shift_they_cover(): void
    {
        [$freelancer, $user] = $this->mobileFreelancer();
        $this->assertNull($freelancer->shift_id, 'Mobile onboarding sets no default shift');
        $shift = Shift::create(['company_id' => $this->company->id, 'name' => 'Pagi', 'code' => 'P', 'start_time' => '07:00:00', 'end_time' => '15:00:00', 'late_tolerance_minutes' => 15, 'is_active' => true]);

        $this->travelTo(Carbon::parse('2026-11-16 07:30:00'));
        $hours = app(WorkScheduleService::class)->resolve($freelancer, today());
        $this->assertSame('policy', $hours['source']);

        Sanctum::actingAs($user);
        $this->postJson('/api/v1/attendance/check-in', [])->assertUnprocessable()->assertJsonValidationErrors('cover_shift_id');
        $this->postJson('/api/v1/attendance/check-in', ['cover_shift_id' => $shift->id])->assertSuccessful();
        $attendance = Attendance::where('freelancer_id', $freelancer->id)->firstOrFail();
        dump(['status' => $attendance->status?->value ?? $attendance->status, 'late' => $attendance->late_minutes]);
        $this->assertSame(AttendanceStatus::PendingApproval, $attendance->status);
        $this->assertTrue($attendance->is_off_schedule);
        $this->assertSame(30, $attendance->check_in_late_minutes);
    }

    /** Once the PIC records the real 06:00 shift (the only place the system can learn it), 07:30 is late. */
    public function test_scheduled_early_shift_makes_the_same_check_in_late(): void
    {
        [$freelancer, $user] = $this->mobileFreelancer();
        $shift = Shift::create(['company_id' => $this->company->id, 'name' => 'Pagi', 'code' => 'P', 'start_time' => '06:00:00', 'end_time' => '14:00:00', 'late_tolerance_minutes' => 15, 'is_active' => true]);
        ShiftSchedule::create(['date' => '2026-11-16', 'freelancer_id' => $freelancer->id, 'shift_id' => $shift->id, 'company_location_id' => $this->outlet->id]);

        $this->travelTo(Carbon::parse('2026-11-16 07:30:00'));
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/attendance/check-in', [])->assertSuccessful();
        $attendance = Attendance::where('freelancer_id', $freelancer->id)->firstOrFail();
        dump(['status' => $attendance->status?->value ?? $attendance->status, 'late' => $attendance->late_minutes]);
        $this->assertSame(AttendanceStatus::Late, $attendance->status);
        $this->assertSame(90, (int) $attendance->late_minutes);
    }
}
