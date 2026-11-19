<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\Resignation;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Laporan absensi (H11, H12): unrecorded absences and approved leave are counted per worker per day,
 * so one worker's attendance or leave never hides a colleague's absence, and leave that starts before
 * or ends after the range is counted only for the days inside it.
 */
class ReportsAbsenceCountTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Division $division;

    protected function setUp(): void
    {
        parent::setUp();

        // Sunday 15 November 2026: every weekday of the first two weeks is in the past.
        $this->travelTo(Carbon::parse('2026-11-15 12:00'));

        Role::findOrCreate('Admin', 'web');

        $this->company = Company::create(['name' => 'PT Laporan', 'code' => 'PLP', 'is_active' => true]);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Operasional', 'is_active' => true]);

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $admin->grantDataAccess(DataScope::All);
        Sanctum::actingAs($admin);
    }

    public function test_one_present_worker_does_not_hide_a_colleagues_absence(): void
    {
        $present = $this->freelancer('Hadir');
        $this->freelancer('Tidak Hadir');
        Attendance::create(['freelancer_id' => $present->id, 'date' => '2026-11-02', 'check_in' => '2026-11-02 08:00:00', 'status' => 'on_time']);

        $this->assertSame(
            ['total_present' => 1, 'total_late' => 0, 'total_leave' => 0, 'total_absent' => 1],
            $this->summary('2026-11-02', '2026-11-02'),
        );
    }

    public function test_leave_that_wraps_the_range_counts_only_the_days_inside_it(): void
    {
        $onLeave = $this->freelancer('Cuti Panjang');
        // Leave Mon 2 - Wed 11 November, report Wed 4 - Fri 6.
        $this->leave($onLeave, '2026-11-02', '2026-11-11');

        $this->assertSame(
            ['total_present' => 0, 'total_late' => 0, 'total_leave' => 3, 'total_absent' => 0],
            $this->summary('2026-11-04', '2026-11-06'),
        );
    }

    public function test_partially_overlapping_leave_is_clipped_to_the_range(): void
    {
        $early = $this->freelancer('Cuti Awal');
        $late = $this->freelancer('Cuti Akhir');
        // Report Wed 4 - Fri 6. Mon 2 - Thu 5 overlaps two days, Fri 6 - Tue 10 overlaps one.
        $this->leave($early, '2026-11-02', '2026-11-05');
        $this->leave($late, '2026-11-06', '2026-11-10');

        $summary = $this->summary('2026-11-04', '2026-11-06');

        $this->assertSame(3, $summary['total_leave']);
        // Early: Fri unrecorded. Late: Wed + Thu unrecorded.
        $this->assertSame(3, $summary['total_absent']);
    }

    public function test_one_workers_leave_does_not_hide_another_workers_absence(): void
    {
        $onLeave = $this->freelancer('Cuti');
        $this->freelancer('Tanpa Kabar');
        $this->leave($onLeave, '2026-11-02', '2026-11-02');

        $this->assertSame(
            ['total_present' => 0, 'total_late' => 0, 'total_leave' => 1, 'total_absent' => 1],
            $this->summary('2026-11-02', '2026-11-02'),
        );
    }

    public function test_inactive_workers_and_days_outside_the_contract_are_not_absences(): void
    {
        Freelancer::create(['company_id' => $this->company->id, 'division_id' => $this->division->id, 'full_name' => 'Selesai', 'status' => 'completed']);
        // Contract starts Thursday: only Thu + Fri can be absences.
        Freelancer::create(['company_id' => $this->company->id, 'division_id' => $this->division->id, 'full_name' => 'Baru', 'status' => 'active', 'start_date' => '2026-11-05']);

        $this->assertSame(2, $this->summary('2026-11-02', '2026-11-06')['total_absent']);
    }

    public function test_a_scheduled_weekday_day_off_is_not_an_absence(): void
    {
        $offDuty = $this->freelancer('Libur Jadwal');
        // Wednesday 4 November is a day off on the shift schedule; Thursday is a rostered working day. A worker on
        // a roster is only expected on rostered days (as in Rekap Tim), so Thursday needs its own shift.
        ShiftSchedule::create(['freelancer_id' => $offDuty->id, 'date' => '2026-11-04', 'is_day_off' => true]);
        ShiftSchedule::create(['freelancer_id' => $offDuty->id, 'date' => '2026-11-05', 'shift_id' => $this->shift()->id]);

        $this->assertSame(0, $this->summary('2026-11-04', '2026-11-04')['total_absent']);
        $this->assertSame(1, $this->summary('2026-11-04', '2026-11-05')['total_absent']);
    }

    public function test_a_contract_closed_after_the_range_keeps_its_absences_in_the_report(): void
    {
        // Contract ran to Friday 13 November and is now completed; report covers Mon 2 - Fri 6.
        Freelancer::create(['company_id' => $this->company->id, 'division_id' => $this->division->id, 'full_name' => 'Kontrak Selesai', 'status' => 'completed', 'start_date' => '2026-10-01', 'end_date' => '2026-11-13']);

        $this->assertSame(5, $this->summary('2026-11-02', '2026-11-06')['total_absent']);
    }

    public function test_a_worker_is_not_absent_after_their_employment_ended(): void
    {
        // Contract ended Tuesday 3 November: only Mon + Tue count.
        Freelancer::create(['company_id' => $this->company->id, 'division_id' => $this->division->id, 'full_name' => 'Selesai Selasa', 'status' => 'completed', 'start_date' => '2026-10-01', 'end_date' => '2026-11-03']);

        $this->assertSame(2, $this->summary('2026-11-02', '2026-11-06')['total_absent']);
        $this->assertSame(0, $this->summary('2026-11-04', '2026-11-06')['total_absent']);
    }

    public function test_a_resigned_employee_is_counted_up_to_their_last_working_day(): void
    {
        $employee = Employee::create(['company_id' => $this->company->id, 'division_id' => $this->division->id, 'job_level_id' => JobLevel::create(['name' => 'Staff', 'level_number' => 1])->id, 'job_title_id' => JobTitle::create(['name' => 'Staff'])->id, 'nip' => 'EMP001', 'full_name' => 'Sudah Resign', 'status' => 'inactive', 'join_date' => '2026-01-05']);
        // Last working day Wednesday 4 November: Mon - Wed count, Thu + Fri do not.
        Resignation::create(['employee_id' => $employee->id, 'resignation_date' => '2026-10-20', 'last_working_day' => '2026-11-04', 'reason' => 'Pindah kota', 'status' => 'approved']);

        $this->assertSame(3, $this->summary('2026-11-02', '2026-11-06')['total_absent']);
    }

    public function test_a_rehired_employee_with_an_old_resignation_is_still_counted(): void
    {
        $employee = Employee::create(['company_id' => $this->company->id, 'division_id' => $this->division->id, 'job_level_id' => JobLevel::create(['name' => 'Staff', 'level_number' => 1])->id, 'job_title_id' => JobTitle::create(['name' => 'Staff'])->id, 'nip' => 'EMP002', 'full_name' => 'Kembali Bekerja', 'status' => 'active', 'join_date' => '2024-01-08']);
        Resignation::create(['employee_id' => $employee->id, 'resignation_date' => '2025-06-01', 'last_working_day' => '2025-06-30', 'reason' => 'Pindah kota', 'status' => 'approved']);

        $this->assertSame(5, $this->summary('2026-11-02', '2026-11-06')['total_absent']);
    }

    /**
     * @return array{total_present: int, total_late: int, total_leave: int, total_absent: int}
     */
    private function summary(string $from, string $until): array
    {
        // The four figures this class pins; the summary has more keys since the ledger (see OldClientContractTest).
        return array_intersect_key(
            $this->getJson("/api/v1/laporan-absensi?start_date={$from}&end_date={$until}")->assertOk()->json('summary'),
            array_flip(['total_present', 'total_late', 'total_leave', 'total_absent']),
        );
    }

    private function shift(): Shift
    {
        return Shift::create(['company_id' => $this->company->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '17:00']);
    }

    private function freelancer(string $name): Freelancer
    {
        return Freelancer::create(['company_id' => $this->company->id, 'division_id' => $this->division->id, 'full_name' => $name, 'status' => 'active']);
    }

    private function leave(Freelancer $worker, string $from, string $until): LeaveRequest
    {
        return LeaveRequest::create(['freelancer_id' => $worker->id, 'leave_type' => 'annual', 'start_date' => $from, 'end_date' => $until, 'days_count' => 1, 'reason' => 'Keperluan keluarga', 'status' => 'approved']);
    }
}
