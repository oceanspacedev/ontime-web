<?php

namespace App\Filament\Resources\ShiftSchedules\Pages;

use App\Filament\Resources\ShiftSchedules\ShiftScheduleResource;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\ShiftSchedule;
use App\Services\ScheduleCopyService;
use App\Services\ShiftScheduleSpreadsheet;
use Carbon\CarbonPeriod;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

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
                ->slideOver()
                ->modalWidth(Width::Medium)
                ->modalIcon('heroicon-o-calendar-days')
                ->modalHeading('Atur Jadwal Shift')
                ->modalSubmitActionLabel('Simpan Jadwal')
                ->schema([
                    Section::make('Pekerja')
                        ->icon('heroicon-o-users')
                        ->compact()
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
                                    $set('company_location_id', null);
                                }),

                            Select::make('workers')
                                ->label('Pekerja')
                                ->multiple()
                                ->searchable()
                                ->options(fn (Get $get): array => ShiftScheduleResource::workerOptions($get('company_id')))
                                ->required(),
                        ]),

                    Section::make('Tanggal')
                        ->icon('heroicon-o-calendar')
                        ->compact()
                        ->columns(2)
                        ->schema([
                            DatePicker::make('date_from')
                                ->label('Dari')
                                ->default(today())
                                ->required(),

                            DatePicker::make('date_until')
                                ->label('Sampai')
                                ->default(today()->addDays(6))
                                ->afterOrEqual('date_from')
                                ->required(),

                            ToggleButtons::make('weekdays')
                                ->label('Hari')
                                ->multiple()
                                ->options([1 => 'Sen', 2 => 'Sel', 3 => 'Rab', 4 => 'Kam', 5 => 'Jum', 6 => 'Sab', 7 => 'Min'])
                                ->default([1, 2, 3, 4, 5, 6, 7])
                                ->inline()
                                ->required()
                                ->columnSpanFull(),
                        ]),

                    Section::make('Jadwal')
                        ->icon('heroicon-o-clock')
                        ->compact()
                        ->columns(2)
                        ->schema([
                            ToggleButtons::make('mode')
                                ->hiddenLabel()
                                ->options(['shift' => 'Masuk', 'day_off' => 'Libur'])
                                ->icons(['shift' => 'heroicon-o-briefcase', 'day_off' => 'heroicon-o-moon'])
                                ->colors(['shift' => 'primary', 'day_off' => 'gray'])
                                ->default('shift')
                                ->inline()
                                ->grouped()
                                ->live()
                                ->columnSpanFull(),

                            Select::make('shift_id')
                                ->label('Shift')
                                ->options(fn (Get $get): array => ShiftScheduleResource::shiftOptions($get('company_id'), borrowing: true))
                                ->searchable()
                                ->required(fn (Get $get): bool => $get('mode') === 'shift')
                                ->visible(fn (Get $get): bool => $get('mode') === 'shift')
                                ->columnSpanFull(),

                            Select::make('company_location_id')
                                ->label('Outlet')
                                ->options(fn (Get $get): array => ShiftScheduleResource::locationOptions($get('company_id'), borrowing: true))
                                ->searchable()
                                ->placeholder('Lokasi mana pun')
                                ->visible(fn (Get $get): bool => $get('mode') === 'shift'),

                            TextInput::make('section')
                                ->label('Section')
                                ->placeholder('Kasir, Bar, Cook')
                                ->datalist(fn (Get $get): array => ShiftScheduleResource::sectionSuggestions($get('company_id')))
                                ->maxLength(50)
                                ->visible(fn (Get $get): bool => $get('mode') === 'shift'),

                            TextInput::make('notes')
                                ->label('Catatan')
                                ->maxLength(255)
                                ->columnSpanFull(),

                            Toggle::make('overwrite')
                                ->label('Timpa jadwal yang sudah ada')
                                ->default(true)
                                ->columnSpanFull(),
                        ]),
                ])
                ->action(fn (array $data) => $this->assignSchedule($data)),

            Action::make('copyWeek')
                ->label('Salin Minggu')
                ->icon('heroicon-o-document-duplicate')
                ->color('gray')
                ->modalWidth(Width::Medium)
                ->modalHeading('Salin Jadwal Satu Minggu')
                ->modalDescription('Hari yang sudah terisi tidak ditimpa, dan shift yang sudah nonaktif dilewati.')
                ->modalSubmitActionLabel('Salin')
                ->schema([
                    DatePicker::make('from')
                        ->label('Salin dari minggu yang memuat tanggal')
                        ->default(today()->subWeek())
                        ->required(),

                    DatePicker::make('to')
                        ->label('Ke minggu yang memuat tanggal')
                        ->default(today())
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $copies = app(ScheduleCopyService::class);
                    $result = $copies->copyWeek(auth()->user(), Carbon::parse($data['from']), Carbon::parse($data['to']));

                    Notification::make()
                        ->title($result['copied'] > 0 ? 'Jadwal disalin' : 'Tidak ada jadwal yang disalin')
                        ->body($copies->summary($result))
                        ->color($result['copied'] > 0 ? 'success' : 'warning')
                        ->send();
                }),

            Action::make('exportSchedule')
                ->label('Ekspor Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->modalHeading('Ekspor Jadwal Mingguan')
                ->modalSubmitActionLabel('Unduh Excel')
                ->schema([
                    Select::make('company_id')
                        ->label('Badan Usaha')
                        ->options(fn (): array => ShiftScheduleResource::placeableCompanyOptions())
                        ->searchable()
                        ->required(),

                    Grid::make(2)->schema([
                        DatePicker::make('date_from')
                            ->label('Dari Tanggal')
                            ->default(today()->startOfWeek())
                            ->required(),

                        DatePicker::make('date_until')
                            ->label('Sampai Tanggal')
                            ->default(today()->endOfWeek())
                            ->afterOrEqual('date_from')
                            ->required(),
                    ]),

                    Toggle::make('include_unscheduled')
                        ->label('Sertakan pekerja tanpa jadwal')
                        ->default(true),
                ])
                ->action(fn (array $data): ?BinaryFileResponse => $this->exportSchedule($data)),

            Action::make('importSchedule')
                ->label('Impor Excel')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->modalHeading('Impor Jadwal Mingguan')
                ->modalDescription('Pakai file dari Ekspor Excel. Nama sheet = nama outlet.')
                ->modalSubmitActionLabel('Impor')
                ->schema([
                    Select::make('company_id')
                        ->label('Badan Usaha')
                        ->options(fn (): array => ShiftScheduleResource::placeableCompanyOptions())
                        ->searchable()
                        ->required(),

                    FileUpload::make('file')
                        ->label('File Excel (.xlsx)')
                        ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                        ->storeFiles(false)
                        ->required(),
                ])
                ->action(fn (array $data) => $this->importSchedule($data)),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function exportSchedule(array $data): ?BinaryFileResponse
    {
        $company = Company::query()->findOrFail((int) $data['company_id']);
        abort_unless(ShiftScheduleResource::canPlaceInCompany($company->id), 403);

        $from = Carbon::parse($data['date_from'])->startOfDay();
        $until = Carbon::parse($data['date_until'])->startOfDay();

        if ($from->diffInDays($until) + 1 > ShiftScheduleSpreadsheet::MAX_DAYS) {
            Notification::make()
                ->title('Rentang tanggal terlalu panjang')
                ->body('Ekspor maksimal '.ShiftScheduleSpreadsheet::MAX_DAYS.' hari per file.')
                ->danger()
                ->send();

            return null;
        }

        $path = app(ShiftScheduleSpreadsheet::class)->export(auth()->user(), $company, $from, $until, (bool) ($data['include_unscheduled'] ?? false));
        $fileName = sprintf('jadwal-shift-%s-%s-sd-%s.xlsx', Str::slug($company->code ?: $company->name), $from->format('Ymd'), $until->format('Ymd'));

        return response()->download($path, $fileName)->deleteFileAfterSend();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function importSchedule(array $data): void
    {
        $company = Company::query()->findOrFail((int) $data['company_id']);
        abort_unless(ShiftScheduleResource::canPlaceInCompany($company->id), 403);

        $file = $data['file'];
        $path = $file instanceof TemporaryUploadedFile ? $file->getRealPath() : (string) $file;

        try {
            $result = app(ShiftScheduleSpreadsheet::class)->import(auth()->user(), $company, $path);
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->title('File tidak bisa diimpor')
                ->body($exception instanceof InvalidArgumentException ? $exception->getMessage() : 'Pastikan file berformat .xlsx hasil Ekspor Excel.')
                ->danger()
                ->send();

            return;
        }

        $summary = "Baru: {$result['created']}, diperbarui: {$result['updated']}, dihapus: {$result['deleted']}, tidak berubah: {$result['unchanged']}.";
        $lines = collect($result['issues'])->take(8)->map(fn (string $issue): string => '• '.e($issue));

        if ($result['issue_count'] > 8) {
            $lines->push('…dan '.($result['issue_count'] - 8).' catatan lain.');
        }

        Notification::make()
            ->title($result['issue_count'] > 0 ? "Jadwal diimpor dengan {$result['issue_count']} catatan" : 'Jadwal diimpor')
            ->body(new HtmlString(collect([e($summary), ...$lines])->implode('<br>')))
            ->status($result['issue_count'] > 0 ? 'warning' : 'success')
            ->persistent($result['issue_count'] > 0)
            ->send();
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

        if (! $isDayOff && ! array_key_exists($shiftId, ShiftScheduleResource::shiftOptions($companyId, borrowing: true))) {
            abort(422, 'Shift tidak termasuk badan usaha yang dipilih.');
        }

        $locationId = $isDayOff || blank($data['company_location_id'] ?? null) ? null : (int) $data['company_location_id'];

        if ($locationId && ! array_key_exists($locationId, ShiftScheduleResource::locationOptions($companyId, borrowing: true))) {
            abort(422, 'Outlet tidak termasuk badan usaha yang dipilih.');
        }

        $section = $isDayOff ? null : (trim((string) ($data['section'] ?? '')) ?: null);

        $workers = $this->resolveWorkers((array) $data['workers'], $companyId);
        $weekdays = array_map('intval', (array) $data['weekdays']);
        $dates = collect(CarbonPeriod::create($from, $until))->filter(fn ($date): bool => in_array($date->dayOfWeekIso, $weekdays, true));
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        DB::transaction(function () use ($workers, $dates, $isDayOff, $shiftId, $locationId, $section, $data, &$counts): void {
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
                        'company_location_id' => $locationId,
                        'is_day_off' => $isDayOff,
                        'section' => $section,
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
