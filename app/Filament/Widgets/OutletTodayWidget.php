<?php

namespace App\Filament\Widgets;

use App\Services\OutletRosterService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Today's roster at the outlets a manager oversees: who is scheduled where and whether they checked in.
 */
class OutletTodayWidget extends TableWidget
{
    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    /** @var list<array<string, mixed>>|null */
    private ?array $roster = null;

    public static function canView(): bool
    {
        return (bool) auth()->user()?->canManageWorkers();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Hari Ini di Outlet Saya')
            ->description(fn (): string => $this->summary())
            ->records(fn (): array => $this->rows())
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('Belum ada jadwal hari ini')
            ->emptyStateIcon('heroicon-o-calendar-days')
            ->columns([
                TextColumn::make('outlet')
                    ->label('Outlet')
                    ->weight('medium'),

                TextColumn::make('name')
                    ->label('Pekerja')
                    ->description(fn (array $record): string => $record['number']),

                TextColumn::make('shift')
                    ->label('Shift')
                    ->placeholder('-')
                    ->description(fn (array $record): ?string => $record['section']),

                TextColumn::make('state')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => OutletRosterService::STATE_LABELS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        OutletRosterService::STATE_PRESENT, OutletRosterService::STATE_DONE => 'success',
                        OutletRosterService::STATE_LATE, OutletRosterService::STATE_WAITING => 'warning',
                        OutletRosterService::STATE_MISSING => 'danger',
                        OutletRosterService::STATE_UPCOMING => 'info',
                        default => 'gray',
                    }),

                TextColumn::make('check_in')
                    ->label('Masuk')
                    ->placeholder('-'),

                TextColumn::make('check_out')
                    ->label('Pulang')
                    ->placeholder('-'),
            ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(): array
    {
        $rows = [];

        foreach ($this->roster() as $outlet) {
            foreach ($outlet['entries'] as $entry) {
                $rows[$entry['schedule_id']] = [
                    'outlet' => $outlet['location']['name'] ?? 'Tanpa outlet',
                    'name' => $entry['worker']['name'],
                    'number' => $entry['worker']['number'],
                    'shift' => $entry['shift'] ? ($entry['shift']['code'] ?: $entry['shift']['name']).' · '.$entry['shift']['start_time'].'-'.$entry['shift']['end_time'] : null,
                    'section' => $entry['section'],
                    'state' => $entry['state'],
                    'check_in' => $entry['check_in'],
                    'check_out' => $entry['check_out'],
                ];
            }
        }

        return $rows;
    }

    private function summary(): string
    {
        $totals = [];

        foreach ($this->roster() as $outlet) {
            foreach ($outlet['counts'] as $key => $count) {
                $totals[$key] = ($totals[$key] ?? 0) + $count;
            }
        }

        if ($totals === []) {
            return now()->translatedFormat('l, d F Y');
        }

        return sprintf(
            '%s · %d terjadwal · %d hadir · %d terlambat · %d belum absen',
            now()->translatedFormat('l, d F Y'),
            $totals['scheduled'],
            $totals[OutletRosterService::STATE_PRESENT] + $totals[OutletRosterService::STATE_DONE],
            $totals[OutletRosterService::STATE_LATE],
            $totals[OutletRosterService::STATE_MISSING],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function roster(): array
    {
        return $this->roster ??= app(OutletRosterService::class)->roster(auth()->user(), today());
    }
}
