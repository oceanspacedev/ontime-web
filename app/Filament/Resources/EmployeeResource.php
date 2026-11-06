<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ScopesToManagedCompanies;
use App\Filament\Forms\Components\MasterFacePhotoField;
use App\Filament\Resources\EmployeeResource\Pages;
use App\Filament\Resources\ShiftSchedules\ShiftScheduleResource;
use App\Models\Company;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\Shift;
use App\Models\User;
use App\Services\AnnualLeaveService;
use App\Services\WhatsAppNotificationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
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
use Filament\Schemas\Schema;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use UnitEnum;

class EmployeeResource extends Resource
{
    use ScopesToManagedCompanies;

    protected static ?string $model = Employee::class;

    protected static ?string $modelLabel = 'Karyawan';

    protected static ?string $pluralModelLabel = 'Data Karyawan';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static string|UnitEnum|null $navigationGroup = 'Manajemen SDM';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return static::canManageWorkerData() && ! static::isCoordinator();
    }

    public static function getEloquentQuery(): Builder
    {
        return static::scopeToManagedWorkers(parent::getEloquentQuery()->with(['company.policy', 'user.roles', 'user.employee', 'user.intern', 'user.freelancer']));
    }

    public static function canImportFromTalenta(): bool
    {
        return static::canManageCompanyData();
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
                                    TextInput::make('nip')
                                        ->label('NIP')
                                        ->required()
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
                                ]),

                            Section::make('Penempatan')
                                ->icon('heroicon-o-building-office-2')
                                ->columns(2)
                                ->schema([
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
                                        ->live()
                                        ->afterStateUpdated(fn ($set) => $set('job_title_id', null))
                                        ->required(),

                                    Select::make('job_level_id')
                                        ->label('Level Jabatan')
                                        ->options(JobLevel::query()->orderBy('level_order')->pluck('name', 'id'))
                                        ->required(),

                                    Select::make('job_title_id')
                                        ->label('Posisi')
                                        ->options(function (Get $get) {
                                            $divisionId = $get('division_id');
                                            if (! $divisionId) {
                                                return JobTitle::query()->pluck('name', 'id');
                                            }

                                            return JobTitle::query()
                                                ->where('division_id', $divisionId)
                                                ->orWhereNull('division_id')
                                                ->pluck('name', 'id');
                                        })
                                        ->searchable()
                                        ->required(),

                                    Select::make('shift_id')
                                        ->label('Shift Default')
                                        ->options(fn (Get $get, ?Employee $record): array => ShiftScheduleResource::shiftOptions($get('company_id')) + ($record?->shift ? [$record->shift_id => $record->shift->label()] : []))
                                        ->placeholder('Ikut jam kerja badan usaha')
                                        ->nullable()
                                        ->columnSpanFull(),
                                ]),

                            Section::make('Kepegawaian')
                                ->icon('heroicon-o-calendar-days')
                                ->columns(2)
                                ->schema([
                                    DatePicker::make('join_date')
                                        ->label('Tanggal Bergabung')
                                        ->native(false),

                                    Select::make('status')
                                        ->label('Status')
                                        ->options([
                                            'active' => 'Aktif',
                                            'inactive' => 'Non-Aktif',
                                            'resigned' => 'Resign',
                                        ])
                                        ->default('active')
                                        ->required(),

                                    Toggle::make('has_annual_leave')
                                        ->label('Berhak cuti tahunan')
                                        ->default(false)
                                        ->inline(false)
                                        ->live(),

                                    TextInput::make('annual_leave_quota')
                                        ->label('Kuota Cuti')
                                        ->numeric()
                                        ->integer()
                                        ->minValue(0)
                                        ->maxValue(365)
                                        ->suffix('hari')
                                        ->nullable()
                                        ->placeholder(fn (Get $get): string => 'Ikut badan usaha ('.static::companyAnnualLeaveQuota($get('company_id')).')')
                                        ->visible(fn (Get $get): bool => (bool) $get('has_annual_leave')),
                                ]),

                            Section::make('Data Pribadi')
                                ->icon('heroicon-o-identification')
                                ->collapsible()
                                ->columns(2)
                                ->schema([
                                    Select::make('gender')
                                        ->label('Jenis Kelamin')
                                        ->options(['Male' => 'Laki-laki', 'Female' => 'Perempuan']),

                                    DatePicker::make('birth_date')
                                        ->label('Tanggal Lahir')
                                        ->native(false),

                                    TextInput::make('marital_status')
                                        ->label('Status Pernikahan')
                                        ->datalist(['Single', 'Married', 'Widow', 'Widower', 'Divorced'])
                                        ->maxLength(50),

                                    TextInput::make('tax_status')
                                        ->label('Status Pajak (PTKP)')
                                        ->datalist(['TK/0', 'TK/1', 'TK/2', 'TK/3', 'K/0', 'K/1', 'K/2', 'K/3'])
                                        ->maxLength(20),

                                    TextInput::make('talenta_id')
                                        ->label('ID Talenta')
                                        ->placeholder('Dari import Talenta')
                                        ->readOnly(),
                                ]),
                        ])->columnSpan(['lg' => 2]),

                        Group::make([
                            Section::make('Akun')
                                ->icon('heroicon-o-key')
                                ->schema([
                                    Select::make('user_id')
                                        ->label('Akun User')
                                        ->placeholder('Belum ada akun')
                                        ->options(fn (?Employee $record): array => static::assignableUserOptions() + ($record?->user ? [$record->user->getKey() => $record->user->name] : []))
                                        ->searchable()
                                        ->nullable(),
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
                ImageColumn::make('avatar_url')
                    ->label('')
                    ->circular()
                    ->imageSize(32)
                    ->toggleable(),

                TextColumn::make('nip')
                    ->label('NIP')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('full_name')
                    ->label('Nama Lengkap')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('company.name')
                    ->label('Perusahaan')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('division.name')
                    ->label('Divisi')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('jobLevel.name')
                    ->label('Level Jabatan')
                    ->sortable(),

                TextColumn::make('jobTitle.name')
                    ->label('Posisi')
                    ->searchable(),

                BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'success' => 'active',
                        'danger' => 'inactive',
                        'gray' => 'resigned',
                    ])
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'active' => 'Aktif',
                        'inactive' => 'Non-Aktif',
                        'resigned' => 'Resign',
                        default => ucfirst($state),
                    }),

                IconColumn::make('has_annual_leave')
                    ->label('Cuti Tahunan')
                    ->boolean()
                    ->tooltip(fn (Employee $record): string => $record->has_annual_leave
                        ? 'Kuota '.($record->annual_leave_quota ?? $record->company?->policy?->annual_leave_quota ?? AnnualLeaveService::DEFAULT_QUOTA).' hari per tahun'
                        : 'Tidak berhak cuti tahunan')
                    ->toggleable(),

                TextColumn::make('offboarding_status')
                    ->label('Pengunduran Diri')
                    ->state(function (Employee $record) {
                        $latest = $record->resignations()->latest()->first();
                        if (! $latest) {
                            return '-';
                        }

                        return match ($latest->status) {
                            'pending' => 'Proses Resign',
                            'approved' => 'Resign Disetujui',
                            'rejected' => 'Resign Ditolak',
                            default => '-',
                        };
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Proses Resign' => 'warning',
                        'Resign Disetujui' => 'danger',
                        'Resign Ditolak' => 'secondary',
                        default => 'gray',
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

                SelectFilter::make('job_level_id')
                    ->label('Level Jabatan')
                    ->relationship('jobLevel', 'name'),

                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'active' => 'Aktif',
                        'inactive' => 'Non-Aktif',
                        'resigned' => 'Resign',
                    ])
                    // Most rows are resigned history; start with the people who still work here.
                    ->default('active'),
            ])
            ->actions([
                Action::make('createUser')
                    ->label('Buat Akun')
                    ->icon('heroicon-o-user-plus')
                    ->color('success')
                    ->visible(fn (Employee $record): bool => ! $record->user_id)
                    ->modalHeading(fn (Employee $record) => "Buat Akun User untuk {$record->full_name}")
                    ->modalDescription('Tinjau kredensial dan role yang akan dibuat untuk akun karyawan ini.')
                    ->schema([
                        TextInput::make('email')
                            ->label('Alamat Email Login')
                            ->email()
                            ->default(fn (Employee $record) => $record->email ?: strtolower($record->nip).'@employee.local')
                            ->required(),
                        Select::make('role')
                            ->label('Role Akses')
                            ->options(fn (): array => static::assignableRoleOptions())
                            ->default('Employee')
                            ->required(),
                        TextInput::make('password')
                            ->label('Password Awal')
                            ->default(fn (): string => Str::password(10, symbols: false))
                            ->helperText('Dibuat acak dan dikirim lewat WhatsApp bila nomor tersedia.')
                            ->required(),
                    ])
                    ->action(function (Employee $record, array $data) {
                        $email = $data['email'];
                        $roleName = $data['role'] ?? 'Employee';
                        $password = $data['password'];

                        if (User::where('email', $email)->exists()) {
                            Notification::make()
                                ->title('Gagal Membuat Akun')
                                ->body("Email {$email} sudah digunakan oleh akun user lain.")
                                ->danger()
                                ->send();

                            return;
                        }

                        $user = User::create([
                            'name' => $record->full_name,
                            'email' => $email,
                            'phone' => $record->phone,
                            'password' => Hash::make($password),
                        ]);

                        abort_unless(array_key_exists($roleName, static::assignableRoleOptions()), 403);

                        if ($role = Role::firstOrCreate(['name' => $roleName])) {
                            $user->assignRole($role);
                        }

                        $record->update(['user_id' => $user->id]);

                        $waResult = WhatsAppNotificationService::sendAccountCredentials(
                            $record->full_name,
                            $record->phone,
                            $email,
                            $password,
                            $roleName
                        );

                        $waStatusNote = $waResult['api_sent']
                            ? "\n✅ Notifikasi WhatsApp telah otomatis terkirim via WAG Gateway."
                            : ($waResult['api_error'] ? "\n⚠️ WAG Gateway: {$waResult['api_error']}" : "\n⚠️ Nomor HP belum diisi.");

                        $notification = Notification::make()
                            ->title('Akun User Berhasil Dibuat!')
                            ->body("Kredensial Login {$record->full_name} (Role: {$roleName}):\nEmail: {$email}\nNo. HP: ".($record->phone ?? '-')."\nPassword: {$password}\nMetode Login: Email + Password atau WhatsApp OTP{$waStatusNote}")
                            ->success();

                        if (! $waResult['api_sent'] && ! empty($waResult['wa_url'])) {
                            $notification->actions([
                                Action::make('send_wa')
                                    ->label('Kirim via WA Web/App (Fallback)')
                                    ->url($waResult['wa_url'], shouldOpenInNewTab: true)
                                    ->button()
                                    ->color('warning'),
                            ]);
                        }

                        $notification->send();
                    }),
                Action::make('deleteUser')
                    ->label('Hapus Akun')
                    ->icon('heroicon-o-user-minus')
                    ->color('danger')
                    ->visible(fn (Employee $record): bool => (bool) $record->user_id && static::canManageAccount($record->user))
                    ->requiresConfirmation()
                    ->modalHeading(fn (Employee $record) => "Hapus Akun User {$record->full_name}")
                    ->modalDescription('Apakah Anda yakin ingin menghapus akun user ini? Pengguna ini tidak akan dapat login lagi ke sistem.')
                    ->action(function (Employee $record) {
                        $user = $record->user;
                        $record->update(['user_id' => null]);
                        $user?->delete();

                        Notification::make()
                            ->title('Akun User Berhasil Dihapus!')
                            ->body("Akun user untuk {$record->full_name} telah dihapus dari sistem.")
                            ->success()
                            ->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('assignDefaultShift')
                        ->label('Atur Shift Default')
                        ->icon('heroicon-o-clock')
                        ->schema([
                            Select::make('shift_id')
                                ->label('Shift')
                                ->options(fn (): array => static::scopeToPlaceableCompanies(Shift::query()->active()->with('company'))
                                    ->get()
                                    ->mapWithKeys(fn (Shift $shift): array => [$shift->id => "{$shift->company->name} - {$shift->label()}"])
                                    ->all())
                                ->placeholder('Kosongkan untuk ikut jam kerja badan usaha')
                                ->searchable(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $shift = filled($data['shift_id'] ?? null) ? Shift::query()->find($data['shift_id']) : null;
                            abort_if($shift && ! static::canPlaceInCompany($shift->company_id), 403);

                            // A shift belongs to one company, so only employees of that company receive it.
                            $targets = $records->filter(fn (Employee $employee): bool => $shift ? (int) $employee->company_id === (int) $shift->company_id : true);
                            Employee::query()->whereKey($targets->modelKeys())->update(['shift_id' => $shift?->id]);

                            $skipped = $records->count() - $targets->count();

                            Notification::make()
                                ->title('Shift default diperbarui')
                                ->body("{$targets->count()} karyawan diperbarui".($skipped > 0 ? ", {$skipped} dilewati karena beda badan usaha dengan shift." : '.'))
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('setAnnualLeave')
                        ->label('Atur Cuti Tahunan')
                        ->icon('heroicon-o-calendar-days')
                        ->schema([
                            Toggle::make('has_annual_leave')
                                ->label('Berhak cuti tahunan')
                                ->default(true)
                                ->live(),
                            TextInput::make('annual_leave_quota')
                                ->label('Kuota per tahun (hari)')
                                ->numeric()
                                ->integer()
                                ->minValue(0)
                                ->maxValue(365)
                                ->nullable()
                                ->helperText('Kosongkan untuk memakai kuota badan usaha masing-masing karyawan.')
                                ->visible(fn (Get $get): bool => (bool) $get('has_annual_leave')),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $eligible = (bool) ($data['has_annual_leave'] ?? false);
                            $quota = $eligible && filled($data['annual_leave_quota'] ?? null) ? (int) $data['annual_leave_quota'] : null;

                            Employee::query()->whereKey($records->modelKeys())->update([
                                'has_annual_leave' => $eligible,
                                'annual_leave_quota' => $quota,
                            ]);

                            Notification::make()
                                ->title('Cuti tahunan diperbarui')
                                ->body($eligible
                                    ? "{$records->count()} karyawan kini berhak cuti tahunan".($quota !== null ? " dengan kuota {$quota} hari." : ' dengan kuota badan usaha.')
                                    : "{$records->count()} karyawan tidak lagi berhak cuti tahunan.")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Annual leave quota of a company, shown as the default when an employee has no own quota.
     */
    protected static function companyAnnualLeaveQuota(mixed $companyId): int
    {
        $policyQuota = filled($companyId) ? Company::query()->with('policy')->find($companyId)?->policy?->annual_leave_quota : null;

        return $policyQuota ?? AnnualLeaveService::DEFAULT_QUOTA;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmployees::route('/'),
            'create' => Pages\CreateEmployee::route('/create'),
            'edit' => Pages\EditEmployee::route('/{record}/edit'),
        ];
    }
}
