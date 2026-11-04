<?php

namespace App\Filament\Resources\Users;

use App\Enums\SystemRole;
use App\Filament\Concerns\ScopesToManagedCompanies;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Division;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\User;
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
use Spatie\Permission\Models\Role;
use UnitEnum;

class UserResource extends Resource
{
    use ScopesToManagedCompanies;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with([
                'roles',
                'employee.company',
                'employee.division',
                'intern.company',
                'intern.division',
                'freelancer.company',
                'freelancer.division',
            ]);

        $user = auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasRole('Superadmin')) {
            return $query;
        }

        // Admin Badan Usaha: accounts of workers in their companies, never Superadmin/Admin accounts or their own.
        $managed = $user->managedCompanyIds() ?? [];

        return $query
            ->whereKeyNot($user->getKey())
            ->whereDoesntHave('roles', fn (Builder $roles) => $roles->whereIn('name', [SystemRole::Superadmin->value, SystemRole::Admin->value]))
            ->where(fn (Builder $scope) => $scope
                ->whereHas('employee', fn (Builder $worker) => $worker->whereIn('company_id', $managed))
                ->orWhereHas('intern', fn (Builder $worker) => $worker->whereIn('company_id', $managed))
                ->orWhereHas('freelancer', fn (Builder $worker) => $worker->whereIn('company_id', $managed)));
    }

    /**
     * Deteksi tipe profil yang terhubung ke user ini.
     * Mengembalikan: 'employee' | 'intern' | 'freelancer' | null
     */
    /**
     * Interns and freelancers are managed from their own menus, so the employee section is hidden for them.
     */
    /**
     * @param  array<int|string>|null  $roleIds
     */
    protected static function selectsAdminRole(?array $roleIds): bool
    {
        $adminRoleId = Role::query()->where('name', SystemRole::Admin->value)->value('id');

        return $adminRoleId !== null && in_array((string) $adminRoleId, array_map('strval', $roleIds ?? []), true);
    }

    protected static function canManageEmployeeProfile(?User $user): bool
    {
        if ($user === null) {
            return true;
        }

        return ! in_array(static::detectProfileType($user), ['intern', 'freelancer'], true);
    }

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
        return static::canManageCompanyData();
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

                            Select::make('managedCompanies')
                                ->label('Akses Badan Usaha')
                                ->helperText('Badan usaha yang dikelola penuh oleh Admin ini: karyawan, absensi, shift, pengajuan, laporan, dan akun user.')
                                ->relationship('managedCompanies', 'name')
                                ->multiple()
                                ->preload()
                                ->required(fn (Get $get): bool => static::selectsAdminRole($get('roles')))
                                ->visible(fn (Get $get): bool => (bool) auth()->user()?->hasRole('Superadmin') && static::selectsAdminRole($get('roles')))
                                ->columnSpanFull(),

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
                                ->options(fn (): array => static::managedCompanyOptions())
                                ->required()
                                ->live()
                                ->afterStateUpdated(fn (Set $set) => $set('division_id', null))
                                ->default(fn (callable $livewire) => $livewire->record?->employee?->company_id ?? null),

                            Select::make('division_id')
                                ->label('Divisi Target')
                                ->options(function (Get $get) {
                                    $companyId = $get('company_id');
                                    if (! $companyId) {
                                        return [];
                                    }

                                    return Division::query()
                                        ->where('company_id', $companyId)
                                        ->where('is_active', true)
                                        ->pluck('name', 'id');
                                })
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
