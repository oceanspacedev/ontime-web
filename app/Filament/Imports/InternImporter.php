<?php

namespace App\Filament\Imports;

use App\Filament\Imports\Concerns\ResolvesWorkerPlacement;
use App\Models\Intern;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Number;
use Illuminate\Validation\Rule;

/**
 * CSV / Excel import of peserta magang. Rows are matched on NIS: existing records are updated,
 * new ones are created. Column names match InternExporter.
 */
class InternImporter extends Importer
{
    use ResolvesWorkerPlacement;

    protected static ?string $model = Intern::class;

    /** @var array<string, string> */
    private const STATUSES = [
        'active' => 'active', 'aktif' => 'active',
        'completed' => 'completed', 'selesai' => 'completed', 'lulus' => 'completed',
        'extended' => 'extended', 'diperpanjang' => 'extended',
        'terminated' => 'terminated', 'berhenti' => 'terminated',
    ];

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('nis')
                ->label('NIS')
                ->requiredMapping()
                ->rules(['required', 'max:50'])
                ->guess(['id magang', 'nis / id magang'])
                ->example('2026.07.20.01'),

            ImportColumn::make('full_name')
                ->label('Nama Lengkap')
                ->requiredMapping()
                ->rules(['required', 'max:255'])
                ->guess(['nama', 'name', 'full name'])
                ->example('MUHAMMAD AFRIZA'),

            ImportColumn::make('institution')
                ->label('Institusi')
                ->requiredMapping()
                ->rules(['required', 'max:255'])
                ->guess(['asal sekolah', 'kampus', 'sekolah', 'institution'])
                ->ignoreBlankState()
                ->example('SMK Wikrama Bogor'),

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
                ->example('IT SUPPORT'),

            ImportColumn::make('mentor')
                ->label('Mentor (NIP)')
                ->guess(['mentor', 'pembimbing', 'nip mentor'])
                ->ignoreBlankState()
                ->fillRecordUsing(fn () => null)
                ->example('2009.07.01.01'),

            ImportColumn::make('shift')
                ->label('Shift')
                ->ignoreBlankState()
                ->fillRecordUsing(fn () => null)
                ->example('Pagi'),

            ImportColumn::make('email')
                ->label('Email')
                ->rules(['nullable', 'email', 'max:255'])
                ->ignoreBlankState()
                ->example('magang@example.com'),

            ImportColumn::make('phone')
                ->label('Telepon')
                ->rules(['nullable', 'max:50'])
                ->guess(['mobile_phone', 'no hp', 'hp'])
                ->ignoreBlankState()
                ->example('081234567890'),

            ImportColumn::make('start_date')
                ->label('Tanggal Mulai')
                ->castStateUsing(fn (mixed $state): mixed => static::castDate($state))
                ->rules(['nullable', 'date'])
                ->ignoreBlankState()
                ->example('2026-07-20'),

            ImportColumn::make('end_date')
                ->label('Tanggal Selesai')
                ->castStateUsing(fn (mixed $state): mixed => static::castDate($state))
                ->rules(['nullable', 'date'])
                ->ignoreBlankState()
                ->example('2026-10-20'),

            ImportColumn::make('status')
                ->label('Status')
                ->castStateUsing(fn (mixed $state): mixed => static::castChoice($state, self::STATUSES))
                ->rules(['nullable', Rule::in(array_unique(self::STATUSES))])
                ->ignoreBlankState()
                ->example('Aktif'),
        ];
    }

    public function resolveRecord(): Intern
    {
        $record = Intern::query()->firstOrNew(['nis' => trim((string) $this->data['nis'])]);
        $this->guardExistingRecord($record);

        return $record;
    }

    protected function afterFill(): void
    {
        $this->placeInCompany($this->record);

        if (filled($this->data['mentor'] ?? null)) {
            $this->record->mentor_id = $this->employeeIdFromNip($this->data['mentor'], 'Mentor (NIP)');
        }

        $this->record->status ??= 'active';
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Import peserta magang selesai: '.Number::format($import->successful_rows).' baris berhasil.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' baris gagal, unduh file baris gagal untuk melihat alasannya.';
        }

        return $body;
    }
}
