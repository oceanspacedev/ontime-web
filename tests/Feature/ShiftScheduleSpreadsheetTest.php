<?php

namespace Tests\Feature;

use App\Filament\Resources\ShiftSchedules\Pages\ManageShiftSchedules;
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

    public function test_the_same_worker_and_date_in_two_sheets_keeps_the_first(): void
    {
        $header = ['ID Pekerja', 'Nama', 'Jenis', '2026-11-02'];
        $path = $this->writeWorkbook([
            'JChicken Ciledug' => [$header, ['FL-01', 'Bayu', '', 'P']],
            'Momoyo Ciledug' => [$header, ['FL-01', 'Bayu', '', 'HK']],
        ]);

        $result = app(ShiftScheduleSpreadsheet::class)->import($this->admin, $this->company, $path);

        $this->assertSame($this->jchickenMorning->id, ShiftSchedule::where('freelancer_id', $this->bayu->id)->value('shift_id'));
        $this->assertStringContainsString('sudah dijadwalkan di sheet "JChicken Ciledug"', $result['issues'][0]);
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
