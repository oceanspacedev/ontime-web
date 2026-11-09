<?php

namespace Tests\Feature;

use App\Filament\Imports\EmployeeImporter;
use App\Filament\Imports\InternImporter;
use App\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Intern;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\Shift;
use App\Models\User;
use Filament\Actions\Exports\Models\Export;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader as CsvReader;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SpreadsheetImportExportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = ['NIP', 'Nama Lengkap', 'Badan Usaha', 'Divisi', 'Level Jabatan', 'Posisi', 'Email', 'Telepon', 'Jenis Kelamin', 'Tanggal Lahir', 'Status'];

    private User $superadmin;

    private Company $top;

    private Division $depo;

    private Employee $existing;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        foreach (['Superadmin', 'Admin', 'Employee'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->superadmin = User::factory()->create();
        $this->superadmin->assignRole('Superadmin');
        $this->actingAs($this->superadmin);

        $this->top = Company::create(['name' => 'CV Top Selular', 'code' => 'TOP', 'is_active' => true]);
        $root = Division::create(['company_id' => $this->top->id, 'name' => 'DISTRIBUTION SALES', 'is_active' => true]);
        $this->depo = Division::create(['company_id' => $this->top->id, 'parent_id' => $root->id, 'name' => 'DEPO BANDUNG', 'is_active' => true]);
        JobLevel::create(['name' => 'STAFF', 'level_order' => 1]);

        $this->existing = Employee::create([
            'company_id' => $this->top->id,
            'division_id' => $this->depo->id,
            'job_level_id' => JobLevel::first()->id,
            'job_title_id' => JobTitle::create(['name' => 'SALES', 'division_id' => $this->depo->id])->id,
            'nip' => '2020.01.01.01',
            'full_name' => 'BUDI LAMA',
            'join_date' => '2020-01-01',
            'status' => 'active',
        ]);
    }

    public function test_employees_import_from_a_csv_file(): void
    {
        $csv = implode("\n", [
            implode(',', self::HEADER),
            '2020.01.01.01,BUDI SANTOSO,TOP,DISTRIBUTION SALES > DEPO BANDUNG,STAFF,SALES,budi@example.com,081234567890,Laki-laki,28/02/1991,Aktif',
            '2026.01.01.01,SITI BARU,CV Top Selular,DEPO BANDUNG,staff,KASIR,siti@example.com,089876543210,P,2000-01-01,',
        ]);

        Livewire::test(ListEmployees::class)
            ->callAction('importSpreadsheet', data: ['file' => UploadedFile::fake()->createWithContent('karyawan.csv', $csv)])
            ->assertHasNoFormErrors();

        $existing = $this->existing->fresh();
        $this->assertSame('BUDI SANTOSO', $existing->full_name);
        $this->assertSame('Male', $existing->gender);
        $this->assertSame('1991-02-28', $existing->birth_date->toDateString());
        $this->assertSame('2020-01-01', $existing->join_date->toDateString(), 'Columns that are not in the file stay as they were.');

        $newcomer = Employee::where('nip', '2026.01.01.01')->firstOrFail();
        $this->assertSame([$this->depo->id, 'Female', 'active', 'KASIR'], [$newcomer->division_id, $newcomer->gender, $newcomer->status, $newcomer->jobTitle->name]);
        $this->assertSame(2, Import::firstOrFail()->successful_rows);
    }

    public function test_employees_import_from_an_excel_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(self::HEADER));
        $writer->addRow(Row::fromValues(['2026.02.02.02', 'EXCEL BARU', 'TOP', 'DEPO BANDUNG', 'STAFF', 'SALES', 'excel@example.com', 81234567890, 'Laki-laki', new \DateTimeImmutable('1995-05-17'), 'Aktif']));
        $writer->close();

        Livewire::test(ListEmployees::class)
            ->callAction('importSpreadsheet', data: ['file' => UploadedFile::fake()->createWithContent('karyawan.xlsx', (string) file_get_contents($path))])
            ->assertHasNoFormErrors();

        @unlink($path);

        $employee = Employee::where('nip', '2026.02.02.02')->firstOrFail();
        $this->assertSame('EXCEL BARU', $employee->full_name);
        $this->assertSame('1995-05-17', $employee->birth_date->toDateString());
        $this->assertSame('6281234567890', $employee->phone, 'Numeric Excel cells are read without a trailing ".0".');
    }

    public function test_ambiguous_division_names_must_be_written_as_a_path(): void
    {
        Division::create(['company_id' => $this->top->id, 'name' => 'DEPO BANDUNG', 'is_active' => true]);

        $this->expectException(RowImportFailedException::class);
        $this->expectExceptionMessage('ada di beberapa tempat');

        $this->runEmployeeRow(['2026.03.03.03', 'AMBIGU', 'TOP', 'DEPO BANDUNG', 'STAFF', 'SALES', '', '', '', '', '']);
    }

    public function test_brand_shift_names_resolve_to_the_worker_brand_or_the_written_brand(): void
    {
        $luuca = Division::create(['company_id' => $this->top->id, 'name' => 'LUUCA', 'is_active' => true]);
        $salesMorning = Shift::create(['company_id' => $this->top->id, 'division_id' => $this->depo->parent_id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '17:00']);
        $luucaMorning = Shift::create(['company_id' => $this->top->id, 'division_id' => $luuca->id, 'name' => 'Pagi', 'start_time' => '09:00', 'end_time' => '18:00']);

        $this->runEmployeeRow(['2026.04.04.04', 'SHIFT BRAND SENDIRI', 'TOP', 'DEPO BANDUNG', 'STAFF', 'SALES', '', '', '', '', ''], shift: 'Pagi');
        $this->runEmployeeRow(['2026.05.05.05', 'SHIFT BRAND LAIN', 'TOP', 'DEPO BANDUNG', 'STAFF', 'SALES', '', '', '', '', ''], shift: 'luuca · pagi');
        $this->runEmployeeRow(['2026.06.06.06', 'SHIFT PIPA', 'TOP', 'DEPO BANDUNG', 'STAFF', 'SALES', '', '', '', '', ''], shift: 'LUUCA | Pagi');

        $this->assertSame($salesMorning->id, Employee::where('nip', '2026.04.04.04')->value('shift_id'), 'The brand above the worker division wins.');
        $this->assertSame($luucaMorning->id, Employee::where('nip', '2026.05.05.05')->value('shift_id'));
        $this->assertSame($luucaMorning->id, Employee::where('nip', '2026.06.06.06')->value('shift_id'));
        $this->assertSame('LUUCA · Pagi', $luucaMorning->qualifiedName());
    }

    public function test_shift_name_used_by_other_brands_only_must_name_the_brand(): void
    {
        foreach (['JCHICKEN', 'MOMOYO'] as $brand) {
            $division = Division::create(['company_id' => $this->top->id, 'name' => $brand, 'is_active' => true]);
            Shift::create(['company_id' => $this->top->id, 'division_id' => $division->id, 'name' => 'Siang', 'start_time' => '13:00', 'end_time' => '22:00']);
        }

        $this->expectException(RowImportFailedException::class);
        $this->expectExceptionMessage('ada di beberapa brand');

        $this->runEmployeeRow(['2026.07.07.07', 'AMBIGU', 'TOP', 'DEPO BANDUNG', 'STAFF', 'SALES', '', '', '', '', ''], shift: 'Siang');
    }

    public function test_company_admin_cannot_import_into_other_companies(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        Company::create(['name' => 'CV Lain', 'code' => 'LAIN', 'is_active' => true]);
        $this->grantDataAccessTo($admin, Company::where('code', 'LAIN')->value('id'));

        $this->expectException(RowImportFailedException::class);
        $this->expectExceptionMessage('di luar akses Anda');

        $this->runEmployeeRow(['2020.01.01.01', 'BUDI', 'TOP', 'DEPO BANDUNG', 'STAFF', 'SALES', '', '', '', '', ''], $admin);
    }

    public function test_unknown_job_level_fails_the_row_with_a_reason(): void
    {
        $this->expectException(RowImportFailedException::class);
        $this->expectExceptionMessage('Level jabatan "DIREKTUR" tidak ditemukan');

        $this->runEmployeeRow(['2026.04.04.04', 'LEVEL SALAH', 'TOP', 'DEPO BANDUNG', 'DIREKTUR', 'SALES', '', '', '', '', '']);
    }

    public function test_interns_import_with_mentor_nip(): void
    {
        $import = Import::create(['file_name' => 'magang.csv', 'file_path' => 'magang.csv', 'importer' => InternImporter::class, 'total_rows' => 1, 'user_id' => $this->superadmin->id]);
        $columns = ['nis' => 'NIS', 'full_name' => 'Nama Lengkap', 'institution' => 'Institusi', 'company' => 'Badan Usaha', 'division' => 'Divisi', 'mentor' => 'Mentor (NIP)', 'status' => 'Status'];

        ($import->getImporter($columns, []))(['NIS' => 'MG-001', 'Nama Lengkap' => 'MAGANG BARU', 'Institusi' => 'SMK Test', 'Badan Usaha' => 'TOP', 'Divisi' => 'DEPO BANDUNG', 'Mentor (NIP)' => '2020.01.01.01', 'Status' => 'Aktif']);

        $intern = Intern::where('nis', 'MG-001')->firstOrFail();
        $this->assertSame([$this->existing->id, $this->depo->id, 'active'], [$intern->mentor_id, $intern->division_id, $intern->status]);
    }

    public function test_employee_export_writes_columns_that_import_back_unchanged(): void
    {
        Livewire::test(ListEmployees::class)->callAction('exportSpreadsheet');

        $export = Export::firstOrFail();
        $disk = $export->getFileDisk();
        $directory = $export->getFileDirectory();

        // Filament stores the CSV as a header file plus data chunks and joins them on download.
        $csv = (string) $disk->get($directory.DIRECTORY_SEPARATOR.'headers.csv');
        foreach (collect($disk->files($directory))->filter(fn (string $file): bool => preg_match('/\d+\.csv$/', $file) === 1)->sort() as $chunk) {
            $csv .= $disk->get($chunk);
        }

        $this->assertSame(1, $export->successful_rows);
        $this->assertStringContainsString('NIP,"Nama Lengkap","Badan Usaha",Divisi', str_replace("\u{FEFF}", '', $csv));
        $this->assertStringContainsString('DISTRIBUTION SALES > DEPO BANDUNG', $csv);
        $this->assertTrue($export->getFileDisk()->exists($export->getFileDirectory().DIRECTORY_SEPARATOR.$export->file_name.'.xlsx'));

        // Import the exported rows back, mapping columns by label like Filament's automatic mapping.
        $reader = CsvReader::fromString(str_replace("\u{FEFF}", '', $csv));
        $reader->setHeaderOffset(0);
        $columnMap = collect(EmployeeImporter::getColumns())
            ->mapWithKeys(fn ($column): array => [$column->getName() => $column->getLabel()])
            ->filter(fn (string $label): bool => in_array($label, $reader->getHeader(), true))
            ->all();

        $import = Import::create(['file_name' => 'export.csv', 'file_path' => 'export.csv', 'importer' => EmployeeImporter::class, 'total_rows' => 1, 'user_id' => $this->superadmin->id]);

        foreach ($reader->getRecords() as $row) {
            ($import->getImporter($columnMap, []))($row);
        }

        $this->assertArrayHasKey('division', $columnMap);
        $this->assertSame($this->depo->id, $this->existing->fresh()->division_id);
        $this->assertSame('BUDI LAMA', $this->existing->fresh()->full_name);
        $this->assertSame(1, Employee::count());
    }

    /**
     * @param  list<string>  $values  in the order of self::HEADER
     */
    private function runEmployeeRow(array $values, ?User $user = null, ?string $shift = null): void
    {
        $import = Import::create(['file_name' => 'karyawan.csv', 'file_path' => 'karyawan.csv', 'importer' => EmployeeImporter::class, 'total_rows' => 1, 'user_id' => ($user ?? $this->superadmin)->id]);
        $columns = array_combine(['nip', 'full_name', 'company', 'division', 'job_level', 'job_title', 'email', 'phone', 'gender', 'birth_date', 'status'], self::HEADER);
        $row = array_combine(self::HEADER, $values);

        if ($shift !== null) {
            $columns['shift'] = 'Shift';
            $row['Shift'] = $shift;
        }

        ($import->getImporter($columns, []))($row);
    }
}
