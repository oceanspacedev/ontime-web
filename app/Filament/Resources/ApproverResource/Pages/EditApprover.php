<?php

namespace App\Filament\Resources\ApproverResource\Pages;

use App\Filament\Concerns\ShowsModelValidationErrorsOnForm;
use App\Filament\Resources\ApproverResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditApprover extends EditRecord
{
    use ShowsModelValidationErrorsOnForm;

    protected static string $resource = ApproverResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Switching to company-level scope hides the division field, so clear the old division.
        $data['division_id'] ??= null;

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->withFormErrorKeys(fn (): Model => parent::handleRecordUpdate($record, $data));
    }
}
