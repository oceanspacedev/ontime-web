<?php

namespace App\Filament\Imports;

use App\Filament\Imports\Concerns\ResolvesWorkerPlacement;
use App\Models\Freelancer;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Number;
use Illuminate\Validation\Rule;

/**
 * CSV / Excel import of freelancer. Rows are matched on Nomor Freelance: existing records are updated,
 * new ones are created. Column names match FreelancerExporter.
 */
class FreelancerImporter extends Importer
{
    use ResolvesWorkerPlacement;

    protected static ?string $model = Freelancer::class;

    /** @var array<string, string> */
    private const STATUSES = [
        'active' => 'active', 'aktif' => 'active',
        'completed' => 'completed', 'selesai' => 'completed', 'selesai kontrak' => 'completed',
        'extended' => 'extended', 'diperpanjang' => 'extended',
        'terminated' => 'terminated', 'berhenti' => 'terminated',
    ];

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('freelancer_number')
                ->label('Nomor Freelance')
                ->requiredMapping()
                ->rules(['required', 'max:50'])
                ->guess(['id freelance', 'nomor freelancer', 'freelancer number'])
                ->example('FL-2026-001'),

            ImportColumn::make('full_name')
                ->label('Nama Lengkap')
                ->requiredMapping()
                ->rules(['required', 'max:255'])
                ->guess(['nama', 'name', 'full name'])
                ->example('SITI FREELANCE'),

            ImportColumn::make('institution')
                ->label('Institusi')
                ->rules(['nullable', 'max:255'])
                ->guess(['asal sekolah', 'kampus', 'sekolah', 'institution'])
                ->ignoreBlankState()
                ->example('Freelance Professional'),

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

            ImportColumn::make('supervisor')
                ->label('PIC (NIP)')
                ->guess(['pic', 'supervisor', 'penanggung jawab', 'nip pic'])
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
                ->example('freelance@example.com'),

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

    public function resolveRecord(): Freelancer
    {
        $record = Freelancer::query()->firstOrNew(['freelancer_number' => trim((string) $this->data['freelancer_number'])]);
        $this->guardExistingRecord($record);

        return $record;
    }

    protected function afterFill(): void
    {
        $this->placeInCompany($this->record);

        if (filled($this->data['supervisor'] ?? null)) {
            $this->record->supervisor_id = $this->employeeIdFromNip($this->data['supervisor'], 'PIC (NIP)');
        }

        $this->record->status ??= 'active';
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Import freelancer selesai: '.Number::format($import->successful_rows).' baris berhasil.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' baris gagal, unduh file baris gagal untuk melihat alasannya.';
        }

        return $body;
    }
}
