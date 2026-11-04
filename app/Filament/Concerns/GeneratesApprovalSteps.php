<?php

namespace App\Filament\Concerns;

use App\Services\ApprovalFlowService;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Validation\ValidationException;

trait GeneratesApprovalSteps
{
    /**
     * Generate the approval steps for the record that was just created.
     *
     * When no approval flow is configured the whole creation is rolled back and the
     * reason is shown to the user, so a request is never stored without steps.
     *
     * @throws Halt
     */
    protected function generateApprovalStepsOrHalt(string $requestType): void
    {
        try {
            app(ApprovalFlowService::class)->generateSteps($this->getRecord(), $requestType);
        } catch (ValidationException $exception) {
            Notification::make()
                ->title('Pengajuan belum dapat dikirim')
                ->body(collect($exception->errors())->flatten()->first())
                ->danger()
                ->persistent()
                ->send();

            throw (new Halt)->rollBackDatabaseTransaction();
        }
    }
}
