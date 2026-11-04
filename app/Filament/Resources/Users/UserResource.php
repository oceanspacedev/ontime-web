<?php

namespace App\Filament\Resources\Users;

use App\Enums\DataScope;
use App\Enums\SystemRole;
use App\Filament\Concerns\ScopesToManagedCompanies;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Division;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\User;
use App\Services\DataAccessService;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
use Illuminate\Support\Facades\Hash;
use UnitEnum;

class UserResource extends Resource
{
    use ScopesToManagedCompanies;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with([
                'roles',
                'accessibleCompanies',
                'accessibleDivisions',
                'employee.company',
                'employee.division',
                'intern.company',
                'intern.division',
                'freelancer.company',
                'freelancer.division',
            ]);

        // Admin: accounts of workers inside their data access, never Superadmin/Admin accounts or their own.
        return static::scopeToManageableAccounts($query, includeUnlinked: false);
    }

    /**
     * Labels for the division picker: "TOP · DISTRIBUTION SALES > DEPO BANDUNG", built from one query.
     *
     * @return array<int, string>
     */
    public static function divisionPathLabels(): array
    {
        return once(function (): array {
            $divisions = Division::query()->with('company:id,code,name')->get(['id', 'company_id', 'parent_id', 'name'])->keyBy('id');

            return $divisions
                ->map(function (Division $division) use ($divisions): string {
                    $names = [];
                    $visited = [];

                    for ($node = $division; $node && ! isset($visited[$node->id]); $node = $divisions->get($node->parent_id)) {
                        $visited[$node->id] = true;
                        array_unshift($names, $node->name);
                    }

                    return ($division->company?->code ?: $division->company?->name).' · '.implode(' > ', $names);
                })
                ->sort()
                ->all();
        });
    }

    protected static function selectedScope(Get $get): ?DataScope
    {
        $state = $get('data_scope');

        return $state instanceof DataScope ? $state : DataScope::tryFrom((string) $state);
    }

    public static function dataAccessSummary(User $record): string
    {
        $scope = DataAccessService::scopeOf($record);

        return match ($scope) {
            DataScope::Companies => $scope->getLabel().': '.($record->accessibleCompanies->pluck('code')->filter()->implode(', ') ?: '-'),
            DataScope::Divisions => $scope->getLabel().': '.($record->accessibleDivisions->pluck('name')->implode(', ') ?: '-'),
            default => $scope->getLabel(),
        };
    }

    /**
     * Interns and freelancers are managed from their own menus, so the employee section is hidden for them.
     */
    protected static function canManageEmployeeProfile(?User $user): bool
    {
        if ($user === null) {
            return true;
        }

        return ! in_array(static::detectProfileType($user), ['intern', 'freelancer'], true);
    }

    /**
     * Deteksi tipe profil yang terhubung ke user ini: 'employee' | 'intern' | 'freelancer' | null.
     */
    protected static function detectProfileType(User $user): ?string
    {
        if ($user->employee !== null) {
            return 'employee';
        }

        if ($user->intern !== null) {
            return 'intern';
        }

        if ($user->freelancer !== null) {
            return 'freelancer';
        }

        return null;
    }

    protected static ?string $model = User::class;

    protected static ?string $slug = 'users';

    protected static ?string $modelLabel = 'Pengguna (User)';

    protected static ?string $pluralModelLabel = 'Manajemen User';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-plus';

    protected static string|UnitEnum|null $navigationGroup = 'Pengaturan Sistem';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return static::canManageWorkerData();
    }

    public static function canEdit(Model $record): bool
    {
        return (bool) auth()->user()?->hasRole(SystemRole::Superadmin->value) || static::canManageAccount($record);
    }

    public static function canDelete(Model $record): bool
    {
        return static::canManageAccount($record);
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()?->hasRole('Superadmin') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informasi Akun User')
                    ->description('Buat dan atur kredensial akun pengguna serta peran (Role) aksesnya.')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('name')
                                ->label('Nama Akun User')
                                ->required()
                                ->live(onBlur: true)
                                ->maxLength(255),

                            TextInput::make('email')
                                ->label('Alamat Email')
                                ->email()
                                ->required()
                                ->unique(ignoreRecord: true)
                                ->maxLength(255),

                            TextInput::make('password')
                                ->label('Password')
                                ->password()
                                ->dehydrateStateUsing(fn (?string $state) => filled($state) ? Hash::make($state) : null)
                                ->dehydrated(fn (?string $state) => filled($state))
                                ->required(fn (string $operation): bool => $operation === 'create')
                                ->maxLength(255),

                            Select::make('roles')
                                ->label('Role Akses')
                                ->relationship('roles', 'name', fn (Builder $query) => $query->whereIn('name', array_keys(static::assignableRoleOptions())))
                                ->multiple()
                                ->preload()
                                ->live()
                                ->required(),

                            Toggle::make('has_employee_profile')
                                ->label('Kelola data karyawan untuk akun ini')
                                ->helperText('Matikan untuk akun tanpa data karyawan, misalnya Superadmin atau BOD. Data karyawan yang sudah ada tidak akan dihapus.')
                                ->default(true)
                                ->live()
                                ->dehydrated(false)
                                ->afterStateHydrated(function (Toggle $component, ?User $record): void {
                                    if ($record) {
                                        $component->state($record->employee()->exists());
                                    }
                                })
                                ->visible(fn (callable $livewire) => (bool) auth()->user()?->hasRole('Superadmin') && static::canManageEmployeeProfile($livewire->record))
                                ->columnSpanFull(),
                        ]),
                    ]),

                Section::make('Akses Data')
                    ->description('Menentukan data siapa yang bisa dilihat, dikelola, dan disetujui akun ini. Role menentukan apa yang bisa dilakukan, akses data menentukan untuk siapa.')
                    ->icon('heroicon-o-shield-check')
                    ->visible(fn (): bool => (bool) auth()->user()?->hasRole(SystemRole::Superadmin->value))
                    ->schema([
                        Select::make('data_scope')
                            ->label('Tingkat Akses')
                            ->options(DataScope::class)
                            ->default(DataScope::Own->value)
                            ->required()
                            ->live()
                            ->helperText(fn (Get $get, ?User $record): string => $record?->hasRole(SystemRole::Superadmin->value)
                                ? 'Superadmin selalu punya akses Semua, apa pun pilihan di sini.'
                                : (string) static::selectedScope($get)?->getDescription()),

                        Select::make('accessibleCompanies')
                            ->label('Badan Usaha')
                            ->relationship('accessibleCompanies', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->required(fn (Get $get): bool => static::selectedScope($get) === DataScope::Companies)
                            ->visible(fn (Get $get): bool => static::selectedScope($get) === DataScope::Companies),

                        Select::make('accessibleDivisions')
                            ->label('Divisi')
                            ->helperText('Sub-divisi di bawahnya ikut otomatis.')
                            ->relationship('accessibleDivisions', 'name')
                            ->options(fn (): array => static::divisionPathLabels())
                            ->getOptionLabelFromRecordUsing(fn (Division $record): string => static::divisionPathLabels()[$record->id] ?? $record->name)
                            ->multiple()
                            ->searchable()
                            ->required(fn (Get $get): bool => static::selectedScope($get) === DataScope::Divisions)
                            ->visible(fn (Get $get): bool => static::selectedScope($get) === DataScope::Divisions),
                    ]),

                // === SECTION: Profil Magang (read-only) ===
                Section::make('Profil Peserta Magang')
                    ->description('Data peserta magang yang terhubung dengan akun ini. Edit melalui menu Magang.')
                    ->icon('heroicon-o-academic-cap')
                    ->visible(fn (callable $livewire) => $livewire->record !== null && static::detectProfileType($livewire->record) === 'intern')
                    ->schema([
                        Grid::make(2)->schema([
                            Placeholder::make('intern_full_name')
                                ->label('Nama Lengkap')
                                ->content(fn (callable $livewire) => $livewire->record?->intern?->full_name ?? '-'),

                            Placeholder::make('intern_nis')
                                ->label('NIS / ID Magang')
                                ->content(fn (callable $livewire) => $livewire->record?->intern?->nis ?? '-'),

                            Placeholder::make('intern_company')
                                ->label('Badan Usaha')
                                ->content(fn (callable $livewire) => $livewire->record?->intern?->company?->name ?? '-'),

                            Placeholder::make('intern_division')
                                ->label('Divisi')
                                ->content(fn (callable $livewire) => $livewire->record?->intern?->division?->name ?? '-'),

                            Placeholder::make('intern_institution')
                                ->label('Asal Institusi')
                                ->content(fn (callable $livewire) => $livewire->record?->intern?->institution ?? '-'),

                            Placeholder::make('intern_status')
                                ->label('Status Program Magang')
                                ->content(fn (callable $livewire) => strtoupper($livewire->record?->intern?->status ?? '-')),

                            Placeholder::make('intern_start')
                                ->label('Periode Magang')
                                ->content(fn (callable $livewire) => $livewire->record?->intern?->start_date?->format('d M Y').' s/d '.$livewire->record?->intern?->end_date?->format('d M Y') ?? '-')
                                ->columnSpanFull(),
                        ]),
                    ]),

                // === SECTION: Profil Freelancer (read-only) ===
                Section::make('Profil Freelancer')
                    ->description('Data freelancer yang terhubung dengan akun ini. Edit melalui menu Freelance.')
                    ->icon('heroicon-o-briefcase')
                    ->visible(fn (callable $livewire) => $livewire->record !== null && static::detectProfileType($livewire->record) === 'freelancer')
                    ->schema([
                        Grid::make(2)->schema([
                            Placeholder::make('freelancer_full_name')
                                ->label('Nama Lengkap')
                                ->content(fn (callable $livewire) => $livewire->record?->freelancer?->full_name ?? '-'),

                            Placeholder::make('freelancer_number')
                                ->label('ID / No. Freelance')
                                ->content(fn (callable $livewire) => $livewire->record?->freelancer?->freelancer_number ?? '-'),

                            Placeholder::make('freelancer_company')
                                ->label('Badan Usaha')
                                ->content(fn (callable $livewire) => $livewire->record?->freelancer?->company?->name ?? '-'),

                            Placeholder::make('freelancer_division')
                                ->label('Divisi')
                                ->content(fn (callable $livewire) => $livewire->record?->freelancer?->division?->name ?? '-'),

                            Placeholder::make('freelancer_status')
                                ->label('Status Kontrak')
                                ->content(fn (callable $livewire) => strtoupper($livewire->record?->freelancer?->status ?? '-')),

                            Placeholder::make('freelancer_period')
                                ->label('Masa Kontrak Kerja')
                                ->content(fn (callable $livewire) => $livewire->record?->freelancer?->start_date?->format('d M Y').' s/d '.$livewire->record?->freelancer?->end_date?->format('d M Y') ?? '-')
                                ->columnSpanFull(),
                        ]),
                    ]),

                // === SECTION: Profil Karyawan (editable) ===
                Section::make('Profil & Relasi Data Karyawan (Employee Profile)')
                    ->description('Tautkan atau buat data Karyawan (Badan Usaha, Divisi, Jabatan, NIP, dll.) untuk user ini.')
                    ->relationship('employee')
                    ->visible(fn (callable $livewire, Get $get): bool => static::canManageEmployeeProfile($livewire->record) && (bool) $get('has_employee_profile'))
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('company_id')
                                ->label('Badan Usaha (Company)')
                                ->options(fn (): array => static::placeableCompanyOptions())
                                ->required()
                                ->live()
                                ->afterStateUpdated(fn (Set $set) => $set('division_id', null))
                                ->default(fn (callable $livewire) => $livewire->record?->employee?->company_id ?? null),

                            Select::make('division_id')
                                ->label('Divisi Target')
                                ->options(fn (Get $get): array => static::placeableDivisionOptions($get('company_id')))
                                ->required()
                                ->default(fn (callable $livewire) => $livewire->record?->employee?->division_id ?? null),

                            Select::make('job_level_id')
                                ->label('Tingkat Jabatan (Job Level)')
                                ->options(JobLevel::query()->orderBy('level_order')->pluck('name', 'id'))
                                ->required()
                                ->default(fn (callable $livewire) => $livewire->record?->employee?->job_level_id ?? null),

                            Select::make('job_title_id')
                                ->label('Nama Jabatan (Job Title)')
                                ->options(JobTitle::query()->pluck('name', 'id'))
                                ->required()
                                ->default(fn (callable $livewire) => $livewire->record?->employee?->job_title_id ?? null),

                            TextInput::make('nip')
                                ->label('NIP Karyawan')
                                ->placeholder('Contoh: EMP-001')
                                ->required()
                                ->unique('employees', 'nip', ignoreRecord: true)
                                ->default(fn (callable $livewire) => $livewire->record?->employee?->nip ?? null),

                            TextInput::make('full_name')
                                ->label('Nama Lengkap Karyawan')
                                ->required()
                                ->default(fn (callable $livewire) => $livewire->record?->employee?->full_name ?? null),

                            DatePicker::make('join_date')
                                ->label('Tanggal Bergabung')
                                ->default(fn (callable $livewire) => $livewire->record?->employee?->join_date ?? today())
                                ->required(),

                            Select::make('status')
                                ->label('Status Kepegawaian')
                                ->options([
                                    'active' => 'Aktif',
                                    'inactive' => 'Non-Aktif',
                                    'resigned' => 'Resign',
                                ])
                                ->default(fn (callable $livewire) => $livewire->record?->employee?->status ?? 'active')
                                ->required(),
                        ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nama User')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('badan_usaha')
                    ->label('Badan Usaha')
                    ->placeholder('- Belum Linked -')
                    ->searchable(query: function ($query, string $search) {
                        $query->where(function ($q) use ($search) {
                            $q->whereHas('employee.company', fn ($q) => $q->where('name', 'like', "%{$search}%"))
                                ->orWhereHas('intern.company', fn ($q) => $q->where('name', 'like', "%{$search}%"))
                                ->orWhereHas('freelancer.company', fn ($q) => $q->where('name', 'like', "%{$search}%"));
                        });
                    })
                    ->state(fn (User $record): ?string => $record->employee?->company?->name
                        ?? $record->intern?->company?->name
                        ?? $record->freelancer?->company?->name),

                TextColumn::make('divisi')
                    ->label('Divisi')
                    ->placeholder('-')
                    ->state(fn (User $record): ?string => $record->employee?->division?->name
                        ?? $record->intern?->division?->name
                        ?? $record->freelancer?->division?->name),

                TextColumn::make('tipe_profil')
                    ->label('Tipe')
                    ->badge()
                    ->color(fn (User $record): string => match (static::detectProfileType($record)) {
                        'employee' => 'success',
                        'intern' => 'info',
                        'freelancer' => 'warning',
                        default => 'gray',
                    })
                    ->state(fn (User $record): string => match (static::detectProfileType($record)) {
                        'employee' => 'Karyawan',
                        'intern' => 'Magang',
                        'freelancer' => 'Freelancer',
                        default => 'Belum Linked',
                    }),

                TextColumn::make('data_access')
                    ->label('Akses Data')
                    ->badge()
                    ->color(fn (User $record): string => match (DataAccessService::scopeOf($record)) {
                        DataScope::All => 'danger',
                        DataScope::Companies => 'warning',
                        DataScope::Divisions => 'info',
                        DataScope::Own => 'gray',
                    })
                    ->state(fn (User $record): string => static::dataAccessSummary($record))
                    ->wrap(),

                BadgeColumn::make('roles.name')
                    ->label('Role Akses')
                    ->colors([
                        'danger' => 'Superadmin',
                        'warning' => 'Approver',
                        'info' => 'Employee',
                    ]),

                TextColumn::make('created_at')
                    ->label('Dibuat Pada')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->actions([
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
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
