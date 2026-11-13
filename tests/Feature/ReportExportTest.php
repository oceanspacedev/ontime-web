<?php

namespace Tests\Feature;

use App\Exports\AttendanceReportExport;
use App\Filament\Pages\LaporanAbsensi;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
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

    public function test_export_recap_sheet_lists_workers_down_and_dates_across_with_total_hours(): void
    {
        [, , $employeeA] = $this->createCompanyWithEmployee('PT Alpha', 'ALPHA');
        [, , $employeeB] = $this->createCompanyWithEmployee('PT Beta', 'BETA');
        Attendance::query()->delete();

        // Monday: on time, 8.5 hours. Tuesday: late and never checked out. Wednesday: on leave.
        $this->createAttendance($employeeA, '2026-11-02', '08:00', '16:30');
        $this->createAttendance($employeeA, '2026-11-03', '08:20', null, ['status' => 'late', 'late_minutes' => 20]);
        $this->createAttendance($employeeA, '2026-11-04', null, null, ['status' => 'leave']);
        $this->createAttendance($employeeB, '2026-11-03', '09:00', '17:00');

        $sheet = $this->exportToSpreadsheet('2026-11-01', '2026-11-04')->getSheetByName('Rekap');

        $this->assertSame(['Nama', 'NIP / ID', 'Tipe', 'Badan Usaha', 'Divisi', 'Total'], $this->rowValues($sheet, 1, 'A', 'F'));
        $this->assertSame(['Hari Masuk', 'Terlambat (kali)', 'Telat (menit)', 'Jumlah Jam'], $this->rowValues($sheet, 2, 'F', 'I'));

        // One group of three columns per day of the selected range, including days nobody worked.
        $this->assertSame('Min, 01 Nov 2026', $sheet->getCell('J1')->getValue());
        $this->assertSame('Sen, 02 Nov 2026', $sheet->getCell('M1')->getValue());
        $this->assertSame('Rab, 04 Nov 2026', $sheet->getCell('S1')->getValue());
        $this->assertSame(['Masuk', 'Pulang', 'Jam'], $this->rowValues($sheet, 2, 'M', 'O'));
        $this->assertNull($sheet->getCell('V1')->getValue());
        $this->assertArrayHasKey('M1:O1', $sheet->getMergeCells());

        // One row per worker, sorted by company.
        $this->assertSame(['Karyawan PT Alpha', 'NIPALPHA', 'Karyawan', 'PT Alpha', 'Divisi ALPHA'], $this->rowValues($sheet, 3, 'A', 'E'));
        $this->assertSame('Karyawan PT Beta', $sheet->getCell('A4')->getValue());
        $this->assertNull($sheet->getCell('A5')->getValue());

        // Totals: two days present, one of them late, 8.5 hours worked.
        $this->assertSame([2, 1, 20], $this->rowValues($sheet, 3, 'F', 'H'));
        $this->assertEqualsWithDelta(8.5, $sheet->getCell('I3')->getValue(), 0.001);
        $this->assertSame('0.00', $sheet->getStyle('I3')->getNumberFormat()->getFormatCode());

        $this->assertSame([null, null, null], $this->rowValues($sheet, 3, 'J', 'L'));
        $this->assertSame(['08:00', '16:30'], $this->rowValues($sheet, 3, 'M', 'N'));
        $this->assertEqualsWithDelta(8.5, $sheet->getCell('O3')->getValue(), 0.001);

        // No check-out yet: the hours stay empty and the late check-in is highlighted.
        $this->assertSame(['08:20', '-', null], $this->rowValues($sheet, 3, 'P', 'R'));
        $this->assertSame('FDE68A', $sheet->getStyle('P3')->getFill()->getStartColor()->getRGB());
        $this->assertNotSame('FDE68A', $sheet->getStyle('M3')->getFill()->getStartColor()->getRGB());

        // A day without a check-in shows its status across the day's columns.
        $this->assertSame('Cuti / Izin', $sheet->getCell('S3')->getValue());
        $this->assertArrayHasKey('S3:U3', $sheet->getMergeCells());

        $this->assertSame([1, 0, 0], $this->rowValues($sheet, 4, 'F', 'H'));
        $this->assertEqualsWithDelta(8.0, $sheet->getCell('I4')->getValue(), 0.001);
        $this->assertSame(['09:00', '17:00'], $this->rowValues($sheet, 4, 'P', 'Q'));
    }

    public function test_export_recap_sheet_adds_up_the_segments_of_a_split_shift(): void
    {
        [, , $employee] = $this->createCompanyWithEmployee('PT Alpha', 'ALPHA');
        Attendance::query()->delete();

        $this->createAttendance($employee, '2026-11-02', '07:45', '13:00', ['segment' => 1]);
        $this->createAttendance($employee, '2026-11-02', '17:45', '22:00', ['segment' => 2, 'status' => 'late', 'late_minutes' => 5]);

        $sheet = $this->exportToSpreadsheet('2026-11-02', '2026-11-02')->getSheetByName('Rekap');

        $this->assertSame(["07:45\n17:45", "13:00\n22:00"], $this->rowValues($sheet, 3, 'J', 'K'));
        $this->assertEqualsWithDelta(9.5, $sheet->getCell('L3')->getValue(), 0.001);
        $this->assertSame([1, 1, 5], $this->rowValues($sheet, 3, 'F', 'H'));
        $this->assertEqualsWithDelta(9.5, $sheet->getCell('I3')->getValue(), 0.001);
        $this->assertNull($sheet->getCell('A4')->getValue());
    }

    public function test_export_recap_sheet_keeps_a_numeric_worker_id_as_text(): void
    {
        [, , $employee] = $this->createCompanyWithEmployee('PT Alpha', 'ALPHA');
        $employee->update(['nip' => '1234567890123456']);

        $sheet = $this->exportToSpreadsheet(today()->toDateString(), today()->toDateString())->getSheetByName('Rekap');

        $this->assertSame('1234567890123456', $sheet->getCell('B3')->getValue());
    }

    public function test_export_detail_sheet_keeps_one_row_per_attendance_with_total_hours(): void
    {
        [, , $employee] = $this->createCompanyWithEmployee('PT Alpha', 'ALPHA');
        Attendance::query()->delete();

        $this->createAttendance($employee, '2026-11-02', '08:00', '16:30');
        $this->createAttendance($employee, '2026-11-03', '08:20', null, ['status' => 'late', 'late_minutes' => 20]);

        $sheet = $this->exportToSpreadsheet('2026-11-01', '2026-11-04')->getSheetByName('Detail');

        $this->assertSame(['Tanggal', 'Jam Check In', 'Jam Check Out', 'Jumlah Jam', 'Foto Check-In'], $this->rowValues($sheet, 1, 'F', 'J'));
        $this->assertSame('Keterangan / Notes', $sheet->getCell('P1')->getValue());

        // Newest first, as on the page.
        $this->assertSame(['03/11/2026', '08:20:00', '-', '-'], $this->rowValues($sheet, 2, 'F', 'I'));
        $this->assertSame(['02/11/2026', '08:00:00', '16:30:00'], $this->rowValues($sheet, 3, 'F', 'H'));
        $this->assertEqualsWithDelta(8.5, $sheet->getCell('I3')->getValue(), 0.001);
        $this->assertSame('Terlambat', $sheet->getCell('N2')->getValue());
    }

    public function test_export_button_downloads_the_filtered_range(): void
    {
        [, , $employee] = $this->createCompanyWithEmployee('PT Alpha', 'ALPHA');
        Attendance::query()->delete();
        $this->createAttendance($employee, '2026-11-02', '08:00', '16:30');
        $this->createAttendance($employee, '2026-10-30', '08:00', '16:30');

        $superadminUser = User::factory()->create();
        $superadminUser->assignRole('Superadmin');
        $this->actingAs($superadminUser);

        Excel::fake();

        $page = new LaporanAbsensi;
        $page->filterData = ['date_from' => '2026-11-01', 'date_to' => '2026-11-07'];
        $page->exportExcel();

        Excel::assertDownloaded('laporan-rekap-absensi.xlsx', function (AttendanceReportExport $export): bool {
            [$recap] = $export->sheets();
            $rows = $recap->array();

            // Seven days of three columns after the nine worker and total columns, and only November's attendance.
            return count($rows[0]) === 9 + 7 * 3
                && $rows[0][9] === 'Min, 01 Nov 2026'
                && $rows[2][0] === 'Karyawan PT Alpha'
                && $rows[2][5] === 1;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createAttendance(Employee $employee, string $date, ?string $checkIn, ?string $checkOut, array $attributes = []): Attendance
    {
        return Attendance::create([
            'employee_id' => $employee->id,
            'date' => $date,
            'check_in' => $checkIn ? Carbon::parse("{$date} {$checkIn}") : null,
            'check_out' => $checkOut ? Carbon::parse("{$date} {$checkOut}") : null,
            'status' => 'on_time',
            ...$attributes,
        ]);
    }

    /**
     * Build the workbook the export button downloads and read it back.
     */
    private function exportToSpreadsheet(string $from, string $to): Spreadsheet
    {
        $superadminUser = User::factory()->create();
        $superadminUser->assignRole('Superadmin');
        $this->actingAs($superadminUser);

        $page = new LaporanAbsensi;
        $page->filterData = ['date_from' => $from, 'date_to' => $to];

        $path = tempnam(sys_get_temp_dir(), 'laporan').'.xlsx';
        file_put_contents($path, Excel::raw(
            new AttendanceReportExport($page->getReportDataProperty(), Carbon::parse($from), Carbon::parse($to)),
            ExcelWriter::XLSX,
        ));

        try {
            return IOFactory::load($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * @return list<mixed>
     */
    private function rowValues(Worksheet $sheet, int $row, string $firstColumn, string $lastColumn): array
    {
        return $sheet->rangeToArray("{$firstColumn}{$row}:{$lastColumn}{$row}", null, false, false)[0];
    }
}
