<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ScopesToManagedCompanies;
use App\Filament\Forms\Components\MapPickerField;
use App\Filament\Resources\CompanyLocationResource\Pages;
use App\Filament\Resources\Shifts\ShiftResource;
use App\Models\CompanyLocation;
use App\Services\CompanyPolicyService;
use App\Services\GeofenceService;
use BackedEnum;
use Closure;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use UnitEnum;

class CompanyLocationResource extends Resource
{
    use ScopesToManagedCompanies;

    protected static ?string $model = CompanyLocation::class;

    protected static ?string $modelLabel = 'Lokasi / Cabang';

    protected static ?string $pluralModelLabel = 'Lokasi & Cabang Kantor';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map-pin';

    protected static string|UnitEnum|null $navigationGroup = 'Struktur Organisasi';

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        return static::canManageCompanyData();
    }

    public static function getEloquentQuery(): Builder
    {
        return static::scopeToManagedCompanies(parent::getEloquentQuery()->with('division'));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informasi Lokasi & Cabang Office')
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('company_id')
                                ->label('Badan Usaha (Company)')
                                ->options(fn (): array => static::managedCompanyOptions())
                                ->required()
                                ->searchable()
                                ->live()
                                ->afterStateUpdated(fn (Set $set) => $set('division_id', null)),

                            Select::make('division_id')
                                ->label('Brand (opsional)')
                                ->options(fn (Get $get): array => ShiftResource::brandOptions($get('company_id')))
                                ->searchable()
                                ->nullable(),

                            TextInput::make('name')
                                ->label('Nama Cabang / Lokasi')
                                ->placeholder('Contoh: Kantor Pusat Monas / Cabang Bandung')
                                ->required()
                                ->maxLength(255),

                            TextInput::make('latitude')
                                ->label('Latitude')
                                ->numeric()
                                ->required()
                                ->placeholder('Contoh: -6.1753924'),

                            TextInput::make('longitude')
                                ->label('Longitude')
                                ->numeric()
                                ->required()
                                ->placeholder('Contoh: 106.8271528'),

                            TextInput::make('radius_meters')
                                ->label('Radius Geofence (Meter)')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(CompanyPolicyService::MAX_GEOFENCE_RADIUS_METERS)
                                ->placeholder('Kosongkan untuk ikuti Kebijakan Perusahaan'),

                            Toggle::make('is_active')
                                ->label('Status Aktif')
                                ->rules([
                                    fn (Get $get, ?CompanyLocation $record): Closure => static::keepsGpsLocationRule($get, $record),
                                ])
                                ->default(true),

                            Textarea::make('address')
                                ->label('Alamat Lengkap Cabang')
                                ->rows(3)
                                ->columnSpanFull(),

                            MapPickerField::make('map_picker')
                                ->label('Pilih Koordinat di Peta')
                                ->latField('latitude')
                                ->lngField('longitude'),
                        ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('company.name')
                    ->label('Badan Usaha')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('name')
                    ->label('Nama Cabang / Lokasi')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('division.name')
                    ->label('Brand')
                    ->placeholder('-')
                    ->toggleable(),

                TextColumn::make('latitude')
                    ->label('Latitude')
                    ->numeric(),

                TextColumn::make('longitude')
                    ->label('Longitude')
                    ->numeric(),

                TextColumn::make('radius_meters')
                    ->label('Radius')
                    ->state(fn (CompanyLocation $record) => $record->radius_meters ? "{$record->radius_meters}m" : 'Def. Policy')
                    ->badge()
                    ->color('info'),

                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('company_id')
                    ->label('Badan Usaha')
                    ->relationship('company', 'name', fn (Builder $query) => static::scopeToManagedCompanies($query, 'companies.id')),

                TernaryFilter::make('is_active')
                    ->label('Status Aktif'),
            ])
            ->actions([
                EditAction::make(),
                static::guardLastGpsLocation(DeleteAction::make()),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->before(function (DeleteBulkAction $action, Collection $records): void {
                            if (static::removesLastGpsLocation($records)) {
                                Notification::make()->danger()->title(CompanyPolicyService::GPS_WITHOUT_LOCATION_MESSAGE)->send();
                                $action->cancel();
                            }
                        }),
                ]),
            ]);
    }

    /**
     * While GPS is required, the last active location of a company without HQ coordinates can neither
     * be switched off nor moved to another company.
     */
    public static function keepsGpsLocationRule(Get $get, ?CompanyLocation $record): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
            if (! $record?->is_active || ! $record->company) {
                return;
            }

            $leavesCompany = (bool) $value === false || (int) $get('company_id') !== (int) $record->company_id;

            if ($leavesCompany && GeofenceService::wouldLeaveGpsWithoutLocation($record->company, [$record->id])) {
                $fail(CompanyPolicyService::GPS_WITHOUT_LOCATION_MESSAGE);
            }
        };
    }

    /**
     * Refuse deleting the last active location of a company that requires GPS.
     */
    public static function guardLastGpsLocation(DeleteAction $action): DeleteAction
    {
        return $action->before(function (DeleteAction $action, CompanyLocation $record): void {
            if (static::removesLastGpsLocation(collect([$record]))) {
                Notification::make()->danger()->title(CompanyPolicyService::GPS_WITHOUT_LOCATION_MESSAGE)->send();
                $action->cancel();
            }
        });
    }

    /**
     * Whether removing these locations leaves a company that requires GPS without any coordinate.
     *
     * @param  Collection<int, CompanyLocation>  $records
     */
    public static function removesLastGpsLocation(Collection $records): bool
    {
        return $records
            ->filter(fn (CompanyLocation $location): bool => (bool) $location->is_active && $location->company !== null)
            ->groupBy('company_id')
            ->contains(fn (Collection $locations): bool => GeofenceService::wouldLeaveGpsWithoutLocation(
                $locations->first()->company,
                $locations->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            ));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCompanyLocations::route('/'),
            'create' => Pages\CreateCompanyLocation::route('/create'),
            'edit' => Pages\EditCompanyLocation::route('/{record}/edit'),
        ];
    }
}
