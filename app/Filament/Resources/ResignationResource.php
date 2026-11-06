<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ScopesToManagedCompanies;
use App\Filament\Resources\ResignationResource\Pages;
use App\Models\Employee;
use App\Models\Resignation;
use App\Services\ApprovalFlowService;
use App\Services\DataAccessService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ResignationResource extends Resource
{
    use ScopesToManagedCompanies;

    protected static ?string $model = Resignation::class;

    protected static ?string $modelLabel = 'Pengunduran Diri';

    protected static ?string $pluralModelLabel = 'Pengunduran Diri Karyawan';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-right-on-rectangle';

    protected static string|UnitEnum|null $navigationGroup = 'Presensi & Pengajuan';

    protected static ?int $navigationSort = 5;

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny() && ! DataAccessService::isCoordinator(auth()->user());
    }

    /**
     * Resignations belong to employees: Superadmin and Admin manage the ones inside their data access,
     * employees file their own, and Approver / BOD review the ones inside their data access.
     */
    public static function canViewAny(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        return $user->hasAnyRole(['Superadmin', 'Approver', 'BOD']) || $user->canManageWorkers() || $user->employee !== null;
    }

    public static function canCreate(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->canManageWorkers() || $user?->employee);
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

        // Eager load the worker so table columns and approve/reject checks do not lazy load per row.
        $query = parent::getEloquentQuery()->with(['employee']);

        return $user ? $query->visibleTo($user) : $query->whereRaw('1 = 0');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informasi Pengajuan Resign')
                    ->description('Isi data pengajuan resign karyawan beserta tanggal hari kerja terakhir.')
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('employee_id')
                                ->label('Karyawan')
                                ->relationship('employee', 'full_name', fn (Builder $query) => $query
                                    ->whereNotIn('status', ['inactive'])
                                    ->when(
                                        auth()->user()?->canManageWorkers(),
                                        fn (Builder $managed) => static::scopeToManagedWorkers($managed),
                                        fn (Builder $ownOnly) => $ownOnly->whereKey(auth()->user()?->employee?->id),
                                    ))
                                ->default(fn (): ?int => auth()->user()?->canManageWorkers() ? null : auth()->user()?->employee?->id)
                                ->getOptionLabelFromRecordUsing(fn (Employee $record) => "{$record->nip} - {$record->full_name}")
                                ->searchable()
                                ->preload()
                                ->required(),

                            DatePicker::make('resignation_date')
                                ->label('Tanggal Surat / Pengajuan')
                                ->default(today())
                                ->required()
                                ->native(false),

                            DatePicker::make('last_working_day')
                                ->label('Hari Kerja Terakhir (Last Working Day)')
                                ->default(today())
                                ->required()
                                ->native(false),

                            Textarea::make('reason')
                                ->label('Alasan Resign')
                                ->required()
                                ->columnSpanFull(),

                            Textarea::make('handover_notes')
                                ->label('Catatan Serah Terima Tugas / Aset (Opsional)')
                                ->nullable()
                                ->columnSpanFull(),
                        ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        $service = app(ApprovalFlowService::class);

        return $table
            ->columns([
                TextColumn::make('employee.nip')
                    ->label('NIP')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('employee.full_name')
                    ->label('Nama Karyawan')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('employee.company.name')
                    ->label('Badan Usaha')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('employee.division.name')
                    ->label('Divisi')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('resignation_date')
                    ->label('Tgl Pengajuan')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('last_working_day')
                    ->label('Kerja Terakhir')
                    ->date('d M Y')
                    ->sortable(),

                BadgeColumn::make('status')
                    ->label('Status Approval')
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'approved',
                        'danger' => 'rejected',
                    ])
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'pending' => 'Pending Approval',
                        'approved' => 'Disetujui (Resigned)',
                        'rejected' => 'Ditolak',
                        default => $state,
                    }),

                TextColumn::make('current_step')
                    ->label('Tahap Approval')
                    ->formatStateUsing(fn ($record) => $record->status === 'approved' ? 'Selesai' : "Tahap {$record->current_step}"),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Pending Approval',
                        'approved' => 'Disetujui (Resigned)',
                        'rejected' => 'Ditolak',
                    ]),
            ])
            ->actions([
                Action::make('viewSteps')
                    ->label('Lacak Step')
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->modalHeading('Detail & Progress Approval Resign')
                    ->modalSubmitAction(false)
                    ->schema(function (Resignation $record) {
                        $steps = $record->approvalSteps()->orderBy('step_order')->get();
                        $schemaComponents = [];

                        foreach ($steps as $step) {
                            $statusLabel = match ($step->status) {
                                'approved' => 'Disetujui',
                                'rejected' => 'Ditolak',
                                'pending' => ($step->step_order == $record->current_step && $record->status === 'pending')
                                    ? 'Menunggu Persetujuan (Tahap Aktif)'
                                    : 'Belum Dimulai',
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

                Action::make('approveStep')
                    ->label('Setujui')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Resignation $record) => $service->isUserAuthorizedToApprove($record, auth()->user()))
                    ->requiresConfirmation()
                    ->action(function (Resignation $record) use ($service): void {
                        $service->approveStep($record, auth()->user());

                        Notification::make()
                            ->title('Approval Resign Berhasil')
                            ->body('Pengajuan resign telah disetujui. Jika ini tahap akhir, status karyawan & akun user telah dinonaktifkan.')
                            ->success()
                            ->send();
                    }),

                Action::make('rejectStep')
                    ->label('Tolak')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Resignation $record) => $service->isUserAuthorizedToApprove($record, auth()->user()))
                    ->schema([
                        Textarea::make('rejection_reason')
                            ->label('Alasan Penolakan')
                            ->required(),
                    ])
                    ->action(function (Resignation $record, array $data) use ($service): void {
                        $service->rejectStep($record, auth()->user(), $data['rejection_reason']);

                        Notification::make()
                            ->title('Pengajuan Resign Ditolak')
                            ->body('Pengajuan resign telah ditolak.')
                            ->danger()
                            ->send();
                    }),

                Action::make('cetakSurat')
                    ->label('Cetak Surat')
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->modalHeading('Surat Keterangan Pengunduran Diri')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup')
                    ->modalContent(fn (Resignation $record) => view('filament.modals.print-resignation', ['record' => $record])),

                EditAction::make(),
                DeleteAction::make(),
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
            'index' => Pages\ListResignations::route('/'),
            'create' => Pages\CreateResignation::route('/create'),
            'edit' => Pages\EditResignation::route('/{record}/edit'),
        ];
    }
}
