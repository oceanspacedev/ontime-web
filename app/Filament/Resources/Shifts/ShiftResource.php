<?php

namespace App\Filament\Resources\Shifts;

use App\Filament\Concerns\ScopesToManagedCompanies;
use App\Filament\Resources\Shifts\Pages\ManageShifts;
use App\Models\Division;
use App\Models\Shift;
use App\Services\DataAccessService;
use App\Services\ShiftUsageService;
use BackedEnum;
use Closure;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
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
use Illuminate\Support\Collection;
use Illuminate\Validation\Rules\Unique;
use Throwable;
use UnitEnum;
use WeakMap;

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

    /**
     * ShiftUsageService::hoursLockedReason() per shift object, so the form asks once for its four hour fields.
     *
     * @var WeakMap<Shift, string|null>|null
     */
    private static ?WeakMap $hoursLockedReasons = null;

    /**
     * Company admins manage every shift; a Koordinator manages the shifts of their brands.
     */
    public static function canViewAny(): bool
    {
        return static::canManageCompanyData() || (static::isCoordinator() && static::canManageWorkerData());
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['company', 'division'])->withCount('employees');

        if (! static::isCoordinator()) {
            return static::scopeToManagedCompanies($query);
        }

        $brandIds = DataAccessService::coordinatedDivisionIds(auth()->user());

        return static::scopeToPlaceableCompanies($query)->when($brandIds !== null, fn (Builder $brandShifts) => $brandShifts->whereIn('division_id', $brandIds));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('company_id')
                    ->label('Badan Usaha')
                    ->options(fn (): array => static::shiftCompanyOptions())
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
                    ->required(fn (): bool => static::isCoordinator())
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

                TextInput::make('talenta_code')
                    ->label('Kode Talenta')
                    ->placeholder('SIFT 09 -18')
                    ->helperText('Kode shift di Talenta, untuk mencocokkan jadwal.')
                    ->maxLength(50),

                TimePicker::make('start_time')
                    ->label('Jam Masuk')
                    ->seconds(false)
                    ->disabled(fn (?Shift $record): bool => static::hoursLockedReason($record) !== null)
                    ->helperText(fn (?Shift $record): ?string => static::hoursLockedReason($record))
                    ->required(),

                TimePicker::make('end_time')
                    ->label('Jam Pulang')
                    ->seconds(false)
                    ->disabled(fn (?Shift $record): bool => static::hoursLockedReason($record) !== null)
                    ->helperText('Boleh lewat tengah malam.')
                    ->required(),

                TimePicker::make('second_start_time')
                    ->label('Jam Masuk Segmen 2')
                    ->seconds(false)
                    ->disabled(fn (?Shift $record): bool => static::hoursLockedReason($record) !== null)
                    ->helperText('Khusus split shift: kosongkan bila shift hanya satu segmen.')
                    ->requiredWith('second_end_time')
                    ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                        $firstEnd = substr((string) $get('end_time'), 0, 5);

                        if (filled($value) && filled($firstEnd) && substr((string) $value, 0, 5) <= $firstEnd) {
                            $fail('Segmen 2 harus dimulai setelah segmen 1 selesai.');
                        }
                    }),

                TimePicker::make('second_end_time')
                    ->label('Jam Pulang Segmen 2')
                    ->seconds(false)
                    ->disabled(fn (?Shift $record): bool => static::hoursLockedReason($record) !== null)
                    ->requiredWith('second_start_time'),

                // Not locked with the hours: it only applies to check-ins made from now on (each attendance
                // keeps the normal hours of the moment it was checked in).
                TextInput::make('normal_minutes')
                    ->label('Jam kerja normal (jam)')
                    ->numeric()
                    ->step(0.5)
                    ->suffix('jam')
                    ->placeholder('Seluruh jam shift')
                    ->helperText('Kosong = seluruh jam shift normal, tanpa lembur otomatis. Jam kerja di dalam shift di atas angka ini dihitung lembur otomatis, berlaku untuk absen masuk setelah disimpan.')
                    ->formatStateUsing(fn (mixed $state): int|float|null => is_numeric($state) ? $state / 60 : null)
                    ->dehydrateStateUsing(fn (mixed $state): ?int => is_numeric($state) ? (int) round($state * 60) : null)
                    ->rule(fn (Get $get, ?Shift $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                        if (blank($value)) {
                            return;
                        }

                        $scheduledMinutes = static::scheduledMinutesOf($get, $record);

                        if (! is_numeric($value) || $value <= 0) {
                            $fail('Jam kerja normal harus lebih dari 0.');
                        } elseif ($scheduledMinutes !== null && (int) round($value * 60) > $scheduledMinutes) {
                            $fail('Jam kerja normal tidak boleh melebihi panjang shift ('.static::hoursLabel($scheduledMinutes).' jam).');
                        }
                    })
                    ->columnSpanFull(),

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
                    ->state(fn (Shift $record): string => $record->hoursText().($record->crossesMidnight() && ! $record->hasSecondSegment() ? ' (+1 hari)' : '')),

                TextColumn::make('talenta_code')
                    ->label('Kode Talenta')
                    ->placeholder('-')
                    ->toggleable(),

                TextColumn::make('normal_minutes')
                    ->label('Jam Normal')
                    ->formatStateUsing(fn (?int $state): string => static::hoursLabel((int) $state).' jam')
                    ->placeholder('Seluruh shift')
                    ->toggleable(),

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
                    ->options(fn (): array => static::shiftCompanyOptions(activeOnly: false)),

                SelectFilter::make('division_id')
                    ->label('Brand')
                    ->relationship('division', 'name', fn (Builder $query): Builder => $query->whereIn('id', static::getEloquentQuery()->withoutEagerLoads()->reorder()->whereNotNull('division_id')->select('shifts.division_id'))),

                TernaryFilter::make('is_active')
                    ->label('Status Aktif'),
            ])
            ->recordActions([
                EditAction::make()
                    ->slideOver()
                    ->modalWidth(Width::Medium),
                DeleteAction::make()
                    ->disabled(fn (Shift $record): bool => app(ShiftUsageService::class)->deletionBlockedReason($record) !== null)
                    ->tooltip(fn (Shift $record): ?string => app(ShiftUsageService::class)->deletionBlockedReason($record))
                    ->before(function (DeleteAction $action, Shift $record): void {
                        $reason = app(ShiftUsageService::class)->deletionBlockedReason($record);

                        if ($reason === null) {
                            return;
                        }

                        Notification::make()->title('Shift tidak dapat dihapus')->body($reason)->danger()->send();
                        $action->cancel();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->using(function (DeleteBulkAction $action, Collection $records): void {
                            $records->each(function (Shift $shift) use ($action): void {
                                if (app(ShiftUsageService::class)->deletionBlockedReason($shift) !== null) {
                                    $action->reportBulkProcessingFailure(
                                        'in_use',
                                        fn (int $count): string => "{$count} shift tidak dihapus karena sudah dipakai pekerja, jadwal, atau absensi. Nonaktifkan shift tersebut.",
                                    );

                                    return;
                                }

                                try {
                                    $shift->delete() || $action->reportBulkProcessingFailure();
                                } catch (Throwable $exception) {
                                    $action->reportBulkProcessingFailure();
                                    report($exception);
                                }
                            });
                        }),
                ]),
            ]);
    }

    /**
     * Scheduled minutes of the shift as the form holds it now (both parts of a split shift); the stored
     * hours where the form has none. Null while the hours are not filled in.
     */
    public static function scheduledMinutesOf(Get $get, ?Shift $record): ?int
    {
        $hours = [];

        foreach (['start_time', 'end_time', 'second_start_time', 'second_end_time'] as $field) {
            $hours[$field] = filled($get($field)) ? $get($field) : $record?->{$field};
        }

        if (blank($hours['start_time']) || blank($hours['end_time'])) {
            return null;
        }

        return (new Shift($hours))->scheduledMinutes();
    }

    /**
     * Minutes as hours without trailing zeros: 480 => "8", 825 => "13,75".
     */
    public static function hoursLabel(int $minutes): string
    {
        return str_replace('.', ',', (string) round($minutes / 60, 2));
    }

    /**
     * Why the hours of the shift being edited are locked (ShiftUsageService), or null for a new or unused shift.
     */
    public static function hoursLockedReason(?Shift $record): ?string
    {
        if (! $record?->exists) {
            return null;
        }

        self::$hoursLockedReasons ??= new WeakMap;

        if (! self::$hoursLockedReasons->offsetExists($record)) {
            self::$hoursLockedReasons[$record] = app(ShiftUsageService::class)->hoursLockedReason($record);
        }

        return self::$hoursLockedReasons[$record];
    }

    /**
     * Companies whose shifts the user manages: company admins their companies, a Koordinator the companies of their brands.
     *
     * @return array<int, string>
     */
    public static function shiftCompanyOptions(bool $activeOnly = true): array
    {
        return static::isCoordinator() ? static::placeableCompanyOptions($activeOnly) : static::managedCompanyOptions($activeOnly);
    }

    /**
     * Active divisions of a managed company, labelled with their full path ("F&B > JCHICKEN").
     *
     * @return array<int, string>
     */
    public static function brandOptions(int|string|null $companyId): array
    {
        if (! $companyId || ! in_array((int) $companyId, array_map('intval', array_keys(static::shiftCompanyOptions())), true)) {
            return [];
        }

        $coordinatedBrandIds = static::isCoordinator() ? DataAccessService::coordinatedDivisionIds(auth()->user()) : null;

        $divisions = Division::query()->where('company_id', $companyId)->get(['id', 'parent_id', 'name', 'is_active'])->keyBy('id');

        return $divisions
            ->where('is_active', true)
            ->when($coordinatedBrandIds !== null, fn ($brands) => $brands->whereIn('id', $coordinatedBrandIds))
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
