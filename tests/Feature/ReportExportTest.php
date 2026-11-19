<?php

namespace Tests\Feature;

use App\Exports\AttendanceReportExport;
use App\Filament\Pages\LaporanAbsensi;
use App\Filament\Resources\Attendances\Pages\ListAttendances;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
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
        LeaveRequest::create(['employee_id' => $employeeA->id, 'leave_type' => 'annual', 'start_date' => '2026-11-04', 'end_date' => '2026-11-04', 'days_count' => 1, 'reason' => 'Keperluan keluarga', 'status' => 'approved']);
        $this->createAttendance($employeeB, '2026-11-03', '09:00', '17:00');

        $sheet = $this->exportToSpreadsheet('2026-11-01', '2026-11-04')->getSheetByName('Rekap');

        $this->assertSame(['Nama', 'NIP / ID', 'Tipe', 'Badan Usaha', 'Divisi', 'Total'], $this->rowValues($sheet, 1, 'A', 'F'));
        $this->assertSame(['Hari Masuk', 'Terlambat (kali)', 'Telat (menit)', 'Pulang Cepat (menit)', 'Lembur (jam)', 'Jumlah Jam'], $this->rowValues($sheet, 2, 'F', 'K'));

        // One group of three columns per day of the selected range, including days nobody worked.
        $this->assertSame('Min, 01 Nov 2026', $sheet->getCell('L1')->getValue());
        $this->assertSame('Sen, 02 Nov 2026', $sheet->getCell('O1')->getValue());
        $this->assertSame('Rab, 04 Nov 2026', $sheet->getCell('U1')->getValue());
        $this->assertSame(['Masuk', 'Pulang', 'Jam'], $this->rowValues($sheet, 2, 'O', 'Q'));
        $this->assertArrayHasKey('O1:Q1', $sheet->getMergeCells());

        // The totals added later follow the dates, so every earlier column keeps its place.
        $this->assertSame('Total Lainnya', $sheet->getCell('X1')->getValue());
        $this->assertSame(['Cuti (hari)', 'Tidak Hadir (hari)', 'Libur (hari)', 'Lupa Absen Pulang (hari)', 'Jam Normal', 'Lembur Otomatis (jam)', 'Lembur Pengajuan (jam)'], $this->rowValues($sheet, 2, 'X', 'AD'));
        $this->assertNull($sheet->getCell('AE1')->getValue());
        $this->assertArrayHasKey('X1:AD1', $sheet->getMergeCells());

        // One row per worker, sorted by company.
        $this->assertSame(['Karyawan PT Alpha', 'NIPALPHA', 'Karyawan', 'PT Alpha', 'Divisi ALPHA'], $this->rowValues($sheet, 3, 'A', 'E'));
        $this->assertSame('Karyawan PT Beta', $sheet->getCell('A4')->getValue());
        $this->assertNull($sheet->getCell('A5')->getValue());

        // Totals: two days present, one of them late, 8.5 hours worked.
        $this->assertSame([2, 1, 20], $this->rowValues($sheet, 3, 'F', 'H'));
        $this->assertEqualsWithDelta(8.5, $sheet->getCell('K3')->getValue(), 0.001);
        $this->assertSame('0.00', $sheet->getStyle('K3')->getNumberFormat()->getFormatCode());

        $this->assertSame([null, null, null], $this->rowValues($sheet, 3, 'L', 'N'));
        $this->assertSame(['08:00', '16:30'], $this->rowValues($sheet, 3, 'O', 'P'));
        $this->assertEqualsWithDelta(8.5, $sheet->getCell('Q3')->getValue(), 0.001);

        // No check-out yet: the hours stay empty and the late check-in is highlighted.
        $this->assertSame(['08:20', '-', null], $this->rowValues($sheet, 3, 'R', 'T'));
        $this->assertSame('FDE68A', $sheet->getStyle('R3')->getFill()->getStartColor()->getRGB());
        $this->assertNotSame('FDE68A', $sheet->getStyle('O3')->getFill()->getStartColor()->getRGB());

        // A day without a check-in shows its status across the day's columns.
        $this->assertSame('Cuti / Izin', $sheet->getCell('U3')->getValue());
        $this->assertArrayHasKey('U3:W3', $sheet->getMergeCells());

        $this->assertSame([1, 0, 0], $this->rowValues($sheet, 4, 'F', 'H'));
        $this->assertEqualsWithDelta(8.0, $sheet->getCell('K4')->getValue(), 0.001);
        $this->assertSame(['09:00', '17:00'], $this->rowValues($sheet, 4, 'R', 'S'));

        // One leave day and one forgotten check-out for the first worker, none for the second.
        $this->assertSame([1, 0, 0, 1], $this->rowValues($sheet, 3, 'X', 'AA'));
        $this->assertSame([0, 0, 0, 0], $this->rowValues($sheet, 4, 'X', 'AA'));
    }

    public function test_export_recap_sheet_lists_a_scheduled_worker_without_any_attendance(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 09:00'));
        [$company, , $present] = $this->createCompanyWithEmployee('PT Alpha', 'ALPHA');
        [, , $noShow] = $this->createCompanyWithEmployee('PT Beta', 'BETA');
        Attendance::query()->delete();
        Employee::query()->update(['join_date' => '2026-01-05']);

        $shift = Shift::create(['company_id' => $company->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '17:00']);
        ShiftSchedule::create(['employee_id' => $present->id, 'date' => '2026-11-02', 'shift_id' => $shift->id]);
        $this->createAttendance($present, '2026-11-02', '08:00', '17:00');
        // Scheduled on Monday and never came, a day off on Tuesday, nothing planned on Wednesday.
        ShiftSchedule::create(['employee_id' => $noShow->id, 'date' => '2026-11-02', 'shift_id' => $shift->id]);
        ShiftSchedule::create(['employee_id' => $noShow->id, 'date' => '2026-11-03', 'is_day_off' => true]);

        $sheet = $this->exportToSpreadsheet('2026-11-02', '2026-11-04')->getSheetByName('Rekap');

        $this->assertSame('Karyawan PT Beta', $sheet->getCell('A4')->getValue());
        $this->assertSame([0, 0, 0], $this->rowValues($sheet, 4, 'F', 'H'));
        $this->assertSame('Tidak Hadir', $sheet->getCell('L4')->getValue());
        $this->assertSame('Libur', $sheet->getCell('O4')->getValue());
        $this->assertNull($sheet->getCell('R4')->getValue());
        // Cuti, Tidak Hadir, Libur after the three dates.
        $this->assertSame([0, 1, 1], $this->rowValues($sheet, 4, 'U', 'W'));

        // The detail list names the absent day too.
        $detail = $this->exportToSpreadsheet('2026-11-02', '2026-11-04')->getSheetByName('Detail');
        $absent = collect($detail->toArray(null, false, false))->first(fn (array $row): bool => $row[3] === 'Karyawan PT Beta');

        $this->assertSame(['02/11/2026', '-', '-', '-'], array_slice($absent, 5, 4));
        $this->assertSame('Tidak Hadir', $absent[13]);
        $this->assertSame('Pagi', $absent[17]);
    }

    public function test_export_reports_a_long_shift_with_normal_hours_as_automatic_overtime(): void
    {
        [$company, , $employee] = $this->createCompanyWithEmployee('PT Alpha', 'ALPHA');
        Attendance::query()->delete();

        $long = Shift::create(['company_id' => $company->id, 'name' => 'Long Shift', 'start_time' => '08:45', 'end_time' => '22:30', 'normal_minutes' => 480]);
        $schedule = ShiftSchedule::create(['employee_id' => $employee->id, 'date' => '2026-11-03', 'shift_id' => $long->id]);
        // Checked in through the app: the row keeps the schedule and the normal hours that applied.
        $this->createAttendance($employee, '2026-11-03', '08:40', '22:35', [
            'shift_id' => $long->id, 'shift_schedule_id' => $schedule->id, 'sched_start_at' => '2026-11-03 08:45:00', 'sched_end_at' => '2026-11-03 22:30:00',
            'sched_scheduled_minutes' => 825, 'sched_normal_minutes' => 480, 'sched_shift_name' => 'Long Shift', 'sched_source' => 'schedule',
        ]);
        OvertimeRequest::create(['employee_id' => $employee->id, 'date' => '2026-11-03', 'start_time' => '22:30', 'end_time' => '23:30', 'duration_minutes' => 60, 'reason' => 'Tutup kasir', 'status' => 'approved']);

        $workbook = $this->exportToSpreadsheet('2026-11-03', '2026-11-03');
        $recap = $workbook->getSheetByName('Rekap');

        // Lembur (jam) is the automatic overtime plus the approved request; the parts are listed after the date.
        $this->assertEqualsWithDelta(6.75, $recap->getCell('J3')->getValue(), 0.001);
        $this->assertEqualsWithDelta(13.92, $recap->getCell('K3')->getValue(), 0.01);
        $this->assertEqualsWithDelta(8.0, $recap->getCell('S3')->getValue(), 0.001);
        $this->assertEqualsWithDelta(5.75, $recap->getCell('T3')->getValue(), 0.001);
        $this->assertEqualsWithDelta(1.0, $recap->getCell('U3')->getValue(), 0.001);

        $detail = $workbook->getSheetByName('Detail');

        $this->assertSame(['Long Shift', '-', '08:45 - 22:30'], $this->rowValues($detail, 2, 'R', 'T'));
        $this->assertEqualsWithDelta(5.75, $detail->getCell('U2')->getValue(), 0.001);
    }

    public function test_web_report_and_attendance_list_show_the_schedule_kept_on_the_row(): void
    {
        [$company, , $employee] = $this->createCompanyWithEmployee('PT Alpha', 'ALPHA');
        Attendance::query()->delete();

        $outlet = CompanyLocation::create(['company_id' => $company->id, 'name' => 'Luuca Ciledug', 'latitude' => -6.2, 'longitude' => 106.7]);
        $long = Shift::create(['company_id' => $company->id, 'name' => 'Long Shift', 'start_time' => '08:45', 'end_time' => '22:30', 'normal_minutes' => 480]);
        $schedule = ShiftSchedule::create(['employee_id' => $employee->id, 'date' => '2026-11-03', 'shift_id' => $long->id, 'company_location_id' => $outlet->id]);
        $snapshot = $this->createAttendance($employee, '2026-11-03', '08:40', '22:35', [
            'shift_id' => $long->id, 'shift_schedule_id' => $schedule->id, 'company_location_id' => $outlet->id,
            'sched_start_at' => '2026-11-03 08:45:00', 'sched_end_at' => '2026-11-03 22:30:00', 'sched_scheduled_minutes' => 825, 'sched_normal_minutes' => 480,
            'sched_shift_name' => 'Long Shift', 'sched_outlet_name' => 'Luuca Ciledug', 'sched_source' => 'schedule',
        ]);
        // The roster and the shift master change afterwards: the row keeps what applied.
        $long->update(['name' => 'Long Shift Baru', 'normal_minutes' => 600]);
        $outlet->update(['name' => 'Luuca Pindah']);
        // A row from before the snapshot existed is read against the company hours.
        $legacy = $this->createAttendance($employee, '2026-11-04', '08:00', null);

        $superadminUser = User::factory()->create();
        $superadminUser->assignRole('Superadmin');
        $this->actingAs($superadminUser);

        Livewire::test(LaporanAbsensi::class)
            ->set('filterData.date_from', '2026-11-01')
            ->set('filterData.date_to', '2026-11-07')
            ->assertTableColumnStateSet('shift.name', 'Long Shift', $snapshot)
            ->assertTableColumnStateSet('outlet', 'Luuca Ciledug', $snapshot)
            ->assertTableColumnStateSet('jadwal', '08:45 - 22:30', $snapshot)
            ->assertTableColumnStateSet('jam_kerja', '13j 55m', $snapshot)
            ->assertTableColumnStateSet('lembur', '5j 45m', $snapshot)
            ->assertTableColumnStateSet('jadwal', '08:00 - 17:00', $legacy)
            ->assertTableColumnStateSet('jam_kerja', null, $legacy)
            ->assertTableColumnStateSet('lembur', null, $legacy)
            // The figures above the table come from the same ledger as the export.
            ->assertSeeInOrder(['Hadir', '2 hari', 'Lupa Absen Pulang', '1 hari', 'Jam Kerja', '13,92 jam', 'Lembur', '5,75 jam']);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ListAttendances::class)
            ->assertTableColumnStateSet('schedule_shift', 'Long Shift', $snapshot)
            ->assertTableColumnStateSet('schedule_outlet', 'Luuca Ciledug', $snapshot)
            ->assertTableColumnStateSet('schedule_hours', '08:45 - 22:30', $snapshot)
            ->assertTableColumnStateSet('schedule_session', 'Jadwal 1', $snapshot);
    }

    public function test_export_recap_sheet_adds_up_the_segments_of_a_split_shift(): void
    {
        [, , $employee] = $this->createCompanyWithEmployee('PT Alpha', 'ALPHA');
        Attendance::query()->delete();

        $this->createAttendance($employee, '2026-11-02', '07:45', '13:00', ['segment' => 1]);
        $this->createAttendance($employee, '2026-11-02', '17:45', '22:00', ['segment' => 2, 'status' => 'late', 'late_minutes' => 5]);

        $sheet = $this->exportToSpreadsheet('2026-11-02', '2026-11-02')->getSheetByName('Rekap');

        $this->assertSame(["07:45\n17:45", "13:00\n22:00"], $this->rowValues($sheet, 3, 'L', 'M'));
        $this->assertEqualsWithDelta(9.5, $sheet->getCell('N3')->getValue(), 0.001);
        $this->assertSame([1, 1, 5], $this->rowValues($sheet, 3, 'F', 'H'));
        $this->assertEqualsWithDelta(9.5, $sheet->getCell('K3')->getValue(), 0.001);
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
        $this->assertSame('Menit Pulang Cepat', $sheet->getCell('P1')->getValue());
        $this->assertSame('Keterangan / Notes', $sheet->getCell('Q1')->getValue());
        // Added after the existing columns: the schedule of the row and its automatic overtime.
        $this->assertSame(['Shift', 'Outlet', 'Jadwal', 'Lembur Otomatis (jam)'], $this->rowValues($sheet, 1, 'R', 'U'));
        $this->assertNull($sheet->getCell('V1')->getValue());

        // Newest first, as on the page.
        $this->assertSame(['03/11/2026', '08:20:00', '-', '-'], $this->rowValues($sheet, 2, 'F', 'I'));
        $this->assertSame(['02/11/2026', '08:00:00', '16:30:00'], $this->rowValues($sheet, 3, 'F', 'H'));
        $this->assertEqualsWithDelta(8.5, $sheet->getCell('I3')->getValue(), 0.001);
        $this->assertSame('Terlambat', $sheet->getCell('N2')->getValue());
        $this->assertSame(20, $sheet->getCell('O2')->getValue());
        // No shift on the row: it is read against the company hours.
        $this->assertSame(['-', '-', '08:00 - 17:00'], $this->rowValues($sheet, 3, 'R', 'T'));
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

            // Seven days of three columns after the eleven worker and total columns, then the seven totals
            // added later, and only November's attendance.
            return count($rows[0]) === 11 + 7 * 3 + 7
                && $rows[0][11] === 'Min, 01 Nov 2026'
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
        file_put_contents($path, Excel::raw(new AttendanceReportExport($page->ledgerQuery()), ExcelWriter::XLSX));

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
