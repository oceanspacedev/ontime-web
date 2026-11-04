<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ScopesToManagedCompanies;
use App\Filament\Resources\DivisionResource\Pages;
use App\Models\Division;
use App\Rules\ValidDivisionParent;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use UnitEnum;

class DivisionResource extends Resource
{
    use ScopesToManagedCompanies;

    protected static ?string $model = Division::class;

    protected static ?string $modelLabel = 'Divisi';

    protected static ?string $pluralModelLabel = 'Divisi Perusahaan';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-group';

    protected static string|UnitEnum|null $navigationGroup = 'Struktur Organisasi';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return static::canManageCompanyData();
    }

    public static function getEloquentQuery(): Builder
    {
        return static::scopeToManagedCompanies(parent::getEloquentQuery());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informasi Divisi')
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('company_id')
                                ->label('Badan Usaha (Company)')
                                ->options(fn (): array => static::managedCompanyOptions(activeOnly: false))
                                ->required()
                                ->live()
                                ->afterStateUpdated(fn ($set) => $set('parent_id', null)),

                            Select::make('parent_id')
                                ->label('Parent Divisi')
                                ->placeholder('Pilih Parent Divisi (Opsional)')
                                ->options(function (Get $get, ?Division $record) {
                                    $companyId = $get('company_id');
                                    if (! $companyId) {
                                        return [];
                                    }

                                    $query = Division::query()->where('company_id', $companyId);

                                    if ($record && $record->exists) {
                                        $excludedIds = $record->descendantsAndSelf()->pluck('id')->toArray();
                                        $query->whereNotIn('id', $excludedIds);
                                    }

                                    $divisionsByParent = $query
                                        ->orderBy('name')
                                        ->get(['id', 'name', 'parent_id'])
                                        ->groupBy(fn (Division $division): int => (int) $division->parent_id);

                                    return static::formatTreeOptions($divisionsByParent);
                                })
                                ->rule(fn (?Division $record) => new ValidDivisionParent($record?->id)),

                            TextInput::make('name')
                                ->label('Nama Divisi')
                                ->required()
                                ->maxLength(255),

                            TextInput::make('code')
                                ->label('Kode Divisi')
                                ->maxLength(50),

                            Toggle::make('is_active')
                                ->label('Status Aktif')
                                ->default(true),
                        ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('company.name')
                    ->label('Perusahaan')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('name')
                    ->label('Nama Divisi')
                    ->formatStateUsing(function (Division $record) {
                        $depth = $record->getDepth();
                        $prefix = str_repeat('— ', $depth);

                        return $prefix.$record->name;
                    })
                    ->searchable()
                    ->sortable(),
                TextColumn::make('parent.name')
                    ->label('Parent Divisi')
                    ->placeholder('- Top Level -')
                    ->sortable(),
                TextColumn::make('code')
                    ->label('Kode')
                    ->searchable(),
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
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Build indented tree options from divisions grouped by parent id (0 = top level).
     *
     * @param  Collection<int, Collection<int, Division>>  $divisionsByParent
     * @return array<int, string>
     */
    protected static function formatTreeOptions(Collection $divisionsByParent, int $parentId = 0, int $depth = 0): array
    {
        if ($depth > 50) {
            return [];
        }

        $options = [];

        foreach ($divisionsByParent->get($parentId, collect()) as $division) {
            $options[$division->id] = str_repeat('— ', $depth).$division->name;
            $options += static::formatTreeOptions($divisionsByParent, (int) $division->id, $depth + 1);
        }

        return $options;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDivisions::route('/'),
            'create' => Pages\CreateDivision::route('/create'),
            'edit' => Pages\EditDivision::route('/{record}/edit'),
        ];
    }
}
