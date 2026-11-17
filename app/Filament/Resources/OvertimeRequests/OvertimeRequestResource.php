<?php

namespace App\Filament\Resources\OvertimeRequests;

use App\Filament\Concerns\ScopesToManagedCompanies;
use App\Filament\Resources\OvertimeRequests\Pages\CreateOvertimeRequest;
use App\Filament\Resources\OvertimeRequests\Pages\EditOvertimeRequest;
use App\Filament\Resources\OvertimeRequests\Pages\ListOvertimeRequests;
use App\Models\OvertimeRequest;
use App\Services\ApprovalFlowService;
use App\Services\DataAccessService;
use App\Services\OvertimeRequestService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class OvertimeRequestResource extends Resource
{
    use ScopesToManagedCompanies;

    protected static ?string $model = OvertimeRequest::class;

    protected static ?string $slug = 'overtime-requests';

    protected static ?string $modelLabel = 'Lembur';

    protected static ?string $pluralModelLabel = 'Pengajuan Lembur';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static string|UnitEnum|null $navigationGroup = 'Presensi & Pengajuan';

    protected static ?int $navigationSort = 3;

    /**
     * Koordinators handle requests from Approval Saya, so the list stays out of their menu.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return ! DataAccessService::isCoordinator(auth()->user());
    }

    public static function canViewAny(): bool
    {
        return true;
    }

    public static function canCreate(): bool
    {
        $user = auth()->user();

        // Requests belong to a worker profile; a role alone (e.g. BOD without employee data) cannot submit.
        return (bool) ($user?->employee || $user?->intern || $user?->freelancer);
    }

    public static function canEdit(Model $record): bool
    {
        return static::canManageWorkerRecord($record);
    }

    public static function canDelete(Model $record): bool
    {
        return static::canManageWorkerRecord($record);
    }

    public static function canDeleteAny(): bool
    {
        return static::canManageWorkerData();
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        // Eager load the worker and its company so table columns, approve/reject checks and the actual
        // duration action's tenant check do not lazy load per row.
        $query = parent::getEloquentQuery()->with(['employee.company', 'intern.company', 'freelancer.company']);

        return $user ? $query->visibleTo($user) : $query->whereRaw('1 = 0');
    }

    public static function form(Schema $schema): Schema
    {
        // Shown only: the stored duration is always derived again from the times when saving.
        $calculateDuration = function (Get $get, Set $set): void {
            $set('duration_minutes', OvertimeRequestService::durationMinutes($get('start_time'), $get('end_time')));
        };

        // Hours of a request an approver already acted on are fixed: reject it and let the worker file again.
        $hoursLocked = fn (?OvertimeRequest $record): bool => $record !== null && ! ApprovalFlowService::isUntouchedByApprovers($record);

        return $schema
            ->components([
                Section::make('Form Pengajuan Lembur')
                    ->description('Isi tanggal, estimasi jam lembur, dan rincian tugas yang dikerjakan.')
                    ->schema([
                        Grid::make(2)->schema([
                            DatePicker::make('date')
                                ->label('Tanggal Lembur')
                                ->required()
                                ->default(today())
                                ->disabled($hoursLocked)
                                ->helperText(fn (?OvertimeRequest $record): ?string => $hoursLocked($record)
                                    ? 'Tanggal dan jam lembur yang sudah diproses tidak bisa diubah. Tolak pengajuan ini lalu minta pekerja mengajukan ulang.'
                                    : 'Hari ini, paling lama 31 hari ke belakang atau 31 hari ke depan.'),

                            TimePicker::make('start_time')
                                ->label('Jam Mulai Lembur (24 Jam)')
                                ->native(false)
                                ->displayFormat('H:i')
                                ->format('H:i')
                                ->seconds(false)
                                ->required()
                                ->disabled($hoursLocked)
                                ->live()
                                ->afterStateUpdated($calculateDuration),

                            TimePicker::make('end_time')
                                ->label('Jam Selesai Lembur (24 Jam)')
                                ->native(false)
                                ->displayFormat('H:i')
                                ->format('H:i')
                                ->seconds(false)
                                ->required()
                                ->disabled($hoursLocked)
                                ->live()
                                ->afterStateUpdated($calculateDuration),

                            TextInput::make('duration_minutes')
                                ->label('Estimasi Durasi (Menit)')
                                ->numeric()
                                ->disabled()
                                ->dehydrated(false)
                                ->placeholder('Otomatis terhitung dari jam mulai & selesai')
                                ->helperText(fn (Get $get): ?string => $get('duration_minutes') ? 'Setara dengan '.round(((int) $get('duration_minutes')) / 60, 1).' jam. Maksimal 12 jam; jam selesai sebelum jam mulai berarti melewati tengah malam.' : 'Maksimal 12 jam; jam selesai sebelum jam mulai berarti melewati tengah malam.'),

                            Textarea::make('reason')
                                ->label('Tugas / Alasan Lembur')
                                ->required()
                                ->columnSpanFull(),
                        ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        $service = new ApprovalFlowService;

        return $table
            ->columns([
                TextColumn::make('worker_name')
                    ->label('Nama Pemohon')
                    ->state(function (OvertimeRequest $record): string {
                        return $record->employee?->full_name
                            ?? $record->intern?->full_name
                            ?? $record->freelancer?->full_name
                            ?? '—';
                    })
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function (Builder $q) use ($search) {
                            $q->whereHas('employee', fn ($e) => $e->where('full_name', 'like', "%{$search}%"))
                                ->orWhereHas('intern', fn ($i) => $i->where('full_name', 'like', "%{$search}%"))
                                ->orWhereHas('freelancer', fn ($f) => $f->where('full_name', 'like', "%{$search}%"));
                        });
                    })
                    ->sortable(query: fn (Builder $query, string $direction) => $query),

                TextColumn::make('date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('start_time')
                    ->label('Jam Mulai')
                    ->time('H:i'),

                TextColumn::make('end_time')
                    ->label('Jam Selesai')
                    ->time('H:i'),

                TextColumn::make('duration_minutes')
                    ->label('Estimasi Durasi')
                    ->formatStateUsing(fn ($state) => "{$state} menit"),

                TextColumn::make('actual_duration_minutes')
                    ->label('Durasi Aktual')
                    ->placeholder('- Belum diisi -')
                    ->formatStateUsing(function ($state, OvertimeRequest $record) {
                        if (! $state) {
                            return '-';
                        }
                        $diff = $state - $record->duration_minutes;
                        if ($diff > 0) {
                            return "{$state} m (+{$diff}m dari estimasi)";
                        }

                        return "{$state} m";
                    })
                    ->color(fn ($state, OvertimeRequest $record) => ($state && $state > $record->duration_minutes) ? 'warning' : 'currentColor'),

                BadgeColumn::make('status')
                    ->label('Status Request')
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'approved',
                        'danger' => 'rejected',
                    ])
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'pending' => 'Menunggu Approval',
                        'approved' => 'Disetujui',
                        'rejected' => 'Ditolak',
                        default => $state,
                    }),
            ])
            ->actions([
                Action::make('lacakProgres')
                    ->label('Lacak Progres')
                    ->color('info')
                    ->visible(fn (OvertimeRequest $record): bool => true)
                    ->modalHeading('Progres Approval Transparan')
                    ->modalDescription('Melacak status persetujuan di setiap tahap secara real-time.')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup')
                    ->schema(function (OvertimeRequest $record) {
                        $steps = $record->approvalSteps()->orderBy('step_order')->get();
                        $schemaComponents = [];

                        foreach ($steps as $step) {
                            $statusLabel = match ($step->status) {
                                'approved' => 'Disetujui',
                                'rejected' => 'Ditolak',
                                'pending' => ($step->step_order == $record->current_step && $record->status === 'pending')
                                    ? ' Menunggu Persetujuan (Tahap Aktif)'
                                    : ' Belum Dimulai',
                                default => $step->status,
                            };

                            $actionedBy = $step->actioner ? $step->actioner->name : '-';
                            $actionedAt = $step->actioned_at ? $step->actioned_at->format('d M Y H:i') : '-';
                            $notes = $step->notes ? " (Catatan: {$step->notes})" : '';

                            $schemaComponents[] = Section::make("Tahap {$step->step_order}: {$step->step_name}")
                                ->description("Status: {$statusLabel} | Oleh: {$actionedBy} ({$actionedAt}){$notes}")
                                ->compact();
                        }

                        return $schemaComponents;
                    }),

                Action::make('fillActualDuration')
                    ->label('Isi Durasi Aktual')
                    ->icon('heroicon-o-pencil-square')
                    ->color('info')
                    ->visible(fn (OvertimeRequest $record): bool => OvertimeRequestService::canFillActualDuration($record, auth()->user()))
                    ->fillForm(fn (OvertimeRequest $record): array => [
                        'actual_duration_minutes' => $record->actual_duration_minutes ?? $record->duration_minutes,
                    ])
                    ->schema([
                        TextInput::make('actual_duration_minutes')
                            ->label('Durasi Aktual Lembur (Menit)')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(OvertimeRequestService::MAX_DURATION_MINUTES)
                            ->helperText('Maksimal 720 menit (12 jam).')
                            ->required(),
                    ])
                    ->action(function (OvertimeRequest $record, array $data): void {
                        try {
                            app(OvertimeRequestService::class)->fillActualDuration($record, auth()->user(), $data['actual_duration_minutes'] ?? null);
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->title('Durasi aktual tidak disimpan')
                                ->body(collect($exception->errors())->flatten()->first())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Durasi Aktual Diperbarui')
                            ->body("Durasi aktual lembur berhasil dicatat: {$data['actual_duration_minutes']} menit.")
                            ->success()
                            ->send();
                    }),

                Action::make('approveStep')
                    ->label('Setujui')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (OvertimeRequest $record) => $service->isUserAuthorizedToApprove($record, auth()->user()))
                    ->requiresConfirmation()
                    ->action(function (OvertimeRequest $record) use ($service): void {
                        try {
                            $service->approveStep($record, auth()->user());
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->title('Pengajuan tidak bisa disetujui')
                                ->body(collect($exception->errors())->flatten()->first())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Approval Lembur Berhasil!')
                            ->body('Pengajuan lembur berhasil disetujui.')
                            ->success()
                            ->send();
                    }),

                Action::make('rejectStep')
                    ->label('Tolak')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (OvertimeRequest $record) => $service->isUserAuthorizedToApprove($record, auth()->user()))
                    ->schema([
                        Textarea::make('rejection_reason')
                            ->label('Alasan Penolakan')
                            ->required(),
                    ])
                    ->action(function (OvertimeRequest $record, array $data) use ($service): void {
                        try {
                            $service->rejectStep($record, auth()->user(), $data['rejection_reason']);
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->title('Pengajuan tidak bisa ditolak')
                                ->body(collect($exception->errors())->flatten()->first())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Pengajuan Lembur Ditolak')
                            ->body('Pengajuan lembur telah ditolak.')
                            ->danger()
                            ->send();
                    }),

                EditAction::make()->visible(fn (OvertimeRequest $record): bool => static::canEdit($record)),
                DeleteAction::make()->visible(fn (OvertimeRequest $record): bool => static::canDelete($record)),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOvertimeRequests::route('/'),
            'create' => CreateOvertimeRequest::route('/create'),
            'edit' => EditOvertimeRequest::route('/{record}/edit'),
        ];
    }
}
