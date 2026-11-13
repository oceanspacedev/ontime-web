<?php

namespace App\Filament\Pages;

use App\Models\CompanyLocation;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\Shift;
use App\Models\User;
use App\Services\SchedulingOptions;
use App\Services\ShiftAssignmentService;
use App\Services\WeeklyScheduleService;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * The week as the outlets' spreadsheets show it: a line per person, a column per day.
 * Same data as the weekly tab of the mobile app; a cell opens the day's shift, outlet and section.
 */
class JadwalMingguan extends Page
{
    protected static ?string $title = 'Jadwal Mingguan';

    protected static ?string $navigationLabel = 'Jadwal Mingguan';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|UnitEnum|null $navigationGroup = 'Jadwal & Shift';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.jadwal-mingguan';

    /** Any date inside the week shown (YYYY-MM-DD). */
    public string $date = '';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->canManageWorkers();
    }

    public function mount(): void
    {
        $this->date = today()->toDateString();
    }

    public function previousWeek(): void
    {
        $this->date = Carbon::parse($this->date)->subWeek()->toDateString();
    }

    public function nextWeek(): void
    {
        $this->date = Carbon::parse($this->date)->addWeek()->toDateString();
    }

    public function thisWeek(): void
    {
        $this->date = today()->toDateString();
    }

    /**
     * @return array<string, mixed>
     */
    public function getWeekProperty(): array
    {
        return app(WeeklyScheduleService::class)->week(auth()->user(), Carbon::parse($this->date));
    }

    /**
     * Text of one cell: the shift code ("SP"), "OFF", or a dash.
     *
     * @param  array<string, mixed>|null  $cell
     */
    public function cellText(?array $cell): string
    {
        return match (true) {
            $cell === null => '–',
            $cell['is_day_off'] => 'OFF',
            default => ($cell['shift']['code'] ?? null) ?: ($cell['shift']['name'] ?? '–'),
        };
    }

    /**
     * Shifts to pick for a day, grouped by brand; an outlet narrows them to its brand and the company-wide ones.
     *
     * @return array<string, array<int, string>>
     */
    public function shiftChoices(int|string|null $companyId, int|string|null $outletId): array
    {
        $outlet = $outletId ? CompanyLocation::query()->find($outletId) : null;

        return app(SchedulingOptions::class)->shifts(auth()->user(), $companyId, borrowing: true)
            ->filter(fn (Shift $shift): bool => ! $outlet?->division_id || $shift->division_id === null || (int) $shift->division_id === (int) $outlet->division_id)
            ->groupBy(fn (Shift $shift): string => $shift->division?->name ?? 'Semua brand')
            ->map(fn ($shifts) => $shifts->mapWithKeys(fn (Shift $shift): array => [$shift->id => ($shift->code ? "{$shift->code} · " : '').$shift->name.' ('.$shift->hoursText().')'])->all())
            ->all();
    }

    /**
     * Whether the outlet belongs to a brand the user does not run, so a worker is only lent there.
     */
    private function isOtherBrand(CompanyLocation $location): bool
    {
        $brandIds = app(SchedulingOptions::class)->ownBrandIds(auth()->user());

        return $brandIds !== null && ! in_array((int) $location->division_id, $brandIds, true);
    }

    public function editCellAction(): Action
    {
        return Action::make('editCell')
            ->modalHeading(fn (array $arguments): string => ($arguments['name'] ?? 'Jadwal').' · '.Carbon::parse($arguments['date'] ?? today())->translatedFormat('l, d F Y'))
            ->modalWidth(Width::Medium)
            ->modalSubmitActionLabel('Simpan')
            ->fillForm(fn (array $arguments): array => [
                'mode' => $arguments['mode'] ?? ShiftAssignmentService::MODE_SHIFT,
                'shift_id' => $arguments['shift_id'] ?? null,
                'company_location_id' => $arguments['location_id'] ?? null,
                'section' => $arguments['section'] ?? null,
            ])
            ->schema(fn (array $arguments): array => [
                ToggleButtons::make('mode')
                    ->hiddenLabel()
                    ->options([ShiftAssignmentService::MODE_SHIFT => 'Masuk', ShiftAssignmentService::MODE_DAY_OFF => 'Libur', ShiftAssignmentService::MODE_CLEAR => 'Kosongkan'])
                    ->colors([ShiftAssignmentService::MODE_SHIFT => 'primary', ShiftAssignmentService::MODE_DAY_OFF => 'gray', ShiftAssignmentService::MODE_CLEAR => 'danger'])
                    ->inline()
                    ->grouped()
                    ->live(),

                Select::make('company_location_id')
                    ->label('Outlet')
                    ->options(fn (): array => app(SchedulingOptions::class)->locations(auth()->user(), $arguments['company_id'] ?? null, borrowing: true)
                        ->mapWithKeys(fn (CompanyLocation $location): array => [$location->id => $location->name.($this->isOtherBrand($location) ? ' · pinjam' : '')])
                        ->all())
                    ->searchable()
                    ->placeholder('Lokasi mana pun')
                    ->live()
                    ->afterStateUpdated(function (Set $set, Get $get, ?string $state): void {
                        // A shift of another brand no longer fits the chosen outlet.
                        $shift = Shift::query()->find($get('shift_id'));
                        $outlet = $state ? CompanyLocation::query()->find($state) : null;

                        if ($shift && $outlet && $shift->division_id !== null && $outlet->division_id !== null && (int) $shift->division_id !== (int) $outlet->division_id) {
                            $set('shift_id', null);
                        }
                    })
                    ->visible(fn (Get $get): bool => $get('mode') === ShiftAssignmentService::MODE_SHIFT),

                Select::make('shift_id')
                    ->label('Shift')
                    ->options(fn (Get $get): array => $this->shiftChoices($arguments['company_id'] ?? null, $get('company_location_id')))
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(function (Set $set, Get $get, ?string $state) use ($arguments): void {
                        // With one outlet for the shift's brand, the outlet follows the shift.
                        $shift = $state ? Shift::query()->find($state) : null;

                        if ($shift?->division_id && blank($get('company_location_id'))) {
                            $outlets = app(SchedulingOptions::class)->locations(auth()->user(), $arguments['company_id'] ?? null, borrowing: true)->where('division_id', $shift->division_id);

                            if ($outlets->count() === 1) {
                                $set('company_location_id', $outlets->first()->id);
                            }
                        }
                    })
                    ->required(fn (Get $get): bool => $get('mode') === ShiftAssignmentService::MODE_SHIFT)
                    ->visible(fn (Get $get): bool => $get('mode') === ShiftAssignmentService::MODE_SHIFT),

                TextInput::make('section')
                    ->label('Section')
                    ->datalist(fn (): array => app(SchedulingOptions::class)->sections(auth()->user(), $arguments['company_id'] ?? null))
                    ->maxLength(50)
                    ->visible(fn (Get $get): bool => $get('mode') === ShiftAssignmentService::MODE_SHIFT),
            ])
            ->action(function (array $data, array $arguments): void {
                $user = auth()->user();
                $model = ['employee' => Employee::class, 'intern' => Intern::class, 'freelancer' => Freelancer::class][$arguments['type'] ?? ''] ?? null;
                $worker = $model && $user instanceof User ? $model::query()->find($arguments['id'] ?? 0) : null;

                if (! $worker) {
                    Notification::make()->title('Pekerja tidak ditemukan')->danger()->send();

                    return;
                }

                try {
                    app(ShiftAssignmentService::class)->assignDay(
                        $user,
                        $worker,
                        (string) $arguments['date'],
                        $data['mode'],
                        isset($data['shift_id']) ? (int) $data['shift_id'] : null,
                        isset($data['company_location_id']) ? (int) $data['company_location_id'] : null,
                        $data['section'] ?? null,
                    );
                } catch (AuthorizationException|ValidationException $exception) {
                    Notification::make()->title('Jadwal tidak disimpan')->body($exception instanceof ValidationException ? collect($exception->errors())->flatten()->implode(' ') : $exception->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title($data['mode'] === ShiftAssignmentService::MODE_CLEAR ? 'Jadwal dihapus' : 'Jadwal disimpan')->success()->send();
            });
    }
}
