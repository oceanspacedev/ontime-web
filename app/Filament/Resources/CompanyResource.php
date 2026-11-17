<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ScopesToManagedCompanies;
use App\Filament\Forms\Components\MapPickerField;
use App\Filament\Resources\CompanyResource\Pages;
use App\Models\Company;
use App\Services\CompanyPolicyService;
use App\Services\CompanyProvisioningService;
use App\Services\DataAccessService;
use App\Services\GeofenceService;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

class CompanyResource extends Resource
{
    use ScopesToManagedCompanies;

    protected static ?string $model = Company::class;

    protected static ?string $modelLabel = 'Badan Usaha';

    protected static ?string $pluralModelLabel = 'Badan Usaha';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static string|UnitEnum|null $navigationGroup = 'Struktur Organisasi';

    protected static ?int $navigationSort = 1;

    public const LIVENESS_DRIVER_MESSAGE = CompanyPolicyService::LIVENESS_DRIVER_MESSAGE;

    public static function canViewAny(): bool
    {
        return static::canManageCompanyData();
    }

    /**
     * Admin Badan Usaha edits their own companies; the Superadmin and the PIC of a Klien add and remove
     * them (the PIC only in their own Klien). A company with divisions or workers cannot be deleted.
     */
    public static function canCreate(): bool
    {
        $user = auth()->user();

        return $user !== null && DataAccessService::canCreateCompany($user);
    }

    public static function canDelete(Model $record): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        return $user->hasRole('Superadmin')
            || (DataAccessService::isTenantOwner($user) && $user->tenant_id !== null && (int) $record->getAttribute('tenant_id') === (int) $user->tenant_id);
    }

    public static function canDeleteAny(): bool
    {
        $user = auth()->user();

        return $user !== null && DataAccessService::canCreateCompany($user);
    }

    /**
     * The Klien a company form works in: the picked one for the Superadmin, the user's own otherwise.
     */
    public static function formTenantId(Get $get, ?Model $record = null): ?int
    {
        $user = auth()->user();

        if ($user?->hasRole('Superadmin')) {
            $picked = $get('tenant_id') ?? $record?->getAttribute('tenant_id');

            return filled($picked) ? (int) $picked : null;
        }

        return $user?->tenant_id;
    }

    /**
     * While GPS is required, a company left without an active location in the form keeps its HQ
     * coordinates: clearing them, or switching off or removing the last active location, is refused.
     */
    public static function needsHqCoordinates(Get $get, ?Company $record): bool
    {
        if (! $record?->policy?->require_gps) {
            return false;
        }

        $hasActiveLocation = collect($get('locations') ?? [])
            ->contains(fn (mixed $location): bool => is_array($location) && (bool) ($location['is_active'] ?? false));

        return ! $hasActiveLocation;
    }

    public static function getEloquentQuery(): Builder
    {
        return static::scopeToManagedCompanies(parent::getEloquentQuery(), 'companies.id');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('CompanyTabs')
                    ->tabs([
                        Tabs\Tab::make('Informasi Utama')
                            ->icon('heroicon-o-building-office')
                            ->schema([
                                Section::make('Informasi Badan Usaha')
                                    ->schema([
                                        Grid::make(2)->schema([
                                            Select::make('tenant_id')
                                                ->label('Klien')
                                                ->relationship('tenant', 'name')
                                                ->searchable()
                                                ->preload()
                                                ->placeholder('Tanpa klien')
                                                ->helperText('Badan usaha tanpa klien tidak terlihat oleh PIC mana pun.')
                                                ->live()
                                                ->visible(fn (): bool => (bool) auth()->user()?->hasRole('Superadmin'))
                                                ->columnSpanFull(),

                                            TextInput::make('name')
                                                ->label('Nama Badan Usaha')
                                                ->placeholder('Contoh: PT OnTime Indonesia')
                                                ->required()
                                                ->maxLength(255),

                                            TextInput::make('code')
                                                ->label('Kode Perusahaan')
                                                ->placeholder('Contoh: ONTIME')
                                                ->required()
                                                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get, ?Model $record): Unique => CompanyProvisioningService::uniqueCodeRule($rule, static::formTenantId($get, $record)))
                                                ->validationMessages(['unique' => CompanyProvisioningService::CODE_TAKEN_MESSAGE])
                                                ->maxLength(50),

                                            TextInput::make('email')
                                                ->label('Email Perusahaan')
                                                ->email()
                                                ->maxLength(255),

                                            TextInput::make('phone')
                                                ->label('Telepon Perusahaan')
                                                ->tel()
                                                ->maxLength(50),

                                            Toggle::make('is_active')
                                                ->label('Status Aktif')
                                                ->default(true),

                                            Textarea::make('address')
                                                ->label('Alamat Utama Perusahaan')
                                                ->rows(3)
                                                ->columnSpanFull(),
                                        ]),
                                    ]),

                                Section::make('Koordinat GPS Utama / HQ')
                                    ->description('Koordinat ini digunakan sebagai acuan kantor pusat jika lokasi cabang spesifik belum didaftarkan.')
                                    ->schema([
                                        Grid::make(2)->schema([
                                            TextInput::make('latitude')
                                                ->label('Latitude Kantor Utama')
                                                ->numeric()
                                                ->required(fn (Get $get, ?Company $record): bool => static::needsHqCoordinates($get, $record))
                                                ->validationMessages(['required' => CompanyPolicyService::GPS_WITHOUT_LOCATION_MESSAGE])
                                                ->placeholder('Contoh: -6.1753924'),

                                            TextInput::make('longitude')
                                                ->label('Longitude Kantor Utama')
                                                ->numeric()
                                                ->required(fn (Get $get, ?Company $record): bool => static::needsHqCoordinates($get, $record))
                                                ->validationMessages(['required' => CompanyPolicyService::GPS_WITHOUT_LOCATION_MESSAGE])
                                                ->placeholder('Contoh: 106.8271528'),
                                        ]),

                                        MapPickerField::make('hq_map_picker')
                                            ->label('Pilih Koordinat HQ di Peta')
                                            ->latField('latitude')
                                            ->lngField('longitude'),
                                    ])
                                    ->collapsible(),
                            ]),

                        Tabs\Tab::make('Lokasi & Cabang (Multi-Geofence)')
                            ->icon('heroicon-o-map-pin')
                            ->schema([
                                Section::make('Daftar Cabang & Site Operasional')
                                    ->description('Kelola seluruh lokasi kantor cabang, outlet, atau site operasional pendukung. Pengguna yang berada di salah satu lokasi aktif ini dapat melakukan absensi GPS.')
                                    ->schema([
                                        Repeater::make('locations')
                                            ->relationship('locations')
                                            ->itemLabel(fn (array $state): ?string => ! empty($state['name'])
                                                ? "Lokasi: {$state['name']}".(! empty($state['radius_meters']) ? " (Radius: {$state['radius_meters']}m)" : '')
                                                : 'Lokasi Cabang Baru'
                                            )
                                            ->collapsible()
                                            ->cloneable()
                                            ->schema([
                                                Grid::make(3)->schema([
                                                    TextInput::make('name')
                                                        ->label('Nama Cabang / Lokasi')
                                                        ->placeholder('Misal: Cabang Bandung / Outlet Mall')
                                                        ->required()
                                                        ->columnSpan(2),

                                                    Toggle::make('is_active')
                                                        ->label('Status Aktif')
                                                        ->default(true)
                                                        ->inline(false)
                                                        ->columnSpan(1),

                                                    TextInput::make('latitude')
                                                        ->label('Latitude')
                                                        ->numeric()
                                                        ->required()
                                                        ->placeholder('-6.1753924')
                                                        ->columnSpan(1),

                                                    TextInput::make('longitude')
                                                        ->label('Longitude')
                                                        ->numeric()
                                                        ->required()
                                                        ->placeholder('106.8271528')
                                                        ->columnSpan(1),

                                                    TextInput::make('radius_meters')
                                                        ->label('Radius Geofence (Meter)')
                                                        ->numeric()
                                                        ->minValue(1)
                                                        ->maxValue(CompanyPolicyService::MAX_GEOFENCE_RADIUS_METERS)
                                                        ->placeholder('Opsional (Ikuti kebijakan)')
                                                        ->columnSpan(1),

                                                    Textarea::make('address')
                                                        ->label('Alamat Cabang')
                                                        ->rows(2)
                                                        ->columnSpanFull(),

                                                    MapPickerField::make('map_picker')
                                                        ->label('Pilih Koordinat di Peta')
                                                        ->latField('latitude')
                                                        ->lngField('longitude'),
                                                ]),
                                            ])
                                            ->defaultItems(0)
                                            ->addActionLabel('Tambah Cabang / Lokasi Baru')
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('tenant.name')
                    ->label('Klien')
                    ->searchable()
                    ->sortable()
                    ->visible(fn (): bool => (bool) auth()->user()?->hasRole('Superadmin')),
                TextColumn::make('name')
                    ->label('Nama Perusahaan')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('code')
                    ->label('Kode')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable(),
                TextColumn::make('phone')
                    ->label('Telepon'),
                TextColumn::make('attendance_mode')
                    ->label('Mode Presensi')
                    ->badge()
                    ->color('gray')
                    ->state(fn (Company $record): string => $record->policy?->modeLabel() ?? 'Klik saja'),
                TextColumn::make('employees_count')
                    ->label('Jumlah Karyawan')
                    ->counts('employees')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('policy'))
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Status Aktif'),
            ])
            ->actions([
                Action::make('managePolicy')
                    ->label('Kebijakan Absensi')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->color('warning')
                    ->slideOver()
                    ->modalWidth(Width::Medium)
                    ->modalIcon('heroicon-o-shield-check')
                    ->modalHeading('Kebijakan Absensi')
                    ->modalDescription(fn (Company $record): string => $record->name)
                    ->modalSubmitActionLabel('Simpan Kebijakan')
                    ->successNotificationTitle('Kebijakan absensi disimpan')
                    ->fillForm(fn (Company $record): array => CompanyPolicyService::formState($record))
                    ->schema([
                        Section::make('Jam Masuk')
                            ->icon('heroicon-o-clock')
                            ->compact()
                            ->schema([
                                TextInput::make('late_tolerance_minutes')
                                    ->label('Toleransi Keterlambatan')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(CompanyPolicyService::MAX_LATE_TOLERANCE_MINUTES)
                                    ->suffix('menit')
                                    ->required()
                                    ->default(15),
                            ]),

                        Section::make('Lokasi')
                            ->icon('heroicon-o-map-pin')
                            ->compact()
                            ->schema([
                                Toggle::make('require_gps')
                                    ->label('Wajib Lokasi GPS')
                                    ->live()
                                    ->rules([
                                        fn (?Company $record): Closure => CompanyPolicyService::gpsRule($record),
                                    ])
                                    ->helperText(fn (?Company $record): ?string => $record === null || GeofenceService::hasConfiguredLocations($record)
                                        ? null
                                        : 'Isi koordinat HQ atau cabang aktif dulu.')
                                    ->default(false),

                                TextInput::make('geofence_radius_meters')
                                    ->label('Radius Geofence')
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(CompanyPolicyService::MAX_GEOFENCE_RADIUS_METERS)
                                    ->suffix('meter')
                                    ->placeholder('100')
                                    ->visible(fn (Get $get) => (bool) $get('require_gps'))
                                    ->required(fn (Get $get) => (bool) $get('require_gps')),

                                Select::make('mock_location_action')
                                    ->label('Jika Lokasi Palsu Terdeteksi')
                                    ->native(false)
                                    ->options(static::mockLocationActionOptions())
                                    ->default('reject')
                                    ->visible(fn (Get $get) => (bool) $get('require_gps'))
                                    ->required(fn (Get $get) => (bool) $get('require_gps')),
                            ]),

                        Section::make('Verifikasi Selfie')
                            ->icon('heroicon-o-camera')
                            ->compact()
                            ->schema([
                                Radio::make('selfie_mode')
                                    ->hiddenLabel()
                                    ->options(static::selfieModeOptions())
                                    ->descriptions(fn (): array => static::selfieModeDescriptions())
                                    ->disableOptionWhen(fn (string $value): bool => $value === 'liveness' && ! CompanyPolicyService::supportsLiveness())
                                    ->rules([
                                        fn (): Closure => CompanyPolicyService::livenessRule(),
                                    ])
                                    ->in(array_keys(static::selfieModeOptions()))
                                    ->required()
                                    ->default('none')
                                    ->live(),

                                // Older callers send the stored flags instead of selfie_mode. They are
                                // never filled, so they only carry a value when a caller sends them.
                                Hidden::make('require_photo'),
                                Hidden::make('require_face_recognition'),
                                Hidden::make('require_liveness')
                                    ->rules([
                                        fn (): Closure => CompanyPolicyService::livenessFlagRule(),
                                    ]),

                                TextInput::make('face_match_threshold')
                                    ->label('Ambang Kemiripan Wajah')
                                    ->numeric()
                                    ->suffix('%')
                                    ->minValue(1)
                                    ->maxValue(100)
                                    ->default(60)
                                    ->visible(fn (Get $get) => static::usesFaceMatch($get('selfie_mode')))
                                    ->required(fn (Get $get) => static::usesFaceMatch($get('selfie_mode'))),

                                Select::make('face_fail_action')
                                    ->label('Jika Wajah Tidak Cocok')
                                    ->native(false)
                                    ->options(CompanyPolicyService::faceFailActionOptions())
                                    ->default('reject')
                                    ->visible(fn (Get $get) => static::usesFaceMatch($get('selfie_mode')))
                                    ->required(fn (Get $get) => static::usesFaceMatch($get('selfie_mode'))),
                            ]),

                        Section::make('Cuti & Approval')
                            ->icon('heroicon-o-calendar-days')
                            ->compact()
                            ->columns(2)
                            ->schema([
                                TextInput::make('annual_leave_quota')
                                    ->label('Kuota Cuti Tahunan')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(CompanyPolicyService::MAX_ANNUAL_LEAVE_QUOTA)
                                    ->suffix('hari')
                                    ->required()
                                    ->default(12),

                                TextInput::make('default_approval_stages')
                                    ->label('Tahap Approval')
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(CompanyPolicyService::MAX_APPROVAL_STAGES)
                                    ->suffix('tahap')
                                    ->required()
                                    ->default(1),
                            ]),
                    ])
                    ->action(function (Company $record, array $data): void {
                        CompanyPolicyService::save($record, $data);
                    }),

                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->authorizeIndividualRecords(),
                ]),
            ]);
    }

    /**
     * Turn the form's selfie mode into the stored requirement flags (see CompanyPolicyService::normalize()).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeAttendancePolicy(array $data): array
    {
        return CompanyPolicyService::normalize($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function preferLegacySelfieFlags(array $data): array
    {
        return CompanyPolicyService::preferLegacySelfieFlags($data);
    }

    /**
     * @return array<string, string>
     */
    public static function selfieModeOptions(): array
    {
        return CompanyPolicyService::selfieModeOptions();
    }

    /**
     * @return array<string, string>
     */
    public static function mockLocationActionOptions(): array
    {
        return CompanyPolicyService::mockLocationActionOptions();
    }

    /**
     * @return array<string, string>
     */
    public static function selfieModeDescriptions(): array
    {
        return CompanyPolicyService::selfieModeWarnings();
    }

    public static function usesFaceMatch(mixed $selfieMode): bool
    {
        return CompanyPolicyService::usesFaceMatch($selfieMode);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCompanies::route('/'),
            'create' => Pages\CreateCompany::route('/create'),
            'edit' => Pages\EditCompany::route('/{record}/edit'),
        ];
    }
}
