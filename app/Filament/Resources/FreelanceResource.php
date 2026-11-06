<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\OpensWorkerAccounts;
use App\Filament\Concerns\ScopesToManagedCompanies;
use App\Filament\Forms\Components\MasterFacePhotoField;
use App\Filament\Resources\FreelanceResource\Pages;
use App\Filament\Resources\ShiftSchedules\ShiftScheduleResource;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Services\DataAccessService;
use App\Services\WorkerAccountService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class FreelanceResource extends Resource
{
    use OpensWorkerAccounts;
    use ScopesToManagedCompanies;

    protected static ?string $model = Freelancer::class;

    protected static ?string $modelLabel = 'Freelance';

    protected static ?string $pluralModelLabel = 'Data Freelance';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-briefcase';

    protected static string|UnitEnum|null $navigationGroup = 'Manajemen SDM';

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        return static::canManageWorkerData();
    }

    public static function getEloquentQuery(): Builder
    {
        return static::scopeToManagedWorkers(parent::getEloquentQuery()->with(['user.roles', 'user.employee', 'user.intern', 'user.freelancer']));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(['default' => 1, 'lg' => 3])
                    ->columnSpanFull()
                    ->schema([
                        Group::make([
                            Section::make('Data Diri')
                                ->icon('heroicon-o-user')
                                ->columns(2)
                                ->schema([
                                    TextInput::make('freelancer_number')
                                        ->label('Nomor Freelance')
                                        ->placeholder('Otomatis')
                                        ->unique(ignoreRecord: true)
                                        ->maxLength(50),

                                    TextInput::make('full_name')
                                        ->label('Nama Lengkap')
                                        ->required()
                                        ->maxLength(255),

                                    TextInput::make('email')
                                        ->label('Email')
                                        ->email()
                                        ->maxLength(255),

                                    TextInput::make('phone')
                                        ->label('Telepon')
                                        ->tel()
                                        ->maxLength(50),

                                    TextInput::make('institution')
                                        ->label('Keahlian / Vendor')
                                        ->nullable()
                                        ->maxLength(255)
                                        ->columnSpanFull(),
                                ]),

                            Section::make('Penempatan')
                                ->icon('heroicon-o-building-office-2')
                                ->columns(2)
                                ->schema([
                                    Select::make('supervisor_id')
                                        ->label('PIC')
                                        ->relationship('supervisor', 'full_name', function ($query, Get $get, ?Freelancer $record) {
                                            // Keep the currently assigned person selectable even after they resign,
                                            // otherwise existing records fail validation on save.
                                            $query->where(function ($available) use ($record) {
                                                $available->whereNotIn('status', ['inactive', 'resigned']);

                                                if ($record?->supervisor_id) {
                                                    $available->orWhere('id', $record->supervisor_id);
                                                }
                                            });

                                            if ($companyId = $get('company_id')) {
                                                $query->where('company_id', $companyId);
                                            }

                                            static::scopeToManagedWorkers($query);
                                        })
                                        ->getOptionLabelFromRecordUsing(fn (Employee $record) => "{$record->nip} - {$record->full_name}")
                                        ->helperText('Badan usaha dan divisi ikut terisi.')
                                        ->searchable()
                                        ->preload()
                                        ->live()
                                        ->afterStateUpdated(function (Set $set, ?string $state) {
                                            if (! $state) {
                                                return;
                                            }

                                            $employee = Employee::find($state);
                                            if ($employee) {
                                                $set('company_id', $employee->company_id);
                                                $set('division_id', $employee->division_id);
                                            }
                                        })
                                        ->nullable()
                                        ->columnSpanFull(),

                                    Select::make('company_id')
                                        ->label('Badan Usaha')
                                        ->options(fn (): array => static::placeableCompanyOptions())
                                        ->required()
                                        ->live()
                                        ->afterStateUpdated(function ($set): void {
                                            $set('division_id', null);
                                            $set('shift_id', null);
                                        }),

                                    Select::make('division_id')
                                        ->label('Divisi')
                                        ->options(fn (Get $get): array => static::placeableDivisionOptions($get('company_id')))
                                        ->required(),

                                    Select::make('shift_id')
                                        ->label('Shift Default')
                                        ->options(fn (Get $get, ?Freelancer $record): array => ShiftScheduleResource::shiftOptions($get('company_id')) + ($record?->shift ? [$record->shift_id => $record->shift->label()] : []))
                                        ->placeholder('Ikut jam kerja badan usaha')
                                        ->nullable()
                                        ->columnSpanFull(),
                                ]),

                            Section::make('Kontrak')
                                ->icon('heroicon-o-calendar-days')
                                ->columns(3)
                                ->schema([
                                    DatePicker::make('start_date')
                                        ->label('Mulai')
                                        ->native(false),

                                    DatePicker::make('end_date')
                                        ->label('Selesai')
                                        ->native(false),

                                    Select::make('status')
                                        ->label('Status')
                                        ->options([
                                            'active' => 'Aktif',
                                            'completed' => 'Selesai Kontrak',
                                            'extended' => 'Diperpanjang',
                                            'terminated' => 'Berhenti',
                                        ])
                                        ->default('active')
                                        ->required(),
                                ]),
                        ])->columnSpan(['lg' => 2]),

                        Group::make([
                            Section::make('Akun')
                                ->icon('heroicon-o-key')
                                ->schema([
                                    Toggle::make('create_account')
                                        ->label('Buat akun login')
                                        ->helperText('Password dikirim lewat WhatsApp.')
                                        ->default(true)
                                        ->live()
                                        ->dehydrated(false)
                                        ->visibleOn('create'),

                                    Select::make('user_id')
                                        ->label('Akun User')
                                        ->placeholder('Belum ada akun')
                                        ->options(fn (?Freelancer $record): array => static::assignableUserOptions() + ($record?->user ? [$record->user->getKey() => $record->user->name] : []))
                                        ->searchable()
                                        ->nullable()
                                        ->hidden(fn (Get $get, string $operation): bool => $operation === 'create' && (bool) $get('create_account')),
                                ]),

                            Section::make('Foto Master Wajah')
                                ->icon('heroicon-o-face-smile')
                                ->schema([
                                    MasterFacePhotoField::make('master_face_photo')
                                        ->label('Foto Master Wajah')
                                        ->hiddenLabel(),
                                ]),
                        ])->columnSpan(['lg' => 1]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('freelancer_number')
                    ->label('ID / No. Freelance')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('full_name')
                    ->label('Nama Lengkap')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('institution')
                    ->label('Keahlian / Agensi')
                    ->searchable()
                    ->placeholder('-'),

                TextColumn::make('company.name')
                    ->label('Perusahaan')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('division.name')
                    ->label('Divisi')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('supervisor.full_name')
                    ->label('PIC / Supervisor')
                    ->searchable()
                    ->placeholder('-'),

                TextColumn::make('start_date')
                    ->label('Mulai')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('end_date')
                    ->label('Selesai')
                    ->date('d M Y')
                    ->sortable(),

                BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'success' => 'active',
                        'info' => 'completed',
                        'warning' => 'extended',
                        'danger' => 'terminated',
                    ])
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'active' => 'Aktif',
                        'completed' => 'Selesai Kontrak',
                        'extended' => 'Diperpanjang',
                        'terminated' => 'Berhenti',
                        default => $state,
                    }),

                TextColumn::make('user.email')
                    ->label('Akun User')
                    ->badge()
                    ->color(fn ($state) => $state ? 'success' : 'warning')
                    ->formatStateUsing(fn ($state) => $state ? 'Sudah Punya Akun' : 'Belum Ada Akun')
                    ->searchable(),
            ])
            ->filters([
                SelectFilter::make('company_id')
                    ->label('Badan Usaha')
                    ->relationship('company', 'name', fn (Builder $query) => static::scopeToPlaceableCompanies($query, 'companies.id')),

                SelectFilter::make('division_id')
                    ->label('Divisi')
                    ->relationship('division', 'name', fn (Builder $query) => static::scopeToPlaceableDivisions($query)),

                SelectFilter::make('supervisor_id')
                    ->label('PIC / Supervisor (Karyawan)')
                    ->relationship('supervisor', 'full_name'),

                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'active' => 'Aktif',
                        'completed' => 'Selesai Kontrak',
                        'extended' => 'Diperpanjang',
                        'terminated' => 'Berhenti',
                    ]),
            ])
            ->actions([
                static::createAccountAction(),

                ...static::contractActions(),

                Action::make('deleteUser')
                    ->label('Hapus Akun')
                    ->icon('heroicon-o-user-minus')
                    ->color('danger')
                    ->visible(fn (Freelancer $record): bool => (bool) $record->user_id && static::canManageAccount($record->user) && ! DataAccessService::isCoordinator(auth()->user()))
                    ->requiresConfirmation()
                    ->modalHeading(fn (Freelancer $record) => "Hapus Akun User {$record->full_name}")
                    ->modalDescription('Apakah Anda yakin ingin menghapus akun user ini? Pengguna ini tidak akan dapat login lagi ke sistem.')
                    ->action(function (Freelancer $record) {
                        $user = $record->user;
                        $record->update(['user_id' => null]);
                        $user?->delete();

                        Notification::make()
                            ->title('Akun User Berhasil Dihapus!')
                            ->body("Akun user untuk {$record->full_name} telah dihapus dari sistem.")
                            ->success()
                            ->send();
                    }),

                Action::make('cetakSurat')
                    ->label('Cetak Surat')
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->modalHeading('Surat Keterangan Kerjasama Freelance')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup')
                    ->modalContent(fn (Freelancer $record) => view('filament.modals.print-freelance', ['record' => $record])),

                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * End a contract (account switched off, later schedules removed) or take the freelancer back on.
     *
     * @return list<Action>
     */
    public static function contractActions(): array
    {
        return [
            Action::make('endContract')
                ->label('Akhiri Kontrak')
                ->icon('heroicon-o-arrow-right-start-on-rectangle')
                ->color('danger')
                ->visible(fn (Freelancer $record): bool => app(WorkerAccountService::class)->isActive($record))
                ->requiresConfirmation()
                ->modalHeading(fn (Freelancer $record): string => "Akhiri kontrak {$record->full_name}?")
                ->modalDescription('Akun dinonaktifkan dan jadwal setelah hari ini dihapus. Bisa diaktifkan lagi kapan saja.')
                ->modalSubmitActionLabel('Akhiri')
                ->successNotificationTitle('Kontrak diakhiri')
                ->action(function (Freelancer $record, Action $action): void {
                    app(WorkerAccountService::class)->endContract($record);
                    $action->success();
                }),

            Action::make('reactivate')
                ->label('Aktifkan Lagi')
                ->icon('heroicon-o-arrow-path')
                ->color('success')
                ->visible(fn (Freelancer $record): bool => ! app(WorkerAccountService::class)->isActive($record))
                ->requiresConfirmation()
                ->modalHeading(fn (Freelancer $record): string => "Aktifkan lagi {$record->full_name}?")
                ->modalDescription('Status kembali Aktif dan akun yang sama bisa dipakai lagi.')
                ->modalSubmitActionLabel('Aktifkan')
                ->successNotificationTitle('Freelancer aktif lagi')
                ->action(function (Freelancer $record, Action $action): void {
                    app(WorkerAccountService::class)->reactivate($record);
                    $action->success();
                }),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFreelancers::route('/'),
            'create' => Pages\CreateFreelancer::route('/create'),
            'edit' => Pages\EditFreelancer::route('/{record}/edit'),
        ];
    }
}
