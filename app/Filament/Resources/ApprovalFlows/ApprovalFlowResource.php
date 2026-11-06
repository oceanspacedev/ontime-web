<?php

namespace App\Filament\Resources\ApprovalFlows;

use App\Filament\Concerns\ScopesToManagedCompanies;
use App\Filament\Resources\ApprovalFlows\Pages\CreateApprovalFlow;
use App\Filament\Resources\ApprovalFlows\Pages\EditApprovalFlow;
use App\Filament\Resources\ApprovalFlows\Pages\ListApprovalFlows;
use App\Models\Company;
use App\Models\User;
use App\Services\ApprovalFlowService;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
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
        return $schema
            ->components([
                Section::make('Identitas Perusahaan')
                    ->schema([
                        Select::make('company_id')
                            ->label('Badan Usaha (Company)')
                            ->options(fn (): array => static::managedCompanyOptions())
                            ->required()
                            ->disabledOn('edit')
                            ->default(fn () => request()->query('company_id')),
                    ]),

                Section::make('Konfigurasi Tahap Approval')
                    ->description('Atur urutan dan penugasan approver untuk setiap jenis pengajuan (Cuti, Lembur, Koreksi Absensi).')
                    ->schema([
                        Tabs::make('ApprovalFlows')
                            ->tabs([
                                Tabs\Tab::make('Cuti / Izin')
                                    ->icon('heroicon-o-calendar')
                                    ->schema([
                                        static::getStepRepeaterSchema('leave_steps', 'Leave / Cuti'),
                                    ]),

                                Tabs\Tab::make('Lembur')
                                    ->icon('heroicon-o-clock')
                                    ->schema([
                                        static::getStepRepeaterSchema('overtime_steps', 'Overtime / Lembur'),
                                    ]),

                                Tabs\Tab::make('Koreksi Absensi')
                                    ->icon('heroicon-o-arrow-path')
                                    ->schema([
                                        static::getStepRepeaterSchema('correction_steps', 'Koreksi Absensi'),
                                    ]),

                                Tabs\Tab::make('Pengunduran Diri')
                                    ->icon('heroicon-o-arrow-right-on-rectangle')
                                    ->schema([
                                        static::getStepRepeaterSchema('resignation_steps', 'Pengunduran Diri'),
                                    ]),

                                Tabs\Tab::make('Absensi Luar Geofence')
                                    ->icon('heroicon-o-map-pin')
                                    ->schema([
                                        static::getStepRepeaterSchema('geofence_steps', 'Absensi Luar Geofence'),
                                    ]),
                            ]),
                    ]),
            ]);
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

    public static function getStepRepeaterSchema(string $name, string $label): Repeater
    {
        return Repeater::make($name)
            ->label("Tahap Approval {$label}")
            ->schema([
                Grid::make(2)->schema([
                    TextInput::make('name')
                        ->label('Nama Tahap / Label')
                        ->placeholder('Contoh: Manager Direct / HR Review')
                        ->required(),

                    Radio::make('approver_type')
                        ->label('Penetapan Approver')
                        ->options([
                            'role' => 'Berdasarkan Role (Role Approver Divisi)',
                            'user' => 'User Spesifik (Pengawas Khusus)',
                        ])
                        ->default('role')
                        ->live(),

                    Select::make('approver_role')
                        ->label('Role Approver')
                        ->options(fn (): array => static::approverRoleOptions())
                        ->default('Approver')
                        ->live()
                        ->helperText(fn (Get $get, ?string $state): ?string => filled($state) && ! ApprovalFlowService::hasHolderForRole($state, $get('../../company_id'))
                            ? "Belum ada pengguna aktif dengan role {$state} di badan usaha ini. Pengajuan akan tertahan di tahap ini."
                            : null)
                        ->visible(fn (Get $get) => $get('approver_type') === 'role')
                        ->required(fn (Get $get) => $get('approver_type') === 'role'),

                    Select::make('user_id')
                        ->label('Pilih User Spesifik')
                        ->options(function () {
                            return User::query()
                                ->where('is_active', true)
                                ->whereHas('roles', fn ($q) => $q->whereIn('name', ApprovalFlowService::APPROVER_ROLES))
                                ->pluck('name', 'id');
                        })
                        ->searchable()
                        ->visible(fn (Get $get) => $get('approver_type') === 'user')
                        ->required(fn (Get $get) => $get('approver_type') === 'user'),
                ]),
            ])
            ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
            ->collapsible()
            ->default([
                [
                    'name' => 'Persetujuan Approver',
                    'approver_type' => 'role',
                    'approver_role' => 'Approver',
                ],
                [
                    'name' => 'Persetujuan BOD',
                    'approver_type' => 'role',
                    'approver_role' => 'BOD',
                ],
                [
                    'name' => 'Persetujuan Akhir Superadmin',
                    'approver_type' => 'role',
                    'approver_role' => 'Superadmin',
                ],
            ])
            ->minItems(1)
            ->validationMessages([
                'min' => 'Setiap jenis alur (Cuti, Lembur, Koreksi) wajib memiliki minimal 1 tahap approval.',
            ])
            ->reorderable(true);
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
                    ->state(fn (Company $record): string => $record->approvalFlows()->where('request_type', 'leave')->count().' Tahap')
                    ->color('info'),

                BadgeColumn::make('overtime_steps')
                    ->label('Tahap Lembur')
                    ->state(fn (Company $record): string => $record->approvalFlows()->where('request_type', 'overtime')->count().' Tahap')
                    ->color('warning'),

                BadgeColumn::make('correction_steps')
                    ->label('Tahap Koreksi Absensi')
                    ->state(fn (Company $record): string => $record->approvalFlows()->where('request_type', 'correction')->count().' Tahap')
                    ->color('success'),

                BadgeColumn::make('resignation_steps')
                    ->label('Tahap Pengunduran Diri')
                    ->state(fn (Company $record): string => $record->approvalFlows()->where('request_type', 'resignation')->count().' Tahap')
                    ->color('danger'),

                BadgeColumn::make('geofence_steps')
                    ->label('Tahap Luar Geofence')
                    ->state(fn (Company $record): string => $record->approvalFlows()->where('request_type', 'geofence')->count().' Tahap')
                    ->color('warning'),
            ])
            ->actions([
                EditAction::make()
                    ->label('Atur Alur Approval')
                    ->icon('heroicon-o-pencil-square'),
            ]);
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
