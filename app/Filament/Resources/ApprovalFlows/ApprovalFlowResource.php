<?php

namespace App\Filament\Resources\ApprovalFlows;

use App\Filament\Concerns\ScopesToManagedCompanies;
use App\Filament\Resources\ApprovalFlows\Pages\CreateApprovalFlow;
use App\Filament\Resources\ApprovalFlows\Pages\EditApprovalFlow;
use App\Filament\Resources\ApprovalFlows\Pages\ListApprovalFlows;
use App\Filament\Resources\ApprovalFlows\RelationManagers\DivisionApprovalFlowsRelationManager;
use App\Models\ApprovalFlow;
use App\Models\Company;
use App\Services\ApprovalFlowService;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role;
use UnitEnum;

class ApprovalFlowResource extends Resource
{
    use ScopesToManagedCompanies;

    protected static ?string $model = Company::class;

    protected static ?string $slug = 'approval-flows';

    protected static ?string $modelLabel = 'Alur Persetujuan';

    protected static ?string $pluralModelLabel = 'Alur Persetujuan Perusahaan';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path';

    protected static string|UnitEnum|null $navigationGroup = 'Persetujuan (Inbox)';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return static::canManageCompanyData();
    }

    public static function getEloquentQuery(): Builder
    {
        return static::scopeToManagedCompanies(parent::getEloquentQuery(), 'companies.id');
    }

    public static function form(Schema $schema): Schema
    {
        $tab = fn (string $requestType, string $field, Heroicon $icon): Tab => Tab::make(ApprovalFlowService::requestTypeLabel($requestType))
            ->icon($icon)
            ->badge(fn (Get $get): int => count($get($field) ?? []))
            ->schema([static::getStepRepeaterSchema($field)]);

        return $schema
            ->components([
                Section::make()
                    ->columnSpanFull()
                    ->columns(['default' => 1, 'lg' => 2])
                    ->schema([
                        Select::make('company_id')
                            ->label('Badan usaha')
                            ->placeholder('Pilih badan usaha')
                            ->options(fn (string $operation): array => $operation === 'create' ? static::companiesWithoutFlowOptions() : static::managedCompanyOptions(activeOnly: false))
                            ->searchable()
                            ->required()
                            ->live()
                            ->disabledOn('edit')
                            ->dehydrated()
                            ->default(fn () => request()->query('company_id'))
                            ->helperText(fn (string $operation): ?string => $operation === 'create' && static::companiesWithoutFlowOptions() === []
                                ? 'Semua badan usaha sudah punya alur. Ubah alurnya dari daftar.'
                                : null),
                        Text::make('Berlaku untuk semua divisi, kecuali divisi yang punya aturan sendiri di bagian Aturan per Divisi.')
                            ->color('gray'),
                    ]),

                Tabs::make('ApprovalFlows')
                    ->columnSpanFull()
                    ->tabs([
                        $tab('leave', 'leave_steps', Heroicon::OutlinedCalendarDays),
                        $tab('overtime', 'overtime_steps', Heroicon::OutlinedClock),
                        $tab('correction', 'correction_steps', Heroicon::OutlinedPencilSquare),
                        $tab('resignation', 'resignation_steps', Heroicon::OutlinedArrowRightStartOnRectangle),
                        $tab('geofence', 'geofence_steps', Heroicon::OutlinedMapPin),
                    ]),
            ]);
    }

    /**
     * Companies the current user manages that have no company-wide flow yet; creating one for a
     * company that already has a flow would replace it.
     *
     * @return array<int, string>
     */
    public static function companiesWithoutFlowOptions(): array
    {
        $withFlow = ApprovalFlow::companyWide()->distinct()->pluck('company_id')->all();

        return collect(static::managedCompanyOptions())->except($withFlow)->all();
    }

    /**
     * Only roles that can act on a stage; any other role would leave requests stuck there.
     *
     * @return array<string, string>
     */
    public static function approverRoleOptions(): array
    {
        return Role::query()->whereIn('name', ApprovalFlowService::APPROVER_ROLES)->pluck('name', 'name')->all();
    }

    /**
     * The stages of one request type, in order. Each stage is approved by a role or by one user.
     */
    public static function getStepRepeaterSchema(string $name): Repeater
    {
        return Repeater::make($name)
            ->label('Tahap approval')
            ->hiddenLabel()
            ->schema([
                Grid::make(['default' => 1, '@lg' => 3])
                    ->gridContainer()
                    ->schema([
                        TextInput::make('name')
                            ->label('Nama tahap')
                            ->placeholder('Persetujuan Manager')
                            ->required(),

                        ToggleButtons::make('approver_type')
                            ->label('Disetujui oleh')
                            ->options([
                                'role' => 'Role',
                                'user' => 'User tertentu',
                            ])
                            ->grouped()
                            ->default('role')
                            ->required()
                            ->live(),

                        Select::make('approver_role')
                            ->label('Role')
                            ->placeholder('Pilih role')
                            ->options(fn (): array => static::approverRoleOptions())
                            ->default('Approver')
                            ->live()
                            ->visible(fn (Get $get): bool => $get('approver_type') !== 'user')
                            ->required(fn (Get $get): bool => $get('approver_type') !== 'user')
                            ->helperText(fn (Get $get, ?string $state): ?string => filled($state) && ! ApprovalFlowService::hasHolderForRole($state, $get('../../company_id'), $get('../../division_id'))
                                ? 'Belum ada pengguna aktif dengan role ini, pengajuan akan tertahan.'
                                : null),

                        Select::make('user_id')
                            ->label('User')
                            ->placeholder('Pilih user')
                            ->options(fn (Get $get): array => ApprovalFlowService::approverUserOptions($get('../../company_id')))
                            ->searchable()
                            ->visible(fn (Get $get): bool => $get('approver_type') === 'user')
                            ->required(fn (Get $get): bool => $get('approver_type') === 'user'),
                    ]),
            ])
            ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
            ->default([
                [
                    'name' => 'Persetujuan Approver',
                    'approver_type' => 'role',
                    'approver_role' => 'Approver',
                ],
            ])
            ->addActionLabel('Tambah tahap')
            ->minItems(1)
            ->validationMessages([
                'min' => 'Minimal 1 tahap approval.',
            ])
            ->reorderable();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nama Badan Usaha')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('code')
                    ->label('Kode')
                    ->searchable()
                    ->sortable(),

                BadgeColumn::make('leave_steps')
                    ->label('Tahap Cuti / Izin')
                    ->state(fn (Company $record): string => $record->approvalFlows()->companyWide()->where('request_type', 'leave')->count().' Tahap')
                    ->color('info'),

                BadgeColumn::make('overtime_steps')
                    ->label('Tahap Lembur')
                    ->state(fn (Company $record): string => $record->approvalFlows()->companyWide()->where('request_type', 'overtime')->count().' Tahap')
                    ->color('warning'),

                BadgeColumn::make('correction_steps')
                    ->label('Tahap Koreksi Absensi')
                    ->state(fn (Company $record): string => $record->approvalFlows()->companyWide()->where('request_type', 'correction')->count().' Tahap')
                    ->color('success'),

                BadgeColumn::make('resignation_steps')
                    ->label('Tahap Pengunduran Diri')
                    ->state(fn (Company $record): string => $record->approvalFlows()->companyWide()->where('request_type', 'resignation')->count().' Tahap')
                    ->color('danger'),

                BadgeColumn::make('geofence_steps')
                    ->label('Tahap Luar Geofence')
                    ->state(fn (Company $record): string => $record->approvalFlows()->companyWide()->where('request_type', 'geofence')->count().' Tahap')
                    ->color('warning'),
            ])
            ->actions([
                EditAction::make()
                    ->label('Atur Alur Approval')
                    ->icon('heroicon-o-pencil-square'),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            DivisionApprovalFlowsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApprovalFlows::route('/'),
            'create' => CreateApprovalFlow::route('/create'),
            'edit' => EditApprovalFlow::route('/{record}/edit'),
        ];
    }
}
