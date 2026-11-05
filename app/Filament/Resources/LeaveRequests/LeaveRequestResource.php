<?php

namespace App\Filament\Resources\LeaveRequests;

use App\Filament\Concerns\ScopesToManagedCompanies;
use App\Filament\Resources\LeaveRequests\Pages\CreateLeaveRequest;
use App\Filament\Resources\LeaveRequests\Pages\EditLeaveRequest;
use App\Filament\Resources\LeaveRequests\Pages\ListLeaveRequests;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\LeaveRequest;
use App\Services\AnnualLeaveService;
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
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use UnitEnum;

class LeaveRequestResource extends Resource
{
    use ScopesToManagedCompanies;

    protected static ?string $model = LeaveRequest::class;

    protected static ?string $slug = 'leave-requests';

    protected static ?string $modelLabel = 'Cuti & Izin';

    protected static ?string $pluralModelLabel = 'Pengajuan Cuti & Izin';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static string|UnitEnum|null $navigationGroup = 'Presensi & Pengajuan';

    protected static ?int $navigationSort = 2;

    public static function shouldRegisterNavigation(): bool
    {
        return true;
    }

    public static function canViewAny(): bool
    {
        return true;
    }

    /**
     * The worker the form is filed for: the record's worker when editing, otherwise the signed-in user.
     */
    public static function leaveWorker(?LeaveRequest $record): Employee|Intern|Freelancer|null
    {
        if ($record) {
            return $record->getWorkerProfile();
        }

        $user = auth()->user();

        return $user?->employee ?? $user?->intern ?? $user?->freelancer;
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

        // Eager load the worker so table columns and approve/reject checks do not lazy load per row.
        $query = parent::getEloquentQuery()->with(['employee', 'intern', 'freelancer']);

        return $user ? $query->visibleTo($user) : $query->whereRaw('1 = 0');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(fn (Get $get): string => match ($get('leave_type')) {
                    'permission' => 'Form Pengajuan Izin Tidak Masuk',
                    'sick' => 'Form Pengajuan Sakit',
                    default => 'Form Pengajuan Cuti Tahunan',
                })
                    ->description('Pilih jenis pengajuan, tentukan rentang tanggal, dan isi alasan pendukung.')
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('leave_type')
                                ->label('Jenis Pengajuan')
                                ->options(function (?LeaveRequest $record): array {
                                    $options = [
                                        LeaveRequest::TYPE_ANNUAL => 'Cuti Tahunan (Potong Kuota)',
                                        'permission' => 'Izin Tidak Masuk',
                                        'sick' => 'Sakit (Dengan/Tanpa Surat Dokter)',
                                    ];

                                    // Only employees HR gave annual leave see it; interns and freelancers never do.
                                    if (! app(AnnualLeaveService::class)->isEligible(static::leaveWorker($record))) {
                                        unset($options[LeaveRequest::TYPE_ANNUAL]);
                                    }

                                    // Keep a type filed from the mobile app (e.g. maternity) selectable on edit.
                                    if ($record?->leave_type && ! isset($options[$record->leave_type])) {
                                        $options[$record->leave_type] = LeaveRequest::typeLabel($record->leave_type);
                                    }

                                    return $options;
                                })
                                ->default(fn (): string => app(AnnualLeaveService::class)->isEligible(static::leaveWorker(null)) ? LeaveRequest::TYPE_ANNUAL : 'permission')
                                ->required()
                                ->live(),

                            DatePicker::make('start_date')
                                ->label(fn (Get $get): string => match ($get('leave_type')) {
                                    'permission' => 'Tanggal Mulai Izin',
                                    'sick' => 'Tanggal Mulai Sakit',
                                    default => 'Tanggal Mulai Cuti',
                                })
                                ->required()
                                ->default(today()),

                            DatePicker::make('end_date')
                                ->label(fn (Get $get): string => match ($get('leave_type')) {
                                    'permission' => 'Tanggal Selesai Izin',
                                    'sick' => 'Tanggal Selesai Sakit',
                                    default => 'Tanggal Selesai Cuti',
                                })
                                ->required()
                                ->default(today()),

                            FileUpload::make('attachment')
                                ->label(fn (Get $get): string => match ($get('leave_type')) {
                                    'sick' => 'Surat Keterangan Dokter (Disarankan)',
                                    'permission' => 'Dokumen Pendukung Izin (Opsional)',
                                    default => 'Dokumen Pendukung Cuti (Opsional)',
                                })
                                ->directory('leave-attachments')
                                ->disk(config('filesystems.default'))
                                ->visibility('public')
                                ->getUploadedFileNameForStorageUsing(function (TemporaryUploadedFile $file): string {
                                    $user = auth()->user();
                                    $workerProfile = $user?->employee ?? $user?->intern ?? $user?->freelancer;
                                    $identifier = (string) ($workerProfile?->id ?? $user?->id ?? 'unknown');

                                    return FileNamingService::generateFileName('LEAVE', $identifier, $file);
                                })
                                ->columnSpanFull(),

                            Textarea::make('reason')
                                ->label(fn (Get $get): string => match ($get('leave_type')) {
                                    'permission' => 'Alasan / Keterangan Izin',
                                    'sick' => 'Keterangan Sakit / Gejala',
                                    default => 'Alasan / Keterangan Cuti',
                                })
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
                    ->state(function (LeaveRequest $record): string {
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

                BadgeColumn::make('leave_type')
                    ->label('Jenis Cuti')
                    ->colors([
                        'info' => 'annual_leave',
                        'warning' => 'permission',
                        'danger' => 'sick',
                    ])
                    ->formatStateUsing(fn (string $state): string => LeaveRequest::typeLabel($state)),

                TextColumn::make('start_date')
                    ->label('Tgl Mulai')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('end_date')
                    ->label('Tgl Selesai')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('days_count')
                    ->label('Durasi')
                    ->formatStateUsing(fn ($state) => "{$state} Hari"),

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
                    ->visible(fn (LeaveRequest $record): bool => true)
                    ->modalHeading('Progres Approval Transparan')
                    ->modalDescription('Melacak status persetujuan di setiap tahap secara real-time.')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup')
                    ->schema(function (LeaveRequest $record) {
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
                    ->visible(fn (LeaveRequest $record) => $service->isUserAuthorizedToApprove($record, auth()->user()))
                    ->requiresConfirmation()
                    ->action(function (LeaveRequest $record) use ($service): void {
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
                            ->title('Approval Cuti Berhasil!')
                            ->body('Pengajuan cuti berhasil disetujui.')
                            ->success()
                            ->send();
                    }),

                Action::make('rejectStep')
                    ->label('Tolak')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (LeaveRequest $record) => $service->isUserAuthorizedToApprove($record, auth()->user()))
                    ->schema([
                        Textarea::make('rejection_reason')
                            ->label('Alasan Penolakan')
                            ->required(),
                    ])
                    ->action(function (LeaveRequest $record, array $data) use ($service): void {
                        $service->rejectStep($record, auth()->user(), $data['rejection_reason']);

                        Notification::make()
                            ->title('Pengajuan Cuti Ditolak')
                            ->body('Pengajuan cuti telah ditolak.')
                            ->danger()
                            ->send();
                    }),

                EditAction::make()->visible(fn (LeaveRequest $record): bool => static::canEdit($record)),
                DeleteAction::make()->visible(fn (LeaveRequest $record): bool => static::canDelete($record)),
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
            'index' => ListLeaveRequests::route('/'),
            'create' => CreateLeaveRequest::route('/create'),
            'edit' => EditLeaveRequest::route('/{record}/edit'),
        ];
    }
}
