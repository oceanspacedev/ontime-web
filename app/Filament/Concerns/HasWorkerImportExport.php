<?php

namespace App\Filament\Concerns;

use App\Filament\Actions\SpreadsheetImportAction;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ExportAction;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Imports\Importer;

/**
 * One "Import / Export" button for worker lists: Filament import (CSV or Excel), Filament export
 * (CSV or Excel) and any extra import actions, such as Talenta for employees.
 */
trait HasWorkerImportExport
{
    /**
     * @param  class-string<Importer>  $importer
     * @param  class-string<Exporter>  $exporter
     * @param  list<Action>  $extraActions
     */
    protected function importExportActionGroup(string $importer, string $exporter, array $extraActions = []): ActionGroup
    {
        return ActionGroup::make([
            SpreadsheetImportAction::make('importSpreadsheet')
                ->label('Import CSV / Excel')
                ->modalHeading('Import '.static::getResource()::getPluralModelLabel())
                ->importer($importer),

            ExportAction::make('exportSpreadsheet')
                ->label('Export CSV / Excel')
                ->modalHeading('Export '.static::getResource()::getPluralModelLabel())
                ->exporter($exporter)
                ->formats([ExportFormat::Xlsx, ExportFormat::Csv])
                ->modalDescription('Mengekspor data sesuai filter dan pencarian tabel saat ini. File siap diunduh dari notifikasi setelah selesai diproses.'),

            ...$extraActions,
        ])
            ->label('Import / Export')
            ->icon('heroicon-m-arrows-up-down')
            ->color('gray')
            ->button()
            ->visible(fn (): bool => (bool) auth()->user()?->canManageCompanies());
    }
}
