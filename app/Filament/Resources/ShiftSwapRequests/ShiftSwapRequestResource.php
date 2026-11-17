<?php

namespace App\Filament\Resources\ShiftSwapRequests;

use App\Filament\Resources\ShiftSwapRequests\Pages\ManageShiftSwapRequests;
use App\Models\ShiftSwapRequest;
use App\Services\ApprovalFlowService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Tukar Shift requests inside the user's data access. Workers file and answer them in the app; approvers
 * approve or reject the ones on their stage here (or in the app).
 */
class ShiftSwapRequestResource extends Resource
{
    protected static ?string $model = ShiftSwapRequest::class;

    protected static ?string $modelLabel = 'Tukar Shift';

    protected static ?string $pluralModelLabel = 'Tukar Shift';

    protected static ?string $slug = 'tukar-shift';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Presensi & Pengajuan';

    protected static ?int $navigationSort = 6;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->hasAnyRole(ApprovalFlowService::INBOX_ROLES) || $user->canManageWorkers());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        $query = parent::getEloquentQuery()->with([
            'employee', 'intern', 'freelancer',
            'counterpartEmployee', 'counterpartIntern', 'counterpartFreelancer',
            'approvalSteps',
        ]);

        return $user ? $query->visibleTo($user) : $query->whereRaw('1 = 0');
    }

    public static function table(Table $table): Table
    {
        $service = app(ApprovalFlowService::class);

        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('requester_name')
                    ->label('Pengaju')
                    ->state(fn (ShiftSwapRequest $record): string => $record->getWorkerProfile()?->full_name ?? '-'),
                TextColumn::make('counterpart_name')
                    ->label('Rekan')
                    ->state(fn (ShiftSwapRequest $record): string => $record->counterpartProfile()?->full_name ?? '-'),
                TextColumn::make('type')
                    ->label('Jenis')
                    ->formatStateUsing(fn (string $state): string => $state === ShiftSwapRequest::TYPE_GIVE_AWAY ? 'Serahkan Shift' : 'Tukar Shift')
                    ->badge(),
                TextColumn::make('requester_date')
                    ->label('Tanggal Pengaju')
                    ->date('d M Y')
                    ->sortable(),
                TextColumn::make('counterpart_date')
                    ->label('Tanggal Rekan')
                    ->date('d M Y')
                    ->placeholder('-'),
                TextColumn::make('status')
                    ->label('Status')
                    // An open request whose date passed reads as expired before shift-swaps:expire saves it.
                    ->state(fn (ShiftSwapRequest $record): string => $record->effectiveStatus())
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ShiftSwapRequest::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        ShiftSwapRequest::STATUS_APPROVED => 'success',
                        ShiftSwapRequest::STATUS_PENDING, ShiftSwapRequest::STATUS_AWAITING_COLLEAGUE => 'warning',
                        ShiftSwapRequest::STATUS_REJECTED, ShiftSwapRequest::STATUS_DECLINED, ShiftSwapRequest::STATUS_FAILED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('reason')
                    ->label('Alasan')
                    ->limit(40)
                    ->toggleable(),
                TextColumn::make('failure_reason')
                    ->label('Keterangan')
                    ->state(fn (ShiftSwapRequest $record): ?string => $record->failure_reason ?? $record->rejection_reason)
                    ->limit(60)
                    ->placeholder('-')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(ShiftSwapRequest::STATUS_LABELS),
            ])
            ->recordActions([
                Action::make('approveStep')
                    ->label('Setujui')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (ShiftSwapRequest $record): bool => $service->isUserAuthorizedToApprove($record, auth()->user()))
                    ->requiresConfirmation()
                    ->modalDescription('Jadwal kedua pekerja langsung ditukar bila ini tahap terakhir.')
                    ->action(function (ShiftSwapRequest $record) use ($service): void {
                        try {
                            $service->approveStep($record, auth()->user());
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->title('Tukar shift tidak bisa disetujui')
                                ->body(collect($exception->errors())->flatten()->first())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()->title('Tukar shift disetujui')->success()->send();
                    }),
                Action::make('rejectStep')
                    ->label('Tolak')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (ShiftSwapRequest $record): bool => $service->isUserAuthorizedToApprove($record, auth()->user()))
                    ->schema([
                        Textarea::make('rejection_reason')
                            ->label('Alasan Penolakan')
                            ->required()
                            ->maxLength(1000),
                    ])
                    ->action(function (ShiftSwapRequest $record, array $data) use ($service): void {
                        try {
                            $service->rejectStep($record, auth()->user(), $data['rejection_reason']);
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->title('Tukar shift tidak bisa ditolak')
                                ->body(collect($exception->errors())->flatten()->first())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()->title('Tukar shift ditolak')->danger()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageShiftSwapRequests::route('/'),
        ];
    }
}
