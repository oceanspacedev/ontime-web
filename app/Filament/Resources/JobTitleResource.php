<?php

namespace App\Filament\Resources;

use App\Filament\Resources\JobTitleResource\Pages;
use App\Models\Division;
use App\Models\JobTitle;
use App\Services\DataAccessService;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class JobTitleResource extends Resource
{
    protected static ?string $model = JobTitle::class;

    protected static ?string $modelLabel = 'Nama Posisi';

    protected static ?string $pluralModelLabel = 'Nama Posisi (Job Title)';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-briefcase';

    protected static string|UnitEnum|null $navigationGroup = 'Struktur Organisasi';

    protected static ?int $navigationSort = 4;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        // Reference data for the whole group: Superadmin, and the Admins (HR) who manage workers.
        return (bool) ($user?->hasRole('Superadmin') || ($user?->hasRole('Admin') && $user->canManageWorkers()));
    }

    /**
     * Shared by every client, so only the platform Superadmin changes it; Admins pick from it.
     */
    public static function canCreate(): bool
    {
        return (bool) auth()->user()?->hasRole('Superadmin');
    }

    public static function canEdit(Model $record): bool
    {
        return (bool) auth()->user()?->hasRole('Superadmin');
    }

    public static function canDelete(Model $record): bool
    {
        return (bool) auth()->user()?->hasRole('Superadmin');
    }

    public static function canDeleteAny(): bool
    {
        return (bool) auth()->user()?->hasRole('Superadmin');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informasi Posisi / Job Title')
                    ->schema([
                        Select::make('division_id')
                            ->label('Divisi Perusahaan')
                            ->options(function () {
                                return Division::with('company')->get()->mapWithKeys(function ($division) {
                                    $companyName = $division->company?->name ?? 'Perusahaan';

                                    return [$division->id => "{$companyName} - {$division->name}"];
                                });
                            })
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->helperText('Pilih divisi jika posisi ini khusus untuk divisi tertentu, atau biarkan kosong jika posisi berlaku secara umum.'),

                        TextInput::make('name')
                            ->label('Nama Posisi / Job Title')
                            ->placeholder('Contoh: IT Support, Mobile Dev, Staff Gudang, CS')
                            ->required()
                            ->maxLength(255),
                    ]),
            ]);
    }

    /**
     * Shared titles plus the titles of the user's own Klien; another client's divisions stay hidden.
     */
    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return $user ? parent::getEloquentQuery()->visibleTo($user) : parent::getEloquentQuery()->whereRaw('1 = 0');
    }

    /**
     * Divisions of the user's Klien, labelled "Company - Division" (every division for the Superadmin).
     *
     * @return array<int, string>
     */
    public static function tenantDivisionOptions(): array
    {
        $user = auth()->user();

        return Division::with('company')
            ->when($user && ! $user->isPlatformAdmin(), fn ($query) => $query->whereIn('company_id', DataAccessService::tenantCompanyIds($user)))
            ->get()
            ->mapWithKeys(fn (Division $division): array => [$division->id => ($division->company?->name ?? 'Perusahaan').' - '.$division->name])
            ->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('division.company.name')
                    ->label('Badan Usaha')
                    ->placeholder('Semua Perusahaan')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('division.name')
                    ->label('Divisi')
                    ->placeholder('Umum (Semua Divisi)')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('name')
                    ->label('Nama Posisi / Job Title')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('employees_count')
                    ->label('Jumlah Karyawan')
                    ->counts('employees')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('division_id')
                    ->label('Divisi')
                    ->options(fn (): array => static::tenantDivisionOptions()),
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListJobTitles::route('/'),
            'create' => Pages\CreateJobTitle::route('/create'),
            'edit' => Pages\EditJobTitle::route('/{record}/edit'),
        ];
    }
}
