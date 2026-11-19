<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Exports\AttendanceReportExport;
use App\Filament\Pages\LaporanAbsensi;
use App\Filament\Widgets\EmployeeStatsWidget;
use App\Filament\Widgets\SuperadminOverviewWidget;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Services\ApprovalFlowService;
use App\Services\TeamRecapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * One seeded month read through every report: the team recap total, the total row of its Excel file, the
 * laporan absensi summary, the attendance list, the web report (its figures, its rows and the workbook it
 * downloads) and the dashboard widgets must give the same figures, for an admin, for the
 * coordinator of the outlet a worker was lent to (host) and for the coordinator of that worker's own
 * brand (home).
 *
 * The month holds an outlet company planned with dated roster rows (a split shift, a check-in waiting for
 * approval, a rejected one, a forgotten check-out, leave, a day off, a long shift with normal hours, an
 * overtime request, a lent worker) and a fixed-hours company (a default shift, the company hours).
 */
class ReportsReconcileTest extends TestCase
{
    use RefreshDatabase;

    private const MONTH = '2026-11';

    /** @var array<string, User> */
    private array $viewers = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Saturday 21 November 2026, noon.
        $this->travelTo(Carbon::parse('2026-11-21 12:00'));

        foreach (['Admin', 'Koordinator', 'Freelancer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->seedMonth();
    }

    /**
     * @return array<string, array{0: string, 1: array<string, int|float>}>
     */
    public static function viewers(): array
    {
        return [
            // Ani 4 + Budi 3 + Cici 3 + Dedi 2 + Eka 1 present days; 345 automatic and 60 requested overtime minutes.
            'admin' => ['admin', ['present_days' => 13, 'late_days' => 2, 'leave_days' => 4, 'absent_days' => 28, 'pending_days' => 1, 'missing_check_out_days' => 2, 'auto_overtime_hours' => 5.75, 'overtime_hours' => 6.75]],
            // Cici, and the one day Budi was lent to JChicken.
            'host coordinator' => ['host', ['present_days' => 4, 'late_days' => 1, 'leave_days' => 0, 'absent_days' => 0, 'pending_days' => 0, 'missing_check_out_days' => 0, 'auto_overtime_hours' => 5.75, 'overtime_hours' => 5.75]],
            // Ani and Budi, the lent day included.
            'home coordinator' => ['home', ['present_days' => 7, 'late_days' => 2, 'leave_days' => 2, 'absent_days' => 3, 'pending_days' => 1, 'missing_check_out_days' => 1, 'auto_overtime_hours' => 0.0, 'overtime_hours' => 1.0]],
        ];
    }

    /**
     * @param  array<string, int|float>  $expected
     */
    #[DataProvider('viewers')]
    public function test_every_report_gives_the_same_figures(string $viewer, array $expected): void
    {
        $user = $this->viewers[$viewer];
        Sanctum::actingAs($user);

        // 1. The team recap total.
        $recap = $this->getJson('/api/v1/team/recap?month='.self::MONTH)->assertOk()->json();
        $total = $recap['total'];

        $this->assertEquals($expected, array_intersect_key($total, $expected));

        // 2. The total row of the recap Excel.
        $excel = $this->excelTotalRow($user);

        $this->assertEquals(
            [$total['scheduled_days'], $total['present_days'], $total['absent_days'], $total['off_days'], $total['late_days'], $total['late_minutes'], $total['early_leave_days'], $total['early_leave_minutes'], $total['scheduled_hours'], $total['worked_hours'], $total['overtime_count'], $total['overtime_hours'], $total['pending_days'], $total['leave_days'], $total['missing_check_out_days'], $total['sessions_missed'], $total['normal_hours'], $total['auto_overtime_hours'], $total['request_overtime_hours'], $total['scheduled_days_to_date']],
            array_map('floatval', array_slice($excel, 6)),
        );
        $this->assertSame(count($recap['data']).' baris', $excel[4]);

        // 3. The laporan absensi summary of the same month.
        $laporan = $this->getJson('/api/v1/laporan-absensi?start_date=2026-11-01&end_date=2026-11-30')->assertOk()->json();

        $this->assertEquals(
            [
                'total_present' => $total['present_days'],
                'total_late' => $total['late_days'],
                'total_leave' => $total['leave_days'],
                'total_absent' => $total['absent_days'],
                'total_pending' => $total['pending_days'],
                'total_missing_check_out' => $total['missing_check_out_days'],
                'total_worked_hours' => $total['worked_hours'],
                'total_overtime_hours' => $total['overtime_hours'],
            ],
            $laporan['summary'],
        );

        // 4. The attendance rows: the list of laporan absensi and GET /attendances hold the same rows, and
        //    their minutes add up to the hours of the reports.
        $rows = $this->allRows('/api/v1/attendances?start_date=2026-11-01&end_date=2026-11-30');
        $reportRows = $this->allRows('/api/v1/laporan-absensi?start_date=2026-11-01&end_date=2026-11-30');

        $this->assertEqualsCanonicalizing(array_column($rows, 'id'), array_column($reportRows, 'id'));
        $this->assertSame($laporan['pagination']['total'], count($rows));
        $this->assertEquals($total['worked_hours'], round(array_sum(array_column($rows, 'worked_minutes')) / 60, 2));
        $this->assertEquals($total['auto_overtime_hours'], round(array_sum(array_column($rows, 'overtime_minutes')) / 60, 2));
        $this->assertSame($total['late_minutes'], array_sum(array_column(array_filter($rows, fn (array $row): bool => $row['status'] !== 'rejected'), 'reported_late_minutes')));
        $this->assertSame($total['missing_check_out_days'], count(array_filter($rows, fn (array $row): bool => $row['is_missing_check_out'])));

        // The recap lines add up to the total wherever a worker-day cannot be at two outlets.
        $this->assertEquals($total['worked_hours'], round(array_sum(array_column($recap['data'], 'worked_hours')), 2));
        $this->assertSame($total['overtime_count'], array_sum(array_column($recap['data'], 'overtime_count')));

        // 5. The web report of the same month: the figures above its table, and the workbook it downloads
        //    (the worker rows of the recap sheet add up to the totals, the detail rows to the hours).
        $page = new LaporanAbsensi;
        $page->filterData = ['date_from' => '2026-11-01', 'date_to' => '2026-11-30'];

        $this->assertSame(
            [
                'Hadir' => $total['present_days'].' hari',
                'Terlambat' => $total['late_days'].' hari',
                'Cuti / Izin' => $total['leave_days'].' hari',
                'Tidak Hadir' => $total['absent_days'].' hari',
                'Menunggu Persetujuan' => $total['pending_days'].' hari',
                'Lupa Absen Pulang' => $total['missing_check_out_days'].' hari',
                'Jam Kerja' => str_replace('.', ',', (string) $total['worked_hours']).' jam',
                'Lembur' => str_replace('.', ',', (string) $total['overtime_hours']).' jam',
            ],
            array_column($page->getSummaryProperty(), 'value', 'label'),
        );

        [$recapSheet, $detailSheet] = (new AttendanceReportExport($page->ledgerQuery()))->sheets();
        $grid = $recapSheet->array();
        $workerRows = array_filter(array_slice($grid, 2), fn (array $row): bool => count($row) > 1);
        $sum = fn (string $heading): float => round(array_sum(array_column($workerRows, array_search($heading, $grid[1], true))), 2);

        $this->assertEquals(
            [
                'Hari Masuk' => $total['present_days'],
                'Terlambat (kali)' => $total['late_days'],
                'Telat (menit)' => $total['late_minutes'],
                'Pulang Cepat (menit)' => $total['early_leave_minutes'],
                'Lembur (jam)' => $total['overtime_hours'],
                'Jumlah Jam' => $total['worked_hours'],
                'Cuti (hari)' => $total['leave_days'],
                'Tidak Hadir (hari)' => $total['absent_days'],
                'Libur (hari)' => $total['off_days'],
                'Lupa Absen Pulang (hari)' => $total['missing_check_out_days'],
                'Jam Normal' => $total['normal_hours'],
                'Lembur Otomatis (jam)' => $total['auto_overtime_hours'],
                'Lembur Pengajuan (jam)' => $total['request_overtime_hours'],
            ],
            [
                'Hari Masuk' => $sum('Hari Masuk'),
                'Terlambat (kali)' => $sum('Terlambat (kali)'),
                'Telat (menit)' => $sum('Telat (menit)'),
                'Pulang Cepat (menit)' => $sum('Pulang Cepat (menit)'),
                'Lembur (jam)' => $sum('Lembur (jam)'),
                'Jumlah Jam' => $sum('Jumlah Jam'),
                'Cuti (hari)' => $sum('Cuti (hari)'),
                'Tidak Hadir (hari)' => $sum('Tidak Hadir (hari)'),
                'Libur (hari)' => $sum('Libur (hari)'),
                'Lupa Absen Pulang (hari)' => $sum('Lupa Absen Pulang (hari)'),
                'Jam Normal' => $sum('Jam Normal'),
                'Lembur Otomatis (jam)' => $sum('Lembur Otomatis (jam)'),
                'Lembur Pengajuan (jam)' => $sum('Lembur Pengajuan (jam)'),
            ],
        );

        $details = $detailSheet->collection()->map(fn (array $row): array => array_combine($detailSheet->headings(), $detailSheet->map($row)));

        $this->assertEquals($total['worked_hours'], round($details->sum(fn (array $row): float => is_numeric($row['Jumlah Jam']) ? $row['Jumlah Jam'] : 0), 2));
        $this->assertEquals($total['auto_overtime_hours'], round($details->sum('Lembur Otomatis (jam)'), 2));
        $this->assertSame($total['late_minutes'], $details->sum('Menit Telat'));
        // Every attendance of the list with a check-in is a detail row; the other rows are the absent and
        // leave days (an absent day whose check-in was rejected is listed by that rejected attendance, a
        // leave day by its leave row or by the approved leave alone).
        $this->assertSame(count(array_filter($rows, fn (array $row): bool => $row['check_in'] !== null)), $details->where('Jam Check In', '!=', '-')->count());
        // A row of the list without a check-in is only ever a leave or absent day (never a stray placeholder),
        // and each of them is a "-" line of the detail sheet.
        $withoutCheckIn = array_values(array_filter($rows, fn (array $row): bool => $row['check_in'] === null));
        $this->assertSame([], array_values(array_diff(array_unique(array_column($withoutCheckIn, 'status')), ['leave', 'absent'])));
        $this->assertLessThanOrEqual($details->where('Jam Check In', '-')->count(), count($withoutCheckIn));
        $this->assertSame(
            $total['absent_days'] + $total['leave_days'],
            $details->where('Jam Check In', '-')->whereIn('Status Kehadiran', ['Tidak Hadir', 'Cuti / Izin'])->count() + $details->where('Status Kehadiran', 'Ditolak')->count(),
        );

        // The rows of the page are the rows of the app's report.
        $this->assertEqualsCanonicalizing(array_column($rows, 'id'), $page->getReportQuery()->pluck('id')->all());
    }

    public function test_a_check_out_after_midnight_and_a_rejected_check_out_reconcile(): void
    {
        $franchise = Company::where('code', 'PFC')->sole();
        $franchise->policy()->update(['require_gps' => true, 'geofence_radius_meters' => 100, 'require_photo' => false, 'require_face_recognition' => false, 'require_liveness' => false]);
        $jchickenBrand = Division::where('name', 'JCHICKEN')->sole();
        $jchicken = CompanyLocation::where('name', 'JChicken Ciledug')->sole();
        $closing = Shift::create(['company_id' => $franchise->id, 'name' => 'Closing', 'start_time' => '14:00', 'end_time' => '22:30']);
        $morning = Shift::where('name', 'Pagi')->sole();

        // Fani (JCHICKEN) closes Thursday 12 November after midnight, and on Friday her check-out from
        // outside the outlet is rejected by the host coordinator.
        $account = User::factory()->create(['is_active' => true]);
        $account->assignRole('Freelancer');
        $fani = Freelancer::create(['user_id' => $account->id, 'company_id' => $franchise->id, 'division_id' => $jchickenBrand->id, 'full_name' => 'Fani', 'status' => 'active']);
        ShiftSchedule::create(['freelancer_id' => $fani->id, 'date' => '2026-11-12', 'shift_id' => $closing->id, 'company_location_id' => $jchicken->id]);
        ShiftSchedule::create(['freelancer_id' => $fani->id, 'date' => '2026-11-13', 'shift_id' => $morning->id, 'company_location_id' => $jchicken->id]);
        $atOutlet = ['latitude' => -6.2, 'longitude' => 106.7];
        Sanctum::actingAs($account);

        $this->travelTo(Carbon::parse('2026-11-12 13:55'));
        $this->postJson('/api/v1/attendance/check-in', $atOutlet)->assertOk();
        $this->travelTo(Carbon::parse('2026-11-13 00:10'));
        $this->postJson('/api/v1/attendance/check-out', $atOutlet)->assertOk()->assertJsonPath('attendance.date', '2026-11-12');

        $this->travelTo(Carbon::parse('2026-11-13 07:58'));
        $this->postJson('/api/v1/attendance/check-in', $atOutlet)->assertOk();
        $this->travelTo(Carbon::parse('2026-11-13 17:05'));
        $pending = $this->postJson('/api/v1/attendance/check-out', ['latitude' => -6.3, 'longitude' => 106.9])->assertOk()->json('attendance.id');
        $this->actingAs($this->viewers['host']);
        app(ApprovalFlowService::class)->rejectStep(Attendance::findOrFail($pending), $this->viewers['host'], 'Pulang dari luar outlet');

        $this->travelTo(Carbon::parse('2026-11-21 12:00'));
        Sanctum::actingAs($this->viewers['admin']);
        $line = collect($this->getJson('/api/v1/team/recap?month='.self::MONTH)->assertOk()->json('data'))->firstWhere('worker.name', 'Fani');

        // Thursday is worked 13:55 - 00:10 (615 minutes); Friday is present with a missing check-out.
        $this->assertSame([2, 1, 10.25], [$line['present_days'], $line['missing_check_out_days'], (float) $line['worked_hours']]);

        // Every report still agrees, the admin figures grown by Fani's two days (and her eight weekdays
        // before the date of her first roster row, absent as for every worker of a company without rosters).
        $this->test_every_report_gives_the_same_figures('admin', ['present_days' => 15, 'late_days' => 2, 'leave_days' => 4, 'absent_days' => 36, 'pending_days' => 1, 'missing_check_out_days' => 3, 'auto_overtime_hours' => 5.75, 'overtime_hours' => 6.75]);
    }

    public function test_two_outlets_in_a_day_a_second_part_alone_and_a_cover_reconcile(): void
    {
        $franchise = Company::where('code', 'PFC')->sole();
        $franchise->policy()->update(['require_gps' => true, 'geofence_radius_meters' => 100, 'require_photo' => false, 'require_face_recognition' => false, 'require_liveness' => false]);
        $luucaBrand = Division::where('name', 'LUUCA')->sole();
        $luuca = CompanyLocation::where('name', 'Luuca Ciledug')->sole();
        $jchicken = CompanyLocation::where('name', 'JChicken Ciledug')->sole();
        $split = Shift::where('name', 'Split')->sole();
        $morning = Shift::where('name', 'Pagi')->sole();
        $luucaDay = Shift::create(['company_id' => $franchise->id, 'name' => 'Luuca Pagi', 'start_time' => '08:00', 'end_time' => '15:00']);
        $jchickenEvening = Shift::create(['company_id' => $franchise->id, 'name' => 'JChicken Sore', 'start_time' => '16:00', 'end_time' => '22:00']);

        // Gina (LUUCA): Tuesday 10 November at Luuca then JChicken, Wednesday only the second part of a split,
        // Thursday a day off on which she covers the morning shift at JChicken.
        $account = User::factory()->create(['is_active' => true]);
        $account->assignRole('Freelancer');
        $gina = Freelancer::create(['user_id' => $account->id, 'company_id' => $franchise->id, 'division_id' => $luucaBrand->id, 'full_name' => 'Gina', 'status' => 'active']);
        ShiftSchedule::create(['freelancer_id' => $gina->id, 'date' => '2026-11-10', 'sequence' => 1, 'shift_id' => $luucaDay->id, 'company_location_id' => $luuca->id]);
        ShiftSchedule::create(['freelancer_id' => $gina->id, 'date' => '2026-11-10', 'sequence' => 2, 'shift_id' => $jchickenEvening->id, 'company_location_id' => $jchicken->id]);
        ShiftSchedule::create(['freelancer_id' => $gina->id, 'date' => '2026-11-11', 'shift_id' => $split->id, 'company_location_id' => $luuca->id]);
        ShiftSchedule::create(['freelancer_id' => $gina->id, 'date' => '2026-11-12', 'is_day_off' => true]);
        $atOutlet = ['latitude' => -6.2, 'longitude' => 106.7];
        $punch = function (string $at, string $kind, array $data = []) use ($account, $atOutlet): array {
            $this->travelTo(Carbon::parse($at));
            Sanctum::actingAs($account);

            return $this->postJson("/api/v1/attendance/{$kind}", $atOutlet + $data)->assertOk()->json('attendance');
        };

        $first = $punch('2026-11-10 07:58', 'check-in');
        $this->assertSame([1, 1], [$first['schedule_sequence'], $first['segment']]);
        $punch('2026-11-10 15:05', 'check-out');
        $second = $punch('2026-11-10 15:55', 'check-in');
        $this->assertSame([2, 1], [$second['schedule_sequence'], $second['segment']]);
        $punch('2026-11-10 22:00', 'check-out');

        $this->assertSame(2, $punch('2026-11-11 17:40', 'check-in')['segment']);
        $punch('2026-11-11 22:00', 'check-out');

        $cover = $punch('2026-11-12 08:00', 'check-in', ['cover_shift_id' => $morning->id, 'cover_location_id' => $jchicken->id]);
        $this->assertSame($jchicken->id, $cover['outlet']['id']);
        $this->actingAs($this->viewers['home']);
        app(ApprovalFlowService::class)->approveStep(Attendance::findOrFail($cover['id']), $this->viewers['home']);
        $punch('2026-11-12 17:00', 'check-out');

        $this->assertSame($jchicken->id, (int) ShiftSchedule::where('freelancer_id', $gina->id)->whereDate('date', '2026-11-12')->sole()->company_location_id);

        $this->travelTo(Carbon::parse('2026-11-21 12:00'));
        Sanctum::actingAs($this->viewers['admin']);
        $lines = collect($this->getJson('/api/v1/team/recap?month='.self::MONTH)->assertOk()->json('data'))->where('worker.name', 'Gina')->keyBy('outlet');
        $figures = fn (array $line): array => array_intersect_key($line, array_flip(['present_days', 'absent_days', 'scheduled_days', 'sessions_scheduled', 'sessions_worked', 'sessions_missed', 'worked_hours']));

        // Tuesday is present at both outlets (427 minutes at Luuca, 365 at JChicken), Wednesday's split worked
        // only in its second part (260 minutes, the first part missed), Thursday's cover booked at JChicken
        // (540 minutes); the six weekdays before the date of her first roster row are absent on her home outlet.
        $this->assertEquals(['scheduled_days' => 2, 'present_days' => 2, 'absent_days' => 6, 'sessions_scheduled' => 3, 'sessions_worked' => 2, 'sessions_missed' => 1, 'worked_hours' => 11.45], $figures($lines['Luuca Ciledug']));
        $this->assertEquals(['scheduled_days' => 2, 'present_days' => 2, 'absent_days' => 0, 'sessions_scheduled' => 2, 'sessions_worked' => 2, 'sessions_missed' => 0, 'worked_hours' => 15.08], $figures($lines['JChicken Ciledug']));

        // The roster of the two-outlet day lists Gina at both outlets but counts her once, like the reports.
        $roster = $this->getJson('/api/v1/team/roster?date=2026-11-10')->assertOk();
        $laporan = $this->getJson('/api/v1/laporan-absensi?start_date=2026-11-10&end_date=2026-11-10')->assertOk()->json('summary');
        $this->assertSame(['Gina', 'Gina'], collect($roster->json('outlets'))->flatMap(fn (array $outlet): array => $outlet['entries'])->pluck('worker.name')->all());
        $this->assertSame($laporan['total_present'], $roster->json('summary.people_checked_in'));
        $this->assertSame(['people_scheduled' => 1, 'people_checked_in' => 1], $roster->json('summary'));

        // Every report still agrees, the admin figures grown by Gina's three present days (the two-outlet day
        // once in the totals) and her six absences.
        $this->test_every_report_gives_the_same_figures('admin', ['present_days' => 16, 'late_days' => 2, 'leave_days' => 4, 'absent_days' => 34, 'pending_days' => 1, 'missing_check_out_days' => 2, 'auto_overtime_hours' => 5.75, 'overtime_hours' => 6.75]);
    }

    public function test_a_company_on_shift_rosters_and_a_fixed_hours_company_reconcile_in_one_run(): void
    {
        // The franchise switches to shift rosters (D3); PT Kantor stays on fixed hours.
        $franchise = Company::where('code', 'PFC')->sole();
        $franchise->policy()->update(['uses_shift_roster' => true, 'require_gps' => false, 'require_photo' => false, 'require_face_recognition' => false, 'require_liveness' => false]);
        app(ApprovalFlowService::class)->createDefaultFlows($franchise);
        $luucaBrand = Division::where('name', 'LUUCA')->sole();
        $luuca = CompanyLocation::where('name', 'Luuca Ciledug')->sole();
        $morning = Shift::where('name', 'Pagi')->sole();
        $employee = fn (string $name, ?Shift $shift, ?User $account = null): Employee => Employee::create([
            'company_id' => $franchise->id, 'division_id' => $luucaBrand->id, 'shift_id' => $shift?->id, 'user_id' => $account?->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_number' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Staff'])->id,
            'nip' => 'PFC-'.$name, 'full_name' => $name, 'status' => 'active', 'join_date' => '2024-01-08',
        ]);

        // Hana: no default shift, never rostered; on Tuesday 10 November she covers the morning at Luuca.
        Role::findOrCreate('Employee', 'web');
        $account = User::factory()->create(['is_active' => true]);
        $account->assignRole('Employee');
        $hana = $employee('Hana', null, $account);
        Sanctum::actingAs($account);
        $this->travelTo(Carbon::parse('2026-11-10 07:58'));
        $this->postJson('/api/v1/attendance/check-in')->assertUnprocessable()->assertJsonValidationErrors('cover_shift_id');
        $cover = $this->postJson('/api/v1/attendance/check-in', ['cover_shift_id' => $morning->id, 'cover_location_id' => $luuca->id])->assertOk()->assertJsonPath('requires_approval', true)->json('attendance.id');
        $this->actingAs($this->viewers['home']);
        app(ApprovalFlowService::class)->approveStep(Attendance::findOrFail($cover), $this->viewers['home']);
        $this->travelTo(Carbon::parse('2026-11-10 16:00'));
        Sanctum::actingAs($account);
        $this->postJson('/api/v1/attendance/check-out')->assertOk();

        // Indra: a default shift and never rostered keeps fixed hours, Monday to Friday; he came twice.
        $indra = $employee('Indra', $morning);
        $this->attend($indra, '2026-11-02', '08:00', '17:00', $luuca);
        $this->attend($indra, '2026-11-03', '08:00', '17:00', $luuca);

        // Joko: first rostered on Wednesday 11 November; the weekdays before are blank, not absent.
        $joko = Freelancer::create(['company_id' => $franchise->id, 'division_id' => $luucaBrand->id, 'full_name' => 'Joko', 'status' => 'active']);
        ShiftSchedule::create(['freelancer_id' => $joko->id, 'date' => '2026-11-11', 'shift_id' => $morning->id, 'company_location_id' => $luuca->id]);
        $this->attend($joko, '2026-11-11', '08:00', '17:00', $luuca);

        $this->travelTo(Carbon::parse('2026-11-21 12:00'));
        Sanctum::actingAs($this->viewers['admin']);
        $lines = collect($this->getJson('/api/v1/team/recap?month='.self::MONTH)->assertOk()->json('data'))->groupBy('worker.name');
        $figures = fn (string $name): array => [
            $lines[$name]->sum('present_days'),
            $lines[$name]->sum('absent_days'),
            $lines[$name]->sum('scheduled_days'),
        ];

        $this->assertSame([1, 0, 1], $figures('Hana'), 'The approved cover rosters the day; her blank days are not absences.');
        $this->assertSame([2, 13, 0], $figures('Indra'), 'Fixed hours: the 13 other weekdays up to yesterday are absences.');
        $this->assertSame([1, 0, 1], $figures('Joko'));
        // PT Kantor keeps the legacy rule: Dedi's and Eka's blank weekdays stay absences.
        $this->assertSame([2, 13, 0], $figures('Dedi'));

        // Every report agrees: the admin figures grown by Hana, Indra and Joko (4 present days, Indra's 13 absences).
        $this->test_every_report_gives_the_same_figures('admin', ['present_days' => 17, 'late_days' => 2, 'leave_days' => 4, 'absent_days' => 41, 'pending_days' => 1, 'missing_check_out_days' => 2, 'auto_overtime_hours' => 5.75, 'overtime_hours' => 6.75]);
    }

    public function test_leave_across_a_day_off_with_the_roster_changed_after_approval_reconciles(): void
    {
        // The franchise plans with shift rosters: leave is charged only for rostered working days (WP13).
        $franchise = Company::where('code', 'PFC')->sole();
        $franchise->policy()->update(['uses_shift_roster' => true]);
        app(ApprovalFlowService::class)->createDefaultFlows($franchise);
        $luucaBrand = Division::where('name', 'LUUCA')->sole();
        $luuca = CompanyLocation::where('name', 'Luuca Ciledug')->sole();
        $morning = Shift::where('name', 'Pagi')->sole();

        // Kiki: Monday 16 working, Tuesday 17 a day off, Wednesday 18 working; sick leave Monday - Wednesday.
        $kiki = Freelancer::create(['company_id' => $franchise->id, 'division_id' => $luucaBrand->id, 'full_name' => 'Kiki', 'status' => 'active']);
        ShiftSchedule::create(['freelancer_id' => $kiki->id, 'date' => '2026-11-16', 'shift_id' => $morning->id, 'company_location_id' => $luuca->id]);
        ShiftSchedule::create(['freelancer_id' => $kiki->id, 'date' => '2026-11-17', 'is_day_off' => true]);
        $wednesday = ShiftSchedule::create(['freelancer_id' => $kiki->id, 'date' => '2026-11-18', 'shift_id' => $morning->id, 'company_location_id' => $luuca->id]);
        $leave = LeaveRequest::create(['freelancer_id' => $kiki->id, 'leave_type' => 'sick', 'start_date' => '2026-11-16', 'end_date' => '2026-11-18', 'days_count' => 2, 'reason' => 'Demam', 'status' => 'pending']);
        app(ApprovalFlowService::class)->generateSteps($leave, 'leave');
        Role::findOrCreate('Superadmin', 'web');
        $superadmin = User::factory()->create();
        $superadmin->assignRole('Superadmin');
        app(ApprovalFlowService::class)->approveStep($leave->fresh(), $superadmin);

        $this->assertSame([2, ['2026-11-16', '2026-11-18']], [$leave->fresh()->days_count, $leave->fresh()->charged_dates]);

        // After the approval Wednesday becomes a day off and Thursday a working day she does not come to.
        $wednesday->update(['is_day_off' => true, 'shift_id' => null, 'company_location_id' => null]);
        ShiftSchedule::create(['freelancer_id' => $kiki->id, 'date' => '2026-11-19', 'shift_id' => $morning->id, 'company_location_id' => $luuca->id]);

        Sanctum::actingAs($this->viewers['admin']);
        $line = collect($this->getJson('/api/v1/team/recap?month='.self::MONTH)->assertOk()->json('data'))->firstWhere('worker.name', 'Kiki');

        // The stored list decides: two leave days, Tuesday off, Thursday absent.
        $this->assertSame([2, 1, 1, 0], [$line['leave_days'], $line['off_days'], $line['absent_days'], $line['present_days']]);

        // Every report agrees: the admin figures grown by Kiki's two leave days and one absence.
        $this->test_every_report_gives_the_same_figures('admin', ['present_days' => 13, 'late_days' => 2, 'leave_days' => 6, 'absent_days' => 29, 'pending_days' => 1, 'missing_check_out_days' => 2, 'auto_overtime_hours' => 5.75, 'overtime_hours' => 6.75]);
    }

    public function test_a_roster_corrected_after_the_fact_and_a_revived_row_reconcile(): void
    {
        $luuca = CompanyLocation::where('name', 'Luuca Ciledug')->sole();
        $franchise = Company::where('code', 'PFC')->sole();
        $ani = Freelancer::where('full_name', 'Ani')->sole();
        $budi = Freelancer::where('full_name', 'Budi')->sole();

        // Ani's rejected Wednesday: she was at the outlet after all; the correction gives the real check-in.
        AttendanceCorrection::create(['freelancer_id' => $ani->id, 'date' => '2026-11-04', 'corrected_check_in' => '08:00', 'corrected_check_out' => '17:00', 'reason' => 'GPS bermasalah', 'status' => 'pending'])->applyCorrection();

        // Budi's lent day was really a later shift at his own outlet: a schedule correction of the locked row.
        $later = Shift::create(['company_id' => $franchise->id, 'name' => 'Pagi Siang', 'start_time' => '08:15', 'end_time' => '17:00']);
        $lent = Attendance::where('freelancer_id', $budi->id)->whereDate('date', '2026-11-04')->sole();
        AttendanceCorrection::create(['freelancer_id' => $budi->id, 'date' => '2026-11-04', 'attendance_id' => $lent->id, 'corrected_shift_id' => $later->id, 'corrected_company_location_id' => $luuca->id, 'reason' => 'Salah jadwal', 'status' => 'pending', 'origin' => 'admin'])->applyCorrection();

        $lent->refresh();
        $this->assertSame([$later->id, $luuca->id, 0, 'Luuca Ciledug'], [$lent->shift_id, $lent->company_location_id, (int) $lent->late_minutes, $lent->sched_outlet_name]);
        $this->assertSame([$later->id, $luuca->id], [$lent->schedule->shift_id, $lent->schedule->company_location_id]);

        Sanctum::actingAs($this->viewers['admin']);
        $line = collect($this->getJson('/api/v1/team/recap?month='.self::MONTH)->assertOk()->json('data'))->firstWhere('worker.name', 'Ani');
        // The revived Wednesday is present and on time; her only late day stays Tuesday's pending check-in.
        $this->assertSame([5, 1], [$line['present_days'], $line['late_days']]);

        // Every report still agrees: Ani's Wednesday moves from absent to present, Budi's day is no longer late.
        $this->test_every_report_gives_the_same_figures('admin', ['present_days' => 14, 'late_days' => 1, 'leave_days' => 4, 'absent_days' => 27, 'pending_days' => 1, 'missing_check_out_days' => 2, 'auto_overtime_hours' => 5.75, 'overtime_hours' => 6.75]);
    }

    public function test_the_dashboard_widgets_count_a_day_like_the_reports(): void
    {
        // Wednesday 4 November: Budi (late, lent to JChicken) and Cici worked, Ani's check-in was rejected,
        // Eka is on leave and Dedi has not come in.
        $this->travelTo(Carbon::parse('2026-11-04 23:00'));
        Role::findOrCreate('Superadmin', 'web');
        $superadmin = User::factory()->create();
        $superadmin->assignRole('Superadmin');

        Sanctum::actingAs($superadmin);
        $summary = $this->getJson('/api/v1/laporan-absensi?start_date=2026-11-04&end_date=2026-11-04')->assertOk()->json('summary');

        $this->assertSame([2, 1, 1], [$summary['total_present'], $summary['total_late'], $summary['total_leave']]);

        $this->actingAs($superadmin);
        Livewire::test(SuperadminOverviewWidget::class)
            ->assertOk()
            ->assertSee("{$summary['total_present']} Karyawan")
            ->assertSee("Telat: {$summary['total_late']} | Cuti/Izin: {$summary['total_leave']}");

        // The employee's own month: Dedi worked two days, neither late.
        $this->travelTo(Carbon::parse('2026-11-21 12:00'));
        $dedi = Employee::where('full_name', 'Dedi')->sole();
        $account = User::factory()->create();
        $dedi->update(['user_id' => $account->id]);

        Sanctum::actingAs($this->viewers['admin']);
        $line = collect($this->getJson('/api/v1/team/recap?month='.self::MONTH)->assertOk()->json('data'))->firstWhere('worker.name', 'Dedi');

        $this->assertSame([2, 0], [$line['present_days'], $line['late_days']]);

        $this->actingAs($account->fresh());
        Livewire::test(EmployeeStatsWidget::class)
            ->assertOk()
            ->assertSee("{$line['present_days']} Hari")
            ->assertSee("Telat: {$line['late_days']} hari bulan ini");
    }

    public function test_a_lent_day_is_listed_with_its_outlet_and_shift_for_the_host(): void
    {
        Sanctum::actingAs($this->viewers['host']);

        $rows = $this->allRows('/api/v1/attendances?start_date=2026-11-04&end_date=2026-11-04');
        $lent = collect($rows)->firstWhere('user.name', 'Budi');

        $this->assertNotNull($lent, 'The host coordinator sees the worker lent to their outlet.');
        $this->assertSame(['JChicken Ciledug', 'Pagi', '08:00', '17:00', 10], [$lent['outlet']['name'], $lent['shift']['name'], $lent['scheduled_start'], $lent['scheduled_end'], $lent['reported_late_minutes']]);
        $this->assertSame(['Budi', 'Cici'], collect($rows)->pluck('user.name')->sort()->values()->all());

        $this->getJson('/api/v1/attendances/'.$lent['id'])->assertOk()->assertJsonPath('data.outlet.name', 'JChicken Ciledug');
    }

    private function seedMonth(): void
    {
        $franchise = Company::create(['name' => 'PT Franchise', 'code' => 'PFC', 'is_active' => true]);
        $luucaBrand = Division::create(['company_id' => $franchise->id, 'name' => 'LUUCA', 'is_active' => true]);
        $jchickenBrand = Division::create(['company_id' => $franchise->id, 'name' => 'JCHICKEN', 'is_active' => true]);
        $luuca = CompanyLocation::create(['company_id' => $franchise->id, 'division_id' => $luucaBrand->id, 'name' => 'Luuca Ciledug', 'latitude' => -6.2, 'longitude' => 106.7]);
        $jchicken = CompanyLocation::create(['company_id' => $franchise->id, 'division_id' => $jchickenBrand->id, 'name' => 'JChicken Ciledug', 'latitude' => -6.2, 'longitude' => 106.7]);
        $morning = Shift::create(['company_id' => $franchise->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '17:00']);
        $split = Shift::create(['company_id' => $franchise->id, 'name' => 'Split', 'start_time' => '07:45', 'end_time' => '13:00', 'second_start_time' => '17:45', 'second_end_time' => '22:00']);
        $long = Shift::create(['company_id' => $franchise->id, 'name' => 'Long Shift', 'start_time' => '08:45', 'end_time' => '22:30', 'normal_minutes' => 480]);

        $office = Company::create(['name' => 'PT Kantor', 'code' => 'KTR', 'is_active' => true]);
        $staff = Division::create(['company_id' => $office->id, 'name' => 'Operasional', 'is_active' => true]);
        $officeHours = Shift::create(['company_id' => $office->id, 'name' => 'Kantor', 'start_time' => '09:00', 'end_time' => '17:00']);

        $this->viewers['admin'] = $this->user('Admin', DataScope::All);
        $this->viewers['host'] = $this->user('Koordinator', DataScope::Divisions, [$jchickenBrand->id]);
        $this->viewers['home'] = $this->user('Koordinator', DataScope::Divisions, [$luucaBrand->id]);

        $schedule = fn (Freelancer $worker, string $date, Shift $shift, CompanyLocation $outlet): ShiftSchedule => ShiftSchedule::create(['freelancer_id' => $worker->id, 'date' => $date, 'shift_id' => $shift->id, 'company_location_id' => $outlet->id]);

        // Ani (LUUCA): on time, waiting for approval and late, rejected, forgotten check-out, day off, leave, a no-show.
        $ani = Freelancer::create(['company_id' => $franchise->id, 'division_id' => $luucaBrand->id, 'full_name' => 'Ani', 'status' => 'active']);

        foreach (['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-09', '2026-11-11', '2026-11-12'] as $date) {
            $schedule($ani, $date, $morning, $luuca);
        }

        ShiftSchedule::create(['freelancer_id' => $ani->id, 'date' => '2026-11-06', 'is_day_off' => true]);
        $this->attend($ani, '2026-11-02', '08:00', '17:00', $luuca);
        $this->attend($ani, '2026-11-03', '08:15', '17:00', $luuca, ['status' => 'pending_approval', 'check_in_late_minutes' => 15]);
        $this->attend($ani, '2026-11-04', '08:00', null, $luuca, ['status' => 'rejected', 'late_minutes' => 30]);
        $this->attend($ani, '2026-11-05', '08:00', null, $luuca);
        $this->attend($ani, '2026-11-11', '08:00', '16:00', $luuca);
        LeaveRequest::create(['freelancer_id' => $ani->id, 'leave_type' => 'annual', 'start_date' => '2026-11-09', 'end_date' => '2026-11-10', 'days_count' => 2, 'reason' => 'Keperluan keluarga', 'status' => 'approved']);
        OvertimeRequest::create(['freelancer_id' => $ani->id, 'date' => '2026-11-02', 'start_time' => '17:00', 'end_time' => '18:00', 'duration_minutes' => 60, 'reason' => 'Stok opname', 'status' => 'approved']);

        // Budi (LUUCA): a split shift, only its second part, a day lent to JChicken, a no-show.
        $budi = Freelancer::create(['company_id' => $franchise->id, 'division_id' => $luucaBrand->id, 'full_name' => 'Budi', 'status' => 'active']);
        $schedule($budi, '2026-11-02', $split, $luuca);
        $schedule($budi, '2026-11-03', $split, $luuca);
        $lentDay = $schedule($budi, '2026-11-04', $morning, $jchicken);
        $schedule($budi, '2026-11-05', $morning, $luuca);
        $this->attend($budi, '2026-11-02', '07:45', '13:00', $luuca);
        $this->attend($budi, '2026-11-02', '17:45', '22:00', $luuca, ['segment' => 2]);
        $this->attend($budi, '2026-11-03', '17:45', '21:30', $luuca, ['segment' => 2]);
        // Checked in through the app: the row keeps the schedule that applied.
        $this->attend($budi, '2026-11-04', '08:10', '17:00', $jchicken, [
            'status' => 'late', 'late_minutes' => 10, 'shift_id' => $morning->id, 'shift_schedule_id' => $lentDay->id,
            'sched_start_at' => '2026-11-04 08:00:00', 'sched_end_at' => '2026-11-04 17:00:00', 'sched_scheduled_minutes' => 540,
            'sched_shift_name' => 'Pagi', 'sched_outlet_name' => 'JChicken Ciledug', 'sched_source' => 'schedule',
        ]);

        // Cici (JCHICKEN): two ordinary days and a long shift checked in with normal hours on its snapshot.
        $cici = Freelancer::create(['company_id' => $franchise->id, 'division_id' => $jchickenBrand->id, 'full_name' => 'Cici', 'status' => 'active']);
        $schedule($cici, '2026-11-02', $morning, $jchicken);
        $schedule($cici, '2026-11-03', $morning, $jchicken);
        $longDay = $schedule($cici, '2026-11-04', $long, $jchicken);
        $this->attend($cici, '2026-11-02', '08:00', '17:00', $jchicken);
        $this->attend($cici, '2026-11-03', '08:00', '17:00', $jchicken);
        $this->attend($cici, '2026-11-04', '08:40', '22:35', $jchicken, [
            'shift_id' => $long->id, 'shift_schedule_id' => $longDay->id, 'sched_start_at' => '2026-11-04 08:45:00', 'sched_end_at' => '2026-11-04 22:30:00',
            'sched_scheduled_minutes' => 825, 'sched_normal_minutes' => 480, 'sched_shift_name' => 'Long Shift', 'sched_outlet_name' => 'JChicken Ciledug', 'sched_source' => 'schedule',
        ]);

        // PT Kantor: Dedi on a default shift, Eka on the company hours; neither ever had a roster.
        $employee = fn (string $name, string $nip, ?Shift $shift): Employee => Employee::create([
            'company_id' => $office->id, 'division_id' => $staff->id, 'shift_id' => $shift?->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_number' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Staff'])->id,
            'nip' => $nip, 'full_name' => $name, 'status' => 'active', 'join_date' => '2024-01-08',
        ]);
        $dedi = $employee('Dedi', 'KTR-001', $officeHours);
        $eka = $employee('Eka', 'KTR-002', null);
        $this->attend($dedi, '2026-11-02', '09:00', '17:00');
        $this->attend($dedi, '2026-11-03', '09:00', '17:00');
        LeaveRequest::create(['employee_id' => $eka->id, 'leave_type' => 'annual', 'start_date' => '2026-11-04', 'end_date' => '2026-11-05', 'days_count' => 2, 'reason' => 'Keperluan keluarga', 'status' => 'approved']);
        $this->attend($eka, '2026-11-06', '08:00', null);
    }

    /**
     * @param  list<int>  $divisionIds
     */
    private function user(string $role, DataScope $scope, array $divisionIds = []): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $user->grantDataAccess($scope, divisionIds: $divisionIds);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function attend(Employee|Freelancer $worker, string $date, string $in, ?string $out, ?CompanyLocation $outlet = null, array $attributes = []): Attendance
    {
        return Attendance::create($attributes + [
            $worker instanceof Employee ? 'employee_id' : 'freelancer_id' => $worker->id,
            'date' => $date,
            'check_in' => "{$date} {$in}:00",
            'check_out' => $out ? "{$date} {$out}:00" : null,
            'status' => 'on_time',
            'late_minutes' => 0,
            'company_location_id' => $outlet?->id,
        ]);
    }

    /**
     * Every row of a paginated attendance list.
     *
     * @return list<array<string, mixed>>
     */
    private function allRows(string $url): array
    {
        $rows = [];
        $page = 1;

        do {
            $response = $this->getJson("{$url}&page={$page}")->assertOk()->json();
            $rows = [...$rows, ...$response['data']];
        } while ($page++ < $response['pagination']['last_page']);

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function excelTotalRow(User $user): array
    {
        $path = app(TeamRecapService::class)->export($user, TeamRecapService::parseMonth(self::MONTH));
        $reader = new XlsxReader;
        $reader->open($path);
        $last = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $last = array_map(fn (mixed $value): string => (string) $value, $row->toArray());
            }

            break;
        }

        $reader->close();
        @unlink($path);

        $this->assertSame('Total', $last[0]);

        return $last;
    }
}
