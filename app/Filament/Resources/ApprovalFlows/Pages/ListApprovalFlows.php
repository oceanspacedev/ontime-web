<?php

namespace App\Filament\Resources\ApprovalFlows\Pages;

use App\Filament\Resources\ApprovalFlows\ApprovalFlowResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListApprovalFlows extends ListRecords
{
    protected static string $resource = ApprovalFlowResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Atur alur badan usaha')
                ->visible(fn (): bool => ApprovalFlowResource::companiesWithoutFlowOptions() !== []),
        ];
    }
}
