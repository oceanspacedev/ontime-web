<?php

namespace App\Filament\Resources\FreelanceResource\Pages;

use App\Filament\Resources\FreelanceResource;
use App\Services\WorkerAccountService;
use Filament\Resources\Pages\EditRecord;

class EditFreelancer extends EditRecord
{
    protected static string $resource = FreelanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...FreelanceResource::contractActions(),
            FreelanceResource::workerDeleteAction(),
        ];
    }

    /**
     * Ending the contract here (an ended status, or an end date already past) switches the account off and
     * signs it out like the end-contract action; taking the worker back on switches it on again.
     */
    protected function afterSave(): void
    {
        if (! $this->record->wasChanged(['status', 'end_date'])) {
            return;
        }

        app(WorkerAccountService::class)->syncContractStatus($this->record, $this->record->getPrevious()['status'] ?? $this->record->status);
    }
}
