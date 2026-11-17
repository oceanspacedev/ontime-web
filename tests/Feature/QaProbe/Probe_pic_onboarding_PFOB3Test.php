<?php

namespace Tests\Feature\QaProbe;

use App\Enums\AttendanceStatus;
use App\Enums\DataScope;
use App\Filament\Resources\FreelanceResource\Pages\EditFreelancer;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\OvertimeRequest;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PFO-B3: deleting a freelancer must not wipe the attendance / overtime history used for payroll.
 */
class Probe_pic_onboarding_PFOB3Test extends TestCase
{
    use RefreshDatabase;

    public function test_pic_deleting_freelancer_keeps_payroll_history(): void
    {
        config(['services.whatsapp_gateway.url' => null, 'services.whatsapp_gateway.token' => null]);
        Http::preventStrayRequests();
        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-16 18:00:00'));
        $this->seed(RoleSeeder::class);

        $tenant = Tenant::factory()->create(['name' => 'Klien B3', 'code' => 'B3']);
        $company = new Company(['name' => 'PT B3', 'code' => 'B3Q', 'is_active' => true]);
        $company->tenant_id = $tenant->id;
        $company->save();
        $division = Division::create(['company_id' => $company->id, 'name' => 'Outlet', 'is_active' => true]);

        $pic = User::factory()->create(['is_active' => true]);
        $pic->forceFill(['tenant_id' => $tenant->id])->save();
        // Real PIC accounts get Admin + PIC (TenantOnboardingService.php:52).
        $pic->assignRole('PIC');
        $pic->assignRole('Admin');
        $pic->grantDataAccess(DataScope::All);

        $freelancer = Freelancer::create([
            'company_id' => $company->id,
            'division_id' => $division->id,
            'full_name' => 'Budi Freelance',
            'phone' => '081234567890',
            'status' => 'active',
            'start_date' => '2026-10-01',
        ]);
        Attendance::create(['freelancer_id' => $freelancer->id, 'date' => '2026-11-15', 'check_in' => '2026-11-15 08:00:00', 'check_out' => '2026-11-15 17:00:00', 'status' => AttendanceStatus::cases()[0]]);
        OvertimeRequest::create(['freelancer_id' => $freelancer->id, 'date' => '2026-11-15', 'start_time' => '17:00', 'end_time' => '20:00', 'duration_minutes' => 180, 'reason' => 'Stock opname', 'status' => 'approved', 'current_step' => 1]);

        $this->actingAs($pic);
        try {
            Livewire::test(EditFreelancer::class, ['record' => $freelancer->id])->callAction('delete');
        } catch (\Throwable $e) {
            fwrite(STDERR, 'EXC: '.get_class($e).': '.substr($e->getMessage(), 0, 300).PHP_EOL);
        }

        $freelancerStillExists = Freelancer::query()->whereKey($freelancer->id)->exists();
        $attendanceCount = Attendance::where('freelancer_id', $freelancer->id)->count();
        $overtimeCount = OvertimeRequest::where('freelancer_id', $freelancer->id)->count();
        fwrite(STDERR, json_encode(compact('freelancerStillExists', 'attendanceCount', 'overtimeCount')).PHP_EOL);

        $this->assertSame(1, $attendanceCount, 'Attendance history for payroll must survive a freelancer delete');
        $this->assertSame(1, $overtimeCount, 'Approved overtime for payroll must survive a freelancer delete');
    }
}
