<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Exports\AttendanceRecapSheet;
use App\Filament\Pages\LaporanAbsensi;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Services\TeamRecapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Cluster E (reports and team recap): one rule for an expected work day (ExpectedWorkDays) in laporan
 * absensi and the team recap, each worker-day counted once, and rejected attendance never counted as worked.
 */
class QaEReportsRecapTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Company $otherCompany;

    private Division $division;

    private Shift $morning;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Sunday 15 November 2026: the first two weeks of the month are in the past.
        $this->travelTo(Carbon::parse('2026-11-15 12:00'));

        Role::findOrCreate('Admin', 'web');

        $this->company = Company::create(['name' => 'PT Laporan', 'code' => 'PLE', 'is_active' => true]);
        $this->otherCompany = Company::create(['name' => 'PT Lain', 'code' => 'PLN', 'is_active' => true]);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Operasional', 'is_active' => true]);
        $this->morning = Shift::create(['company_id' => $this->company->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '17:00']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        $this->admin->grantDataAccess(DataScope::All);
    }

    public function test_today_is_not_an_absence_before_the_day_is_over(): void
    {
        $this->travelTo(Carbon::parse('2026-11-16 07:00'));
        $this->freelancer('Belum Masuk');

        $this->assertSame(0, $this->summary('2026-11-16', '2026-11-16')['total_absent'], 'Monday morning: the shift has not started yet.');
        $this->assertSame(10, $this->summary('2026-11-01', '2026-11-16')['total_absent'], 'Only the ten past weekdays count.');
    }

    public function test_a_holiday_of_the_workers_company_is_not_an_absence_but_another_companys_is_no_excuse(): void
    {
        $this->freelancer('Libur Perusahaan');
        Holiday::create(['company_id' => $this->company->id, 'name' => 'Ulang Tahun Perusahaan', 'date' => '2026-11-03', 'is_national' => false]);
        Holiday::create(['company_id' => $this->otherCompany->id, 'name' => 'Libur PT Lain', 'date' => '2026-11-04', 'is_national' => false]);

        $this->assertSame(0, $this->summary('2026-11-03', '2026-11-03')['total_absent']);
        $this->assertSame(1, $this->summary('2026-11-04', '2026-11-04')['total_absent']);
    }

    public function test_a_shift_rostered_on_a_holiday_is_a_working_day(): void
    {
        $worker = $this->freelancer('Jaga Libur');
        Holiday::create(['name' => 'Libur Nasional', 'date' => '2026-11-03', 'is_national' => true]);
        $this->schedule($worker, '2026-11-03');

        $this->assertSame(1, $this->summary('2026-11-03', '2026-11-03')['total_absent']);
    }

    public function test_approved_leave_wins_over_an_explicit_absent_row_and_a_worked_day_is_not_leave(): void
    {
        $absentOnLeave = $this->freelancer('Alpa Tapi Cuti');
        $workedOnLeave = $this->freelancer('Masuk Saat Cuti');
        Attendance::create(['freelancer_id' => $absentOnLeave->id, 'date' => '2026-11-02', 'status' => 'absent']);
        Attendance::create(['freelancer_id' => $workedOnLeave->id, 'date' => '2026-11-02', 'check_in' => '2026-11-02 08:00:00', 'status' => 'on_time']);
        $this->leave($absentOnLeave, '2026-11-02');
        $this->leave($workedOnLeave, '2026-11-02');

        $this->assertSame(
            ['total_present' => 1, 'total_late' => 0, 'total_leave' => 1, 'total_absent' => 0],
            $this->summary('2026-11-02', '2026-11-02'),
        );
    }

    public function test_a_rejected_late_check_in_is_neither_present_nor_late(): void
    {
        $worker = $this->freelancer('Ditolak Telat');
        Attendance::create(['freelancer_id' => $worker->id, 'date' => '2026-11-02', 'check_in' => '2026-11-02 08:40:00', 'status' => 'rejected', 'late_minutes' => 40]);

        $this->assertSame(
            ['total_present' => 0, 'total_late' => 0, 'total_leave' => 0, 'total_absent' => 1],
            $this->summary('2026-11-02', '2026-11-02'),
        );
    }

    public function test_team_recap_counts_a_rejected_rostered_day_as_absent_without_hours(): void
    {
        $worker = $this->freelancer('Ditolak Rekap');
        $this->schedule($worker, '2026-11-02');
        Attendance::create(['freelancer_id' => $worker->id, 'date' => '2026-11-02', 'check_in' => '2026-11-02 08:30:00', 'check_out' => '2026-11-02 17:00:00', 'status' => 'rejected', 'late_minutes' => 30]);

        [$row] = app(TeamRecapService::class)->recap($this->admin, Carbon::parse('2026-11-01'));

        $this->assertSame([0, 0, 1, 0.0], [$row['present_days'], $row['late_days'], $row['absent_days'], $row['worked_hours']]);
    }

    public function test_team_recap_counts_a_pending_check_in_as_present(): void
    {
        $worker = $this->freelancer('Menunggu Rekap');
        $this->schedule($worker, '2026-11-02');
        Attendance::create(['freelancer_id' => $worker->id, 'date' => '2026-11-02', 'check_in' => '2026-11-02 08:00:00', 'check_out' => '2026-11-02 17:00:00', 'status' => 'pending_approval']);

        [$row] = app(TeamRecapService::class)->recap($this->admin, Carbon::parse('2026-11-01'));

        $this->assertSame([1, 0, 9.0], [$row['present_days'], $row['absent_days'], $row['worked_hours']]);
    }

    public function test_team_recap_does_not_count_a_rostered_day_after_the_contract_ended(): void
    {
        $worker = Freelancer::create(['company_id' => $this->company->id, 'division_id' => $this->division->id, 'full_name' => 'Kontrak Habis', 'status' => 'completed', 'start_date' => '2026-10-01', 'end_date' => '2026-11-03']);
        $this->schedule($worker, '2026-11-03');
        $this->schedule($worker, '2026-11-04');

        [$row] = app(TeamRecapService::class)->recap($this->admin, Carbon::parse('2026-11-01'));

        $this->assertSame(1, $row['absent_days'], 'Only the 3rd, the last contract day, is a no-show.');
        $this->assertArrayNotHasKey('employed_until', $row['worker'], 'The row shape the app reads is unchanged.');
    }

    public function test_web_report_labels_rows_by_their_real_status(): void
    {
        $worker = $this->freelancer('Status Asli');
        $onTime = Attendance::create(['freelancer_id' => $worker->id, 'date' => '2026-11-02', 'check_in' => '2026-11-02 08:00:00', 'status' => 'on_time']);
        $rejected = Attendance::create(['freelancer_id' => $worker->id, 'date' => '2026-11-03', 'check_in' => '2026-11-03 08:00:00', 'status' => 'rejected']);
        $pending = Attendance::create(['freelancer_id' => $worker->id, 'date' => '2026-11-04', 'check_in' => '2026-11-04 08:00:00', 'status' => 'pending_approval']);
        $late = Attendance::create(['freelancer_id' => $worker->id, 'date' => '2026-11-05', 'check_in' => '2026-11-05 08:20:00', 'status' => 'late', 'late_minutes' => 20]);

        $this->actingAs($this->admin);

        Livewire::test(LaporanAbsensi::class)
            ->set('filterData.date_from', '2026-11-01')
            ->set('filterData.date_to', '2026-11-07')
            ->assertTableColumnFormattedStateSet('status', 'Hadir', $onTime)
            ->assertTableColumnFormattedStateSet('status', 'Ditolak', $rejected)
            ->assertTableColumnFormattedStateSet('status', 'Menunggu Persetujuan', $pending)
            ->assertTableColumnFormattedStateSet('status', 'Terlambat (20m)', $late);
    }

    public function test_recap_export_shows_a_rejected_day_as_rejected_and_keeps_pending_hours(): void
    {
        $worker = $this->freelancer('Ekspor');
        Attendance::create(['freelancer_id' => $worker->id, 'date' => '2026-11-02', 'check_in' => '2026-11-02 08:00:00', 'check_out' => '2026-11-02 17:00:00', 'status' => 'rejected']);
        Attendance::create(['freelancer_id' => $worker->id, 'date' => '2026-11-03', 'check_in' => '2026-11-03 08:00:00', 'check_out' => '2026-11-03 17:00:00', 'status' => 'pending_approval']);

        $rows = (new AttendanceRecapSheet(Attendance::with(['employee', 'intern', 'freelancer'])->get(), Carbon::parse('2026-11-02'), Carbon::parse('2026-11-03')))->array();
        $workerRow = $rows[2];

        $this->assertSame(1, $workerRow[5], 'Hari Masuk counts the pending day only.');
        $this->assertEqualsWithDelta(9.0, $workerRow[10], 0.001);
        $this->assertSame('Ditolak', $workerRow[11], 'The rejected day shows its status in place of a check-in time.');
    }

    /**
     * @return array{total_present: int, total_late: int, total_leave: int, total_absent: int}
     */
    private function summary(string $from, string $until): array
    {
        Sanctum::actingAs($this->admin);

        return $this->getJson("/api/v1/laporan-absensi?start_date={$from}&end_date={$until}")
            ->assertOk()
            ->json('summary');
    }

    private function freelancer(string $name): Freelancer
    {
        return Freelancer::create(['company_id' => $this->company->id, 'division_id' => $this->division->id, 'full_name' => $name, 'status' => 'active']);
    }

    private function schedule(Freelancer $worker, string $date): ShiftSchedule
    {
        return ShiftSchedule::create(['freelancer_id' => $worker->id, 'date' => $date, 'shift_id' => $this->morning->id]);
    }

    private function leave(Freelancer $worker, string $date): LeaveRequest
    {
        return LeaveRequest::create(['freelancer_id' => $worker->id, 'leave_type' => 'annual', 'start_date' => $date, 'end_date' => $date, 'days_count' => 1, 'reason' => 'Keperluan keluarga', 'status' => 'approved']);
    }
}
