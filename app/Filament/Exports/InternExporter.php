<?php

namespace App\Filament\Exports;

use App\Models\Intern;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

/**
 * Column labels match InternImporter, so an exported file can be edited and imported back.
 */
class InternExporter extends Exporter
{
    protected static ?string $model = Intern::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('nis')->label('NIS'),
            ExportColumn::make('full_name')->label('Nama Lengkap'),
            ExportColumn::make('institution')->label('Institusi'),
            ExportColumn::make('company.name')->label('Badan Usaha'),
            ExportColumn::make('division')->label('Divisi')->state(fn (Intern $record): ?string => $record->division?->pathName()),
            ExportColumn::make('mentor.nip')->label('Mentor (NIP)'),
            ExportColumn::make('mentor.full_name')->label('Nama Mentor'),
            ExportColumn::make('shift')->label('Shift')->state(fn (Intern $record): ?string => $record->shift?->qualifiedName()),
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
        return $query->with(['company', 'division.parent.parent.parent', 'mentor', 'shift.division', 'user']);
    }

    public function getFileName(Export $export): string
    {
        return 'magang-'.now()->format('Ymd-His');
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Export peserta magang selesai: '.Number::format($export->successful_rows).' baris.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' baris gagal diekspor.';
        }

        return $body;
    }
}
