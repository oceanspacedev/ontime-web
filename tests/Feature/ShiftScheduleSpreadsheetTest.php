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
use App\Models\LeaveRequest;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Services\ShiftAssignmentService;
use App\Services\ShiftScheduleSpreadsheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ShiftScheduleSpreadsheetTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private CompanyLocation $jchickenOutlet;

    private CompanyLocation $momoyoOutlet;

    private Shift $jchickenMorning;

    private Shift $momoyoMorning;

    private Shift $housekeeping;

    private Shift $afternoon;

    private Employee $manager;

    private Freelancer $bayu;

    private Carbon $monday;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        // D4: an import never changes a day already gone, so the grids below (from 2 Nov) lie ahead.
        $this->travelTo(Carbon::parse('2026-11-01 08:00:00'));

        foreach (['Superadmin', 'Admin', 'Employee'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->company = Company::create(['name' => 'PT Franchise Ciledug', 'code' => 'PFC', 'is_active' => true]);
        $jchicken = Division::create(['company_id' => $this->company->id, 'name' => 'JCHICKEN', 'is_active' => true]);
        $momoyo = Division::create(['company_id' => $this->company->id, 'name' => 'MOMOYO', 'is_active' => true]);

        $this->jchickenOutlet = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $jchicken->id, 'name' => 'JChicken Ciledug', 'latitude' => -6.2297, 'longitude' => 106.7112]);
        $this->momoyoOutlet = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $momoyo->id, 'name' => 'Momoyo Ciledug', 'latitude' => -6.2297, 'longitude' => 106.7113]);

        $this->jchickenMorning = Shift::create(['company_id' => $this->company->id, 'division_id' => $jchicken->id, 'name' => 'Pagi', 'code' => 'P', 'start_time' => '08:00', 'end_time' => '17:00']);
        $this->momoyoMorning = Shift::create(['company_id' => $this->company->id, 'division_id' => $momoyo->id, 'name' => 'Pagi', 'code' => 'P', 'start_time' => '08:30', 'end_time' => '17:30']);
        $this->housekeeping = Shift::create(['company_id' => $this->company->id, 'division_id' => $momoyo->id, 'name' => 'Housekeeping', 'code' => 'HK', 'start_time' => '10:00', 'end_time' => '19:00']);
        $this->afternoon = Shift::create(['company_id' => $this->company->id, 'name' => 'Siang', 'start_time' => '13:30', 'end_time' => '22:30']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        $this->grantDataAccessTo($this->admin, $this->company->id);

        $this->manager = Employee::create([
            'company_id' => $this->company->id,
            'division_id' => $jchicken->id,
            'job_level_id' => JobLevel::create(['name' => 'Manager', 'level_order' => 1])->id,
            'job_title_id' => JobTitle::create(['name' => 'Store Manager'])->id,
            'nip' => 'JC-001',
            'full_name' => 'Aisyiyah Dian Locita',
            'status' => 'active',
        ]);
        $this->bayu = Freelancer::create(['company_id' => $this->company->id, 'division_id' => $jchicken->id, 'freelancer_number' => 'FL-01', 'full_name' => 'Bayu Adillah', 'status' => 'active']);

        $this->monday = Carbon::parse('2026-11-02');
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_export_writes_one_sheet_per_outlet_with_codes_sections_and_days_off(): void
    {
        ShiftSchedule::create(['employee_id' => $this->manager->id, 'date' => $this->monday, 'shift_id' => $this->jchickenMorning->id, 'company_location_id' => $this->jchickenOutlet->id, 'section' => 'K']);
        ShiftSchedule::create(['employee_id' => $this->manager->id, 'date' => $this->monday->copy()->addDay(), 'is_day_off' => true]);
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => $this->monday, 'shift_id' => $this->housekeeping->id, 'company_location_id' => $this->momoyoOutlet->id]);
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => $this->monday->copy()->addDays(2), 'shift_id' => $this->afternoon->id]);

        $sheets = $this->readWorkbook($this->export());

        $this->assertSame(['JChicken Ciledug', 'Momoyo Ciledug', 'Tanpa Outlet', 'Kode Shift'], array_keys($sheets));
        $this->assertSame(['ID Pekerja', 'Nama', 'Jenis', 'Sen 02-11-2026', 'Sel 03-11-2026', 'Rab 04-11-2026', 'Kam 05-11-2026', 'Jum 06-11-2026', 'Sab 07-11-2026', 'Min 08-11-2026'], $sheets['JChicken Ciledug'][0]);
        $this->assertSame(['JC-001', 'Aisyiyah Dian Locita', 'Karyawan', 'P (K)', '', '', '', '', '', ''], $sheets['JChicken Ciledug'][1]);
        $this->assertSame(['FL-01', 'Bayu Adillah', 'Freelancer', 'HK', '', '', '', '', '', ''], $sheets['Momoyo Ciledug'][1]);
        $this->assertSame(
            [['JC-001', 'Aisyiyah Dian Locita', 'Karyawan', '', 'OFF', '', '', '', '', ''], ['FL-01', 'Bayu Adillah', 'Freelancer', '', '', 'Siang', '', '', '', '']],
            array_slice($sheets['Tanpa Outlet'], 1),
        );
        $this->assertContains(['MOMOYO', 'HK', 'Housekeeping', '10:00 - 19:00'], $sheets['Kode Shift']);
    }

    public function test_export_lists_unscheduled_workers_when_asked(): void
    {
        $sheets = $this->readWorkbook($this->export(includeUnscheduled: true));

        $this->assertSame(['Tanpa Outlet', 'Kode Shift'], array_keys($sheets));
        $this->assertSame(['JC-001', 'FL-01'], array_column(array_slice($sheets['Tanpa Outlet'], 1), 0));

        $this->assertSame(['ID Pekerja', 'Nama', 'Jenis'], array_slice($this->readWorkbook($this->export())['Tanpa Outlet'][0], 0, 3), 'An empty export still has a sheet to fill in.');
    }

    public function test_exported_file_imports_back_without_changes(): void
    {
        ShiftSchedule::create(['employee_id' => $this->manager->id, 'date' => $this->monday, 'shift_id' => $this->jchickenMorning->id, 'company_location_id' => $this->jchickenOutlet->id, 'section' => 'K']);
        ShiftSchedule::create(['employee_id' => $this->manager->id, 'date' => $this->monday->copy()->addDay(), 'is_day_off' => true]);
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => $this->monday, 'shift_id' => $this->momoyoMorning->id, 'company_location_id' => $this->momoyoOutlet->id]);

        $result = app(ShiftScheduleSpreadsheet::class)->import($this->admin, $this->company, $this->export());

        $this->assertSame(['created' => 0, 'updated' => 0, 'deleted' => 0, 'unchanged' => 3, 'issues' => [], 'issue_count' => 0], $result);
    }

    public function test_import_reads_a_grid_like_the_outlet_spreadsheets(): void
    {
        ShiftSchedule::create(['employee_id' => $this->manager->id, 'date' => $this->monday->copy()->addDays(4), 'shift_id' => $this->afternoon->id]);
        $header = ['NO', 'Nama', 'Jenis', 'Senin 02/11/2026', 'Selasa 03/11/2026', 'Rabu 04/11/2026', 'Kamis 05/11/2026', 'Jumat 06/11/2026', 'Sabtu 07/11/2026'];

        $path = $this->writeWorkbook([
            'JChicken Ciledug' => [
                $header,
                ['JC-001', 'Aisyiyah', 'Karyawan', 'P', 'DAY OFF', '08.00 - 17.00', 'Siang (Kasir)', '-', 'x'],
                ['FL-01', 'Bayu', '', 'mmy', 'mmy', '', '', '', ''],
                [],
                ['ZZ-999', 'Orang Lain', '', 'P', '', '', '', '', ''],
            ],
            'Momoyo Ciledug' => [
                $header,
                ['FL-01', 'Bayu', 'Freelancer', 'HK', 'P (K)', 'JCHICKEN · Pagi', '', '', ''],
            ],
            'Outlet Hilang' => [$header, ['JC-001', 'Aisyiyah', '', 'P', '', '', '', '', '']],
        ]);

        $result = app(ShiftScheduleSpreadsheet::class)->import($this->admin, $this->company, $path);

        $this->assertSame([7, 0, 1], [$result['created'], $result['updated'], $result['deleted']]);
        $this->assertSame([
            [$this->jchickenMorning->id, $this->jchickenOutlet->id, false, null],
            [null, null, true, null],
            [$this->jchickenMorning->id, $this->jchickenOutlet->id, false, null],
            [$this->afternoon->id, $this->jchickenOutlet->id, false, 'Kasir'],
        ], $this->scheduleRows('employee_id', $this->manager->id), 'Codes resolve to the brand of the outlet sheet.');
        $this->assertSame([
            [$this->housekeeping->id, $this->momoyoOutlet->id, false, null],
            [$this->momoyoMorning->id, $this->momoyoOutlet->id, false, 'K'],
            [$this->jchickenMorning->id, $this->momoyoOutlet->id, false, null],
        ], $this->scheduleRows('freelancer_id', $this->bayu->id), '"mmy" in the JChicken sheet leaves the Momoyo schedule alone.');
        $this->assertFalse(ShiftSchedule::where('employee_id', $this->manager->id)->whereDate('date', $this->monday->copy()->addDays(4))->exists(), '"-" removes the schedule.');

        $this->assertSame(5, $result['issue_count']);
        $this->assertStringContainsString('baris 2, 2026-11-07: "x" bukan kode', $result['issues'][0]);
        $this->assertStringContainsString('"mmy"', $result['issues'][1]);
        $this->assertStringContainsString('Sheet "JChicken Ciledug" baris 5: pekerja aktif "ZZ-999" tidak ditemukan', $result['issues'][3]);
        $this->assertStringContainsString('Sheet "Outlet Hilang": outlet dengan nama ini tidak ada', $result['issues'][4]);
    }

    /**
     * Carried must-fix (g): the import refuses a shift whose hours already hold a check-in without a schedule,
     * as the app, Atur Jadwal and copy week do.
     */
    public function test_import_skips_a_shift_over_a_check_in_without_a_schedule(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 09:00:00'));
        Attendance::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-02', 'check_in' => '2026-11-02 08:05:00', 'status' => 'on_time', 'late_minutes' => 0, 'company_location_id' => $this->jchickenOutlet->id]);
        $header = ['ID Pekerja', 'Nama', 'Jenis', '2026-11-02', '2026-11-03'];
        $path = $this->writeWorkbook(['JChicken Ciledug' => [$header, ['FL-01', 'Bayu', '', 'P', 'P']]]);

        $result = app(ShiftScheduleSpreadsheet::class)->import($this->admin, $this->company, $path);

        $this->assertSame(1, $result['created'], 'Only the next day is written.');
        $this->assertFalse(ShiftSchedule::where('freelancer_id', $this->bayu->id)->whereDate('date', '2026-11-02')->exists());
        $this->assertStringContainsString('2026-11-02: '.ShiftAssignmentService::UNLINKED_ATTENDANCE_MESSAGE, $result['issues'][0]);
    }

    /**
     * D1: a single cell on a day with two schedules (added from the app) is matched by outlet: a cell equal to
     * one of them keeps it and removes the other (the cell lists all of that outlet's schedules), a day off
     * beside the other schedule is skipped with an issue line, and "-" removes the schedules at that sheet's
     * outlet. (Review fix: the schedule the cell no longer names used to be kept.)
     */
    public function test_import_matches_a_day_with_two_schedules_by_outlet(): void
    {
        $first = ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-02', 'sequence' => 1, 'shift_id' => $this->jchickenMorning->id, 'company_location_id' => $this->jchickenOutlet->id]);
        $second = ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-02', 'sequence' => 2, 'shift_id' => $this->afternoon->id, 'company_location_id' => $this->jchickenOutlet->id]);
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-03', 'sequence' => 1, 'shift_id' => $this->jchickenMorning->id, 'company_location_id' => $this->jchickenOutlet->id]);
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-03', 'sequence' => 2, 'shift_id' => $this->afternoon->id, 'company_location_id' => $this->jchickenOutlet->id]);
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-04', 'sequence' => 1, 'shift_id' => $this->jchickenMorning->id, 'company_location_id' => $this->jchickenOutlet->id]);
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-04', 'sequence' => 2, 'shift_id' => $this->afternoon->id, 'company_location_id' => $this->jchickenOutlet->id]);
        $header = ['ID Pekerja', 'Nama', 'Jenis', '2026-11-02', '2026-11-03', '2026-11-04'];
        $path = $this->writeWorkbook(['JChicken Ciledug' => [$header, ['FL-01', 'Bayu', '', 'Siang', 'DAY OFF', '-']]]);

        $result = app(ShiftScheduleSpreadsheet::class)->import($this->admin, $this->company, $path);

        $this->assertSame([0, 0, 3, 1], [$result['created'], $result['updated'], $result['deleted'], $result['unchanged']]);
        $this->assertSame(1, $result['issue_count']);
        $this->assertStringContainsString('2026-11-03: '.ShiftAssignmentService::DAY_OFF_WITH_OTHERS_MESSAGE, $result['issues'][0]);
        $this->assertSame(3, ShiftSchedule::where('freelancer_id', $this->bayu->id)->count());
        $this->assertFalse(ShiftSchedule::where('freelancer_id', $this->bayu->id)->whereDate('date', '2026-11-04')->exists(), '"-" removes both JChicken schedules.');
        $this->assertNull($first->fresh(), 'The cell "Siang" no longer names the Pagi schedule.');
        $this->assertSame($this->afternoon->id, $second->fresh()->shift_id);
    }

    /**
     * Review fix: a joined cell is matched in two passes, so "M+P" over an existing (here locked) M schedule
     * keeps that schedule and adds only P, instead of trying to turn M into P and adding M again.
     */
    public function test_a_joined_cell_adds_a_new_part_beside_its_existing_schedule(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 07:00:00'));
        $evening = $this->momoyoEvening();
        $locked = ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-02', 'sequence' => 1, 'shift_id' => $evening->id, 'company_location_id' => $this->momoyoOutlet->id]);
        Attendance::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-02', 'shift_schedule_id' => $locked->id, 'schedule_sequence' => 1, 'check_in' => '2026-11-02 06:55:00', 'status' => 'on_time', 'late_minutes' => 0, 'company_location_id' => $this->momoyoOutlet->id]);
        $free = ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-03', 'sequence' => 1, 'shift_id' => $evening->id, 'company_location_id' => $this->momoyoOutlet->id, 'notes' => 'Tutup toko']);
        $this->assertTrue($locked->isLocked());
        $path = $this->writeWorkbook(['Momoyo Ciledug' => [['ID Pekerja', 'Nama', 'Jenis', '2026-11-02', '2026-11-03'], ['FL-01', 'Bayu', '', 'P+M', 'P+M']]]);

        $result = app(ShiftScheduleSpreadsheet::class)->import($this->admin, $this->company, $path);

        $this->assertSame(['created' => 2, 'updated' => 0, 'deleted' => 0, 'unchanged' => 2, 'issues' => [], 'issue_count' => 0], $result);

        foreach ([$locked, $free] as $existing) {
            $this->assertSame(
                [[1, $evening->id], [2, $this->momoyoMorning->id]],
                ShiftSchedule::where('freelancer_id', $this->bayu->id)->whereDate('date', $existing->date)->orderBy('sequence')->get()->map(fn (ShiftSchedule $row): array => [$row->sequence, $row->shift_id])->all(),
            );
        }

        $this->assertSame([$evening->id, 'Tutup toko'], [$free->fresh()->shift_id, $free->fresh()->notes]);
    }

    /**
     * Review fix: export, edit, import gives back the edited file. Dropping a part of an exported "P (K)+M"
     * cell removes that schedule; a part that cannot go (it has a check-in) stays with an issue line.
     */
    public function test_dropping_a_part_of_a_joined_cell_removes_that_schedule(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 07:00:00'));
        $evening = $this->momoyoEvening();

        foreach (['2026-11-02', '2026-11-03'] as $date) {
            ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => $date, 'sequence' => 1, 'shift_id' => $this->momoyoMorning->id, 'company_location_id' => $this->momoyoOutlet->id, 'section' => 'K']);
            ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => $date, 'sequence' => 2, 'shift_id' => $evening->id, 'company_location_id' => $this->momoyoOutlet->id]);
        }

        $lockedMorning = ShiftSchedule::where('freelancer_id', $this->bayu->id)->whereDate('date', '2026-11-02')->where('sequence', 1)->sole();
        Attendance::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-02', 'shift_schedule_id' => $lockedMorning->id, 'schedule_sequence' => 1, 'check_in' => '2026-11-02 06:55:00', 'status' => 'on_time', 'late_minutes' => 0, 'company_location_id' => $this->momoyoOutlet->id]);
        $path = $this->writeWorkbook(['Momoyo Ciledug' => [['ID Pekerja', 'Nama', 'Jenis', '2026-11-02', '2026-11-03'], ['FL-01', 'Bayu', '', 'M', 'P (K)']]]);

        $result = app(ShiftScheduleSpreadsheet::class)->import($this->admin, $this->company, $path);

        $this->assertSame([0, 0, 1, 2, 1], [$result['created'], $result['updated'], $result['deleted'], $result['unchanged'], $result['issue_count']]);
        $this->assertStringContainsString('2026-11-02: jadwal "P (K)" tidak ada lagi di sel, tetapi tidak dihapus: '.ShiftSchedule::LOCKED_MESSAGE, $result['issues'][0]);
        $this->assertSame(2, ShiftSchedule::where('freelancer_id', $this->bayu->id)->whereDate('date', '2026-11-02')->count());
        $this->assertSame([$this->momoyoMorning->id], ShiftSchedule::where('freelancer_id', $this->bayu->id)->whereDate('date', '2026-11-03')->pluck('shift_id')->all());
    }

    /**
     * D1: "-" on one outlet's sheet removes only that outlet's schedules; a cell on another outlet's sheet
     * changes the schedule there and leaves the rest of the day alone.
     */
    public function test_a_clear_cell_removes_only_that_outlets_schedule(): void
    {
        $evening = $this->momoyoEvening();
        $jchicken = ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-02', 'sequence' => 1, 'shift_id' => $this->jchickenMorning->id, 'company_location_id' => $this->jchickenOutlet->id]);
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-02', 'sequence' => 2, 'shift_id' => $evening->id, 'company_location_id' => $this->momoyoOutlet->id]);
        $header = ['ID Pekerja', 'Nama', 'Jenis', '2026-11-02'];
        $path = $this->writeWorkbook(['Momoyo Ciledug' => [$header, ['FL-01', 'Bayu', '', '-']]]);

        $result = app(ShiftScheduleSpreadsheet::class)->import($this->admin, $this->company, $path);

        $this->assertSame([0, 0, 1, 0, 0], [$result['created'], $result['updated'], $result['deleted'], $result['unchanged'], $result['issue_count']]);
        $this->assertSame([$jchicken->id], ShiftSchedule::where('freelancer_id', $this->bayu->id)->pluck('id')->all());
    }

    /**
     * A file without several schedules a day (the shape before D1) re-imports exactly as it did: one cell
     * per worker and date replaces that day's single schedule, whatever outlet it had.
     */
    public function test_a_legacy_single_sheet_file_reimports_identically(): void
    {
        $moved = ShiftSchedule::create(['employee_id' => $this->manager->id, 'date' => '2026-11-02', 'shift_id' => $this->jchickenMorning->id, 'company_location_id' => $this->momoyoOutlet->id]);
        ShiftSchedule::create(['employee_id' => $this->manager->id, 'date' => '2026-11-03', 'shift_id' => $this->jchickenMorning->id, 'company_location_id' => $this->jchickenOutlet->id]);
        $header = ['ID Pekerja', 'Nama', 'Jenis', '2026-11-02', '2026-11-03', '2026-11-04'];
        $path = $this->writeWorkbook(['JChicken Ciledug' => [$header, ['JC-001', 'Aisyiyah', '', 'Siang', 'P', 'OFF']]]);

        $result = app(ShiftScheduleSpreadsheet::class)->import($this->admin, $this->company, $path);

        $this->assertSame([1, 1, 0, 1, 0], [$result['created'], $result['updated'], $result['deleted'], $result['unchanged'], $result['issue_count']]);
        $this->assertSame([
            [$this->afternoon->id, $this->jchickenOutlet->id, false, null],
            [$this->jchickenMorning->id, $this->jchickenOutlet->id, false, null],
            [null, null, true, null],
        ], $this->scheduleRows('employee_id', $this->manager->id), 'The Momoyo schedule moves to the sheet\'s outlet, as before.');
        $this->assertSame(1, $moved->fresh()->sequence);

        $again = app(ShiftScheduleSpreadsheet::class)->import($this->admin, $this->company, $path);

        $this->assertSame(['created' => 0, 'updated' => 0, 'deleted' => 0, 'unchanged' => 3, 'issues' => [], 'issue_count' => 0], $again);
        $this->assertSame([1, 1, 1], ShiftSchedule::where('employee_id', $this->manager->id)->orderBy('date')->pluck('sequence')->all());
    }

    /**
     * D1: two schedules at one outlet are exported as "A+B" in one cell, and that cell imports back as the
     * same two schedules (a fresh day gets both, numbered 1 and 2).
     */
    public function test_a_joined_cell_round_trips_two_schedules_at_one_outlet(): void
    {
        $evening = $this->momoyoEvening();
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-02', 'sequence' => 1, 'shift_id' => $this->momoyoMorning->id, 'company_location_id' => $this->momoyoOutlet->id, 'section' => 'K']);
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-02', 'sequence' => 2, 'shift_id' => $evening->id, 'company_location_id' => $this->momoyoOutlet->id]);

        $sheets = $this->readWorkbook($this->export());

        $this->assertSame(['FL-01', 'Bayu Adillah', 'Freelancer', 'P (K)+M', '', '', '', '', '', ''], $sheets['Momoyo Ciledug'][1]);
        $this->assertSame(['created' => 0, 'updated' => 0, 'deleted' => 0, 'unchanged' => 2, 'issues' => [], 'issue_count' => 0], app(ShiftScheduleSpreadsheet::class)->import($this->admin, $this->company, $this->export()));

        $path = $this->writeWorkbook(['Momoyo Ciledug' => [['ID Pekerja', 'Nama', 'Jenis', '2026-11-03'], ['FL-01', 'Bayu', '', 'P (K)+M']]]);
        $result = app(ShiftScheduleSpreadsheet::class)->import($this->admin, $this->company, $path);

        $this->assertSame(2, $result['created']);
        $this->assertSame(
            [[1, $this->momoyoMorning->id, 'K'], [2, $evening->id, null]],
            ShiftSchedule::where('freelancer_id', $this->bayu->id)->whereDate('date', '2026-11-03')->orderBy('sequence')->get()->map(fn (ShiftSchedule $row): array => [$row->sequence, $row->shift_id, $row->section])->all(),
        );
    }

    /**
     * A schedule added by the import must fit the day: it may not overlap a schedule the file leaves alone.
     */
    public function test_import_refuses_an_added_schedule_that_overlaps_the_day(): void
    {
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-02', 'sequence' => 1, 'shift_id' => $this->jchickenMorning->id, 'company_location_id' => $this->jchickenOutlet->id]);
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-02', 'sequence' => 2, 'shift_id' => $this->momoyoEvening()->id, 'company_location_id' => $this->momoyoOutlet->id]);
        $path = $this->writeWorkbook(['Tanpa Outlet' => [['ID Pekerja', 'Nama', 'Jenis', '2026-11-02'], ['FL-01', 'Bayu', '', 'HK']]]);

        $result = app(ShiftScheduleSpreadsheet::class)->import($this->admin, $this->company, $path);

        $this->assertSame(0, $result['created']);
        $this->assertStringContainsString('2026-11-02: '.ShiftAssignmentService::OVERLAP_MESSAGE, $result['issues'][0]);
        $this->assertSame(2, ShiftSchedule::where('freelancer_id', $this->bayu->id)->count());
    }

    /**
     * A new schedule from the import never takes the number of a check-in without a schedule of that day,
     * or it would inherit that row.
     */
    public function test_import_gives_a_new_schedule_a_number_no_check_in_holds(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 07:00:00'));
        Attendance::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-02', 'schedule_sequence' => 1, 'check_in' => '2026-11-02 05:00:00', 'check_out' => '2026-11-02 06:00:00', 'status' => 'pending_approval', 'late_minutes' => 0, 'company_location_id' => $this->jchickenOutlet->id]);
        $header = ['ID Pekerja', 'Nama', 'Jenis', '2026-11-02'];
        $path = $this->writeWorkbook(['JChicken Ciledug' => [$header, ['FL-01', 'Bayu', '', 'Siang']]]);

        $result = app(ShiftScheduleSpreadsheet::class)->import($this->admin, $this->company, $path);

        $this->assertSame(1, $result['created']);
        $this->assertSame(2, ShiftSchedule::where('freelancer_id', $this->bayu->id)->sole()->sequence);
    }

    /**
     * D1 (rewritten, signed off): the same worker and date on two outlet sheets is two schedules when their
     * hours do not overlap, numbered in sheet order. It used to keep only the first sheet's cell.
     */
    public function test_the_same_worker_and_date_in_two_sheets_is_two_schedules(): void
    {
        $evening = $this->momoyoEvening();
        $header = ['ID Pekerja', 'Nama', 'Jenis', '2026-11-02'];
        $path = $this->writeWorkbook([
            'JChicken Ciledug' => [$header, ['FL-01', 'Bayu', '', 'P']],
            'Momoyo Ciledug' => [$header, ['FL-01', 'Bayu', '', 'M']],
        ]);

        $result = app(ShiftScheduleSpreadsheet::class)->import($this->admin, $this->company, $path);

        $this->assertSame([2, 0], [$result['created'], $result['issue_count']]);
        $this->assertSame(
            [[1, $this->jchickenMorning->id, $this->jchickenOutlet->id], [2, $evening->id, $this->momoyoOutlet->id]],
            ShiftSchedule::where('freelancer_id', $this->bayu->id)->orderBy('sequence')->get()->map(fn (ShiftSchedule $row): array => [$row->sequence, $row->shift_id, $row->company_location_id])->all(),
        );
    }

    /**
     * Two sheets whose shifts overlap for the same worker and date: the first sheet's schedule is kept and
     * the second cell is an issue line, as is a day off beside a shift.
     */
    public function test_overlapping_hours_or_a_day_off_on_two_sheets_keep_the_first(): void
    {
        $header = ['ID Pekerja', 'Nama', 'Jenis', '2026-11-02', '2026-11-03'];
        $path = $this->writeWorkbook([
            'JChicken Ciledug' => [$header, ['FL-01', 'Bayu', '', 'P', 'OFF']],
            'Momoyo Ciledug' => [$header, ['FL-01', 'Bayu', '', 'HK', 'HK']],
        ]);

        $result = app(ShiftScheduleSpreadsheet::class)->import($this->admin, $this->company, $path);

        $this->assertSame([[$this->jchickenMorning->id, $this->jchickenOutlet->id, false, null], [null, null, true, null]], $this->scheduleRows('freelancer_id', $this->bayu->id));
        $this->assertSame(2, $result['issue_count']);
        $this->assertStringContainsString('2026-11-02: jam shift ini bertabrakan dengan jadwal di sheet "JChicken Ciledug"', $result['issues'][0]);
        $this->assertStringContainsString('2026-11-03: pekerja ini sudah dijadwalkan di sheet "JChicken Ciledug"', $result['issues'][1]);
    }

    /**
     * A part of a cell refused while reading (it overlaps a schedule read first from another sheet) still
     * names its schedule: the existing row stays, and the issue line says so, instead of the row being
     * removed as missing from its cell.
     */
    public function test_a_schedule_whose_part_was_refused_while_reading_is_kept(): void
    {
        $evening = $this->momoyoEvening();
        $late = Shift::create(['company_id' => $this->company->id, 'name' => 'Sore', 'code' => 'S', 'start_time' => '17:45', 'end_time' => '22:00']);
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-02', 'sequence' => 1, 'shift_id' => $this->momoyoMorning->id, 'company_location_id' => $this->momoyoOutlet->id]);
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-02', 'sequence' => 2, 'shift_id' => $evening->id, 'company_location_id' => $this->momoyoOutlet->id]);
        $header = ['ID Pekerja', 'Nama', 'Jenis', '2026-11-02'];
        $path = $this->writeWorkbook([
            'JChicken Ciledug' => [$header, ['FL-01', 'Bayu', '', 'S']],
            'Momoyo Ciledug' => [$header, ['FL-01', 'Bayu', '', 'P+M']],
        ]);

        $result = app(ShiftScheduleSpreadsheet::class)->import($this->admin, $this->company, $path);

        $this->assertSame([0, 0, 0], [$result['created'], $result['updated'], $result['deleted']]);
        $this->assertSame(
            [[1, $this->momoyoMorning->id], [2, $evening->id]],
            ShiftSchedule::where('freelancer_id', $this->bayu->id)->orderBy('sequence')->get()->map(fn (ShiftSchedule $row): array => [$row->sequence, $row->shift_id])->all(),
        );
        $issues = implode("\n", $result['issues']);
        $this->assertStringContainsString('jam shift ini bertabrakan dengan jadwal di sheet "JChicken Ciledug"', $issues);
        $this->assertStringContainsString('jadwal "M" tetap ada, karena bagian selnya dilewati.', $issues);
        $this->assertStringContainsString(ShiftAssignmentService::OVERLAP_MESSAGE, $issues, 'The new S still may not overlap M.');
        $this->assertFalse(ShiftSchedule::where('shift_id', $late->id)->exists());
    }

    /**
     * A "+" inside a section reads back: "P (K+L)+M" is two schedules, the first with section K+L.
     */
    public function test_a_plus_inside_a_section_round_trips(): void
    {
        $evening = $this->momoyoEvening();
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-02', 'sequence' => 1, 'shift_id' => $this->momoyoMorning->id, 'company_location_id' => $this->momoyoOutlet->id, 'section' => 'K+L']);
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-02', 'sequence' => 2, 'shift_id' => $evening->id, 'company_location_id' => $this->momoyoOutlet->id]);

        $this->assertSame('P (K+L)+M', $this->readWorkbook($this->export())['Momoyo Ciledug'][1][3]);
        $this->assertSame(['created' => 0, 'updated' => 0, 'deleted' => 0, 'unchanged' => 2, 'issues' => [], 'issue_count' => 0], app(ShiftScheduleSpreadsheet::class)->import($this->admin, $this->company, $this->export()));

        $path = $this->writeWorkbook(['Momoyo Ciledug' => [['ID Pekerja', 'Nama', 'Jenis', '2026-11-03'], ['FL-01', 'Bayu', '', 'P (K+L)+M']]]);
        $this->assertSame(2, app(ShiftScheduleSpreadsheet::class)->import($this->admin, $this->company, $path)['created']);
        $this->assertSame(
            [[$this->momoyoMorning->id, 'K+L'], [$evening->id, null]],
            ShiftSchedule::where('freelancer_id', $this->bayu->id)->whereDate('date', '2026-11-03')->orderBy('sequence')->get()->map(fn (ShiftSchedule $row): array => [$row->shift_id, $row->section])->all(),
        );
    }

    /**
     * A shift coded "P+S" next to the shifts "P" and "S": the two schedules P and S are exported as
     * "[P]+[S]" so they read back as two, the split shift alone keeps "P+S", and the file re-imports with no
     * change and no issue. A hand-typed "P+S" still reads as that shift (the format before the brackets).
     */
    public function test_a_cell_that_would_read_as_another_shift_is_written_in_brackets_and_round_trips(): void
    {
        $evening = Shift::create(['company_id' => $this->company->id, 'division_id' => $this->momoyoOutlet->division_id, 'name' => 'Sore', 'code' => 'S', 'start_time' => '18:00', 'end_time' => '23:00']);
        $split = Shift::create(['company_id' => $this->company->id, 'division_id' => $this->momoyoOutlet->division_id, 'name' => 'Pagi Sore', 'code' => 'P+S', 'start_time' => '08:30', 'end_time' => '12:00', 'second_start_time' => '18:00', 'second_end_time' => '22:00']);
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-02', 'sequence' => 1, 'shift_id' => $this->momoyoMorning->id, 'company_location_id' => $this->momoyoOutlet->id]);
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-02', 'sequence' => 2, 'shift_id' => $evening->id, 'company_location_id' => $this->momoyoOutlet->id, 'section' => 'Bar']);
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-03', 'sequence' => 1, 'shift_id' => $split->id, 'company_location_id' => $this->momoyoOutlet->id]);

        $row = $this->readWorkbook($this->export())['Momoyo Ciledug'][1];
        $this->assertSame(['[P]+[S (Bar)]', 'P+S'], [$row[3], $row[4]]);
        $this->assertSame(['created' => 0, 'updated' => 0, 'deleted' => 0, 'unchanged' => 3, 'issues' => [], 'issue_count' => 0], app(ShiftScheduleSpreadsheet::class)->import($this->admin, $this->company, $this->export()));

        $path = $this->writeWorkbook(['Momoyo Ciledug' => [['ID Pekerja', 'Nama', 'Jenis', '2026-11-04', '2026-11-05'], ['FL-01', 'Bayu', '', 'P+S', '[P]+[S]']]]);
        $this->assertSame(3, app(ShiftScheduleSpreadsheet::class)->import($this->admin, $this->company, $path)['created']);
        $this->assertSame([$split->id], ShiftSchedule::where('freelancer_id', $this->bayu->id)->whereDate('date', '2026-11-04')->pluck('shift_id')->all());
        $this->assertSame([$this->momoyoMorning->id, $evening->id], ShiftSchedule::where('freelancer_id', $this->bayu->id)->whereDate('date', '2026-11-05')->orderBy('sequence')->pluck('shift_id')->all());
    }

    /**
     * The overlap with a neighbouring day is judged on the days as the file leaves them: a week reworked in
     * one file (Monday to a night shift, Tuesday from 05:00 to the afternoon) is written whole. Two new days
     * of the file that do overlap keep the later one and refuse the earlier.
     */
    public function test_the_neighbouring_day_check_reads_the_days_as_the_file_leaves_them(): void
    {
        $night = Shift::create(['company_id' => $this->company->id, 'name' => 'Malam', 'code' => 'MLM', 'start_time' => '22:00', 'end_time' => '06:00']);
        $early = Shift::create(['company_id' => $this->company->id, 'name' => 'Subuh', 'code' => 'SB', 'start_time' => '05:00', 'end_time' => '13:00']);
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-02', 'shift_id' => $this->momoyoMorning->id, 'company_location_id' => $this->momoyoOutlet->id]);
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-03', 'shift_id' => $early->id, 'company_location_id' => $this->momoyoOutlet->id]);

        $path = $this->writeWorkbook(['Momoyo Ciledug' => [['ID Pekerja', 'Nama', 'Jenis', '2026-11-02', '2026-11-03'], ['FL-01', 'Bayu', '', 'MLM', 'Siang']]]);
        $result = app(ShiftScheduleSpreadsheet::class)->import($this->admin, $this->company, $path);

        $this->assertSame([2, []], [$result['updated'], $result['issues']]);
        $this->assertSame([$night->id, $this->afternoon->id], ShiftSchedule::where('freelancer_id', $this->bayu->id)->orderBy('date')->pluck('shift_id')->all());

        // Both new: Thursday night runs into Friday's 05:00 start, so Thursday is refused and Friday written.
        $path = $this->writeWorkbook(['Momoyo Ciledug' => [['ID Pekerja', 'Nama', 'Jenis', '2026-11-05', '2026-11-06'], ['FL-01', 'Bayu', '', 'MLM', 'SB']]]);
        $result = app(ShiftScheduleSpreadsheet::class)->import($this->admin, $this->company, $path);

        $this->assertSame(1, $result['created']);
        $this->assertStringContainsString(ShiftAssignmentService::ADJACENT_OVERLAP_MESSAGE, $result['issues'][0]);
        $this->assertSame([$early->id], ShiftSchedule::where('freelancer_id', $this->bayu->id)->where('date', '>=', '2026-11-05')->pluck('shift_id')->all());
    }

    /**
     * A busy week (several schedules a day on one and on two sheets, sections with "+", days off, a
     * schedule-less placement row and an approved leave on a scheduled day) exported and imported again
     * unchanged changes nothing and reports nothing.
     */
    public function test_a_busy_week_with_leave_round_trips_without_changes_or_issues(): void
    {
        $evening = $this->momoyoEvening();
        $create = fn (array $attributes) => ShiftSchedule::create($attributes);
        // The manager: Monday two schedules at JChicken (one with a "+" section), Tuesday a day off, Friday on leave.
        $create(['employee_id' => $this->manager->id, 'date' => '2026-11-02', 'sequence' => 1, 'shift_id' => $this->jchickenMorning->id, 'company_location_id' => $this->jchickenOutlet->id, 'section' => 'Kasir+Dapur']);
        $create(['employee_id' => $this->manager->id, 'date' => '2026-11-02', 'sequence' => 2, 'shift_id' => $evening->id, 'company_location_id' => $this->jchickenOutlet->id]);
        $create(['employee_id' => $this->manager->id, 'date' => '2026-11-03', 'is_day_off' => true]);
        $create(['employee_id' => $this->manager->id, 'date' => '2026-11-06', 'shift_id' => $this->jchickenMorning->id, 'company_location_id' => $this->jchickenOutlet->id]);
        // Bayu: Monday at two outlets, Tuesday three schedules over two sheets, Wednesday a placement row
        // beside a shift, Thursday a schedule without an outlet.
        $create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-02', 'sequence' => 1, 'shift_id' => $this->jchickenMorning->id, 'company_location_id' => $this->jchickenOutlet->id]);
        $create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-02', 'sequence' => 2, 'shift_id' => $evening->id, 'company_location_id' => $this->momoyoOutlet->id, 'section' => 'A+B']);
        $early = Shift::create(['company_id' => $this->company->id, 'name' => 'Subuh', 'code' => 'SB', 'start_time' => '04:00', 'end_time' => '07:00']);
        $create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-03', 'sequence' => 1, 'shift_id' => $early->id, 'company_location_id' => $this->momoyoOutlet->id]);
        $create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-03', 'sequence' => 2, 'shift_id' => $this->momoyoMorning->id, 'company_location_id' => $this->momoyoOutlet->id]);
        $create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-03', 'sequence' => 3, 'shift_id' => $evening->id, 'company_location_id' => $this->jchickenOutlet->id]);
        $create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-04', 'sequence' => 1, 'company_location_id' => $this->momoyoOutlet->id]);
        $create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-04', 'sequence' => 2, 'shift_id' => $this->momoyoMorning->id, 'company_location_id' => $this->momoyoOutlet->id]);
        $create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-05', 'shift_id' => $this->afternoon->id]);
        LeaveRequest::create(['employee_id' => $this->manager->id, 'leave_type' => LeaveRequest::TYPE_ANNUAL, 'start_date' => '2026-11-06', 'end_date' => '2026-11-06', 'days_count' => 1, 'charged_dates' => ['2026-11-06'], 'reason' => 'Keluarga', 'status' => 'approved']);
        $before = ShiftSchedule::query()->orderBy('id')->get()->map(fn (ShiftSchedule $row): array => $row->only(['id', 'sequence', 'shift_id', 'company_location_id', 'is_day_off', 'section']))->all();

        $result = app(ShiftScheduleSpreadsheet::class)->import($this->admin, $this->company, $this->export());

        $this->assertSame(['created' => 0, 'updated' => 0, 'deleted' => 0, 'unchanged' => 11, 'issues' => [], 'issue_count' => 0], $result);
        $this->assertSame($before, ShiftSchedule::query()->orderBy('id')->get()->map(fn (ShiftSchedule $row): array => $row->only(['id', 'sequence', 'shift_id', 'company_location_id', 'is_day_off', 'section']))->all());
    }

    public function test_ambiguous_code_outside_a_brand_outlet_asks_for_the_brand(): void
    {
        $path = $this->writeWorkbook(['Tanpa Outlet' => [['ID Pekerja', 'Nama', 'Jenis', '2026-11-02', '2026-11-03'], ['FL-01', 'Bayu', '', 'P', 'MOMOYO | P']]]);

        $result = app(ShiftScheduleSpreadsheet::class)->import($this->admin, $this->company, $path);

        $this->assertStringContainsString('cocok dengan beberapa shift (JCHICKEN · Pagi, MOMOYO · Pagi)', $result['issues'][0]);
        $this->assertSame([[$this->momoyoMorning->id, null, false, null]], $this->scheduleRows('freelancer_id', $this->bayu->id));
    }

    public function test_import_ignores_workers_outside_the_admin_data_access(): void
    {
        $other = Company::create(['name' => 'PT Lain', 'code' => 'LN', 'is_active' => true]);
        $outsider = Freelancer::create(['company_id' => $other->id, 'division_id' => Division::create(['company_id' => $other->id, 'name' => 'X', 'is_active' => true])->id, 'freelancer_number' => 'FL-99', 'full_name' => 'Orang Luar', 'status' => 'active']);
        $path = $this->writeWorkbook(['Tanpa Outlet' => [['ID Pekerja', 'Nama', 'Jenis', '2026-11-02'], ['FL-99', 'Orang Luar', '', 'Siang']]]);

        $result = app(ShiftScheduleSpreadsheet::class)->import($this->admin, $this->company, $path);

        $this->assertSame(1, $result['issue_count']);
        $this->assertFalse(ShiftSchedule::where('freelancer_id', $outsider->id)->exists());
    }

    public function test_panel_actions_download_and_upload_the_grid(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ManageShiftSchedules::class)
            ->callAction('exportSchedule', data: [
                'company_id' => $this->company->id,
                'date_from' => '2026-11-02',
                'date_until' => '2026-11-08',
                'include_unscheduled' => true,
            ])
            ->assertHasNoFormErrors()
            ->assertFileDownloaded('jadwal-shift-pfc-20261102-sd-20261108.xlsx');

        $path = $this->writeWorkbook(['Momoyo Ciledug' => [['ID Pekerja', 'Nama', 'Jenis', 'Sen 02-11-2026'], ['FL-01', 'Bayu', 'Freelancer', 'HK']]]);

        Livewire::test(ManageShiftSchedules::class)
            ->callAction('importSchedule', data: [
                'company_id' => $this->company->id,
                'file' => UploadedFile::fake()->createWithContent('jadwal.xlsx', file_get_contents($path)),
            ])
            ->assertHasNoFormErrors()
            ->assertNotified('Jadwal diimpor');

        $this->assertSame([[$this->housekeeping->id, $this->momoyoOutlet->id, false, null]], $this->scheduleRows('freelancer_id', $this->bayu->id));
    }

    /**
     * A Momoyo evening shift that does not overlap any morning shift of the fixture.
     */
    private function momoyoEvening(): Shift
    {
        return Shift::create(['company_id' => $this->company->id, 'division_id' => $this->momoyoOutlet->division_id, 'name' => 'Malam', 'code' => 'M', 'start_time' => '18:00', 'end_time' => '23:00']);
    }

    private function export(bool $includeUnscheduled = false): string
    {
        return $this->files[] = app(ShiftScheduleSpreadsheet::class)->export($this->admin, $this->company, $this->monday, $this->monday->copy()->addDays(6), $includeUnscheduled);
    }

    /**
     * @param  array<string, list<list<string>>>  $sheets
     */
    private function writeWorkbook(array $sheets): string
    {
        $path = $this->files[] = tempnam(sys_get_temp_dir(), 'grid').'.xlsx';
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

    /**
     * @return array<string, list<list<string>>>
     */
    private function readWorkbook(string $path): array
    {
        $reader = new XlsxReader;
        $reader->open($path);
        $sheets = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $sheets[$sheet->getName()][] = array_map(fn (mixed $value): string => (string) $value, $row->toArray());
            }
        }

        $reader->close();

        return $sheets;
    }

    /**
     * @return list<array{0: int|null, 1: int|null, 2: bool, 3: string|null}>
     */
    private function scheduleRows(string $column, int $workerId): array
    {
        return ShiftSchedule::where($column, $workerId)
            ->orderBy('date')
            ->get()
            ->map(fn (ShiftSchedule $schedule): array => [$schedule->shift_id, $schedule->company_location_id, $schedule->is_day_off, $schedule->section])
            ->all();
    }
}
