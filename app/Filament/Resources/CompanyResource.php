<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ScopesToManagedCompanies;
use App\Filament\Forms\Components\MapPickerField;
use App\Filament\Resources\CompanyResource\Pages;
use App\Models\Company;
use App\Models\CompanyPolicy;
use App\Services\Face\FaceVerifier;
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

    public const LIVENESS_DRIVER_MESSAGE = 'Liveness butuh CompreFace (FACE_DRIVER=compreface).';

    public static function canViewAny(): bool
    {
        return static::canManageCompanyData();
    }

    /**
     * Admin Badan Usaha edits their own companies; only Superadmin adds or removes companies.
     */
    public static function canCreate(): bool
    {
        return auth()->user()?->hasRole('Superadmin') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->hasRole('Superadmin') ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()?->hasRole('Superadmin') ?? false;
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
                                            TextInput::make('name')
                                                ->label('Nama Badan Usaha')
                                                ->placeholder('Contoh: PT OnTime Indonesia')
                                                ->required()
                                                ->maxLength(255),

                                            TextInput::make('code')
                                                ->label('Kode Perusahaan')
                                                ->placeholder('Contoh: ONTIME')
                                                ->required()
                                                ->unique(ignoreRecord: true)
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
                                                ->placeholder('Contoh: -6.1753924'),

                                            TextInput::make('longitude')
                                                ->label('Longitude Kantor Utama')
                                                ->numeric()
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
                    ->fillForm(fn (Company $record): array => [
                        'late_tolerance_minutes' => $record->policy?->late_tolerance_minutes ?? 15,
                        'require_gps' => $record->policy?->require_gps ?? false,
                        'geofence_radius_meters' => $record->policy?->geofence_radius_meters ?? 100,
                        'mock_location_action' => $record->policy?->mock_location_action ?? 'reject',
                        'selfie_mode' => $record->policy?->selfieMode() ?? 'none',
                        'face_match_threshold' => $record->policy?->face_match_threshold ?? 60,
                        'face_fail_action' => $record->policy?->face_fail_action ?? 'reject',
                        'annual_leave_quota' => $record->policy?->annual_leave_quota ?? 12,
                        'default_approval_stages' => $record->policy?->default_approval_stages ?? 1,
                    ])
                    ->schema([
                        Section::make('Jam Masuk')
                            ->icon('heroicon-o-clock')
                            ->compact()
                            ->schema([
                                TextInput::make('late_tolerance_minutes')
                                    ->label('Toleransi Keterlambatan')
                                    ->numeric()
                                    ->minValue(0)
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
                                        fn (?Company $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                                            if ($value && $record && ! GeofenceService::hasConfiguredLocations($record)) {
                                                $fail('Wajib Lokasi GPS membutuhkan Koordinat GPS Utama atau minimal satu cabang aktif.');
                                            }
                                        },
                                    ])
                                    ->helperText(fn (?Company $record): ?string => $record === null || GeofenceService::hasConfiguredLocations($record)
                                        ? null
                                        : 'Isi koordinat HQ atau cabang aktif dulu.')
                                    ->default(false),

                                TextInput::make('geofence_radius_meters')
                                    ->label('Radius Geofence')
                                    ->numeric()
                                    ->minValue(1)
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
                                    ->disableOptionWhen(fn (string $value): bool => $value === 'liveness' && ! static::faceVerifier()->supportsPose())
                                    ->rules([
                                        fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                            if ($value === 'liveness' && ! static::faceVerifier()->supportsPose()) {
                                                $fail(static::LIVENESS_DRIVER_MESSAGE);
                                            }
                                        },
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
                                        fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                            if (filter_var($value, FILTER_VALIDATE_BOOLEAN) && ! static::faceVerifier()->supportsPose()) {
                                                $fail(static::LIVENESS_DRIVER_MESSAGE);
                                            }
                                        },
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
                                    ->options([
                                        'reject' => 'Tolak Presensi Langsung',
                                        'approval' => 'Alihkan ke Approval Atasan (Pending)',
                                    ])
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
                                    ->suffix('hari')
                                    ->required()
                                    ->default(12),

                                TextInput::make('default_approval_stages')
                                    ->label('Tahap Approval')
                                    ->numeric()
                                    ->minValue(1)
                                    ->suffix('tahap')
                                    ->required()
                                    ->default(1),
                            ]),
                    ])
                    ->action(function (Company $record, array $data): void {
                        $record->policy()->updateOrCreate(
                            ['company_id' => $record->id],
                            static::normalizeAttendancePolicy(static::preferLegacySelfieFlags($data))
                        );
                    }),

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
     * Turn the form's selfie mode into the stored requirement flags.
     *
     * Older callers that still send `require_photo`, `require_face_recognition` or `require_liveness`
     * instead of `selfie_mode` keep working: the strictest flag wins, so face recognition always
     * implies a photo and liveness always implies face recognition.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeAttendancePolicy(array $data): array
    {
        $mode = $data['selfie_mode'] ?? null;

        if (! is_string($mode) || ! array_key_exists($mode, static::selfieModeOptions())) {
            $mode = match (true) {
                ! empty($data['require_liveness']) => 'liveness',
                ! empty($data['require_face_recognition']) => 'face',
                ! empty($data['require_photo']) => 'photo',
                default => 'none',
            };
        }

        unset($data['selfie_mode']);

        return [...$data, ...CompanyPolicy::flagsForSelfieMode($mode)];
    }

    /**
     * Let the old requirement flags decide the selfie mode when a caller sent them.
     *
     * The form always fills `selfie_mode`, so without this an older caller that only sends
     * `require_photo` / `require_face_recognition` / `require_liveness` would silently save the
     * filled mode instead of the flags it asked for.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function preferLegacySelfieFlags(array $data): array
    {
        $legacyKeys = ['require_photo', 'require_face_recognition', 'require_liveness'];
        $sentLegacyFlags = array_filter(
            array_intersect_key($data, array_flip($legacyKeys)),
            fn (mixed $value): bool => $value !== null && $value !== '',
        );

        if ($sentLegacyFlags === []) {
            return array_diff_key($data, array_flip($legacyKeys));
        }

        unset($data['selfie_mode']);

        foreach ($legacyKeys as $key) {
            $data[$key] = filter_var($data[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        return $data;
    }

    /**
     * @return array<string, string>
     */
    public static function selfieModeOptions(): array
    {
        return [
            'none' => 'Tanpa foto (klik saja)',
            'photo' => 'Foto selfie',
            'face' => 'Foto + cocokkan dengan foto master wajah',
            'liveness' => 'Liveness (gerak kepala) + cocokkan wajah',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function mockLocationActionOptions(): array
    {
        return [
            'reject' => 'Tolak presensi',
            'approval' => 'Alihkan ke persetujuan atasan',
        ];
    }

    /**
     * Warnings for modes the configured face service cannot do properly; other modes need no description.
     *
     * @return array<string, string>
     */
    public static function selfieModeDescriptions(): array
    {
        $verifier = static::faceVerifier();

        return array_filter([
            'face' => $verifier->isAccurate() ? null : 'Pembanding bawaan, bukan pengenalan wajah sungguhan.',
            'liveness' => $verifier->supportsPose() ? null : 'Butuh CompreFace.',
        ]);
    }

    public static function usesFaceMatch(mixed $selfieMode): bool
    {
        return in_array($selfieMode, ['face', 'liveness'], true);
    }

    protected static function faceVerifier(): FaceVerifier
    {
        return app(FaceVerifier::class);
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
