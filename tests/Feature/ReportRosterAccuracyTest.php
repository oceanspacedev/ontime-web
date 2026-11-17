<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Filament\Resources\Shifts\Pages\ManageShifts;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\Holiday;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Services\ScheduleCopyService;
use App\Services\TeamRecapService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Payroll reports follow the roster: laporan absensi counts no-shows like Rekap Tim (RPT-B5), a worked day off
 * is present only (RPT-B7), Jam Terjadwal never drops to 0 for a rostered working day and a closed month keeps
 * its hours because a used shift is deactivated, not deleted or re-timed (RPT-B8), and copying a week includes
 * its Sunday and its working days without a shift (SCH-5).
 */
class ReportRosterAccuracyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Division $brand;

    private CompanyLocation $outlet;

    private User $coordinator;

    private Shift $morning;

    protected function setUp(): void
    {
        parent::setUp();

        // Sunday 15 November 2026, noon.
        $this->travelTo(Carbon::parse('2026-11-15 12:00'));

        foreach (['Koordinator', 'Freelancer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->company = Company::create(['name' => 'PT Roster', 'code' => 'RST', 'is_active' => true]);
        // A company gets its default policy on creation; this Klien works 09:00 - 17:00.
        $this->company->policy()->update(['work_start_time' => '09:00:00', 'work_end_time' => '17:00:00']);
        $this->brand = Division::create(['company_id' => $this->company->id, 'name' => 'JCHICKEN', 'is_active' => true]);
        $this->outlet = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $this->brand->id, 'name' => 'JChicken Ciledug', 'latitude' => -6.2, 'longitude' => 106.7]);
        $this->morning = $this->shift('Pagi', '08:00', '17:00');

        $this->coordinator = User::factory()->create();
        $this->coordinator->assignRole('Koordinator');
        $this->coordinator->grantDataAccess(DataScope::Divisions, divisionIds: [$this->brand->id]);
        Sanctum::actingAs($this->coordinator);
    }

    public function test_api_counts_only_rostered_no_shows_for_a_weekend_worker(): void
    {
        $weekend = $this->freelancer('Akhir Pekan');
        $this->schedule($weekend, '2026-11-07');
        $this->schedule($weekend, '2026-11-08');

        $this->assertSame(2, $this->summary('2026-11-02', '2026-11-08')['total_absent']);
        $this->assertSame(2, $this->recapRow($weekend)['absent_days']);
    }

    public function test_api_keeps_the_policy_week_for_a_worker_without_any_roster(): void
    {
        $this->freelancer('Tanpa Jadwal');

        // Mon 2 - Sun 8: five weekdays, the weekend is not a working day.
        $this->assertSame(5, $this->summary('2026-11-02', '2026-11-08')['total_absent']);
    }

    public function test_api_mixes_rostered_and_unrostered_workers_without_leaking_between_them(): void
    {
        $rostered = $this->freelancer('Berjadwal');
        $this->freelancer('Tanpa Jadwal');
        $this->schedule($rostered, '2026-11-03');
        ShiftSchedule::create(['freelancer_id' => $rostered->id, 'date' => '2026-11-04', 'is_day_off' => true]);

        // Rostered: Tue 3 only. Unrostered: Mon 2 - Fri 6.
        $this->assertSame(1 + 5, $this->summary('2026-11-02', '2026-11-08')['total_absent']);
    }

    public function test_a_worker_is_rostered_from_their_first_schedule_onward_whatever_the_range(): void
    {
        $worker = $this->freelancer('Jadwal Mulai Tengah Bulan');
        // No roster Mon 2 - Fri 6 (policy week); rostered from Tue 10, so from Mon 9 on only Tue 10 is a working day.
        $this->schedule($worker, '2026-11-10');
        ShiftSchedule::create(['freelancer_id' => $worker->id, 'date' => '2026-11-11', 'is_day_off' => true]);
        $this->schedule($worker, '2026-11-20');

        // The same worker-days give the same no-shows whatever the range (Fri 20 is still ahead).
        $this->assertSame(5, $this->summary('2026-11-02', '2026-11-06')['total_absent']);
        $this->assertSame(5 + 1, $this->summary('2026-11-02', '2026-11-14')['total_absent']);
        $this->assertSame(5 + 1, $this->summary('2026-11-02', '2026-11-30')['total_absent']);
        $this->assertSame(1, $this->summary('2026-11-09', '2026-11-30')['total_absent']);
    }

    public function test_api_does_not_count_a_rejected_check_in_on_a_rostered_day_off_as_absent(): void
    {
        $worker = $this->freelancer('Ditolak Saat Libur');
        ShiftSchedule::create(['freelancer_id' => $worker->id, 'date' => '2026-11-06', 'is_day_off' => true]);
        Attendance::create(['freelancer_id' => $worker->id, 'date' => '2026-11-06', 'check_in' => '2026-11-06 08:00:00', 'status' => 'rejected', 'late_minutes' => 0]);

        $this->assertSame(0, $this->summary('2026-11-06', '2026-11-06')['total_absent']);
        $this->assertSame(0, $this->recapRow($worker)['absent_days']);
    }

    public function test_api_does_not_count_an_explicit_absence_on_an_unrostered_weekend_but_does_on_a_weekday(): void
    {
        $worker = $this->freelancer('Tanpa Jadwal Ditolak');
        Attendance::create(['freelancer_id' => $worker->id, 'date' => '2026-11-07', 'check_in' => '2026-11-07 08:00:00', 'status' => 'rejected', 'late_minutes' => 0]);
        Attendance::create(['freelancer_id' => $worker->id, 'date' => '2026-11-06', 'status' => 'absent', 'late_minutes' => 0]);

        // Saturday is not a policy working day; the absent Friday is.
        $this->assertSame(1, $this->summary('2026-11-06', '2026-11-07')['total_absent']);
    }

    public function test_api_counts_a_rejected_check_in_on_a_rostered_working_day_once(): void
    {
        $worker = $this->freelancer('Ditolak Saat Kerja');
        $this->schedule($worker, '2026-11-06');
        Attendance::create(['freelancer_id' => $worker->id, 'date' => '2026-11-06', 'check_in' => '2026-11-06 08:00:00', 'status' => 'rejected', 'late_minutes' => 0]);

        $this->assertSame(1, $this->summary('2026-11-06', '2026-11-06')['total_absent']);
        $this->assertSame(1, $this->recapRow($worker)['absent_days']);
    }

    public function test_today_and_holidays_are_not_counted_but_a_rostered_holiday_is(): void
    {
        Holiday::create(['name' => 'Libur Nasional', 'date' => '2026-11-10', 'is_national' => true]);
        $rostered = $this->freelancer('Berjadwal');
        $this->freelancer('Tanpa Jadwal');
        // A shift on the holiday makes it a working day; a shift today is not a no-show yet.
        $this->schedule($rostered, '2026-11-10');
        $this->schedule($rostered, '2026-11-15');

        // Rostered: holiday Tue 10 only. Unrostered: Mon 9, Wed 11 - Fri 13 (holiday and weekend excluded).
        $this->assertSame(1 + 4, $this->summary('2026-11-09', '2026-11-15')['total_absent']);
    }

    public function test_api_counts_a_worked_rostered_overnight_day_as_present(): void
    {
        $night = $this->shift('Malam', '22:00', '06:00');
        $worker = $this->freelancer('Shift Malam');
        $this->schedule($worker, '2026-11-12', $night);
        $this->schedule($worker, '2026-11-13', $night);
        Attendance::create(['freelancer_id' => $worker->id, 'date' => '2026-11-12', 'check_in' => '2026-11-12 22:00:00', 'check_out' => '2026-11-13 06:00:00', 'status' => 'on_time', 'late_minutes' => 0]);

        $this->assertSame(
            ['total_present' => 1, 'total_late' => 0, 'total_leave' => 0, 'total_absent' => 1],
            $this->summary('2026-11-12', '2026-11-13'),
        );
    }

    public function test_a_worked_day_off_is_present_and_not_also_off(): void
    {
        $worker = $this->freelancer('Masuk Saat Libur');
        ShiftSchedule::create(['freelancer_id' => $worker->id, 'date' => '2026-11-06', 'is_day_off' => true]);
        ShiftSchedule::create(['freelancer_id' => $worker->id, 'date' => '2026-11-07', 'is_day_off' => true]);
        Attendance::create(['freelancer_id' => $worker->id, 'date' => '2026-11-06', 'check_in' => '2026-11-06 08:00:00', 'check_out' => '2026-11-06 12:00:00', 'status' => 'on_time', 'late_minutes' => 0]);

        $row = $this->recapRow($worker);

        $this->assertEquals(
            ['present_days' => 1, 'off_days' => 1, 'scheduled_days' => 0, 'absent_days' => 0, 'worked_hours' => 4.0],
            array_intersect_key($row, array_flip(['present_days', 'off_days', 'scheduled_days', 'absent_days', 'worked_hours'])),
        );
    }

    public function test_a_rejected_check_in_on_a_day_off_stays_a_day_off(): void
    {
        $worker = $this->freelancer('Ditolak Saat Libur');
        ShiftSchedule::create(['freelancer_id' => $worker->id, 'date' => '2026-11-06', 'is_day_off' => true]);
        Attendance::create(['freelancer_id' => $worker->id, 'date' => '2026-11-06', 'check_in' => '2026-11-06 08:00:00', 'status' => 'rejected', 'late_minutes' => 0]);

        $row = $this->recapRow($worker);

        $this->assertSame([0, 1, 0], [$row['present_days'], $row['off_days'], $row['absent_days']]);
    }

    public function test_split_and_overnight_shifts_count_their_full_scheduled_hours(): void
    {
        $split = Shift::create(['company_id' => $this->company->id, 'name' => 'Split', 'start_time' => '07:45', 'end_time' => '13:00', 'second_start_time' => '17:45', 'second_end_time' => '22:00']);
        $night = $this->shift('Malam', '22:00', '06:00');
        $worker = $this->freelancer('Split Malam');
        $this->schedule($worker, '2026-11-02', $split);
        $this->schedule($worker, '2026-11-03', $night);

        // 5h15 + 4h15 = 9.5h, plus 8h overnight.
        $this->assertSame(17.5, $this->recapRow($worker)['scheduled_hours']);
    }

    public function test_a_rostered_day_without_a_shift_uses_the_default_shift_then_the_policy_hours(): void
    {
        $withDefault = $this->freelancer('Shift Default', ['shift_id' => $this->shift('Siang', '11:00', '17:00')->id]);
        $withoutDefault = $this->freelancer('Jam Kantor');
        ShiftSchedule::create(['freelancer_id' => $withDefault->id, 'date' => '2026-11-02', 'shift_id' => null]);
        ShiftSchedule::create(['freelancer_id' => $withoutDefault->id, 'date' => '2026-11-02', 'shift_id' => null]);

        // The default shift is 6h; the policy is 09:00 - 17:00 (8h).
        $this->assertSame(6.0, $this->recapRow($withDefault)['scheduled_hours']);
        $this->assertSame(8.0, $this->recapRow($withoutDefault)['scheduled_hours']);
    }

    public function test_a_shift_used_in_a_closed_month_cannot_be_deleted_so_its_scheduled_hours_stay(): void
    {
        $this->actingAs($this->coordinator);
        // 10h, unlike the 8h policy, so a fallback would show.
        $retired = $this->shift('Lama', '07:00', '17:00');
        $worker = $this->freelancer('Shift Lama');
        $this->schedule($worker, '2026-11-02', $retired);
        $this->schedule($worker, '2026-11-03', $retired);

        $this->assertSame(20.0, $this->recapRow($worker)['scheduled_hours']);

        Livewire::test(ManageShifts::class)
            ->assertActionDisabled(TestAction::make('delete')->table($retired))
            ->callAction(TestAction::make('delete')->table($retired))
            ->selectTableRecords([$retired->id])
            ->callAction(TestAction::make('delete')->table()->bulk());

        $this->assertModelExists($retired);
        $this->assertSame(20.0, $this->recapRow($worker)['scheduled_hours']);
    }

    public function test_a_cover_shift_of_an_attendance_cannot_be_deleted_but_an_unused_shift_can(): void
    {
        $this->actingAs($this->coordinator);
        $cover = $this->shift('Pengganti', '10:00', '18:00');
        $unused = $this->shift('Kosong', '12:00', '20:00');
        $worker = $this->freelancer('Pengganti');
        Attendance::create(['freelancer_id' => $worker->id, 'shift_id' => $cover->id, 'is_off_schedule' => true, 'date' => '2026-11-04', 'check_in' => '2026-11-04 10:00:00', 'status' => 'on_time', 'late_minutes' => 0]);

        Livewire::test(ManageShifts::class)
            ->assertActionDisabled(TestAction::make('delete')->table($cover))
            ->callAction(TestAction::make('delete')->table($unused))
            ->assertNotified();

        $this->assertModelExists($cover);
        $this->assertModelMissing($unused);
    }

    public function test_the_hours_of_a_shift_used_on_a_past_day_are_locked(): void
    {
        $this->actingAs($this->coordinator);
        $used = $this->shift('Dipakai', '07:00', '17:00');
        $fresh = $this->shift('Baru', '07:00', '17:00');
        $worker = $this->freelancer('Jam Terkunci');
        $this->schedule($worker, '2026-11-02', $used);
        // Only rostered ahead: the hours may still change.
        $this->schedule($worker, '2026-11-20', $fresh);

        Livewire::test(ManageShifts::class)
            ->callAction(TestAction::make('edit')->table($used), ['start_time' => '09:00', 'end_time' => '15:00', 'name' => 'Dipakai Ubah'])
            ->assertHasNoFormErrors()
            ->callAction(TestAction::make('edit')->table($fresh), ['start_time' => '09:00', 'end_time' => '15:00'])
            ->assertHasNoFormErrors();

        $this->assertSame(['Dipakai Ubah', '07:00', '17:00'], [$used->refresh()->name, substr($used->start_time, 0, 5), substr($used->end_time, 0, 5)]);
        $this->assertSame(['09:00', '15:00'], [substr($fresh->refresh()->start_time, 0, 5), substr($fresh->end_time, 0, 5)]);
        // 10h on the past day stays; the 6h day ahead follows the new hours.
        $this->assertSame(16.0, $this->recapRow($worker)['scheduled_hours']);
    }

    public function test_copy_week_copies_a_working_day_without_a_shift(): void
    {
        $worker = $this->freelancer('Tanpa Shift');
        ShiftSchedule::create(['freelancer_id' => $worker->id, 'date' => '2026-11-16', 'shift_id' => null, 'is_day_off' => false]);
        $inactive = $this->shift('Nonaktif', '08:00', '12:00');
        $inactive->update(['is_active' => false]);
        $this->schedule($worker, '2026-11-17', $inactive);

        $result = app(ScheduleCopyService::class)->copyWeek($this->coordinator, Carbon::parse('2026-11-16'), Carbon::parse('2026-11-23'));

        $this->assertSame(['copied' => 1, 'skipped_unavailable' => 1], array_intersect_key($result, array_flip(['copied', 'skipped_unavailable'])));
        $copy = ShiftSchedule::query()->where('freelancer_id', $worker->id)->whereDate('date', '2026-11-23')->firstOrFail();
        $this->assertSame([null, false], [$copy->shift_id, (bool) $copy->is_day_off]);
    }

    public function test_copy_week_copies_monday_to_sunday_but_not_the_next_monday(): void
    {
        $worker = $this->freelancer('Salin Minggu');
        $this->schedule($worker, '2026-11-16');
        $this->schedule($worker, '2026-11-22');
        // The Monday after the source week must stay out of the copy.
        $this->schedule($worker, '2026-11-23');

        $result = app(ScheduleCopyService::class)->copyWeek($this->coordinator, Carbon::parse('2026-11-16'), Carbon::parse('2026-11-30'));

        $this->assertSame(2, $result['copied']);
        $this->assertTrue(ShiftSchedule::query()->where('freelancer_id', $worker->id)->whereDate('date', '2026-11-30')->exists());
        $this->assertTrue(ShiftSchedule::query()->where('freelancer_id', $worker->id)->whereDate('date', '2026-12-06')->exists());
        $this->assertFalse(ShiftSchedule::query()->where('freelancer_id', $worker->id)->whereDate('date', '2026-12-07')->exists());
    }

    public function test_copy_week_copies_a_sunday_stored_with_a_time_part(): void
    {
        $worker = $this->freelancer('Tanggal Berjam');
        $sunday = $this->schedule($worker, '2026-11-22');
        DB::table('shift_schedules')->where('id', $sunday->id)->update(['date' => '2026-11-22 00:00:00']);

        $result = app(ScheduleCopyService::class)->copyWeek($this->coordinator, Carbon::parse('2026-11-18'), Carbon::parse('2026-11-25'));

        $this->assertSame(['copied' => 1, 'from' => '2026-11-16', 'to' => '2026-11-23'], array_intersect_key($result, array_flip(['copied', 'from', 'to'])));
        $this->assertTrue(ShiftSchedule::query()->where('freelancer_id', $worker->id)->whereDate('date', '2026-11-29')->exists());
    }

    /**
     * @return array{total_present: int, total_late: int, total_leave: int, total_absent: int}
     */
    private function summary(string $from, string $until): array
    {
        return $this->getJson("/api/v1/laporan-absensi?start_date={$from}&end_date={$until}")
            ->assertOk()
            ->json('summary');
    }

    /**
     * @return array<string, mixed>
     */
    private function recapRow(Freelancer $worker): array
    {
        $row = collect(app(TeamRecapService::class)->recap($this->coordinator, Carbon::parse('2026-11-01')))
            ->firstWhere('worker.id', $worker->id);

        $this->assertNotNull($row, "No recap row for {$worker->full_name}.");

        return $row;
    }

    private function shift(string $name, string $start, string $end): Shift
    {
        return Shift::create(['company_id' => $this->company->id, 'division_id' => $this->brand->id, 'name' => $name, 'start_time' => $start, 'end_time' => $end]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function freelancer(string $name, array $attributes = []): Freelancer
    {
        return Freelancer::create(['company_id' => $this->company->id, 'division_id' => $this->brand->id, 'full_name' => $name, 'status' => 'active', ...$attributes]);
    }

    private function schedule(Freelancer $worker, string $date, ?Shift $shift = null): ShiftSchedule
    {
        return ShiftSchedule::create(['freelancer_id' => $worker->id, 'date' => $date, 'shift_id' => ($shift ?? $this->morning)->id, 'company_location_id' => $this->outlet->id]);
    }
}
