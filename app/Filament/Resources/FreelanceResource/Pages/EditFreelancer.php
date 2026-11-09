<?php

namespace App\Filament\Resources\FreelanceResource\Pages;

use App\Filament\Resources\FreelanceResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditFreelancer extends EditRecord
{
    protected static string $resource = FreelanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...FreelanceResource::contractActions(),
            Actions\DeleteAction::make(),
        ];
    }
}
