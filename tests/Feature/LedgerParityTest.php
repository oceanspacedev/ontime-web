<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Models\Attendance;
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
use App\Services\TeamRecapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The attendance ledger against the two report engines it replaced (the team recap and laporan absensi).
 *
 * Written while the old code still existed: on these three fixtures every figure was compared with the
 * old TeamRecapService::recap() and the old laporan summary, and the numbers below are the ones both
 * produced, apart from the intended differences marked (a) to (d):
 * (a) a day off and a leave day are on the worker's home outlet line, not on a line without an outlet,
 * (b) the recap also lists workers who never had a roster, with the Monday-to-Friday days laporan absensi
 *     already expected of them (scheduled and absent days),
 * (c) an explicit absent or rejected row of today is not an absence until the day is over,
 * (d) leave counts the dates the leave charged.
 */
class LedgerParityTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Division $brand;

    private CompanyLocation $luuca;

    private CompanyLocation $jchicken;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Tuesday 17 November 2026, noon.
        $this->travelTo(Carbon::parse('2026-11-17 12:00'));

        foreach (['Admin', 'Koordinator', 'Freelancer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->company = Company::create(['name' => 'PT Franchise', 'code' => 'PFC', 'is_active' => true]);
        $this->company->policy()->update(['work_start_time' => '08:00:00', 'work_end_time' => '17:00:00']);
        $this->brand = Division::create(['company_id' => $this->company->id, 'name' => 'LUUCA', 'is_active' => true]);
        $this->luuca = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $this->brand->id, 'name' => 'Luuca Ciledug', 'latitude' => -6.2, 'longitude' => 106.7]);
        $this->jchicken = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $this->brand->id, 'name' => 'JChicken Ciledug', 'latitude' => -6.2, 'longitude' => 106.7]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        $this->admin->grantDataAccess(DataScope::All);
        Sanctum::actingAs($this->admin);
    }

    public function test_rostered_outlet_staff_keep_their_figures_and_the_day_off_moves_to_the_home_outlet(): void
    {
        $this->rosteredOutletStaff();

        $rows = $this->recap();

        // Before the ledger: the same two outlet lines plus a third line with outlet null that held only the day off.
        $this->assertSame(['JChicken Ciledug', 'Luuca Ciledug'], array_column($rows, 'outlet'));
        [$jchicken, $luuca] = $rows;

        $this->assertSame(
            ['scheduled_days' => 2, 'present_days' => 1, 'pending_days' => 0, 'late_days' => 0, 'late_minutes' => 0, 'early_leave_days' => 1, 'early_leave_minutes' => 60, 'absent_days' => 0, 'off_days' => 0, 'scheduled_hours' => 18.0, 'worked_hours' => 8.08, 'overtime_count' => 0, 'overtime_hours' => 0.0],
            $this->legacyFigures($jchicken),
        );
        $this->assertSame(
            // Intended difference (a): off_days was 0 here and 1 on the outlet-null line.
            ['scheduled_days' => 6, 'present_days' => 3, 'pending_days' => 0, 'late_days' => 1, 'late_minutes' => 20, 'early_leave_days' => 0, 'early_leave_minutes' => 0, 'absent_days' => 2, 'off_days' => 1, 'scheduled_hours' => 54.0, 'worked_hours' => 17.75, 'overtime_count' => 1, 'overtime_hours' => 1.0],
            $this->legacyFigures($luuca),
        );
        // A leave day on a rostered day stays with that outlet; one without a schedule goes to the home outlet.
        $this->assertSame([1, 1], [$jchicken['leave_days'], $luuca['leave_days']]);
        $this->assertSame([1, 1], [$luuca['missing_check_out_days'], $luuca['incomplete_days']]);

        $this->assertSame(['total_present' => 4, 'total_late' => 1, 'total_leave' => 2, 'total_absent' => 2], $this->legacySummary());
    }

    public function test_split_shift_with_a_pending_and_a_rejected_part_keeps_its_figures(): void
    {
        $this->splitPendingRejected();

        $rows = $this->recap();

        $this->assertCount(1, $rows);
        $this->assertSame(
            ['scheduled_days' => 4, 'present_days' => 3, 'pending_days' => 1, 'late_days' => 2, 'late_minutes' => 17, 'early_leave_days' => 1, 'early_leave_minutes' => 60, 'absent_days' => 1, 'off_days' => 0, 'scheduled_hours' => 38.0, 'worked_hours' => 17.72, 'overtime_count' => 0, 'overtime_hours' => 0.0],
            $this->legacyFigures($rows[0]),
        );
        $this->assertSame([8, 4, 2], [$rows[0]['sessions_scheduled'], $rows[0]['sessions_worked'], $rows[0]['sessions_missed']]);

        $this->assertSame(['total_present' => 3, 'total_late' => 2, 'total_leave' => 0, 'total_absent' => 1], $this->legacySummary());
    }

    public function test_fixed_hours_office_staff_are_now_in_the_recap_with_the_absences_laporan_already_counted(): void
    {
        $this->fixedHoursOfficeStaff();

        $rows = $this->recap();

        // Intended difference (b): before the ledger the recap had one line (Citra) with 0 absent days and no
        // line for Dedi, while laporan absensi counted 13 absent days for the two. Standing hours are not on
        // the roster, so "Hari Terjadwal" stays 0 as before.
        $this->assertSame(['Citra', 'Dedi'], array_column(array_column($rows, 'worker'), 'name'));
        [$citra, $dedi] = $rows;

        $this->assertSame(
            ['present_days' => 4, 'pending_days' => 0, 'late_days' => 1, 'late_minutes' => 30, 'early_leave_days' => 1, 'early_leave_minutes' => 180, 'off_days' => 0, 'worked_hours' => 27.5, 'overtime_count' => 0, 'overtime_hours' => 0.0],
            array_diff_key($this->legacyFigures($citra), array_flip(['scheduled_days', 'absent_days', 'scheduled_hours'])),
        );
        // Monday to Friday: absent on 4, 5, 9 - 13 (the 6th is leave, the 17th is today).
        $this->assertSame([0, 7, 0.0, 1], [$citra['scheduled_days'], $citra['absent_days'], $citra['scheduled_hours'], $citra['leave_days']]);
        // Dedi joined on the 9th: absent 9 - 13 and 16.
        $this->assertSame([0, 6, 0.0, 0], [$dedi['scheduled_days'], $dedi['absent_days'], $dedi['scheduled_hours'], $dedi['present_days']]);

        $this->assertSame(['total_present' => 4, 'total_late' => 1, 'total_leave' => 1, 'total_absent' => 13], $this->legacySummary());
        $this->assertSame([13, 1], [$this->recapTotal()['absent_days'], $this->recapTotal()['leave_days']]);
    }

    public function test_an_explicit_absent_or_rejected_row_of_today_is_not_an_absence_yet(): void
    {
        [$citra, $dedi] = $this->fixedHoursOfficeStaff();
        // Intended difference (c): laporan absensi used to count both rows of today at once.
        Attendance::create(['employee_id' => $citra->id, 'date' => '2026-11-17', 'status' => 'absent', 'late_minutes' => 0]);
        $this->attend($dedi, '2026-11-17', '08:00', null, ['status' => 'rejected']);

        $this->assertSame(13, $this->legacySummary()['total_absent']);

        $this->travelTo(Carbon::parse('2026-11-18 12:00'));

        $this->assertSame(15, $this->legacySummary()['total_absent']);
    }

    public function test_leave_counts_the_dates_it_charged_and_a_leave_from_before_that_every_date_of_its_range(): void
    {
        $ani = $this->freelancer('Ani');
        $budi = $this->freelancer('Budi');
        $leave = ['leave_type' => 'annual', 'start_date' => '2026-11-02', 'end_date' => '2026-11-06', 'days_count' => 5, 'reason' => 'Keperluan keluarga', 'status' => 'approved'];
        // Intended difference (d): Ani's leave charged three of its five dates; Budi's has no stored list.
        LeaveRequest::create($leave + ['freelancer_id' => $ani->id, 'charged_dates' => ['2026-11-02', '2026-11-03', '2026-11-05'], 'days_count' => 3]);
        LeaveRequest::create($leave + ['freelancer_id' => $budi->id]);

        $summary = $this->legacySummary('2026-11-02', '2026-11-06');

        $this->assertSame(3 + 5, $summary['total_leave']);
        // Ani is expected on the two dates her leave did not charge.
        $this->assertSame(2, $summary['total_absent']);
        $this->assertSame(8, $this->recapTotal()['leave_days']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recap(): array
    {
        return app(TeamRecapService::class)->recap($this->admin, Carbon::parse('2026-11-01'));
    }

    /**
     * @return array<string, int|float>
     */
    private function recapTotal(): array
    {
        return app(TeamRecapService::class)->report($this->admin, Carbon::parse('2026-11-01'))['total'];
    }

    /**
     * The figures a recap line had before the ledger, in their order.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, int|float>
     */
    private function legacyFigures(array $row): array
    {
        $keys = ['scheduled_days', 'present_days', 'pending_days', 'late_days', 'late_minutes', 'early_leave_days', 'early_leave_minutes', 'absent_days', 'off_days', 'scheduled_hours', 'worked_hours', 'overtime_count', 'overtime_hours'];

        return array_intersect_key($row, array_flip($keys));
    }

    /**
     * The four summary figures laporan absensi had before the ledger.
     *
     * @return array<string, int>
     */
    private function legacySummary(string $from = '2026-11-01', string $until = '2026-11-30'): array
    {
        $summary = $this->getJson("/api/v1/laporan-absensi?start_date={$from}&end_date={$until}")->assertOk()->json('summary');

        return array_intersect_key($summary, array_flip(['total_present', 'total_late', 'total_leave', 'total_absent']));
    }

    /**
     * Ani works at Luuca, helps at JChicken, has a day off, a leave, a no-show, a forgotten check-out and overtime.
     */
    private function rosteredOutletStaff(): Freelancer
    {
        $morning = $this->shift('Pagi', '08:00', '17:00');
        $ani = $this->freelancer('Ani');

        foreach (['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-16', '2026-11-20'] as $date) {
            $this->schedule($ani, $date, $morning, $this->luuca);
        }

        ShiftSchedule::create(['freelancer_id' => $ani->id, 'date' => '2026-11-06', 'is_day_off' => true]);
        $this->schedule($ani, '2026-11-09', $morning, $this->jchicken);
        $this->schedule($ani, '2026-11-10', $morning, $this->jchicken);

        $this->attend($ani, '2026-11-02', '08:20', '17:05', ['status' => 'late', 'late_minutes' => 20, 'company_location_id' => $this->luuca->id]);
        $this->attend($ani, '2026-11-03', '08:00', '17:00', ['company_location_id' => $this->luuca->id]);
        $this->attend($ani, '2026-11-05', '08:00', null, ['company_location_id' => $this->luuca->id]);
        $this->attend($ani, '2026-11-09', '07:55', '16:00', ['company_location_id' => $this->jchicken->id]);

        LeaveRequest::create(['freelancer_id' => $ani->id, 'leave_type' => 'annual', 'start_date' => '2026-11-10', 'end_date' => '2026-11-11', 'days_count' => 2, 'reason' => 'Keperluan keluarga', 'status' => 'approved']);
        OvertimeRequest::create(['freelancer_id' => $ani->id, 'date' => '2026-11-03', 'start_time' => '17:00', 'end_time' => '18:00', 'duration_minutes' => 60, 'reason' => 'Stok opname', 'status' => 'approved']);

        return $ani;
    }

    /**
     * Budi works a split shift: both parts, one part waiting for approval, a rejected check-in, only the second part.
     */
    private function splitPendingRejected(): Freelancer
    {
        $split = Shift::create(['company_id' => $this->company->id, 'name' => 'Split', 'start_time' => '07:45', 'end_time' => '13:00', 'second_start_time' => '17:45', 'second_end_time' => '22:00']);
        $budi = $this->freelancer('Budi');

        foreach (['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05'] as $date) {
            $this->schedule($budi, $date, $split, $this->luuca);
        }

        $this->attend($budi, '2026-11-02', '07:45', '13:00', ['company_location_id' => $this->luuca->id]);
        $this->attend($budi, '2026-11-02', '17:55', '22:00', ['segment' => 2, 'status' => 'late', 'late_minutes' => 10, 'company_location_id' => $this->luuca->id]);
        $this->attend($budi, '2026-11-03', '07:52', '13:00', ['status' => 'pending_approval', 'late_minutes' => 0, 'check_in_late_minutes' => 7, 'company_location_id' => $this->luuca->id]);
        $this->attend($budi, '2026-11-04', '07:45', null, ['status' => 'rejected', 'company_location_id' => $this->luuca->id]);
        $this->attend($budi, '2026-11-05', '17:45', '21:00', ['segment' => 2, 'company_location_id' => $this->luuca->id]);

        return $budi;
    }

    /**
     * Citra (default shift) and Dedi (company hours) never had a roster: Monday to Friday is their week.
     *
     * @return array{0: Employee, 1: Employee}
     */
    private function fixedHoursOfficeStaff(): array
    {
        $office = $this->shift('Kantor', '09:00', '17:00');
        $citra = $this->employee('Citra', 'EMP001', ['shift_id' => $office->id]);
        $dedi = $this->employee('Dedi', 'EMP002', ['join_date' => '2026-11-09']);

        $this->attend($citra, '2026-11-02', '09:00', '17:00');
        $this->attend($citra, '2026-11-03', '09:30', '17:00', ['status' => 'late', 'late_minutes' => 30]);
        Attendance::create(['employee_id' => $citra->id, 'date' => '2026-11-05', 'status' => 'absent', 'late_minutes' => 0]);
        LeaveRequest::create(['employee_id' => $citra->id, 'leave_type' => 'annual', 'start_date' => '2026-11-06', 'end_date' => '2026-11-06', 'days_count' => 1, 'reason' => 'Keperluan keluarga', 'status' => 'approved']);
        $this->attend($citra, '2026-11-07', '10:00', '14:00');
        $this->attend($citra, '2026-11-16', '09:00', '17:00');

        return [$citra, $dedi];
    }

    private function shift(string $name, string $start, string $end): Shift
    {
        return Shift::create(['company_id' => $this->company->id, 'name' => $name, 'start_time' => $start, 'end_time' => $end]);
    }

    private function freelancer(string $name): Freelancer
    {
        return Freelancer::create(['company_id' => $this->company->id, 'division_id' => $this->brand->id, 'full_name' => $name, 'status' => 'active']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function employee(string $name, string $nip, array $attributes = []): Employee
    {
        return Employee::create($attributes + [
            'company_id' => $this->company->id,
            'division_id' => $this->brand->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_number' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Staff'])->id,
            'nip' => $nip,
            'full_name' => $name,
            'status' => 'active',
            'join_date' => '2024-01-08',
        ]);
    }

    private function schedule(Freelancer $worker, string $date, Shift $shift, CompanyLocation $outlet): ShiftSchedule
    {
        return ShiftSchedule::create(['freelancer_id' => $worker->id, 'date' => $date, 'shift_id' => $shift->id, 'company_location_id' => $outlet->id]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function attend(Employee|Freelancer $worker, string $date, string $in, ?string $out, array $attributes = []): Attendance
    {
        return Attendance::create($attributes + [
            $worker instanceof Employee ? 'employee_id' : 'freelancer_id' => $worker->id,
            'date' => $date,
            'check_in' => "{$date} {$in}:00",
            'check_out' => $out ? "{$date} {$out}:00" : null,
            'status' => 'on_time',
            'late_minutes' => 0,
        ]);
    }
}
