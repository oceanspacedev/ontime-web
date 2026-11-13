<?php

namespace App\Filament\Resources;

use App\Filament\Resources\JobLevelResource\Pages;
use App\Models\JobLevel;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class JobLevelResource extends Resource
{
    protected static ?string $model = JobLevel::class;

    protected static ?string $modelLabel = 'Tingkat Jabatan';

    protected static ?string $pluralModelLabel = 'Tingkat Jabatan';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-academic-cap';

    protected static string|UnitEnum|null $navigationGroup = 'Struktur Organisasi';

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        // Reference data for the whole group: Superadmin, and the Admins (HR) who manage workers.
        return (bool) ($user?->hasRole('Superadmin') || ($user?->hasRole('Admin') && $user->canManageWorkers()));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informasi Level Jabatan (Struktural)')
                    ->description('Jabatan hanya merupakan label struktural dan TIDAK menentukan hak akses/role sistem.')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('name')
                                ->label('Nama Level Jabatan')
                                ->placeholder('Contoh: Staff, Manager, Leader, BOD')
                                ->required()
                                ->maxLength(255),
                            TextInput::make('level_order')
                                ->label('Urutan Level')
                                ->numeric()
                                ->default(1)
                                ->required(),
                        ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('level_order')
                    ->label('Urutan')
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Nama Level Jabatan')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('employees_count')
                    ->label('Jumlah Karyawan')
                    ->counts('employees')
                    ->sortable(),
            ])
            ->defaultSort('level_order', 'asc')
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListJobLevels::route('/'),
            'create' => Pages\CreateJobLevel::route('/create'),
            'edit' => Pages\EditJobLevel::route('/{record}/edit'),
        ];
    }
}
