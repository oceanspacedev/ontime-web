<?php

namespace Tests\Feature\QaProbe;

use App\Enums\DataScope;
use App\Exports\AttendanceReportExport;
use App\Filament\Imports\EmployeeImporter;
use App\Filament\Pages\LaporanAbsensi;
use App\Models\ApprovalFlow;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Holiday;
use App\Models\Intern;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\Resignation;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TeamRecapService;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * QA-STAGING.md ROL-036..ROL-050: reports, team recap, exports, leave calendar, resignation, import and
 * the platform monitor, executed locally against the oracle in the catalog. Failing tests are open findings.
 */
class ROLCProbeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $klienA;

    private Tenant $klienB;

    private Company $companyA;

    private Company $companyB;

    private Division $sales;

    private Division $finance;

    private Division $divisionB;

    private Shift $morning;

    private User $adminA;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Queue::fake();
        Http::fake();

        // Sunday 15 November 2026: the first two weeks of the month are in the past.
        $this->travelTo(Carbon::parse('2026-11-15 12:00'));

        foreach (['Superadmin', 'Admin', 'PIC', 'Koordinator', 'Approver', 'Employee', 'Intern', 'Freelancer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->klienA = Tenant::factory()->create(['name' => 'Klien QA', 'code' => 'QA']);
        $this->klienB = Tenant::factory()->create(['name' => 'Klien QB', 'code' => 'QB']);
        $this->companyA = $this->company($this->klienA, 'PT A1', 'SAMA');
        $this->companyB = $this->company($this->klienB, 'PT B1', 'SAMA');
        $this->sales = Division::create(['company_id' => $this->companyA->id, 'name' => 'SALES', 'is_active' => true]);
        $this->finance = Division::create(['company_id' => $this->companyA->id, 'name' => 'FINANCE', 'is_active' => true]);
        $this->divisionB = Division::create(['company_id' => $this->companyB->id, 'name' => 'SALES', 'is_active' => true]);
        $this->morning = Shift::create(['company_id' => $this->companyA->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '17:00']);

        $this->adminA = $this->account($this->klienA, ['Admin'], DataScope::All);
    }

    /**
     * A rejected check-in keeps its check_in time; the report must not count that one worker-day as both
     * present and absent.
     *
     * @see ROL-036
     */
    public function test_rol_036_rejected_check_in_is_not_both_present_and_absent(): void
    {
        $worker = $this->freelancer('Ditolak', $this->sales);
        Attendance::create(['freelancer_id' => $worker->id, 'date' => '2026-11-02', 'check_in' => '2026-11-02 08:30:00', 'status' => 'rejected', 'late_minutes' => 30]);

        $summary = $this->summary('2026-11-02', '2026-11-02');

        $this->assertSame(1, $summary['total_present'] + $summary['total_absent'], 'One worker-day is either present or absent, never both: '.json_encode($summary));
        $this->assertSame(0, $summary['total_present']);
        $this->assertSame(1, $summary['total_absent']);
    }

    /**
     * Laporan absensi and the team recap agree on present/absent worker-days for a scheduled worker, and a
     * split shift is one present day with the hours of both segments.
     *
     * @see ROL-036
     */
    public function test_rol_036_report_and_team_recap_reconcile_worker_days(): void
    {
        $worker = $this->freelancer('Rekonsiliasi', $this->sales);
        // Mon 2: split shift, both segments. Tue 3: day off. Wed 4: scheduled, no attendance.
        $this->schedule($worker, '2026-11-02');
        ShiftSchedule::create(['freelancer_id' => $worker->id, 'date' => '2026-11-03', 'is_day_off' => true]);
        $this->schedule($worker, '2026-11-04');
        Attendance::create(['freelancer_id' => $worker->id, 'date' => '2026-11-02', 'segment' => 1, 'check_in' => '2026-11-02 08:00:00', 'check_out' => '2026-11-02 12:00:00', 'status' => 'on_time']);
        Attendance::create(['freelancer_id' => $worker->id, 'date' => '2026-11-02', 'segment' => 2, 'check_in' => '2026-11-02 17:00:00', 'check_out' => '2026-11-02 21:00:00', 'status' => 'on_time']);

        $response = $this->report('2026-11-02', '2026-11-04');
        $this->assertSame(['total_present' => 1, 'total_late' => 0, 'total_leave' => 0, 'total_absent' => 1], array_intersect_key($response['summary'], array_flip(['total_present', 'total_late', 'total_leave', 'total_absent'])));
        $this->assertSame(2, $response['pagination']['total'], 'The list is per row (segment), the summary per worker-day.');

        [$row] = app(TeamRecapService::class)->recap($this->adminA, Carbon::parse('2026-11-01'));
        $this->assertSame([1, 1, 1, 8.0], [$row['present_days'], $row['absent_days'], $row['off_days'], $row['worked_hours']]);
    }

    /**
     * Two workers on leave on the same day are two worker-days of leave.
     *
     * @see ROL-039
     */
    public function test_rol_039_two_workers_on_leave_the_same_day_are_two_leave_days(): void
    {
        $first = $this->freelancer('Cuti Satu', $this->sales);
        $second = $this->freelancer('Cuti Dua', $this->finance);
        // Leave Mon 2 - Sat 7, report Thu 5 - Mon 9: Thu, Fri, Sat inside the range for each worker.
        $this->leave($first, '2026-11-02', '2026-11-07');
        $this->leave($second, '2026-11-02', '2026-11-07');

        $summary = $this->summary('2026-11-05', '2026-11-09');

        $this->assertSame(6, $summary['total_leave']);
        // Mon 9 is the only working day after the leave: one absence each.
        $this->assertSame(2, $summary['total_absent']);
    }

    /**
     * A national holiday on a weekday is not a no-show without an explicit rule.
     *
     * @see ROL-040
     */
    public function test_rol_040_national_holiday_is_not_counted_as_absent(): void
    {
        $this->freelancer('Libur Nasional', $this->sales);
        Holiday::create(['name' => 'Libur Nasional QA', 'date' => '2026-11-03', 'is_national' => true]);

        $this->assertSame(0, $this->summary('2026-11-03', '2026-11-03')['total_absent']);
    }

    /**
     * A Sunday the roster makes a working day, without attendance, is an absence; it must not vanish.
     *
     * @see ROL-040
     */
    public function test_rol_040_scheduled_sunday_without_attendance_is_absent(): void
    {
        $worker = $this->freelancer('Minggu Kerja', $this->sales);
        $this->rosteredBefore($worker);
        $this->schedule($worker, '2026-11-08');

        $this->assertSame(1, $this->summary('2026-11-08', '2026-11-08')['total_absent']);

        [$row] = app(TeamRecapService::class)->recap($this->adminA, Carbon::parse('2026-11-01'));
        $this->assertSame(1, $row['absent_days'], 'The team recap does count it, so the two sources disagree.');
    }

    /**
     * Approved leave on a scheduled working day is leave, not a no-show, in the team recap and its export.
     *
     * @see ROL-041
     */
    public function test_rol_041_approved_leave_on_a_scheduled_day_is_not_absent_in_team_recap(): void
    {
        $worker = $this->freelancer('Cuti Terjadwal', $this->sales);
        $this->rosteredBefore($worker);
        $this->schedule($worker, '2026-11-04');
        $this->leave($worker, '2026-11-04', '2026-11-04');

        [$row] = app(TeamRecapService::class)->recap($this->adminA, Carbon::parse('2026-11-01'));

        $this->assertSame(0, $row['absent_days'], 'Approved leave was counted as "Tidak Hadir" in the team recap.');
        $this->assertSame(1, $this->summary('2026-11-04', '2026-11-04')['total_leave'], 'Laporan absensi counts the same day as leave.');
    }

    /**
     * A split-shift day whose first segment has no check-in but whose second segment was worked is a
     * present day with the second segment's hours; the order of the rows does not decide presence.
     *
     * @see ROL-042
     */
    public function test_rol_042_empty_first_segment_does_not_hide_a_worked_second_segment_in_team_recap(): void
    {
        $worker = $this->freelancer('Segmen Dua', $this->sales);
        $this->schedule($worker, '2026-11-02');
        Attendance::create(['freelancer_id' => $worker->id, 'date' => '2026-11-02', 'segment' => 1, 'check_in' => null, 'status' => 'absent']);
        Attendance::create(['freelancer_id' => $worker->id, 'date' => '2026-11-02', 'segment' => 2, 'check_in' => '2026-11-02 17:00:00', 'check_out' => '2026-11-02 21:00:00', 'status' => 'on_time']);

        [$row] = app(TeamRecapService::class)->recap($this->adminA, Carbon::parse('2026-11-01'));

        $this->assertSame([1, 0, 4.0], [$row['present_days'], $row['absent_days'], $row['worked_hours']]);
    }

    /**
     * Same fixture on laporan absensi: the day is present, not also absent.
     *
     * @see ROL-042
     */
    public function test_rol_042_empty_first_segment_is_one_present_day_in_the_report(): void
    {
        $worker = $this->freelancer('Segmen Dua API', $this->sales);
        Attendance::create(['freelancer_id' => $worker->id, 'date' => '2026-11-02', 'segment' => 1, 'check_in' => null, 'status' => 'absent']);
        Attendance::create(['freelancer_id' => $worker->id, 'date' => '2026-11-02', 'segment' => 2, 'check_in' => '2026-11-02 17:00:00', 'check_out' => '2026-11-02 21:00:00', 'status' => 'on_time']);

        $summary = $this->summary('2026-11-02', '2026-11-02');

        $this->assertSame([1, 0], [$summary['total_present'], $summary['total_absent']], json_encode($summary));
    }

    /**
     * Changing the company filter to another Klien's company exports nothing of that Klien.
     *
     * @see ROL-043
     */
    public function test_rol_043_export_with_a_tampered_foreign_company_filter_leaks_nothing(): void
    {
        $own = $this->freelancer('Pekerja Klien A', $this->sales);
        $foreign = Freelancer::create(['company_id' => $this->companyB->id, 'division_id' => $this->divisionB->id, 'full_name' => 'Pekerja Klien B', 'status' => 'active']);
        Attendance::create(['freelancer_id' => $own->id, 'date' => '2026-11-02', 'check_in' => '2026-11-02 08:00:00', 'status' => 'on_time']);
        Attendance::create(['freelancer_id' => $foreign->id, 'date' => '2026-11-02', 'check_in' => '2026-11-02 08:00:00', 'status' => 'on_time']);

        $this->actingAs($this->adminA);
        Excel::fake();

        Livewire::test(LaporanAbsensi::class)
            ->set('filterData.date_from', '2026-11-01')
            ->set('filterData.date_to', '2026-11-07')
            ->set('filterData.company_id', $this->companyB->id)
            ->call('exportExcel');

        Excel::assertDownloaded('laporan-rekap-absensi.xlsx', function (AttendanceReportExport $export): bool {
            [$recap] = $export->sheets();

            return ! str_contains(json_encode($recap->array()), 'Pekerja Klien B');
        });
    }

    /**
     * The web report shows an explicit absent row as absent, as the export does, not as "Hadir".
     *
     * @see ROL-043
     */
    public function test_rol_043_web_report_labels_an_absent_row_as_absent(): void
    {
        $worker = $this->freelancer('Alpa', $this->sales);
        $absent = Attendance::create(['freelancer_id' => $worker->id, 'date' => '2026-11-02', 'check_in' => null, 'status' => 'absent']);

        $this->actingAs($this->adminA);

        Livewire::test(LaporanAbsensi::class)
            ->set('filterData.date_from', '2026-11-01')
            ->set('filterData.date_to', '2026-11-07')
            ->assertCanSeeTableRecords([$absent])
            ->assertTableColumnFormattedStateNotSet('status', 'Hadir', $absent);
    }

    /**
     * Scope is judged again at download: a manager whose data access was taken away gets no file from an
     * old link.
     *
     * @see ROL-045
     */
    public function test_rol_045_revoked_scope_blocks_an_old_signed_link(): void
    {
        $coordinator = $this->account($this->klienA, ['Koordinator']);
        $coordinator->grantDataAccess(DataScope::Divisions, divisionIds: [$this->sales->id]);
        Sanctum::actingAs($coordinator);

        $url = $this->getJson('/api/v1/team/recap/link?month=2026-11')->assertOk()->json('url');

        $coordinator->grantDataAccess(DataScope::Own);

        $this->get($url)->assertForbidden();
    }

    /**
     * An employee who passes another Klien's company to the leave calendar sees none of its leave.
     *
     * @see ROL-046
     */
    public function test_rol_046_calendar_company_tamper_shows_no_foreign_leave(): void
    {
        $employee = $this->employee($this->companyA, $this->sales, 'EMP-A');
        $foreign = Freelancer::create(['company_id' => $this->companyB->id, 'division_id' => $this->divisionB->id, 'full_name' => 'Cuti Klien B', 'status' => 'active']);
        $this->leave($foreign, '2026-11-10', '2026-11-11');

        Sanctum::actingAs($employee->user);

        $this->getJson("/api/v1/kalender-cuti?month=11&year=2026&company_id={$this->companyB->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * Intern and freelancer cannot file a resignation through the API.
     *
     * @see ROL-048
     */
    public function test_rol_048_intern_and_freelancer_cannot_file_a_resignation(): void
    {
        $freelancer = $this->freelancer('FL Resign', $this->sales);
        $freelancerUser = $this->account($this->klienA, ['Freelancer']);
        $freelancer->forceFill(['user_id' => $freelancerUser->id])->save();

        $internUser = $this->account($this->klienA, ['Intern']);
        Intern::create(['user_id' => $internUser->id, 'company_id' => $this->companyA->id, 'division_id' => $this->sales->id, 'nis' => 'MG-1', 'institution' => 'SMK QA', 'full_name' => 'Magang Resign', 'status' => 'active']);

        foreach ([$freelancerUser, $internUser] as $user) {
            Sanctum::actingAs($user);
            $this->postJson('/api/v1/resignations', ['resignation_date' => '2026-11-20', 'last_working_day' => '2026-11-30', 'reason' => 'Coba'])->assertUnprocessable();
        }

        $this->assertSame(0, Resignation::count());
    }

    /**
     * The nightly resignation job applies a resignation once: an employee rehired after an old, approved
     * resignation stays active.
     *
     * @see ROL-048
     */
    public function test_rol_048_nightly_job_does_not_deactivate_a_rehired_employee(): void
    {
        $employee = $this->employee($this->companyA, $this->sales, 'EMP-REHIRE');
        Resignation::create(['employee_id' => $employee->id, 'resignation_date' => '2025-06-01', 'last_working_day' => '2025-06-30', 'reason' => 'Pindah kota', 'status' => 'approved']);

        $this->artisan('employees:apply-resignations')->assertSuccessful();

        $this->assertSame('active', $employee->fresh()->status, 'The rehired employee was switched off again by the old resignation.');
        $this->assertTrue((bool) $employee->user->fresh()->is_active);
    }

    /**
     * A rehired employee whose earlier resignation was approved can still file a new one.
     *
     * @see ROL-048
     */
    public function test_rol_048_rehired_employee_can_file_a_new_resignation(): void
    {
        $approver = $this->account($this->klienA, ['Approver'], DataScope::All);
        ApprovalFlow::create(['company_id' => $this->companyA->id, 'request_type' => 'resignation', 'step_number' => 1, 'step_order' => 1, 'name' => 'Atasan', 'approver_type' => 'user', 'user_id' => $approver->id]);

        $employee = $this->employee($this->companyA, $this->sales, 'EMP-REHIRE-2');
        Resignation::create(['employee_id' => $employee->id, 'resignation_date' => '2025-06-01', 'last_working_day' => '2025-06-30', 'reason' => 'Pindah kota', 'status' => 'approved']);

        Sanctum::actingAs($employee->user);

        $response = $this->postJson('/api/v1/resignations', ['resignation_date' => '2026-11-20', 'last_working_day' => '2026-12-31', 'reason' => 'Melanjutkan studi']);

        $this->assertSame(201, $response->status(), (string) $response->json('message'));
    }

    /**
     * Admin divisi Sales: a row for Sales imports, a row for Finance or an unknown company fails with a
     * reason, importing the same NIP again updates rather than duplicates, and Klien B's same NIP is untouched.
     *
     * @see ROL-049
     */
    public function test_rol_049_division_admin_import_respects_scope_and_does_not_duplicate(): void
    {
        JobLevel::firstOrCreate(['name' => 'STAFF'], ['level_order' => 1]);
        $foreignTitle = JobTitle::create(['name' => 'STAFF B']);
        $foreignEmployee = Employee::create(['company_id' => $this->companyB->id, 'division_id' => $this->divisionB->id, 'job_level_id' => JobLevel::first()->id, 'job_title_id' => $foreignTitle->id, 'nip' => 'A-1', 'full_name' => 'MILIK KLIEN B', 'status' => 'active']);

        $admin = $this->account($this->klienA, ['Admin']);
        $admin->grantDataAccess(DataScope::Divisions, divisionIds: [$this->sales->id]);

        $import = Import::create(['file_name' => 'karyawan.csv', 'file_path' => 'karyawan.csv', 'importer' => EmployeeImporter::class, 'total_rows' => 4, 'user_id' => $admin->id]);
        $columns = ['nip' => 'NIP', 'full_name' => 'Nama', 'company' => 'Badan Usaha', 'division' => 'Divisi', 'job_level' => 'Level', 'job_title' => 'Posisi'];
        $run = fn (array $row) => ($import->getImporter($columns, []))(['Level' => 'STAFF', 'Posisi' => 'STAFF', 'Badan Usaha' => 'SAMA', ...$row]);

        $run(['NIP' => 'A-1', 'Nama' => 'LEGAL SALES', 'Divisi' => 'SALES']);

        foreach ([
            ['NIP' => 'A-2', 'Nama' => 'DIVISI LAIN', 'Divisi' => 'FINANCE'],
            ['NIP' => 'A-3', 'Nama' => 'TIDAK DIKENAL', 'Divisi' => 'SALES', 'Badan Usaha' => 'TIDAKADA'],
        ] as $row) {
            try {
                $run($row);
                $this->fail("Row {$row['NIP']} should have failed.");
            } catch (RowImportFailedException) {
                // Failed with a reason, as expected.
            }
        }

        $run(['NIP' => 'A-1', 'Nama' => 'LEGAL SALES ULANG', 'Divisi' => 'SALES']);

        $this->assertSame(1, Employee::query()->where('nip', 'A-1')->where('company_id', $this->companyA->id)->count());
        $this->assertSame('LEGAL SALES ULANG', Employee::query()->where('nip', 'A-1')->where('company_id', $this->companyA->id)->value('full_name'));
        $this->assertSame(0, Employee::query()->whereIn('nip', ['A-2', 'A-3'])->count());
        $this->assertSame('MILIK KLIEN B', $foreignEmployee->fresh()->full_name);
        $this->assertSame($this->companyB->id, $foreignEmployee->fresh()->company_id);
    }

    /**
     * A PIC (Admin + PIC, scope All of their Klien) and a Koordinator cannot open Horizon or the Log Viewer.
     *
     * @see ROL-050
     */
    public function test_rol_050_pic_and_coordinator_cannot_open_the_platform_monitor(): void
    {
        $pic = $this->account($this->klienA, ['Admin', 'PIC'], DataScope::All);
        $coordinator = $this->account($this->klienA, ['Koordinator'], DataScope::All);

        foreach ([$pic, $coordinator] as $user) {
            $this->actingAs($user)->get('/horizon')->assertForbidden();
            $this->actingAs($user)->get('/log-viewer')->assertForbidden();
        }
    }

    /**
     * @return array{total_present: int, total_late: int, total_leave: int, total_absent: int}
     */
    private function summary(string $from, string $until): array
    {
        return $this->report($from, $until)['summary'];
    }

    /**
     * @return array<string, mixed>
     */
    private function report(string $from, string $until): array
    {
        Sanctum::actingAs($this->adminA);

        return $this->getJson("/api/v1/laporan-absensi?start_date={$from}&end_date={$until}")->assertOk()->json();
    }

    private function company(Tenant $tenant, string $name, string $code): Company
    {
        $company = new Company(['name' => $name, 'code' => $code, 'is_active' => true]);
        $company->tenant_id = $tenant->id;
        $company->save();

        return $company;
    }

    /**
     * @param  list<string>  $roles
     */
    private function account(Tenant $tenant, array $roles, DataScope $scope = DataScope::Own): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['tenant_id' => $tenant->id])->save();
        $user->assignRole($roles);
        $user->grantDataAccess($scope);

        return $user->fresh();
    }

    private function freelancer(string $name, Division $division): Freelancer
    {
        return Freelancer::create(['company_id' => $division->company_id, 'division_id' => $division->id, 'full_name' => $name, 'status' => 'active']);
    }

    private function employee(Company $company, Division $division, string $nip): Employee
    {
        $user = $this->account($this->klienA, ['Employee']);

        return Employee::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'division_id' => $division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Staff'])->id,
            'nip' => $nip,
            'full_name' => 'Karyawan '.$nip,
            'join_date' => '2024-01-08',
            'status' => 'active',
        ]);
    }

    /**
     * On the roster since October (a day off on Monday 26 October), so the November weekdays read the roster only.
     */
    private function rosteredBefore(Freelancer $worker): void
    {
        ShiftSchedule::create(['freelancer_id' => $worker->id, 'date' => '2026-10-26', 'is_day_off' => true]);
    }

    private function schedule(Freelancer $worker, string $date): void
    {
        ShiftSchedule::create(['freelancer_id' => $worker->id, 'date' => $date, 'shift_id' => $this->morning->id]);
    }

    private function leave(Freelancer $worker, string $from, string $until): LeaveRequest
    {
        return LeaveRequest::create(['freelancer_id' => $worker->id, 'leave_type' => 'sick', 'start_date' => $from, 'end_date' => $until, 'days_count' => Carbon::parse($from)->diffInDays(Carbon::parse($until)) + 1, 'reason' => 'Keperluan', 'status' => 'approved']);
    }
}
