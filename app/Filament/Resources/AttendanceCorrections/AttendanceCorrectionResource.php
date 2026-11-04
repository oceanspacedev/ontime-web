<?php

namespace App\Filament\Resources\AttendanceCorrections;

use App\Filament\Concerns\ScopesToManagedCompanies;
use App\Filament\Resources\AttendanceCorrections\Pages\CreateAttendanceCorrection;
use App\Filament\Resources\AttendanceCorrections\Pages\EditAttendanceCorrection;
use App\Filament\Resources\AttendanceCorrections\Pages\ListAttendanceCorrections;
use App\Models\AttendanceCorrection;
use App\Services\ApprovalFlowService;
use App\Services\FileNamingService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use UnitEnum;

class AttendanceCorrectionResource extends Resource
{
    use ScopesToManagedCompanies;

    protected static ?string $model = AttendanceCorrection::class;

    protected static ?string $slug = 'koreksi-absensi';

    protected static ?string $modelLabel = 'Koreksi Absensi';

    protected static ?string $pluralModelLabel = 'Koreksi Absensi';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-check';

    protected static string|UnitEnum|null $navigationGroup = 'Presensi & Pengajuan';

    protected static ?int $navigationSort = 2;

    public static function shouldRegisterNavigation(): bool
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
        return static::canManageCompanyData();
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        // Eager load the worker so table columns and approve/reject checks do not lazy load per row.
        $query = parent::getEloquentQuery()->with(['employee', 'intern', 'freelancer']);

        return $user ? $query->visibleTo($user) : $query->whereRaw('1 = 0');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Form Pengajuan Koreksi Jam Absen')
                    ->description('Pilih tanggal absensi yang ingin dikoreksi dan masukkan usulan jam check-in/out.')
                    ->schema([
                        Grid::make(2)->schema([
                            DatePicker::make('date')
                                ->label('Tanggal Absensi')
                                ->required()
                                ->default(today()),

                            TimePicker::make('corrected_check_in')
                                ->label('Usulan Jam Check In (24 Jam)')
                                ->native(false)
                                ->displayFormat('H:i')
                                ->format('H:i')
                                ->seconds(false),

                            TimePicker::make('corrected_check_out')
                                ->label('Usulan Jam Check Out (24 Jam)')
                                ->native(false)
                                ->displayFormat('H:i')
                                ->format('H:i')
                                ->seconds(false),

                            FileUpload::make('attachment')
                                ->label('Bukti Pendukung / Lampiran (Opsional)')
                                ->directory('correction-attachments')
                                ->disk(config('filesystems.default'))
                                ->visibility('public')
                                ->getUploadedFileNameForStorageUsing(function (TemporaryUploadedFile $file): string {
                                    $employeeCode = auth()->user()?->employee?->employee_code ?? auth()->id();

                                    return FileNamingService::generateFileName('CORR', (string) $employeeCode, $file);
                                })
                                ->columnSpanFull(),

                            Textarea::make('reason')
                                ->label('Alasan Koreksi Absensi')
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
                    ->state(fn (AttendanceCorrection $record): string => $record->employee?->full_name
                        ?? $record->intern?->full_name
                        ?? $record->freelancer?->full_name
                        ?? '—')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(function (Builder $q) use ($search) {
                        $q->whereHas('employee', fn ($e) => $e->where('full_name', 'like', "%{$search}%"))
                            ->orWhereHas('intern', fn ($i) => $i->where('full_name', 'like', "%{$search}%"))
                            ->orWhereHas('freelancer', fn ($f) => $f->where('full_name', 'like', "%{$search}%"));
                    })),

                TextColumn::make('date')
                    ->label('Tanggal Absensi')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('corrected_check_in')
                    ->label('Usulan In')
                    ->time('H:i')
                    ->placeholder('-'),

                TextColumn::make('corrected_check_out')
                    ->label('Usulan Out')
                    ->time('H:i')
                    ->placeholder('-'),

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
                    ->visible(fn (AttendanceCorrection $record): bool => true)
                    ->modalHeading('Progres Approval Transparan')
                    ->modalDescription('Melacak status persetujuan di setiap tahap secara real-time.')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup')
                    ->schema(function (AttendanceCorrection $record) {
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

                Action::make('approveStep')
                    ->label('Setujui')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (AttendanceCorrection $record) => $service->isUserAuthorizedToApprove($record, auth()->user()))
                    ->requiresConfirmation()
                    ->action(function (AttendanceCorrection $record) use ($service): void {
                        $service->approveStep($record, auth()->user());

                        Notification::make()
                            ->title('Approval Koreksi Berhasil!')
                            ->body('Pengajuan koreksi absensi berhasil disetujui.')
                            ->success()
                            ->send();
                    }),

                Action::make('rejectStep')
                    ->label('Tolak')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (AttendanceCorrection $record) => $service->isUserAuthorizedToApprove($record, auth()->user()))
                    ->schema([
                        Textarea::make('rejection_reason')
                            ->label('Alasan Penolakan')
                            ->required(),
                    ])
                    ->action(function (AttendanceCorrection $record, array $data) use ($service): void {
                        $service->rejectStep($record, auth()->user(), $data['rejection_reason']);

                        Notification::make()
                            ->title('Pengajuan Ditolak')
                            ->body('Pengajuan telah ditolak.')
                            ->danger()
                            ->send();
                    }),

                EditAction::make()->visible(fn (AttendanceCorrection $record): bool => static::canEdit($record)),
                DeleteAction::make()->visible(fn (AttendanceCorrection $record): bool => static::canDelete($record)),
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
            'index' => ListAttendanceCorrections::route('/'),
            'create' => CreateAttendanceCorrection::route('/create'),
            'edit' => EditAttendanceCorrection::route('/{record}/edit'),
        ];
    }
}
