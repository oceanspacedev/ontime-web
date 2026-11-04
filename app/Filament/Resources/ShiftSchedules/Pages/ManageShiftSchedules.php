<?php

namespace App\Filament\Resources\ShiftSchedules\Pages;

use App\Filament\Resources\ShiftSchedules\ShiftScheduleResource;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use Carbon\CarbonPeriod;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ManageShiftSchedules extends ManageRecords
{
    protected static string $resource = ShiftScheduleResource::class;

    /** Longest range one "Atur Jadwal" run may cover. */
    private const MAX_DAYS = 62;

    /** @var array<string, class-string> */
    private const WORKER_MODELS = ['employee' => Employee::class, 'intern' => Intern::class, 'freelancer' => Freelancer::class];

    protected function getHeaderActions(): array
    {
        return [
            Action::make('assignSchedule')
                ->label('Atur Jadwal')
                ->icon('heroicon-o-calendar-days')
                ->modalHeading('Atur Jadwal Shift')
                ->modalDescription('Tetapkan shift atau hari libur untuk beberapa pekerja sekaligus dalam rentang tanggal.')
                ->modalSubmitActionLabel('Simpan Jadwal')
                ->modalWidth('2xl')
                ->schema([
                    Select::make('company_id')
                        ->label('Badan Usaha')
                        ->options(fn (): array => ShiftScheduleResource::placeableCompanyOptions())
                        ->searchable()
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Set $set): void {
                            $set('workers', []);
                            $set('shift_id', null);
                        }),

                    Select::make('workers')
                        ->label('Pekerja')
                        ->multiple()
                        ->searchable()
                        ->options(fn (Get $get): array => ShiftScheduleResource::workerOptions($get('company_id')))
                        ->required(),

                    Grid::make(2)->schema([
                        DatePicker::make('date_from')
                            ->label('Dari Tanggal')
                            ->default(today())
                            ->required(),

                        DatePicker::make('date_until')
                            ->label('Sampai Tanggal')
                            ->default(today()->addDays(6))
                            ->afterOrEqual('date_from')
                            ->required(),
                    ]),

                    CheckboxList::make('weekdays')
                        ->label('Hari')
                        ->options([1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'])
                        ->default([1, 2, 3, 4, 5, 6, 7])
                        ->columns(4)
                        ->required(),

                    Radio::make('mode')
                        ->label('Jadwal')
                        ->options(['shift' => 'Masuk dengan shift', 'day_off' => 'Libur'])
                        ->default('shift')
                        ->inline()
                        ->live(),

                    Select::make('shift_id')
                        ->label('Shift')
                        ->options(fn (Get $get): array => ShiftScheduleResource::shiftOptions($get('company_id')))
                        ->required(fn (Get $get): bool => $get('mode') === 'shift')
                        ->visible(fn (Get $get): bool => $get('mode') === 'shift'),

                    TextInput::make('notes')
                        ->label('Catatan (opsional)')
                        ->maxLength(255),

                    Toggle::make('overwrite')
                        ->label('Timpa jadwal yang sudah ada pada tanggal tersebut')
                        ->default(true),
                ])
                ->action(fn (array $data) => $this->assignSchedule($data)),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function assignSchedule(array $data): void
    {
        $companyId = (int) $data['company_id'];
        abort_unless(ShiftScheduleResource::canPlaceInCompany($companyId), 403);

        $from = Carbon::parse($data['date_from'])->startOfDay();
        $until = Carbon::parse($data['date_until'])->startOfDay();

        if ($from->diffInDays($until) + 1 > self::MAX_DAYS) {
            Notification::make()
                ->title('Rentang tanggal terlalu panjang')
                ->body('Atur jadwal maksimal '.self::MAX_DAYS.' hari per proses.')
                ->danger()
                ->send();

            return;
        }

        $isDayOff = ($data['mode'] ?? 'shift') === 'day_off';
        $shiftId = $isDayOff ? null : (int) $data['shift_id'];

        if (! $isDayOff && ! Shift::query()->whereKey($shiftId)->where('company_id', $companyId)->exists()) {
            abort(422, 'Shift tidak termasuk badan usaha yang dipilih.');
        }

        $workers = $this->resolveWorkers((array) $data['workers'], $companyId);
        $weekdays = array_map('intval', (array) $data['weekdays']);
        $dates = collect(CarbonPeriod::create($from, $until))->filter(fn ($date): bool => in_array($date->dayOfWeekIso, $weekdays, true));
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        DB::transaction(function () use ($workers, $dates, $isDayOff, $shiftId, $data, &$counts): void {
            foreach ($workers as [$column, $workerId]) {
                foreach ($dates as $date) {
                    $schedule = ShiftSchedule::query()->where($column, $workerId)->whereDate('date', $date->toDateString())->first();

                    if ($schedule && ! ($data['overwrite'] ?? true)) {
                        $counts['skipped']++;

                        continue;
                    }

                    $schedule ??= new ShiftSchedule([$column => $workerId, 'date' => $date->toDateString()]);
                    $counts[$schedule->exists ? 'updated' : 'created']++;

                    $schedule->fill([
                        'shift_id' => $shiftId,
                        'is_day_off' => $isDayOff,
                        'notes' => $data['notes'] ?? null,
                        'created_by' => $schedule->created_by ?? auth()->id(),
                    ])->save();
                }
            }
        });

        Notification::make()
            ->title('Jadwal shift disimpan')
            ->body(count($workers)." pekerja, {$dates->count()} hari. Baru: {$counts['created']}, diperbarui: {$counts['updated']}, dilewati: {$counts['skipped']}.")
            ->success()
            ->send();
    }

    /**
     * Turn "employee:12" style keys into [column, id] pairs, keeping only workers of the company inside the user's data access.
     *
     * @param  list<string>  $keys
     * @return list<array{0: string, 1: int}>
     */
    private function resolveWorkers(array $keys, int $companyId): array
    {
        $workers = [];

        foreach ($keys as $key) {
            [$type, $id] = array_pad(explode(':', (string) $key, 2), 2, null);
            $model = self::WORKER_MODELS[$type] ?? null;

            if ($model && ShiftScheduleResource::scopeToManagedWorkers($model::query())->whereKey((int) $id)->where('company_id', $companyId)->exists()) {
                $workers[] = ["{$type}_id", (int) $id];
            }
        }

        abort_if($workers === [], 422, 'Pilih minimal satu pekerja dari badan usaha tersebut.');

        return $workers;
    }
}
