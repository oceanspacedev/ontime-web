<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ScopesToManagedCompanies;
use App\Filament\Resources\ApproverResource\Pages;
use App\Models\Approver;
use App\Models\Division;
use App\Models\User;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
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
use UnitEnum;

class ApproverResource extends Resource
{
    use ScopesToManagedCompanies;

    protected static ?string $model = Approver::class;

    protected static ?string $modelLabel = 'Penugasan Approver';

    protected static ?string $pluralModelLabel = 'Penugasan Approver';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static string|UnitEnum|null $navigationGroup = 'Persetujuan (Inbox)';

    protected static ?int $navigationSort = 3;

    /**
     * Retired: who approves which workers now follows each user's Akses Data in the User menu. The
     * approvers table stays as history of the old mapping (it seeded Akses Data on migration).
     */
    public static function canViewAny(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return static::scopeToManagedCompanies(parent::getEloquentQuery());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Mapping Approver & Scope')
                    ->description('Tugaskan user ber-role "Approver" atau "BOD" ke lingkup Perusahaan (Company) atau Divisi (Division) tertentu beserta tingkat/tahap jabatannya.')
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('user_id')
                                ->label('User Approver / BOD')
                                ->helperText('Hanya menampilkan user ber-role "Approver" atau "BOD" yang terhubung ke data Employee.')
                                ->options(function () {
                                    return User::query()
                                        ->whereHas('roles', function ($query) {
                                            $query->whereIn('name', ['Approver', 'BOD']);
                                        })
                                        ->whereHas('employee', fn (Builder $employee) => static::scopeToManagedCompanies($employee))
                                        ->get()
                                        ->pluck('name', 'id');
                                })
                                ->searchable()
                                ->required(),

                            Radio::make('scope_type')
                                ->label('Lingkup Approval (Scope)')
                                ->options([
                                    'company' => 'Tingkat Badan Usaha (Company Level)',
                                    'division' => 'Tingkat Divisi (Division Level)',
                                ])
                                ->default('division')
                                ->live()
                                ->dehydrated(false)
                                ->afterStateHydrated(function ($component, $record) {
                                    if ($record) {
                                        $component->state($record->division_id ? 'division' : 'company');
                                    }
                                })
                                ->afterStateUpdated(function (Set $set, ?string $state) {
                                    if ($state === 'company') {
                                        $set('division_id', null);
                                    }
                                }),

                            Select::make('company_id')
                                ->label('Badan Usaha (Company)')
                                ->options(fn (): array => static::managedCompanyOptions())
                                ->required()
                                ->live()
                                ->afterStateUpdated(fn (Set $set) => $set('division_id', null)),

                            Select::make('division_id')
                                ->label('Divisi Target')
                                ->options(function (Get $get) {
                                    $companyId = $get('company_id');
                                    if (! $companyId) {
                                        return [];
                                    }

                                    return Division::query()
                                        ->where('company_id', $companyId)
                                        ->where('is_active', true)
                                        ->pluck('name', 'id');
                                })
                                ->visible(fn (Get $get) => $get('scope_type') === 'division')
                                ->required(fn (Get $get) => $get('scope_type') === 'division')
                                ->nullable(fn (Get $get) => $get('scope_type') === 'company')
                                ->dehydrated(fn (Get $get) => $get('scope_type') === 'division'),

                            TextInput::make('level')
                                ->label('Level / Urutan Tahap Approval')
                                ->placeholder('1 = Tahap 1, 2 = Tahap 2, dst.')
                                ->numeric()
                                ->minValue(1)
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
                TextColumn::make('user.name')
                    ->label('Nama Approver')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('user.employee.nip')
                    ->label('NIP Employee')
                    ->searchable()
                    ->placeholder('-'),

                TextColumn::make('company.name')
                    ->label('Badan Usaha')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('division.name')
                    ->label('Divisi')
                    ->placeholder('- Scope Company (Seluruh Divisi) -')
                    ->sortable()
                    ->searchable(),

                BadgeColumn::make('level')
                    ->label('Tahap / Level')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => "Tahap {$state}"),
            ])
            ->filters([
                SelectFilter::make('company_id')
                    ->label('Badan Usaha')
                    ->relationship('company', 'name'),

                SelectFilter::make('division_id')
                    ->label('Divisi')
                    ->relationship('division', 'name'),
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
            'index' => Pages\ListApprovers::route('/'),
            'create' => Pages\CreateApprover::route('/create'),
            'edit' => Pages\EditApprover::route('/{record}/edit'),
        ];
    }
}
