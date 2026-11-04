<?php

namespace Tests\Feature;

use App\Filament\Pages\AbsenHariIni;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Intern;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PresensiMandiriCheckInTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Division $division;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Employee']);
        Role::create(['name' => 'Intern']);

        $this->company = Company::create(['name' => 'PT Presensi', 'code' => 'PRS', 'is_active' => true]);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Operasional', 'is_active' => true]);
    }

    public function test_employee_can_check_in_from_presensi_mandiri_page(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Employee');

        $employee = Employee::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'job_level_id' => JobLevel::create(['name' => 'Staff', 'level_order' => 1])->id,
            'job_title_id' => JobTitle::create(['name' => 'Staff Operasional'])->id,
            'nip' => 'PRS-001',
            'full_name' => 'Karyawan Presensi',
            'status' => 'active',
        ]);

        $this->actingAs($user);

        Livewire::test(AbsenHariIni::class)
            ->assertSuccessful()
            ->call('processCheckIn')
            ->assertHasNoErrors();

        $attendance = Attendance::where('employee_id', $employee->id)->first();

        $this->assertNotNull($attendance);
        $this->assertNotNull($attendance->check_in);
    }

    public function test_intern_can_check_in_from_presensi_mandiri_page(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Intern');

        $intern = Intern::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'nis' => 'MG-PRS-01',
            'full_name' => 'Magang Presensi',
            'institution' => 'SMK Test',
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonths(2),
            'status' => 'active',
        ]);

        $this->actingAs($user);

        Livewire::test(AbsenHariIni::class)
            ->call('processCheckIn')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('attendances', ['intern_id' => $intern->id]);
    }

    public function test_second_check_in_on_the_same_day_is_refused_without_error(): void
    {
        $employee = $this->makeEmployee();
        $this->actingAs($employee->user);

        Livewire::test(AbsenHariIni::class)->call('processCheckIn');
        Livewire::test(AbsenHariIni::class)
            ->call('processCheckIn')
            ->assertHasNoErrors()
            ->assertNotified('Sudah Check In');

        $this->assertSame(1, Attendance::where('employee_id', $employee->id)->count());
    }

    public function test_late_check_in_stores_positive_late_minutes(): void
    {
        $this->travelTo(today()->setTime(10, 0));

        $employee = $this->makeEmployee();
        $this->actingAs($employee->user);

        Livewire::test(AbsenHariIni::class)->call('processCheckIn');

        $attendance = Attendance::where('employee_id', $employee->id)->firstOrFail();

        $this->assertSame('late', $attendance->status->value);
        $this->assertSame(120, $attendance->late_minutes);
    }

    public function test_check_in_fills_an_existing_row_for_today(): void
    {
        $employee = $this->makeEmployee();
        $existing = Attendance::create(['employee_id' => $employee->id, 'date' => today(), 'status' => 'absent']);

        $this->actingAs($employee->user);

        Livewire::test(AbsenHariIni::class)->call('processCheckIn')->assertHasNoErrors();

        $this->assertSame(1, Attendance::where('employee_id', $employee->id)->count());
        $this->assertNotNull($existing->fresh()->check_in);
    }

    public function test_check_out_requires_check_in_and_happens_once(): void
    {
        $this->travelTo(today()->setTime(8, 0));

        $employee = $this->makeEmployee();
        $this->actingAs($employee->user);

        Livewire::test(AbsenHariIni::class)
            ->call('processCheckOut')
            ->assertNotified('Check Out Gagal');

        Livewire::test(AbsenHariIni::class)->call('processCheckIn');
        Livewire::test(AbsenHariIni::class)->call('processCheckOut');

        $checkOut = Attendance::where('employee_id', $employee->id)->firstOrFail()->check_out;
        $this->assertNotNull($checkOut);

        $this->travel(1)->hours();

        Livewire::test(AbsenHariIni::class)
            ->call('processCheckOut')
            ->assertNotified('Sudah Check Out');

        $this->assertEquals($checkOut, Attendance::where('employee_id', $employee->id)->firstOrFail()->check_out);
    }

    public function test_approved_geofence_check_in_gets_positive_late_minutes(): void
    {
        $employee = $this->makeEmployee();
        $attendance = Attendance::create([
            'employee_id' => $employee->id,
            'date' => today(),
            'check_in' => today()->setTime(9, 30),
            'status' => 'pending_approval',
        ]);

        $attendance->applyGeofenceApproval();

        $this->assertSame(90, $attendance->fresh()->late_minutes);
    }

    private function makeEmployee(): Employee
    {
        $user = User::factory()->create();
        $user->assignRole('Employee');

        return Employee::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Staff Operasional'])->id,
            'nip' => 'PRS-'.$user->id,
            'full_name' => "Karyawan {$user->id}",
            'status' => 'active',
        ]);
    }
}
