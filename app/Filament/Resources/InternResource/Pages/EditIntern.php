<?php

namespace App\Filament\Resources\InternResource\Pages;

use App\Filament\Resources\InternResource;
use App\Services\WorkerAccountService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditIntern extends EditRecord
{
    protected static string $resource = InternResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('createUser')
                ->label('Buat Akun User')
                ->icon('heroicon-o-user-plus')
                ->color('success')
                ->visible(fn (): bool => ! $this->record->user_id)
                ->requiresConfirmation()
                ->modalHeading("Buat Akun User untuk {$this->record->full_name}")
                ->modalDescription('Kata sandi dibuat otomatis lalu dikirim ke WhatsApp bersama link unduh aplikasi.')
                ->action(fn () => InternResource::openAccount($this->record)),
            InternResource::workerDeleteAction(),
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
