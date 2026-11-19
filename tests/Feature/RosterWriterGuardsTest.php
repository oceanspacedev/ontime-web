<?php

namespace Tests\Feature;

use App\Filament\Resources\ShiftSchedules\Pages\ManageShiftSchedules;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Services\ScheduleCopyService;
use App\Services\ShiftAssignmentService;
use App\Services\ShiftScheduleSpreadsheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Every roster writer refuses the same things: a shift or outlet switched off, and a day outside the
 * worker's contract or employment (ended, not started, before the join date, more than a year ahead).
 * Covers one day from the app, "Atur Jadwal", "Salin Minggu" (panel and app) and the Excel import, and
 * the guard that keeps an outlet named in schedules or attendance from being deleted.
 *
 * Today is Wednesday 25 Nov 2026; the source week starts Monday 23 Nov, the target week Monday 30 Nov.
 */
class RosterWriterGuardsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private CompanyLocation $openOutlet;

    private CompanyLocation $closedOutlet;

    private Shift $morning;

    private Employee $manager;

    private Freelancer $bayu;

    private Freelancer $sari;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-25 10:00'));

        foreach (['Superadmin', 'Admin', 'Employee', 'Freelancer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->company = Company::create(['name' => 'PT Franchise', 'code' => 'PFC', 'is_active' => true]);
        $brand = Division::create(['company_id' => $this->company->id, 'name' => 'JCHICKEN', 'is_active' => true]);
        $this->openOutlet = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $brand->id, 'name' => 'JChicken Ciledug', 'latitude' => -6.2297, 'longitude' => 106.7112]);
        $this->closedOutlet = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $brand->id, 'name' => 'JChicken Renovasi', 'latitude' => -6.2297, 'longitude' => 106.7113]);
        $this->morning = Shift::create(['company_id' => $this->company->id, 'division_id' => $brand->id, 'name' => 'Pagi', 'code' => 'P', 'start_time' => '08:00', 'end_time' => '17:00']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        $this->grantDataAccessTo($this->admin, $this->company->id);

        $this->manager = Employee::create([
            'company_id' => $this->company->id,
            'division_id' => $brand->id,
            'job_level_id' => JobLevel::create(['name' => 'Manager', 'level_order' => 1])->id,
            'job_title_id' => JobTitle::create(['name' => 'Store Manager'])->id,
            'nip' => 'JC-001',
            'full_name' => 'Aisyiyah Dian Locita',
            'join_date' => '2026-10-01',
            'status' => 'active',
        ]);
        // Bayu's contract ends on Wednesday 2 Dec; the nightly close has not switched the status yet.
        $this->bayu = Freelancer::create(['company_id' => $this->company->id, 'division_id' => $brand->id, 'freelancer_number' => 'FL-01', 'full_name' => 'Bayu Adillah', 'status' => 'active', 'start_date' => '2026-11-01', 'end_date' => '2026-12-02']);
        $this->sari = Freelancer::create(['company_id' => $this->company->id, 'division_id' => $brand->id, 'freelancer_number' => 'FL-02', 'full_name' => 'Sari Wulan', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_copy_week_skips_an_inactive_outlet_and_days_outside_the_contract_the_same_from_panel_and_app(): void
    {
        $this->seedSourceWeek();
        $expected = ['copied' => 2, 'skipped_existing' => 1, 'skipped_unavailable' => 1, 'skipped_ended' => 2];

        // The service exactly as the panel action calls it.
        $result = app(ScheduleCopyService::class)->copyWeek($this->admin, Carbon::parse('2026-11-23'), Carbon::parse('2026-11-30'));
        $this->assertSame($expected, Arr::only($result, array_keys($expected)));
        $this->assertSame('2 jadwal disalin. 1 hari dilewati karena sudah terisi. 1 dilewati karena shift nonaktif atau di luar akses Anda. 2 dilewati karena di luar masa kontrak pekerja.', app(ScheduleCopyService::class)->summary($result));
        $afterService = $this->targetWeek();
        $this->assertSame(['employee:2026-11-30', 'freelancer:2026-11-30', 'sari:2026-11-30'], $afterService);

        // The panel action.
        $this->resetTargetWeek();
        $this->actingAs($this->admin);
        Livewire::test(ManageShiftSchedules::class)
            ->callAction('copyWeek', data: ['from' => '2026-11-23', 'to' => '2026-11-30'])
            ->assertHasNoFormErrors()
            ->assertNotified('Jadwal disalin');
        $this->assertSame($afterService, $this->targetWeek());

        // The app.
        $this->resetTargetWeek();
        Sanctum::actingAs($this->admin);
        $response = $this->postJson('/api/v1/team/schedules/copy', ['from' => '2026-11-23', 'to' => '2026-11-30'])->assertOk();
        $this->assertSame($expected, Arr::only($response->json('data'), array_keys($expected)));
        $this->assertSame(app(ScheduleCopyService::class)->summary($result), $response->json('message'));
        $this->assertSame($afterService, $this->targetWeek());

        $this->assertFalse(ShiftSchedule::query()->where('company_location_id', $this->closedOutlet->id)->where('date', '>=', '2026-11-30')->exists());
        $this->assertFalse(ShiftSchedule::query()->where('freelancer_id', $this->bayu->id)->where('date', '>', '2026-12-02')->exists());
    }

    public function test_copy_week_skips_a_worker_who_has_left_and_a_week_before_the_join_date(): void
    {
        ShiftSchedule::create(['employee_id' => $this->manager->id, 'date' => '2026-11-23', 'shift_id' => $this->morning->id, 'company_location_id' => $this->openOutlet->id]);
        ShiftSchedule::create(['freelancer_id' => $this->sari->id, 'date' => '2026-11-23', 'shift_id' => $this->morning->id, 'company_location_id' => $this->openOutlet->id]);
        $this->sari->update(['status' => 'completed']);
        $copies = app(ScheduleCopyService::class);

        $forward = $copies->copyWeek($this->admin, Carbon::parse('2026-11-23'), Carbon::parse('2026-11-30'));
        $this->assertSame([1, 1], [$forward['copied'], $forward['skipped_ended']], 'Sari left; only the manager is copied.');

        $beforeJoining = $copies->copyWeek($this->admin, Carbon::parse('2026-11-23'), Carbon::parse('2026-09-21'));
        $this->assertSame([0, 2], [$beforeJoining['copied'], $beforeJoining['skipped_ended']], 'The manager joined on 1 Oct.');

        $tooFar = $copies->copyWeek($this->admin, Carbon::parse('2026-11-23'), Carbon::parse('2099-12-28'));
        $this->assertSame([0, 2], [$tooFar['copied'], $tooFar['skipped_ended']]);

        $this->assertSame(3, ShiftSchedule::query()->count());
    }

    public function test_import_onto_an_inactive_outlet_or_past_the_contract_is_an_issue_not_a_schedule(): void
    {
        $this->closedOutlet->update(['is_active' => false]);
        $header = ['ID Pekerja', 'Nama', 'Jenis', '2026-12-01', '2026-12-03'];

        $path = $this->writeWorkbook([
            'JChicken Ciledug' => [$header, ['FL-01', 'Bayu', '', 'P', 'P'], ['JC-001', 'Aisyiyah', '', 'P', 'OFF']],
            'JChicken Renovasi' => [$header, ['FL-02', 'Sari', '', 'P', 'P']],
        ]);

        $result = app(ShiftScheduleSpreadsheet::class)->import($this->admin, $this->company, $path);

        $this->assertSame([3, 2], [$result['created'], $result['issue_count']]);
        $this->assertSame('Sheet "JChicken Ciledug" baris 2, 2026-12-03: Kontrak pekerja ini berakhir 02 Desember 2026. Hari setelahnya tidak bisa dijadwalkan. Sel dilewati.', $result['issues'][0]);
        $this->assertSame('Sheet "JChicken Renovasi": outlet ini sudah nonaktif. Sheet dilewati.', $result['issues'][1]);
        $this->assertSame(['2026-12-01'], ShiftSchedule::query()->where('freelancer_id', $this->bayu->id)->get()->map(fn (ShiftSchedule $schedule): string => $schedule->date->toDateString())->all());
        $this->assertSame(2, ShiftSchedule::query()->where('employee_id', $this->manager->id)->count());
        $this->assertFalse(ShiftSchedule::query()->where('freelancer_id', $this->sari->id)->exists());
    }

    public function test_import_may_still_clear_a_day_past_the_contract(): void
    {
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-12-03', 'shift_id' => $this->morning->id, 'company_location_id' => $this->openOutlet->id]);
        $path = $this->writeWorkbook(['JChicken Ciledug' => [['ID Pekerja', 'Nama', 'Jenis', '2026-12-03'], ['FL-01', 'Bayu', '', '-']]]);

        $result = app(ShiftScheduleSpreadsheet::class)->import($this->admin, $this->company, $path);

        $this->assertSame([1, 0], [$result['deleted'], $result['issue_count']]);
        $this->assertFalse(ShiftSchedule::query()->where('freelancer_id', $this->bayu->id)->exists());
    }

    public function test_atur_jadwal_refuses_an_ended_worker_and_days_past_the_contract(): void
    {
        $this->sari->update(['status' => 'completed']);
        $this->actingAs($this->admin);

        // The form only lists active workers; a stale selection of someone who left reaches the handler directly.
        Livewire::test(ManageShiftSchedules::class)->instance()->assignSchedule([
            'company_id' => $this->company->id,
            'workers' => ["freelancer:{$this->sari->id}"],
            'date_from' => '2026-11-30',
            'date_until' => '2026-12-01',
            'weekdays' => [1, 2, 3, 4, 5, 6, 7],
            'mode' => 'shift',
            'shift_id' => $this->morning->id,
            'company_location_id' => $this->openOutlet->id,
            'overwrite' => true,
        ]);

        $this->assertFalse(ShiftSchedule::query()->where('freelancer_id', $this->sari->id)->exists());

        Livewire::test(ManageShiftSchedules::class)
            ->callAction('assignSchedule', data: [
                'company_id' => $this->company->id,
                'workers' => ["freelancer:{$this->bayu->id}", "employee:{$this->manager->id}"],
                'date_from' => '2026-12-01',
                'date_until' => '2026-12-04',
                'mode' => 'shift',
                'shift_id' => $this->morning->id,
                'company_location_id' => $this->openOutlet->id,
            ])
            ->assertNotified('Jadwal shift disimpan');

        $this->assertSame(['2026-12-01', '2026-12-02'], ShiftSchedule::query()->where('freelancer_id', $this->bayu->id)->orderBy('date')->get()->map(fn (ShiftSchedule $schedule): string => $schedule->date->toDateString())->all());
        $this->assertSame(4, ShiftSchedule::query()->where('employee_id', $this->manager->id)->count());
    }

    public function test_a_day_before_the_join_date_or_more_than_a_year_ahead_is_refused(): void
    {
        Sanctum::actingAs($this->admin);
        $change = ['worker_type' => 'employee', 'worker_id' => $this->manager->id, 'mode' => 'shift', 'shift_id' => $this->morning->id, 'company_location_id' => $this->openOutlet->id];

        $this->putJson('/api/v1/team/schedules', $change + ['date' => '2026-09-30'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date' => 'Pekerja ini baru bergabung 01 Oktober 2026. Hari sebelumnya tidak bisa dijadwalkan.']);

        $this->putJson('/api/v1/team/schedules', ['date' => '0001-01-01', 'mode' => 'off'] + $change)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date']);

        $this->putJson('/api/v1/team/schedules', $change + ['date' => '2027-11-27'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date' => 'Jadwal hanya bisa diatur sampai satu tahun dari hari ini.']);

        $this->putJson('/api/v1/team/schedules', $change + ['date' => '2099-12-31'])->assertUnprocessable();

        $this->assertFalse(ShiftSchedule::query()->exists());

        // The join date itself, a past day after it, and the last day of the window are fine; clearing is never refused.
        $this->putJson('/api/v1/team/schedules', $change + ['date' => '2026-10-01'])->assertOk();
        $this->putJson('/api/v1/team/schedules', $change + ['date' => '2026-11-20'])->assertOk();
        $this->putJson('/api/v1/team/schedules', $change + ['date' => '2027-11-26'])->assertOk();
        $this->putJson('/api/v1/team/schedules', ['worker_type' => 'employee', 'worker_id' => $this->manager->id, 'date' => '2099-12-31', 'mode' => 'clear'])->assertOk();
    }

    public function test_assignment_error_names_what_is_wrong(): void
    {
        $assignments = app(ShiftAssignmentService::class);
        $day = Carbon::parse('2026-11-30');

        $this->assertNull($assignments->assignmentError($this->bayu, $day, $this->morning->fresh(), $this->openOutlet->fresh()));
        $this->assertNull($assignments->assignmentError($this->bayu, $day));

        $this->closedOutlet->update(['is_active' => false]);
        $this->assertSame('Outlet JChicken Renovasi sudah nonaktif.', $assignments->assignmentError($this->bayu, $day, $this->morning->fresh(), $this->closedOutlet->fresh()));

        $this->morning->update(['is_active' => false]);
        $this->assertSame('Shift Pagi sudah nonaktif.', $assignments->assignmentError($this->bayu, $day, $this->morning->fresh(), $this->openOutlet->fresh()));

        $this->assertSame('Kontrak pekerja ini baru mulai 01 November 2026. Hari sebelumnya tidak bisa dijadwalkan.', $assignments->assignmentError($this->bayu, Carbon::parse('2026-10-31')));
        $this->assertSame('Kontrak pekerja ini berakhir 02 Desember 2026. Hari setelahnya tidak bisa dijadwalkan.', $assignments->assignmentError($this->bayu, Carbon::parse('2026-12-03')));

        $this->manager->update(['status' => 'resigned']);
        $this->assertSame('Kontrak pekerja ini sudah berakhir.', $assignments->assignmentError($this->manager->fresh(), $day));
    }

    public function test_an_outlet_named_in_schedules_or_attendance_cannot_be_deleted_but_an_unused_one_can(): void
    {
        $withAttendance = CompanyLocation::create(['company_id' => $this->company->id, 'name' => 'Outlet Lama', 'latitude' => -6.2, 'longitude' => 106.7]);
        $unused = CompanyLocation::create(['company_id' => $this->company->id, 'name' => 'Outlet Baru', 'latitude' => -6.2, 'longitude' => 106.7]);

        ShiftSchedule::create(['freelancer_id' => $this->sari->id, 'date' => '2026-11-27', 'shift_id' => $this->morning->id, 'company_location_id' => $this->openOutlet->id]);
        ShiftSchedule::create(['freelancer_id' => $this->sari->id, 'date' => '2026-11-10', 'shift_id' => $this->morning->id, 'company_location_id' => $this->closedOutlet->id]);
        Attendance::create(['freelancer_id' => $this->sari->id, 'date' => '2026-11-11', 'check_in' => '2026-11-11 08:00:00', 'status' => 'on_time', 'company_location_id' => $withAttendance->id]);

        $this->assertSame('Outlet JChicken Ciledug masih dipakai di jadwal mendatang. Ubah jadwalnya atau nonaktifkan outlet ini.', $this->openOutlet->deletionBlockedReason());
        $this->assertStringContainsString('sudah dipakai di jadwal atau absensi', (string) $this->closedOutlet->deletionBlockedReason());
        $this->assertStringContainsString('sudah dipakai di jadwal atau absensi', (string) $withAttendance->deletionBlockedReason());
        $this->assertNull($unused->deletionBlockedReason());

        $this->assertFalse($this->openOutlet->delete());
        $this->assertFalse($this->closedOutlet->delete());
        $this->assertFalse($withAttendance->delete());
        $this->assertTrue($unused->delete());

        $this->assertSame(3, CompanyLocation::query()->count());
        $this->assertSame(2, ShiftSchedule::query()->whereNotNull('company_location_id')->count(), 'No schedule lost its outlet.');
        $this->assertSame($withAttendance->id, (int) Attendance::query()->value('company_location_id'));
    }

    /**
     * The week of Monday 23 Nov: the manager at the open outlet (copied) and at the outlet that closes
     * (skipped); Bayu on Monday (copied) and on Thursday and Friday, which land after his contract ends;
     * Sari on Monday, whose target Monday is already filled.
     */
    private function seedSourceWeek(): void
    {
        ShiftSchedule::create(['employee_id' => $this->manager->id, 'date' => '2026-11-23', 'shift_id' => $this->morning->id, 'company_location_id' => $this->openOutlet->id]);
        ShiftSchedule::create(['employee_id' => $this->manager->id, 'date' => '2026-11-24', 'shift_id' => $this->morning->id, 'company_location_id' => $this->closedOutlet->id]);

        foreach (['2026-11-23', '2026-11-26', '2026-11-27'] as $date) {
            ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => $date, 'shift_id' => $this->morning->id, 'company_location_id' => $this->openOutlet->id]);
        }

        ShiftSchedule::create(['freelancer_id' => $this->sari->id, 'date' => '2026-11-23', 'shift_id' => $this->morning->id, 'company_location_id' => $this->openOutlet->id]);
        ShiftSchedule::create(['freelancer_id' => $this->sari->id, 'date' => '2026-11-30', 'is_day_off' => true]);

        $this->closedOutlet->update(['is_active' => false]);
    }

    /**
     * @return list<string> "employee:2026-11-30" style keys of the target week, sorted; Sari's own row reads "sari:...".
     */
    private function targetWeek(): array
    {
        return ShiftSchedule::query()
            ->where('date', '>=', '2026-11-30')
            ->get()
            ->map(fn (ShiftSchedule $schedule): string => match (true) {
                (int) $schedule->freelancer_id === (int) $this->sari->id => 'sari',
                $schedule->employee_id !== null => 'employee',
                default => 'freelancer',
            }.':'.$schedule->date->toDateString())
            ->sort()
            ->values()
            ->all();
    }

    private function resetTargetWeek(): void
    {
        ShiftSchedule::query()->where('date', '>=', '2026-11-30')->where('is_day_off', false)->delete();
    }

    /**
     * @param  array<string, list<list<string>>>  $sheets
     */
    private function writeWorkbook(array $sheets): string
    {
        $path = $this->files[] = tempnam(sys_get_temp_dir(), 'guards').'.xlsx';
        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $first = true;

        foreach ($sheets as $name => $rows) {
            $sheet = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName($name);
            $first = false;

            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues($row));
            }
        }

        $writer->close();

        return $path;
    }
}
