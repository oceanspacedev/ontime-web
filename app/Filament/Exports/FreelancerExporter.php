<?php

namespace App\Filament\Exports;

use App\Models\Freelancer;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

/**
 * Column labels match FreelancerImporter, so an exported file can be edited and imported back.
 */
class FreelancerExporter extends Exporter
{
    protected static ?string $model = Freelancer::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('freelancer_number')->label('Nomor Freelance'),
            ExportColumn::make('full_name')->label('Nama Lengkap'),
            ExportColumn::make('institution')->label('Institusi'),
            ExportColumn::make('company.name')->label('Badan Usaha'),
            ExportColumn::make('division')->label('Divisi')->state(fn (Freelancer $record): ?string => $record->division?->pathName()),
            ExportColumn::make('supervisor.nip')->label('PIC (NIP)'),
            ExportColumn::make('supervisor.full_name')->label('Nama PIC'),
            ExportColumn::make('shift.name')->label('Shift'),
            ExportColumn::make('email')->label('Email'),
            ExportColumn::make('phone')->label('Telepon'),
            ExportColumn::make('start_date')->label('Tanggal Mulai')->formatStateUsing(fn ($state): ?string => $state?->format('Y-m-d')),
            ExportColumn::make('end_date')->label('Tanggal Selesai')->formatStateUsing(fn ($state): ?string => $state?->format('Y-m-d')),
            ExportColumn::make('status')->label('Status')->formatStateUsing(fn (?string $state): ?string => match ($state) {
                'active' => 'Aktif',
                'completed' => 'Selesai',
                'extended' => 'Diperpanjang',
                'terminated' => 'Berhenti',
                default => $state,
            }),
            ExportColumn::make('user.email')->label('Akun Login')->enabledByDefault(false),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with(['company', 'division.parent.parent.parent', 'supervisor', 'shift', 'user']);
    }

    public function getFileName(Export $export): string
    {
        return 'freelancer-'.now()->format('Ymd-His');
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Export freelancer selesai: '.Number::format($export->successful_rows).' baris.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' baris gagal diekspor.';
        }

        return $body;
    }
}
