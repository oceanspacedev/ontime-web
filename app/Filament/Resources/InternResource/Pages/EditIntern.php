<?php

namespace App\Filament\Resources\InternResource\Pages;

use App\Filament\Resources\InternResource;
use App\Services\WorkerAccountService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use InvalidArgumentException;

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
                ->modalDescription('Akun user akan dibuat otomatis menggunakan data nama dan email/NIS magang.')
                ->action(function (): void {
                    try {
                        $account = app(WorkerAccountService::class)->createAccount($this->record);
                    } catch (InvalidArgumentException $exception) {
                        Notification::make()->title('Gagal Membuat Akun')->body($exception->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()
                        ->title('Akun User Berhasil Dibuat')
                        ->body("Email: {$account['email']} | Password: {$account['password']}")
                        ->success()
                        ->persistent()
                        ->send();
                }),
            Actions\DeleteAction::make(),
        ];
    }
}
