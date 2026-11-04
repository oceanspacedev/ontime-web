<?php

namespace App\Filament\Resources\ApproverResource\Pages;

use App\Filament\Concerns\ShowsModelValidationErrorsOnForm;
use App\Filament\Resources\ApproverResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateApprover extends CreateRecord
{
    use ShowsModelValidationErrorsOnForm;

    protected static string $resource = ApproverResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // The division field is hidden (and therefore absent) for company-level scope.
        $data['division_id'] ??= null;

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        return $this->withFormErrorKeys(fn (): Model => parent::handleRecordCreation($data));
    }
}
