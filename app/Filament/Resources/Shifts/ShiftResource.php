<?php

namespace App\Filament\Resources\Shifts;

use App\Filament\Concerns\ScopesToManagedCompanies;
use App\Filament\Resources\Shifts\Pages\ManageShifts;
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
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
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
        return static::scopeToManagedCompanies(parent::getEloquentQuery()->with('company')->withCount('employees'));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(2)->schema([
                    Select::make('company_id')
                        ->label('Badan Usaha')
                        ->options(fn (): array => static::managedCompanyOptions())
                        ->searchable()
                        ->required()
                        ->live(),

                    TextInput::make('name')
                        ->label('Nama Shift')
                        ->placeholder('Contoh: Pagi, Siang, Malam')
                        ->required()
                        ->maxLength(100)
                        ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where('company_id', $get('company_id'))),

                    TimePicker::make('start_time')
                        ->label('Jam Masuk')
                        ->seconds(false)
                        ->required(),

                    TimePicker::make('end_time')
                        ->label('Jam Pulang')
                        ->seconds(false)
                        ->helperText('Boleh lebih kecil dari jam masuk untuk shift yang melewati tengah malam.')
                        ->required(),

                    TextInput::make('late_tolerance_minutes')
                        ->label('Toleransi Terlambat (menit)')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(240)
                        ->helperText('Kosongkan untuk mengikuti toleransi di kebijakan badan usaha.'),

                    TextInput::make('code')
                        ->label('Kode (opsional)')
                        ->maxLength(20),

                    Toggle::make('is_active')
                        ->label('Aktif')
                        ->default(true),
                ]),
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

                TernaryFilter::make('is_active')
                    ->label('Status Aktif'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageShifts::route('/'),
        ];
    }
}
