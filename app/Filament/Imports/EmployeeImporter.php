<?php

namespace App\Filament\Imports;

use App\Filament\Imports\Concerns\ResolvesWorkerPlacement;
use App\Models\Employee;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Number;
use Illuminate\Validation\Rule;

/**
 * CSV / Excel import of employees. Rows are matched on NIP: existing employees are updated,
 * new NIPs are created. Column names match EmployeeExporter, so an export can be edited and imported back.
 */
class EmployeeImporter extends Importer
{
    use ResolvesWorkerPlacement;

    protected static ?string $model = Employee::class;

    /** @var array<string, string> */
    private const STATUSES = [
        'active' => 'active', 'aktif' => 'active',
        'inactive' => 'inactive', 'non-aktif' => 'inactive', 'nonaktif' => 'inactive', 'non aktif' => 'inactive',
        'resigned' => 'resigned', 'resign' => 'resigned', 'keluar' => 'resigned',
    ];

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('nip')
                ->label('NIP')
                ->requiredMapping()
                ->rules(['required', 'max:50'])
                ->guess(['id_employee', 'id karyawan', 'nomor induk'])
                ->example('2024.08.15.03'),

            ImportColumn::make('full_name')
                ->label('Nama Lengkap')
                ->requiredMapping()
                ->rules(['required', 'max:255'])
                ->guess(['nama', 'name', 'full name'])
                ->example('BUDI SANTOSO'),

            ImportColumn::make('company')
                ->label('Badan Usaha')
                ->requiredMapping()
                ->rules(['required'])
                ->guess(['perusahaan', 'company', 'branch'])
                ->fillRecordUsing(fn () => null)
                ->example('CV Top Selular'),

            ImportColumn::make('division')
                ->label('Divisi')
                ->requiredMapping()
                ->rules(['required'])
                ->guess(['organization', 'organisasi'])
                ->fillRecordUsing(fn () => null)
                ->example('DISTRIBUTION SALES > DEPO BANDUNG'),

            ImportColumn::make('job_level')
                ->label('Level Jabatan')
                ->requiredMapping()
                ->rules(['required'])
                ->guess(['level', 'title', 'jabatan'])
                ->fillRecordUsing(fn () => null)
                ->example('STAFF'),

            ImportColumn::make('job_title')
                ->label('Posisi')
                ->requiredMapping()
                ->rules(['required', 'max:255'])
                ->guess(['job', 'job title', 'posisi jabatan'])
                ->fillRecordUsing(fn () => null)
                ->example('SALES'),

            ImportColumn::make('shift')
                ->label('Shift')
                ->ignoreBlankState()
                ->fillRecordUsing(fn () => null)
                ->example('Pagi'),

            ImportColumn::make('email')
                ->label('Email')
                ->rules(['nullable', 'email', 'max:255'])
                ->ignoreBlankState()
                ->example('budi@example.com'),

            ImportColumn::make('phone')
                ->label('Telepon')
                ->rules(['nullable', 'max:50'])
                ->guess(['mobile_phone', 'no hp', 'hp'])
                ->ignoreBlankState()
                ->example('081234567890'),

            ImportColumn::make('gender')
                ->label('Jenis Kelamin')
                ->castStateUsing(fn (mixed $state): mixed => static::castGender($state))
                ->rules(['nullable', Rule::in(['Male', 'Female'])])
                ->ignoreBlankState()
                ->example('Laki-laki'),

            ImportColumn::make('birth_date')
                ->label('Tanggal Lahir')
                ->castStateUsing(fn (mixed $state): mixed => static::castDate($state))
                ->rules(['nullable', 'date'])
                ->ignoreBlankState()
                ->example('1991-02-28'),

            ImportColumn::make('marital_status')
                ->label('Status Pernikahan')
                ->rules(['nullable', 'max:50'])
                ->ignoreBlankState()
                ->example('Married'),

            ImportColumn::make('tax_status')
                ->label('Status Pajak')
                ->rules(['nullable', 'max:20'])
                ->ignoreBlankState()
                ->example('K/1'),

            ImportColumn::make('join_date')
                ->label('Tanggal Bergabung')
                ->castStateUsing(fn (mixed $state): mixed => static::castDate($state))
                ->rules(['nullable', 'date'])
                ->ignoreBlankState()
                ->example('2024-08-15'),

            ImportColumn::make('status')
                ->label('Status')
                ->castStateUsing(fn (mixed $state): mixed => static::castChoice($state, self::STATUSES))
                ->rules(['nullable', Rule::in(array_unique(self::STATUSES))])
                ->ignoreBlankState()
                ->example('Aktif'),
        ];
    }

    public function resolveRecord(): Employee
    {
        $employee = Employee::query()->firstOrNew(['nip' => trim((string) $this->data['nip'])]);
        $this->guardExistingRecord($employee);

        return $employee;
    }

    protected function afterFill(): void
    {
        /** @var Employee $employee */
        $employee = $this->record;
        $division = $this->placeInCompany($employee);

        $employee->job_level_id = $this->jobLevelIdFromState($this->data['job_level'] ?? null);
        $employee->job_title_id = $this->jobTitleIdFor($division, $this->data['job_title'] ?? null);
        $employee->status ??= 'active';
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Import karyawan selesai: '.Number::format($import->successful_rows).' baris berhasil.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' baris gagal, unduh file baris gagal untuk melihat alasannya.';
        }

        return $body;
    }
}
