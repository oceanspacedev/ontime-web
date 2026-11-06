<?php

namespace App\Filament\Resources\Shifts;

use App\Filament\Concerns\ScopesToManagedCompanies;
use App\Filament\Resources\Shifts\Pages\ManageShifts;
use App\Models\Division;
use App\Models\Shift;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

class ShiftResource extends Resource
{
    use ScopesToManagedCompanies;

    protected static ?string $model = Shift::class;

    protected static ?string $modelLabel = 'Shift';

    protected static ?string $pluralModelLabel = 'Master Shift';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Jadwal & Shift';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewAny(): bool
    {
        return static::canManageCompanyData();
    }

    public static function getEloquentQuery(): Builder
    {
        return static::scopeToManagedCompanies(parent::getEloquentQuery()->with(['company', 'division'])->withCount('employees'));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('company_id')
                    ->label('Badan Usaha')
                    ->options(fn (): array => static::managedCompanyOptions())
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('division_id', null))
                    ->columnSpanFull(),

                Select::make('division_id')
                    ->label('Brand')
                    ->options(fn (Get $get): array => static::brandOptions($get('company_id')))
                    ->searchable()
                    ->placeholder('Semua brand')
                    ->nullable()
                    ->columnSpanFull(),

                TextInput::make('name')
                    ->label('Nama Shift')
                    ->placeholder('Pagi')
                    ->required()
                    ->maxLength(100)
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule
                        ->where('company_id', $get('company_id'))
                        ->when(
                            filled($get('division_id')),
                            fn (Unique $rule): Unique => $rule->where('division_id', $get('division_id')),
                            fn (Unique $rule): Unique => $rule->whereNull('division_id'),
                        ))
                    ->validationMessages(['unique' => 'Nama shift ini sudah dipakai di brand yang sama.']),

                TextInput::make('code')
                    ->label('Kode')
                    ->placeholder('P')
                    ->maxLength(20),

                TimePicker::make('start_time')
                    ->label('Jam Masuk')
                    ->seconds(false)
                    ->required(),

                TimePicker::make('end_time')
                    ->label('Jam Pulang')
                    ->seconds(false)
                    ->helperText('Boleh lewat tengah malam.')
                    ->required(),

                TextInput::make('late_tolerance_minutes')
                    ->label('Toleransi Terlambat')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(240)
                    ->suffix('menit')
                    ->placeholder('Ikut kebijakan'),

                Toggle::make('is_active')
                    ->label('Aktif')
                    ->default(true)
                    ->inline(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('start_time')
            ->columns([
                TextColumn::make('company.name')
                    ->label('Badan Usaha')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('division.name')
                    ->label('Brand')
                    ->placeholder('Semua brand')
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Shift')
                    ->searchable(),

                TextColumn::make('hours')
                    ->label('Jam Kerja')
                    ->state(fn (Shift $record): string => substr((string) $record->start_time, 0, 5).' - '.substr((string) $record->end_time, 0, 5).($record->crossesMidnight() ? ' (+1 hari)' : '')),

                TextColumn::make('late_tolerance_minutes')
                    ->label('Toleransi')
                    ->formatStateUsing(fn (?int $state): string => "{$state} menit")
                    ->placeholder('Ikut kebijakan'),

                TextColumn::make('employees_count')
                    ->label('Karyawan (default)')
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('company_id')
                    ->label('Badan Usaha')
                    ->options(fn (): array => static::managedCompanyOptions(activeOnly: false)),

                SelectFilter::make('division_id')
                    ->label('Brand')
                    ->relationship('division', 'name', fn (Builder $query): Builder => $query->whereIn('id', Shift::query()->whereNotNull('division_id')->select('division_id'))),

                TernaryFilter::make('is_active')
                    ->label('Status Aktif'),
            ])
            ->recordActions([
                EditAction::make()
                    ->slideOver()
                    ->modalWidth(Width::Medium),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Active divisions of a managed company, labelled with their full path ("F&B > JCHICKEN").
     *
     * @return array<int, string>
     */
    public static function brandOptions(int|string|null $companyId): array
    {
        if (! $companyId || ! in_array((int) $companyId, array_map('intval', array_keys(static::managedCompanyOptions())), true)) {
            return [];
        }

        $divisions = Division::query()->where('company_id', $companyId)->get(['id', 'parent_id', 'name', 'is_active'])->keyBy('id');

        return $divisions
            ->where('is_active', true)
            ->mapWithKeys(function (Division $division) use ($divisions): array {
                $names = [];
                $node = $division;

                while ($node && count($names) < 10) {
                    array_unshift($names, $node->name);
                    $node = $node->parent_id ? $divisions->get($node->parent_id) : null;
                }

                return [$division->id => implode(' > ', $names)];
            })
            ->sort()
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageShifts::route('/'),
        ];
    }
}
