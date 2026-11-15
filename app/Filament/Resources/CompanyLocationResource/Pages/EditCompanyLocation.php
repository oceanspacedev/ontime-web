<?php

namespace App\Filament\Resources\CompanyLocationResource\Pages;

use App\Filament\Resources\CompanyLocationResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCompanyLocation extends EditRecord
{
    protected static string $resource = CompanyLocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CompanyLocationResource::guardLastGpsLocation(DeleteAction::make()),
        ];
    }
}
