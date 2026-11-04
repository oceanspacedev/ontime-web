<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ScopesToManagedCompanies;
use App\Filament\Resources\InternResource\Pages;
use App\Filament\Resources\ShiftSchedules\ShiftScheduleResource;
use App\Models\Employee;
use App\Models\Intern;
use App\Models\User;
use App\Services\WhatsAppNotificationService;
use BackedEnum;
use Filament\Actions\Action;
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
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use UnitEnum;

class InternResource extends Resource
{
    use ScopesToManagedCompanies;

    protected static ?string $model = Intern::class;

    protected static ?string $modelLabel = 'Magang';

    protected static ?string $pluralModelLabel = 'Data Magang';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-academic-cap';

    protected static string|UnitEnum|null $navigationGroup = 'Manajemen SDM';

    protected static ?int $navigationSort = 2;

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
                Section::make('Informasi Peserta Magang')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('nis')
                                ->label('NIS / ID Magang')
                                ->required()
                                ->unique(ignoreRecord: true)
                                ->maxLength(50),

                            TextInput::make('full_name')
                                ->label('Nama Lengkap')
                                ->required()
                                ->maxLength(255),

                            TextInput::make('institution')
                                ->label('Asal Sekolah / Kampus (Institusi)')
                                ->required()
                                ->maxLength(255),

                            Select::make('company_id')
                                ->label('Badan Usaha (Company)')
                                ->options(fn (): array => static::placeableCompanyOptions())
                                ->helperText('Otomatis terisi jika Mentor Karyawan dipilih.')
                                ->required()
                                ->live()
                                ->afterStateUpdated(function ($set): void {
                                    $set('division_id', null);
                                    $set('shift_id', null);
                                }),

                            Select::make('division_id')
                                ->label('Divisi')
                                ->options(fn (Get $get): array => static::placeableDivisionOptions($get('company_id')))
                                ->helperText('Otomatis terisi jika Mentor Karyawan dipilih.')
                                ->required(),

                            Select::make('shift_id')
                                ->label('Shift Default')
                                ->options(fn (Get $get, ?Intern $record): array => ShiftScheduleResource::shiftOptions($get('company_id')) + ($record?->shift ? [$record->shift_id => $record->shift->label()] : []))
                                ->placeholder('Ikut jam kerja badan usaha')
                                ->helperText('Dipakai bila tidak ada jadwal shift pada tanggal tersebut.')
                                ->nullable(),

                            Select::make('mentor_id')
                                ->label('Pembimbing / Mentor (Karyawan)')
                                ->relationship('mentor', 'full_name', function ($query, Get $get, ?Intern $record) {
                                    // Keep the currently assigned person selectable even after they resign,
                                    // otherwise existing records fail validation on save.
                                    $query->where(function ($available) use ($record) {
                                        $available->whereNotIn('status', ['inactive', 'resigned']);

                                        if ($record?->mentor_id) {
                                            $available->orWhere('id', $record->mentor_id);
                                        }
                                    });

                                    if ($companyId = $get('company_id')) {
                                        $query->where('company_id', $companyId);
                                    }

                                    static::scopeToManagedWorkers($query);
                                })
                                ->getOptionLabelFromRecordUsing(fn (Employee $record) => "{$record->nip} - {$record->full_name}")
                                ->helperText('Memilih Mentor akan otomatis mengeset Badan Usaha & Divisi.')
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
                                ->nullable(),

                            TextInput::make('email')
                                ->label('Email')
                                ->email()
                                ->maxLength(255),

                            TextInput::make('phone')
                                ->label('Telepon')
                                ->tel()
                                ->maxLength(50),

                            DatePicker::make('start_date')
                                ->label('Tanggal Mulai Magang')
                                ->native(false),

                            DatePicker::make('end_date')
                                ->label('Tanggal Selesai Magang')
                                ->native(false),

                            Select::make('status')
                                ->label('Status Magang')
                                ->options([
                                    'active' => 'Aktif',
                                    'completed' => 'Selesai',
                                    'extended' => 'Diperpanjang',
                                    'terminated' => 'Berhenti',
                                ])
                                ->default('active')
                                ->required(),

                            Select::make('user_id')
                                ->label('Akun User Sistem (Opsional)')
                                ->placeholder('Pilih Akun User')
                                ->options(fn (?Intern $record): array => static::assignableUserOptions() + ($record?->user ? [$record->user->getKey() => $record->user->name] : []))
                                ->searchable()
                                ->nullable(),

                            ViewField::make('master_face_photo')
                                ->label('Foto Master Wajah (Face Recognition)')
                                ->view('filament.components.master-face-capture')
                                ->columnSpanFull()
                                ->helperText('Pilih foto dari penyimpanan atau ambil foto via kamera langsung sebagai referensi Face Recognition.'),
                        ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nis')
                    ->label('NIS / ID')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('full_name')
                    ->label('Nama Lengkap')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('institution')
                    ->label('Sekolah / Kampus')
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

                TextColumn::make('mentor.full_name')
                    ->label('Mentor')
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
                        'completed' => 'Selesai',
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

                SelectFilter::make('mentor_id')
                    ->label('Mentor (Karyawan)')
                    ->relationship('mentor', 'full_name'),

                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'active' => 'Aktif',
                        'completed' => 'Selesai',
                        'extended' => 'Diperpanjang',
                        'terminated' => 'Berhenti',
                    ]),
            ])
            ->actions([
                Action::make('createUser')
                    ->label('Buat Akun')
                    ->icon('heroicon-o-user-plus')
                    ->color('success')
                    ->visible(fn (Intern $record): bool => ! $record->user_id)
                    ->modalHeading(fn (Intern $record) => "Buat Akun User untuk {$record->full_name}")
                    ->modalDescription('Tinjau kredensial dan role yang akan dibuat untuk akun peserta magang ini.')
                    ->schema([
                        TextInput::make('email')
                            ->label('Alamat Email Login')
                            ->email()
                            ->default(fn (Intern $record) => $record->email ?: strtolower($record->nis).'@magang.local')
                            ->required(),
                        Select::make('role')
                            ->label('Role Akses')
                            ->options(fn (): array => static::assignableRoleOptions())
                            ->default('Intern')
                            ->required(),
                        TextInput::make('password')
                            ->label('Password Awal')
                            ->default('password123')
                            ->required(),
                    ])
                    ->action(function (Intern $record, array $data) {
                        $email = $data['email'];
                        $roleName = $data['role'] ?? 'Intern';
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
                    ->visible(fn (Intern $record): bool => (bool) $record->user_id && static::canManageAccount($record->user))
                    ->requiresConfirmation()
                    ->modalHeading(fn (Intern $record) => "Hapus Akun User {$record->full_name}")
                    ->modalDescription('Apakah Anda yakin ingin menghapus akun user ini? Pengguna ini tidak akan dapat login lagi ke sistem.')
                    ->action(function (Intern $record) {
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
                    ->modalHeading('Surat Keterangan Magang')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup')
                    ->modalContent(fn (Intern $record) => view('filament.modals.print-intern', ['record' => $record])),

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
            'index' => Pages\ListInterns::route('/'),
            'create' => Pages\CreateIntern::route('/create'),
            'edit' => Pages\EditIntern::route('/{record}/edit'),
        ];
    }
}
