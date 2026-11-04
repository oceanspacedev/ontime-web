<?php

namespace App\Filament\Resources\EmployeeResource\Pages;

use App\Filament\Resources\EmployeeResource;
use App\Services\TalentaEmployeeImportService;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Http\UploadedFile;

class ListEmployees extends ListRecords
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->importTalentaAction(),
            Actions\CreateAction::make(),
        ];
    }

    protected function importTalentaAction(): Action
    {
        return Action::make('importTalenta')
            ->label('Import Talenta (JSON)')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('gray')
            ->visible(fn (): bool => EmployeeResource::canImportFromTalenta())
            ->modalHeading('Import Karyawan dari Talenta')
            ->modalDescription('Unggah file JSON hasil export daftar karyawan Talenta. Karyawan dicocokkan lewat NIP (id_employee). Badan usaha, divisi, posisi, dan level yang belum ada akan dibuat otomatis.')
            ->modalSubmitActionLabel('Proses')
            ->modalWidth('lg')
            ->schema([
                FileUpload::make('file')
                    ->label('File JSON Talenta')
                    ->required()
                    ->acceptedFileTypes(['application/json', 'text/json', 'text/plain'])
                    ->maxSize(12 * 1024)
                    ->storeFiles(false),

                Toggle::make('dry_run')
                    ->label('Pratinjau saja, belum disimpan')
                    ->helperText('Aktifkan untuk melihat jumlah perubahan lebih dulu.')
                    ->default(true),

                Toggle::make('deactivate_missing')
                    ->label('Nonaktifkan karyawan aktif yang tidak ada di file')
                    ->helperText('Hanya untuk badan usaha yang ada di file. Biarkan mati bila file bukan daftar lengkap.')
                    ->default(false),
            ])
            ->action(function (array $data, TalentaEmployeeImportService $importService): void {
                $file = is_array($data['file']) ? ($data['file'][0] ?? null) : $data['file'];

                if (! $file instanceof UploadedFile || ! $file->isValid()) {
                    Notification::make()->title('File tidak valid')->body('Silakan unggah ulang file JSON.')->danger()->send();

                    return;
                }

                $result = $importService->importFromContent(
                    (string) $file->get(),
                    dryRun: (bool) ($data['dry_run'] ?? false),
                    deactivateMissing: (bool) ($data['deactivate_missing'] ?? false),
                    allowedCompanyIds: auth()->user()?->managedCompanyIds(),
                );

                $this->notifyImportResult($result);
            });
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function notifyImportResult(array $result): void
    {
        if (! $result['success']) {
            Notification::make()->title('Import gagal')->body($result['message'])->danger()->persistent()->send();

            return;
        }

        $lines = [
            "Baris di file: {$result['total']}",
            "Karyawan baru: {$result['created']}",
            "Diperbarui: {$result['updated']} (pindah badan usaha/divisi: {$result['moved']})",
            "Diaktifkan kembali: {$result['reactivated']}",
            "Dinonaktifkan: {$result['deactivated']}",
            "Dilewati: {$result['skipped']}",
            'Badan usaha baru: '.($result['new_companies'] === [] ? '-' : implode(', ', $result['new_companies'])),
            "Divisi baru: {$result['new_divisions']}, posisi baru: {$result['new_job_titles']}",
        ];

        if ($result['errors'] !== []) {
            $lines[] = 'Catatan: '.implode(' | ', array_slice($result['errors'], 0, 5)).(count($result['errors']) > 5 ? ' | dan '.(count($result['errors']) - 5).' lainnya' : '');
        }

        Notification::make()
            ->title($result['message'])
            ->body(implode('<br>', array_map('e', $lines)))
            ->{$result['errors'] === [] ? 'success' : 'warning'}()
            ->persistent()
            ->send();
    }
}
