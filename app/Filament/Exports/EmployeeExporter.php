<?php

namespace App\Filament\Exports;

use App\Models\Employee;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

/**
 * Column labels match EmployeeImporter, so an exported file can be edited and imported back.
 */
class EmployeeExporter extends Exporter
{
    protected static ?string $model = Employee::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('nip')->label('NIP'),
            ExportColumn::make('full_name')->label('Nama Lengkap'),
            ExportColumn::make('company.name')->label('Badan Usaha'),
            ExportColumn::make('division')->label('Divisi')->state(fn (Employee $record): ?string => $record->division?->pathName()),
            ExportColumn::make('jobLevel.name')->label('Level Jabatan'),
            ExportColumn::make('jobTitle.name')->label('Posisi'),
            ExportColumn::make('shift')->label('Shift')->state(fn (Employee $record): ?string => $record->shift?->qualifiedName()),
            ExportColumn::make('email')->label('Email'),
            ExportColumn::make('phone')->label('Telepon'),
            ExportColumn::make('gender')->label('Jenis Kelamin')->formatStateUsing(fn (?string $state): ?string => match ($state) {
                'Male' => 'Laki-laki',
                'Female' => 'Perempuan',
                default => $state,
            }),
            ExportColumn::make('birth_date')->label('Tanggal Lahir')->formatStateUsing(fn ($state): ?string => $state?->format('Y-m-d')),
            ExportColumn::make('marital_status')->label('Status Pernikahan'),
            ExportColumn::make('tax_status')->label('Status Pajak'),
            ExportColumn::make('join_date')->label('Tanggal Bergabung')->formatStateUsing(fn ($state): ?string => $state?->format('Y-m-d')),
            ExportColumn::make('status')->label('Status')->formatStateUsing(fn (?string $state): ?string => match ($state) {
                'active' => 'Aktif',
                'inactive' => 'Non-Aktif',
                'resigned' => 'Resign',
                default => $state,
            }),
            ExportColumn::make('talenta_id')->label('ID Talenta')->enabledByDefault(false),
            ExportColumn::make('user.email')->label('Akun Login')->enabledByDefault(false),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with(['company', 'division.parent.parent.parent', 'jobLevel', 'jobTitle', 'shift.division', 'user']);
    }

    public function getFileName(Export $export): string
    {
        return 'karyawan-'.now()->format('Ymd-His');
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Export karyawan selesai: '.Number::format($export->successful_rows).' baris.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' baris gagal diekspor.';
        }

        return $body;
    }
}
