<?php

namespace App\Filament\Resources\ShiftSchedules;

use App\Filament\Concerns\ScopesToManagedCompanies;
use App\Filament\Resources\ShiftSchedules\Pages\ManageShiftSchedules;
use App\Models\CompanyLocation;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Services\DataAccessService;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ShiftScheduleResource extends Resource
{
    use ScopesToManagedCompanies;

    protected static ?string $model = ShiftSchedule::class;

    protected static ?string $modelLabel = 'Jadwal Shift';

    protected static ?string $pluralModelLabel = 'Jadwal Shift';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Jadwal & Shift';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return static::canManageWorkerData();
    }

    /**
     * Schedules are added in bulk with the "Atur Jadwal" action on the list page.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return static::canManageWorkerRecord($record);
    }

    public static function canDelete(Model $record): bool
    {
        return static::canManageWorkerRecord($record);
    }

    public static function canDeleteAny(): bool
    {
        return static::canManageWorkerData();
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $query = parent::getEloquentQuery()->with([...ShiftSchedule::workerEagerLoads(), 'shift.division', 'location']);

        return $user ? $query->visibleTo($user) : $query->whereRaw('1 = 0');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Toggle::make('is_day_off')
                    ->label('Libur')
                    ->live(),

                Select::make('shift_id')
                    ->label('Shift')
                    ->options(fn (?ShiftSchedule $record): array => static::shiftOptions($record?->getWorkerProfile()?->company_id))
                    ->required(fn (Get $get): bool => ! $get('is_day_off'))
                    ->hidden(fn (Get $get): bool => (bool) $get('is_day_off')),

                Select::make('company_location_id')
                    ->label('Outlet')
                    ->options(fn (?ShiftSchedule $record): array => static::locationOptions($record?->getWorkerProfile()?->company_id))
                    ->searchable()
                    ->placeholder('Lokasi mana pun')
                    ->hidden(fn (Get $get): bool => (bool) $get('is_day_off')),

                TextInput::make('section')
                    ->label('Section')
                    ->datalist(fn (?ShiftSchedule $record): array => static::sectionSuggestions($record?->getWorkerProfile()?->company_id))
                    ->maxLength(50)
                    ->hidden(fn (Get $get): bool => (bool) $get('is_day_off')),

                TextInput::make('notes')
                    ->label('Catatan')
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('date', 'desc')
            ->columns([
                TextColumn::make('date')
                    ->label('Tanggal')
                    ->date('D, d M Y')
                    ->sortable(),

                TextColumn::make('worker_name')
                    ->label('Pekerja')
                    ->state(fn (ShiftSchedule $record): string => $record->getWorkerProfile()?->full_name ?? '-')
                    ->description(fn (ShiftSchedule $record): string => match (true) {
                        $record->employee_id !== null => 'Karyawan',
                        $record->intern_id !== null => 'Magang',
                        default => 'Freelancer',
                    })
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $q) => $q
                        ->whereHas('employee', fn ($worker) => $worker->where('full_name', 'like', "%{$search}%"))
                        ->orWhereHas('intern', fn ($worker) => $worker->where('full_name', 'like', "%{$search}%"))
                        ->orWhereHas('freelancer', fn ($worker) => $worker->where('full_name', 'like', "%{$search}%")))),

                TextColumn::make('company')
                    ->label('Badan Usaha')
                    ->state(fn (ShiftSchedule $record): string => $record->getWorkerProfile()?->company?->name ?? '-'),

                TextColumn::make('shift_label')
                    ->label('Shift')
                    ->badge()
                    ->state(fn (ShiftSchedule $record): string => $record->is_day_off ? 'Libur' : ($record->shift?->label() ?? '-'))
                    ->color(fn (ShiftSchedule $record): string => $record->is_day_off ? 'gray' : 'primary'),

                TextColumn::make('location.name')
                    ->label('Outlet')
                    ->placeholder('-')
                    ->description(fn (ShiftSchedule $record): ?string => $record->section),

                TextColumn::make('notes')
                    ->label('Catatan')
                    ->placeholder('-')
                    ->limit(40),
            ])
            ->filters([
                SelectFilter::make('company')
                    ->label('Badan Usaha')
                    ->options(fn (): array => static::placeableCompanyOptions(activeOnly: false))
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereWorkerInCompanies([(int) $data['value']])
                        : $query),

                Filter::make('date_range')
                    ->label('Rentang Tanggal')
                    ->schema([
                        DatePicker::make('from')->label('Dari'),
                        DatePicker::make('until')->label('Sampai'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('date', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('date', '<=', $date))),

                SelectFilter::make('company_location_id')
                    ->label('Outlet')
                    ->relationship('location', 'name', fn (Builder $query): Builder => static::scopeToPlaceableCompanies($query)),

                TernaryFilter::make('is_day_off')
                    ->label('Libur'),
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
     * Active shifts of a company the current user may place workers in.
     *
     * @return array<int, string>
     */
    public static function shiftOptions(int|string|null $companyId): array
    {
        if (! static::canPlaceInCompany($companyId)) {
            return [];
        }

        $brandIds = DataAccessService::coordinatedDivisionIds(auth()->user());

        return Shift::query()
            ->with('division')
            ->active()
            ->where('company_id', $companyId)
            ->when($brandIds !== null, fn (Builder $shifts) => $shifts->where(fn (Builder $own) => $own->whereIn('division_id', $brandIds)->orWhereNull('division_id')))
            ->orderBy('start_time')
            ->get()
            ->sortBy(fn (Shift $shift): string => ($shift->division?->name ?? '').'|'.$shift->start_time)
            ->mapWithKeys(fn (Shift $shift): array => [$shift->id => $shift->label()])
            ->all();
    }

    /**
     * Active outlets (company locations) of a company the current user may place workers in.
     *
     * @return array<int, string>
     */
    public static function locationOptions(int|string|null $companyId): array
    {
        if (! static::canPlaceInCompany($companyId)) {
            return [];
        }

        $brandIds = DataAccessService::coordinatedDivisionIds(auth()->user());

        return CompanyLocation::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->when($brandIds !== null, fn (Builder $outlets) => $outlets->whereIn('division_id', $brandIds))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Sections already used in the company's schedules, offered as suggestions ("Kasir", "Bar", ...).
     *
     * @return list<string>
     */
    public static function sectionSuggestions(int|string|null $companyId): array
    {
        if (! static::canPlaceInCompany($companyId)) {
            return [];
        }

        return ShiftSchedule::query()
            ->whereNotNull('section')
            ->whereWorkerInCompanies([(int) $companyId])
            ->distinct()
            ->orderBy('section')
            ->limit(50)
            ->pluck('section')
            ->all();
    }

    /**
     * Active workers of a company inside the user's data access, grouped by type and keyed
     * "employee:ID", "intern:ID" or "freelancer:ID".
     *
     * @return array<string, array<string, string>>
     */
    public static function workerOptions(int|string|null $companyId): array
    {
        if (! static::canPlaceInCompany($companyId)) {
            return [];
        }

        $options = [];

        foreach (['Karyawan' => [Employee::class, 'employee', 'nip'], 'Magang' => [Intern::class, 'intern', 'nis'], 'Freelancer' => [Freelancer::class, 'freelancer', 'freelancer_number']] as $group => [$model, $type, $code]) {
            $options[$group] = static::scopeToManagedWorkers($model::query())
                ->where('company_id', $companyId)
                ->where('status', 'active')
                ->orderBy('full_name')
                ->get(['id', $code, 'full_name'])
                ->mapWithKeys(fn (Model $worker): array => ["{$type}:{$worker->id}" => trim("{$worker->{$code}} - {$worker->full_name}", ' -')])
                ->all();
        }

        return array_filter($options);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageShiftSchedules::route('/'),
        ];
    }
}
