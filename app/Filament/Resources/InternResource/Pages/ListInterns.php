<?php

namespace App\Filament\Resources\InternResource\Pages;

use App\Filament\Concerns\HasWorkerImportExport;
use App\Filament\Exports\InternExporter;
use App\Filament\Imports\InternImporter;
use App\Filament\Resources\InternResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListInterns extends ListRecords
{
    use HasWorkerImportExport;

    protected static string $resource = InternResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->importExportActionGroup(InternImporter::class, InternExporter::class),
            Actions\CreateAction::make(),
        ];
    }
}
