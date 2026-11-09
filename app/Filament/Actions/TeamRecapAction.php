<?php

namespace App\Filament\Actions;

use App\Services\TeamRecapService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Downloads the monthly team recap (one row per person per outlet) as Excel.
 */
class TeamRecapAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'teamRecap';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Rekap Bulanan')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->visible(fn (): bool => (bool) auth()->user()?->canManageWorkers())
            ->modalHeading('Rekap Bulanan Tim')
            ->modalDescription('Per orang per outlet: hari masuk, tidak hadir, libur, terlambat, dan jam kerja.')
            ->modalSubmitActionLabel('Unduh Excel')
            ->modalWidth('md')
            ->schema([
                Select::make('month')
                    ->label('Bulan')
                    ->options(fn (): array => TeamRecapService::monthOptions())
                    ->default(today()->format('Y-m'))
                    ->required(),
            ])
            ->action(function (array $data): BinaryFileResponse {
                $service = app(TeamRecapService::class);
                $month = Carbon::createFromFormat('Y-m', $data['month'])->startOfMonth();

                return response()->download($service->export(auth()->user(), $month), $service->fileName($month))->deleteFileAfterSend();
            });
    }
}
