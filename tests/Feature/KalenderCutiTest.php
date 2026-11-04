<?php

namespace Tests\Feature;

use App\Filament\Pages\KalenderCuti;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class KalenderCutiTest extends TestCase
{
    use RefreshDatabase;

    protected User $superadmin;

    protected Employee $employee;

    protected Company $company;

    protected Division $division;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Superadmin']);

        $this->superadmin = User::factory()->create();
        $this->superadmin->assignRole('Superadmin');

        $company = $this->company = Company::create([
            'code' => 'COMP-CAL',
            'name' => 'PT Kalender Test',
            'is_active' => true,
        ]);

        $division = $this->division = Division::create([
            'company_id' => $company->id,
            'name' => 'HRD',
            'code' => 'HRD',
            'is_active' => true,
        ]);

        $jobLevel = JobLevel::create(['name' => 'Staff', 'level_order' => 1]);
        $jobTitle = JobTitle::create(['name' => 'HR Staff']);

        $user = User::factory()->create();

        $this->employee = Employee::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'division_id' => $division->id,
            'job_level_id' => $jobLevel->id,
            'job_title_id' => $jobTitle->id,
            'nip' => 'EMP-CAL-01',
            'full_name' => 'Staff Kalender Cuti',
            'status' => 'active',
        ]);
    }

    public function test_can_render_kalender_cuti_page(): void
    {
        $this->actingAs($this->superadmin);

        LeaveRequest::create([
            'employee_id' => $this->employee->id,
            'leave_type' => 'annual_leave',
            'start_date' => now()->startOfMonth()->format('Y-m-d'),
            'end_date' => now()->startOfMonth()->addDays(2)->format('Y-m-d'),
            'days_count' => 3,
            'reason' => 'Liburan keluarga',
            'status' => 'approved',
        ]);

        Livewire::test(KalenderCuti::class)
            ->assertSuccessful()
            ->assertSee('Staff Kalender Cuti');
    }

    public function test_calendar_shows_intern_and_freelancer_leave_without_crashing(): void
    {
        $intern = Intern::create(['company_id' => $this->company->id, 'division_id' => $this->division->id, 'nis' => 'MG-CAL', 'full_name' => 'Magang Kalender', 'institution' => 'SMK', 'status' => 'active']);
        $freelancer = Freelancer::create(['company_id' => $this->company->id, 'division_id' => $this->division->id, 'freelancer_number' => 'FL-CAL', 'full_name' => 'Freelance Kalender', 'status' => 'active']);

        $this->createLeave(['intern_id' => $intern->id], now()->startOfMonth(), now()->startOfMonth());
        $this->createLeave(['freelancer_id' => $freelancer->id], now()->startOfMonth()->addDay(), now()->startOfMonth()->addDay());

        $this->actingAs($this->superadmin);

        Livewire::test(KalenderCuti::class)
            ->assertSuccessful()
            ->assertSee(['Magang Kalender', 'Freelance Kalender']);
    }

    public function test_calendar_shows_leave_that_started_in_the_previous_month(): void
    {
        $leave = $this->createLeave(['employee_id' => $this->employee->id], now()->startOfMonth()->subDays(2), now()->startOfMonth()->addDay());

        $this->actingAs($this->superadmin);

        $calendar = Livewire::test(KalenderCuti::class)->assertSuccessful();

        $this->assertSame([$leave->id], $calendar->instance()->leaveRequests->pluck('id')->all());
    }

    public function test_company_filter_includes_interns(): void
    {
        $intern = Intern::create(['company_id' => $this->company->id, 'division_id' => $this->division->id, 'nis' => 'MG-CAL-2', 'full_name' => 'Magang Filter', 'institution' => 'SMK', 'status' => 'active']);
        $leave = $this->createLeave(['intern_id' => $intern->id], now()->startOfMonth(), now()->startOfMonth());

        $this->actingAs($this->superadmin);

        $calendar = Livewire::test(KalenderCuti::class)->set('selectedCompanyId', $this->company->id);

        $this->assertSame([$leave->id], $calendar->instance()->leaveRequests->pluck('id')->all());
    }

    /**
     * @param  array<string, int>  $owner
     */
    private function createLeave(array $owner, CarbonInterface $start, CarbonInterface $end): LeaveRequest
    {
        return LeaveRequest::create($owner + [
            'leave_type' => 'permission',
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'days_count' => 1,
            'reason' => 'Izin keluarga',
            'status' => 'pending',
        ]);
    }
}
