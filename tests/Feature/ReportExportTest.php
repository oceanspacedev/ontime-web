<?php

namespace Tests\Feature;

use App\Filament\Pages\LaporanAbsensi;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'Approver']);
        Role::create(['name' => 'Superadmin']);
    }

    private function createCompanyWithEmployee(string $name, string $code): array
    {
        $company = Company::create(['name' => $name, 'code' => $code, 'is_active' => true]);
        $division = Division::create(['company_id' => $company->id, 'name' => 'Divisi '.$code, 'code' => $code, 'is_active' => true]);
        $jobLevel = JobLevel::create(['name' => 'Staff', 'level_number' => 1]);
        $jobTitle = JobTitle::create(['name' => 'Staff '.$code]);

        $user = User::factory()->create();
        $employee = Employee::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'division_id' => $division->id,
            'job_level_id' => $jobLevel->id,
            'job_title_id' => $jobTitle->id,
            'nip' => 'NIP'.$code,
            'full_name' => 'Karyawan '.$name,
            'join_date' => now(),
            'status' => 'permanent',
        ]);

        $attendance = Attendance::create([
            'employee_id' => $employee->id,
            'date' => today(),
            'check_in' => now()->setTime(8, 0, 0),
            'status' => 'on_time',
        ]);

        return [$company, $division, $employee, $attendance];
    }

    public function test_approver_cannot_view_or_export_data_from_unauthorized_company(): void
    {
        [$companyA, $divisionA, $employeeA, $attendanceA] = $this->createCompanyWithEmployee('PT Alpha', 'ALPHA');
        [$companyB, $divisionB, $employeeB, $attendanceB] = $this->createCompanyWithEmployee('PT Beta', 'BETA');

        // Approver User mapped ONLY to Company A
        $approverUser = User::factory()->create();
        $approverUser->assignRole('Approver');
        $this->grantDataAccessTo($approverUser, $companyA->id);

        $this->actingAs($approverUser);

        $page = new LaporanAbsensi;
        $page->filterData = [
            'date_from' => today()->subDays(1)->toDateString(),
            'date_to' => today()->addDays(1)->toDateString(),
        ];

        $reportRecords = $page->getReportDataProperty();
        $employeeIds = $reportRecords->pluck('employee_id')->toArray();

        $this->assertContains($employeeA->id, $employeeIds);
        $this->assertNotContains($employeeB->id, $employeeIds);
    }

    public function test_superadmin_can_view_and_export_all_company_attendance_data(): void
    {
        [$companyA, $divisionA, $employeeA, $attendanceA] = $this->createCompanyWithEmployee('PT Alpha', 'ALPHA');
        [$companyB, $divisionB, $employeeB, $attendanceB] = $this->createCompanyWithEmployee('PT Beta', 'BETA');

        $superadminUser = User::factory()->create();
        $superadminUser->assignRole('Superadmin');

        $this->actingAs($superadminUser);

        $page = new LaporanAbsensi;
        $page->filterData = [
            'date_from' => today()->subDays(1)->toDateString(),
            'date_to' => today()->addDays(1)->toDateString(),
        ];

        $reportRecords = $page->getReportDataProperty();
        $employeeIds = $reportRecords->pluck('employee_id')->toArray();

        $this->assertContains($employeeA->id, $employeeIds);
        $this->assertContains($employeeB->id, $employeeIds);
    }
}
