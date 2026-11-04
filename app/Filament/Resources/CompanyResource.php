<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ScopesToManagedCompanies;
use App\Filament\Forms\Components\MapPickerField;
use App\Filament\Resources\CompanyResource\Pages;
use App\Models\Company;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
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
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
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
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Status Aktif'),
            ])
            ->actions([
                Action::make('managePolicy')
                    ->label('Kebijakan Absensi')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->color('warning')
                    ->fillForm(fn (Company $record): array => [
                        'late_tolerance_minutes' => $record->policy?->late_tolerance_minutes ?? 15,
                        'require_photo' => $record->policy?->require_photo ?? false,
                        'require_gps' => $record->policy?->require_gps ?? false,
                        'require_face_recognition' => $record->policy?->require_face_recognition ?? false,
                        'face_match_threshold' => $record->policy?->face_match_threshold ?? 60,
                        'face_fail_action' => $record->policy?->face_fail_action ?? 'reject',
                        'geofence_radius_meters' => $record->policy?->geofence_radius_meters ?? 100,
                        'annual_leave_quota' => $record->policy?->annual_leave_quota ?? 12,
                        'default_approval_stages' => $record->policy?->default_approval_stages ?? 1,
                    ])
                    ->schema([
                        TextInput::make('late_tolerance_minutes')
                            ->label('Toleransi Keterlambatan (Menit)')
                            ->numeric()
                            ->required()
                            ->default(15),

                        Toggle::make('require_photo')
                            ->label('Wajib Foto Absensi')
                            ->default(false),

                        Toggle::make('require_gps')
                            ->label('Wajib Lokasi GPS')
                            ->live()
                            ->default(false),

                        Toggle::make('require_face_recognition')
                            ->label('Wajib Face Recognition (Anti-Spoofing)')
                            ->live()
                            ->afterStateUpdated(function (Set $set, bool $state) {
                                if ($state) {
                                    $set('require_photo', true);
                                }
                            })
                            ->default(false),

                        TextInput::make('face_match_threshold')
                            ->label('Ambang Batas Kemiripan Wajah (%)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(100)
                            ->default(60)
                            ->visible(fn (Get $get) => (bool) $get('require_face_recognition'))
                            ->required(fn (Get $get) => (bool) $get('require_face_recognition')),

                        Select::make('face_fail_action')
                            ->label('Tindakan Jika Wajah Tidak Cocok')
                            ->options([
                                'reject' => 'Tolak Presensi Langsung',
                                'approval' => 'Alihkan ke Approval Atasan (Pending)',
                            ])
                            ->default('reject')
                            ->visible(fn (Get $get) => (bool) $get('require_face_recognition'))
                            ->required(fn (Get $get) => (bool) $get('require_face_recognition')),

                        TextInput::make('geofence_radius_meters')
                            ->label('Radius Geofence (Meter)')
                            ->numeric()
                            ->placeholder('Contoh: 100')
                            ->visible(fn (Get $get) => (bool) $get('require_gps'))
                            ->required(fn (Get $get) => (bool) $get('require_gps')),

                        TextInput::make('annual_leave_quota')
                            ->label('Kuota Cuti Tahunan (Hari)')
                            ->numeric()
                            ->required()
                            ->default(12),

                        TextInput::make('default_approval_stages')
                            ->label('Jumlah Tahap Approval Default (Referensi)')
                            ->numeric()
                            ->required()
                            ->default(1),
                    ])
                    ->action(function (Company $record, array $data): void {
                        $record->policy()->updateOrCreate(
                            ['company_id' => $record->id],
                            $data
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCompanies::route('/'),
            'create' => Pages\CreateCompany::route('/create'),
            'edit' => Pages\EditCompany::route('/{record}/edit'),
        ];
    }
}
