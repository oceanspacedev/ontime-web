<?php

namespace App\Filament\Resources\FreelanceResource\Pages;

use App\Filament\Concerns\HasWorkerImportExport;
use App\Filament\Exports\FreelancerExporter;
use App\Filament\Imports\FreelancerImporter;
use App\Filament\Resources\FreelanceResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListFreelancers extends ListRecords
{
    use HasWorkerImportExport;

    protected static string $resource = FreelanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->importExportActionGroup(FreelancerImporter::class, FreelancerExporter::class),
            Actions\CreateAction::make(),
        ];
    }
}
