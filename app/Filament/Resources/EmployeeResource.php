<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ScopesToManagedCompanies;
use App\Filament\Resources\EmployeeResource\Pages;
use App\Filament\Resources\ShiftSchedules\ShiftScheduleResource;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\Shift;
use App\Models\User;
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
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Hash;
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
        return static::canManageCompanyData();
    }

    public static function getEloquentQuery(): Builder
    {
        return static::scopeToManagedCompanies(parent::getEloquentQuery()->with('user.roles'));
    }

    public static function canImportFromTalenta(): bool
    {
        return static::canManageCompanyData();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informasi Karyawan')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('nip')
                                ->label('NIP / Nomor Induk Pegawai')
                                ->required()
                                ->unique(ignoreRecord: true)
                                ->maxLength(50),

                            TextInput::make('full_name')
                                ->label('Nama Lengkap')
                                ->required()
                                ->maxLength(255),

                            Select::make('company_id')
                                ->label('Badan Usaha (Company)')
                                ->options(fn (): array => static::managedCompanyOptions())
                                ->required()
                                ->live()
                                ->afterStateUpdated(function ($set): void {
                                    $set('division_id', null);
                                    $set('shift_id', null);
                                }),

                            Select::make('division_id')
                                ->label('Divisi')
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
                                ->live()
                                ->afterStateUpdated(fn ($set) => $set('job_title_id', null))
                                ->required(),

                            Select::make('job_level_id')
                                ->label('Level Jabatan')
                                ->options(JobLevel::query()->orderBy('level_order')->pluck('name', 'id'))
                                ->required(),

                            Select::make('job_title_id')
                                ->label('Posisi / Job Title')
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
                                ->helperText('Dipakai bila tidak ada jadwal shift pada tanggal tersebut.')
                                ->nullable(),

                            TextInput::make('email')
                                ->label('Email')
                                ->email()
                                ->maxLength(255),

                            TextInput::make('phone')
                                ->label('Telepon')
                                ->tel()
                                ->maxLength(50),

                            DatePicker::make('join_date')
                                ->label('Tanggal Bergabung')
                                ->native(false),

                            Select::make('status')
                                ->label('Status Karyawan')
                                ->options([
                                    'active' => 'Aktif',
                                    'inactive' => 'Non-Aktif',
                                    'resigned' => 'Resign',
                                ])
                                ->default('active')
                                ->required(),

                            Select::make('user_id')
                                ->label('Akun User Sistem (Opsional)')
                                ->placeholder('Pilih Akun User')
                                ->options(fn (?Employee $record): array => static::assignableUserOptions() + ($record?->user ? [$record->user->getKey() => $record->user->name] : []))
                                ->searchable()
                                ->nullable(),

                            ViewField::make('master_face_photo')
                                ->label('Foto Master Wajah (Face Recognition)')
                                ->view('filament.components.master-face-capture')
                                ->columnSpanFull()
                                ->helperText('Pilih foto dari penyimpanan atau ambil foto via kamera langsung sebagai referensi Face Recognition.'),
                        ]),
                    ]),

                Section::make('Data Pribadi')
                    ->description('Ikut terisi dari import Talenta.')
                    ->collapsible()
                    ->schema([
                        Grid::make(2)->schema([
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
                                ->helperText('Diisi otomatis oleh import Talenta.')
                                ->readOnly(),
                        ]),
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
                    ->relationship('company', 'name'),

                SelectFilter::make('division_id')
                    ->label('Divisi')
                    ->relationship('division', 'name'),

                SelectFilter::make('job_level_id')
                    ->label('Level Jabatan')
                    ->relationship('jobLevel', 'name'),

                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'active' => 'Aktif',
                        'inactive' => 'Non-Aktif',
                        'resigned' => 'Resign',
                    ]),
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
                            ->default('password123')
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
                                ->options(fn (): array => static::scopeToManagedCompanies(Shift::query()->active()->with('company'))
                                    ->get()
                                    ->mapWithKeys(fn (Shift $shift): array => [$shift->id => "{$shift->company->name} - {$shift->label()}"])
                                    ->all())
                                ->placeholder('Kosongkan untuk ikut jam kerja badan usaha')
                                ->searchable(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $shift = filled($data['shift_id'] ?? null) ? Shift::query()->find($data['shift_id']) : null;
                            abort_if($shift && ! auth()->user()?->canManageCompany($shift->company_id), 403);

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

                    DeleteBulkAction::make(),
                ]),
            ]);
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
