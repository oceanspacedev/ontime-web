<?php

namespace Tests\Feature\QaProbe;

use App\Enums\DataScope;
use App\Filament\Resources\FreelanceResource;
use App\Filament\Resources\FreelanceResource\Pages\CreateFreelancer;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\Tenant;
use App\Models\User;
use App\Services\WorkerAccountService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PFO-B8: the PIC can link a login account that already belongs to an employee to a new freelancer.
 */
class Probe_pic_onboarding_PFOB8Test extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Division $division;

    private User $pic;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.whatsapp_gateway.url' => null, 'services.whatsapp_gateway.token' => null]);
        Http::preventStrayRequests();
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-11-16 07:50:00'));
        $this->seed(RoleSeeder::class);

        $this->tenant = Tenant::factory()->create(['name' => 'Klien B8', 'code' => 'B8']);
        $this->company = new Company(['name' => 'PT B8', 'code' => 'B8Q', 'is_active' => true]);
        $this->company->tenant_id = $this->tenant->id;
        $this->company->save();
        $this->company->refresh();
        $this->company->policy->update(['require_gps' => false, 'require_photo' => false, 'require_face_recognition' => false, 'require_liveness' => false, 'work_start_time' => '08:00:00', 'work_end_time' => '17:00:00', 'late_tolerance_minutes' => 0]);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Outlet', 'is_active' => true]);

        $this->pic = User::factory()->create(['is_active' => true]);
        $this->pic->forceFill(['tenant_id' => $this->tenant->id])->save();
        $this->pic->assignRole('PIC');
        $this->pic->assignRole('Admin');
        $this->pic->grantDataAccess(DataScope::All);
    }

    public function test_pic_cannot_link_an_employee_account_to_a_new_freelancer(): void
    {
        $employee = Employee::create([
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'nip' => 'EMP-B8-1',
            'job_level_id' => JobLevel::create(['name' => 'Staff', 'level_number' => 1])->id,
            'job_title_id' => JobTitle::create(['name' => 'Kasir'])->id,
            'full_name' => 'Andi Karyawan',
            'phone' => '081200000001',
            'status' => 'active',
            'join_date' => '2026-01-01',
        ]);
        $account = app(WorkerAccountService::class)->createAccount($employee)['user'];
        $this->assertSame($account->id, $employee->fresh()->user_id);

        $this->actingAs($this->pic);

        $options = FreelanceResource::assignableUserOptions();
        dump(['options' => $options, 'employee_account' => $account->id]);

        Livewire::test(CreateFreelancer::class)
            ->fillForm([
                'company_id' => $this->company->id,
                'division_id' => $this->division->id,
                'full_name' => 'Andi Freelance',
                'phone' => '081200000002',
                'status' => 'active',
                'create_account' => false,
                'user_id' => $account->id,
            ])
            ->call('create');

        $freelancer = Freelancer::where('full_name', 'Andi Freelance')->first();
        dump(['freelancer_saved' => (bool) $freelancer, 'freelancer_user_id' => $freelancer?->user_id]);

        if ($freelancer?->user_id === $account->id) {
            Sanctum::actingAs($account->fresh());
            $checkIn = $this->postJson('/api/v1/attendance/check-in', []);
            $row = Attendance::latest('id')->first();
            dump(['check_in' => $checkIn->status(), 'employee_id' => $row?->employee_id, 'freelancer_id' => $row?->freelancer_id]);
        }

        $this->assertArrayNotHasKey($account->id, $options, 'An account already linked to an employee is offered in the freelancer "Akun User" picker');
        $this->assertNotSame($account->id, $freelancer?->user_id, 'One login account ends up linked to both an employee and a freelancer');
    }
}
