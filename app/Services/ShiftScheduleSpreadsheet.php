<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\XLSX\Options as XlsxReaderOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Weekly schedule grid in Excel, the way outlets already plan shifts: one sheet per outlet, one row
 * per worker and one column per date. A cell holds a shift code or name ("P", "JCHICKEN · Pagi"),
 * hours ("08:00 - 17:00"), "OFF" for a day off, or "-" to remove the schedule; a section goes in
 * brackets ("P (K)"). Blank cells and unknown values (like "mmy" for "works at Momoyo") are left alone.
 */
class ShiftScheduleSpreadsheet
{
    public const NO_OUTLET_SHEET = 'Tanpa Outlet';

    public const LEGEND_SHEET = 'Kode Shift';

    public const MAX_DAYS = 62;

    /** Issues kept in the import result; the rest are only counted. */
    public const MAX_ISSUES = 50;

    private const HEADER_ID = 'ID Pekerja';

    private const HEADER_NAME = 'Nama';

    private const HEADER_TYPE = 'Jenis';

    private const DAY_OFF_VALUES = ['OFF', 'DAY OFF', 'DAYOFF', 'LIBUR'];

    private const DAY_OFF_TEXT = 'OFF';

    private const CLEAR_TEXT = '-';

    private const DAY_NAMES = [1 => 'Sen', 2 => 'Sel', 3 => 'Rab', 4 => 'Kam', 5 => 'Jum', 6 => 'Sab', 7 => 'Min'];

    /** @var array<string, array{model: class-string<Model>, code: string, label: string}> */
    private const WORKER_TYPES = [
        'employee' => ['model' => Employee::class, 'code' => 'nip', 'label' => 'Karyawan'],
        'intern' => ['model' => Intern::class, 'code' => 'nis', 'label' => 'Magang'],
        'freelancer' => ['model' => Freelancer::class, 'code' => 'freelancer_number', 'label' => 'Freelancer'],
    ];

    /** @var Collection<int, Shift>|null */
    private ?Collection $shifts = null;

    /** @var list<int>|null Brands a coordinator is limited to while importing. */
    private ?array $brandIds = null;

    /**
     * Write the schedules of a company between two dates to a temporary .xlsx file and return its path.
     */
    public function export(User $user, Company $company, CarbonInterface $from, CarbonInterface $until, bool $includeUnscheduled = false): string
    {
        $dates = $this->dateRange($from, $until);

        $schedules = ShiftSchedule::query()
            ->visibleTo($user)
            ->whereWorkerInCompanies([$company->id])
            ->whereDate('date', '>=', $dates[0]->toDateString())
            ->whereDate('date', '<=', end($dates)->toDateString())
            ->with(['employee', 'intern', 'freelancer', 'shift.division'])
            ->get();

        /** @var array<string, array<string, array{worker: Model, type: string, cells: array<string, ShiftSchedule>}>> $sheets */
        $sheets = [];

        foreach ($schedules as $schedule) {
            $worker = $schedule->getWorkerProfile();

            if (! $worker) {
                continue;
            }

            $type = $this->workerType($worker);
            $sheetKey = (string) ($schedule->company_location_id ?? '');
            $sheets[$sheetKey]["{$type}:{$worker->getKey()}"] ??= ['worker' => $worker, 'type' => $type, 'cells' => []];
            $sheets[$sheetKey]["{$type}:{$worker->getKey()}"]['cells'][$schedule->date->toDateString()] = $schedule;
        }

        if ($includeUnscheduled) {
            $scheduled = collect($sheets)->flatMap(fn (array $rows): array => array_keys($rows))->flip();

            foreach ($this->managedWorkers($user, $company) as $key => [$type, $worker]) {
                if (! $scheduled->has($key)) {
                    $sheets[''][$key] = ['worker' => $worker, 'type' => $type, 'cells' => []];
                }
            }
        }

        $locations = CompanyLocation::query()
            ->whereIn('id', array_filter(array_keys($sheets)))
            ->orderBy('name')
            ->get();

        $path = tempnam(sys_get_temp_dir(), 'jadwal').'.xlsx';
        $writer = new XlsxWriter;
        $writer->openToFile($path);

        $first = true;
        $usedNames = [];
        $sheetOrder = [...$locations->map(fn (CompanyLocation $location): array => [(string) $location->id, $location->name])->all(), ['', self::NO_OUTLET_SHEET]];

        foreach ($sheetOrder as [$key, $name]) {
            if (! isset($sheets[$key]) && ! ($key === '' && $sheets === [])) {
                continue;
            }

            $sheet = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $first = false;
            $sheet->setName($this->uniqueSheetName($name, $usedNames));
            $sheet->setColumnWidth(16, 1);
            $sheet->setColumnWidth(28, 2);
            $sheet->setColumnWidth(11, 3);
            $sheet->setColumnWidthForRange(15, 4, 3 + count($dates));

            $writer->addRow(Row::fromValues([self::HEADER_ID, self::HEADER_NAME, self::HEADER_TYPE, ...array_map($this->dateHeader(...), $dates)], $this->headerStyle()));

            $rows = collect($sheets[$key] ?? [])->sortBy(fn (array $row): string => Str::upper((string) $row['worker']->full_name));

            foreach ($rows as $row) {
                $values = [
                    (string) $row['worker']->{self::WORKER_TYPES[$row['type']]['code']},
                    (string) $row['worker']->full_name,
                    self::WORKER_TYPES[$row['type']]['label'],
                ];
                $styles = [];

                foreach ($dates as $index => $date) {
                    $schedule = $row['cells'][$date->toDateString()] ?? null;
                    $values[] = $schedule ? $this->cellText($schedule) : '';

                    if ($schedule?->is_day_off) {
                        $styles[3 + $index] = $this->dayOffStyle();
                    }
                }

                $writer->addRow(Row::fromValuesWithStyles($values, columnStyles: $styles));
            }
        }

        $this->writeLegend($writer, $company);
        $writer->close();

        return $path;
    }

    /**
     * Read a grid written by {@see export()} (or typed by hand in the same shape) and save its schedules.
     *
     * @return array{created: int, updated: int, deleted: int, unchanged: int, issues: list<string>, issue_count: int}
     */
    public function import(User $user, Company $company, string $path): array
    {
        $this->shifts = null;
        $this->brandIds = null;
        $issues = [];
        /** @var array<string, array{column: string, worker_id: int, date: string, action: string, shift_id: int|null, location_id: int|null, section: string|null, origin: string}> $entries */
        $entries = [];
        $workers = $this->managedWorkers($user, $company);
        $workerIndex = $this->workerIndex($workers);
        $brandIds = DataAccessService::coordinatedDivisionIds($user);
        $this->brandIds = $brandIds;
        $locations = CompanyLocation::query()
            ->with('division')
            ->where('company_id', $company->id)
            ->when($brandIds !== null, fn (Builder $outlets) => $outlets->whereIn('division_id', $brandIds))
            ->get();
        $allDates = [];

        // Keep empty rows so the row keys are the Excel row numbers shown in issues.
        $readerOptions = new XlsxReaderOptions;
        $readerOptions->SHOULD_PRESERVE_EMPTY_ROWS = true;
        $reader = new XlsxReader($readerOptions);
        $reader->open($path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $sheetName = trim($sheet->getName());

                if (Str::upper($sheetName) === Str::upper(self::LEGEND_SHEET)) {
                    continue;
                }

                $location = null;

                if (Str::upper($sheetName) !== Str::upper(self::NO_OUTLET_SHEET)) {
                    $matches = $locations->filter(fn (CompanyLocation $candidate): bool => Str::upper($this->sheetName($candidate->name)) === Str::upper($this->sheetName($sheetName)));

                    if ($matches->count() !== 1) {
                        $issues[] = $matches->isEmpty()
                            ? "Sheet \"{$sheetName}\": outlet dengan nama ini tidak ada di {$company->name}. Sheet dilewati."
                            : "Sheet \"{$sheetName}\": cocok dengan beberapa outlet. Sheet dilewati.";

                        continue;
                    }

                    $location = $matches->first();
                }

                $columns = null;

                foreach ($sheet->getRowIterator() as $rowNumber => $row) {
                    $cells = array_map($this->cellToString(...), $row->toArray());

                    if ($columns === null) {
                        $columns = $this->readHeader($cells);

                        if ($columns['dates'] === []) {
                            $issues[] = "Sheet \"{$sheetName}\": baris pertama tidak berisi kolom tanggal. Sheet dilewati.";

                            break;
                        }

                        foreach ($columns['dates'] as $date) {
                            $allDates[$date] = true;
                        }

                        if (count($allDates) > self::MAX_DAYS) {
                            throw new InvalidArgumentException('File berisi lebih dari '.self::MAX_DAYS.' tanggal. Pecah menjadi beberapa file.');
                        }

                        continue;
                    }

                    $identifier = trim($cells[$columns['id']] ?? '');

                    if ($identifier === '') {
                        continue;
                    }

                    $where = "Sheet \"{$sheetName}\" baris {$rowNumber}";
                    $typeText = $columns['type'] !== null ? trim($cells[$columns['type']] ?? '') : '';
                    $worker = $this->findWorker($workerIndex, $identifier, $typeText);

                    if (is_string($worker)) {
                        $issues[] = "{$where}: {$worker}";

                        continue;
                    }

                    [$column, $workerId] = $worker;

                    foreach ($columns['dates'] as $index => $date) {
                        $text = trim(preg_replace('/\s+/u', ' ', $cells[$index] ?? '') ?? '');

                        if ($text === '') {
                            continue;
                        }

                        $entry = $this->parseCell($text, $company, $location);

                        if (is_string($entry)) {
                            $issues[] = "{$where}, {$date}: {$entry}";

                            continue;
                        }

                        $key = "{$column}:{$workerId}:{$date}";
                        $entry = ['column' => $column, 'worker_id' => $workerId, 'date' => $date, 'location_id' => $location?->id, 'origin' => $sheetName] + $entry;

                        if (isset($entries[$key])) {
                            $issues[] = "{$where}, {$date}: pekerja ini sudah dijadwalkan di sheet \"{$entries[$key]['origin']}\" pada tanggal yang sama. Yang dipakai jadwal pertama.";

                            continue;
                        }

                        $entries[$key] = $entry;
                    }
                }
            }
        } finally {
            $reader->close();
        }

        $counts = $this->save($user, $entries);

        return $counts + [
            'issues' => array_slice($issues, 0, self::MAX_ISSUES),
            'issue_count' => count($issues),
        ];
    }

    /**
     * @param  array<string, array{column: string, worker_id: int, date: string, action: string, shift_id: int|null, location_id: int|null, section: string|null, origin: string}>  $entries
     * @return array{created: int, updated: int, deleted: int, unchanged: int}
     */
    private function save(User $user, array $entries): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'unchanged' => 0];

        DB::transaction(function () use ($user, $entries, &$counts): void {
            foreach ($entries as $entry) {
                $schedule = ShiftSchedule::query()->where($entry['column'], $entry['worker_id'])->whereDate('date', $entry['date'])->first();

                if ($entry['action'] === 'clear') {
                    if ($schedule) {
                        $schedule->delete();
                        $counts['deleted']++;
                    }

                    continue;
                }

                $dayOff = $entry['action'] === 'day_off';
                $schedule ??= new ShiftSchedule([$entry['column'] => $entry['worker_id'], 'date' => $entry['date']]);
                $schedule->fill([
                    'shift_id' => $dayOff ? null : $entry['shift_id'],
                    'company_location_id' => $dayOff ? null : $entry['location_id'],
                    'is_day_off' => $dayOff,
                    'section' => $dayOff ? null : $entry['section'],
                ]);

                if (! $schedule->exists) {
                    $schedule->created_by = $user->id;
                }

                if ($schedule->exists && ! $schedule->isDirty()) {
                    $counts['unchanged']++;

                    continue;
                }

                $counts[$schedule->exists ? 'updated' : 'created']++;
                $schedule->save();
            }
        });

        return $counts;
    }

    /**
     * @return array{action: string, shift_id: int|null, section: string|null}|string The entry, or why the cell was skipped.
     */
    private function parseCell(string $text, Company $company, ?CompanyLocation $location): array|string
    {
        $upper = Str::upper($text);

        if (in_array($upper, self::DAY_OFF_VALUES, true)) {
            return ['action' => 'day_off', 'shift_id' => null, 'section' => null];
        }

        if ($text === self::CLEAR_TEXT) {
            return ['action' => 'clear', 'shift_id' => null, 'section' => null];
        }

        $section = null;

        if (preg_match('/^(.*?)\s*\(([^()]*)\)$/u', $text, $parts)) {
            $text = trim($parts[1]);
            $section = trim($parts[2]) ?: null;
        }

        $matches = $this->matchShifts($text, $company);

        if ($matches->count() > 1) {
            $brandIds = $this->divisionAndAncestorIds($location?->division);
            $ownBrand = $matches->filter(fn (Shift $shift): bool => in_array((int) $shift->division_id, $brandIds, true));
            $companyWide = $matches->whereNull('division_id');
            $matches = match (true) {
                $ownBrand->isNotEmpty() => $ownBrand,
                $companyWide->isNotEmpty() => $companyWide,
                default => $matches,
            };
        }

        return match ($matches->count()) {
            1 => ['action' => 'shift', 'shift_id' => (int) $matches->first()->id, 'section' => $section !== null ? Str::limit($section, 50, '') : null],
            0 => "\"{$text}\" bukan kode, nama, atau jam shift yang dikenal. Sel dilewati.",
            default => "\"{$text}\" cocok dengan beberapa shift ({$matches->map->qualifiedName()->implode(', ')}). Tulis dengan brand, misalnya \"{$matches->first()->qualifiedName()}\".",
        };
    }

    /**
     * @return Collection<int, Shift>
     */
    private function matchShifts(string $text, Company $company): Collection
    {
        $this->shifts ??= Shift::query()
            ->with('division')
            ->active()
            ->where('company_id', $company->id)
            ->when($this->brandIds !== null, fn (Builder $shifts) => $shifts->where(fn (Builder $own) => $own->whereIn('division_id', $this->brandIds)->orWhereNull('division_id')))
            ->get();

        if (preg_match('/^(\d{1,2})[:.](\d{2})\s*-\s*(\d{1,2})[:.](\d{2})$/', $text, $time)) {
            $start = sprintf('%02d:%s', $time[1], $time[2]);
            $end = sprintf('%02d:%s', $time[3], $time[4]);

            return $this->shifts->filter(fn (Shift $shift): bool => substr((string) $shift->start_time, 0, 5) === $start && substr((string) $shift->end_time, 0, 5) === $end)->values();
        }

        $parts = array_map('trim', preg_split('/\s*[·|]\s*/u', $text, 2) ?: [$text]);
        $upper = Str::upper($text);

        if (count($parts) === 2) {
            [$brand, $name] = array_map(fn (string $part): string => Str::upper($part), $parts);

            return $this->shifts->filter(fn (Shift $shift): bool => Str::upper((string) $shift->division?->name) === $brand
                && (Str::upper($shift->name) === $name || Str::upper((string) $shift->code) === $name))->values();
        }

        $byCode = $this->shifts->filter(fn (Shift $shift): bool => filled($shift->code) && Str::upper((string) $shift->code) === $upper);

        return ($byCode->isNotEmpty() ? $byCode : $this->shifts->filter(fn (Shift $shift): bool => Str::upper($shift->name) === $upper))->values();
    }

    /**
     * @param  list<string>  $cells
     * @return array{id: int, type: int|null, dates: array<int, string>}
     */
    private function readHeader(array $cells): array
    {
        $columns = ['id' => 0, 'type' => null, 'dates' => []];

        foreach ($cells as $index => $cell) {
            $label = Str::upper(trim($cell));

            if (in_array($label, [Str::upper(self::HEADER_ID), 'NIP', 'NO PEKERJA', 'ID'], true)) {
                $columns['id'] = $index;
            } elseif ($label === Str::upper(self::HEADER_TYPE)) {
                $columns['type'] = $index;
            } elseif ($date = $this->parseHeaderDate($cell)) {
                $columns['dates'][$index] = $date;
            }
        }

        return $columns;
    }

    private function parseHeaderDate(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (is_numeric($value) && (float) $value >= 20000 && (float) $value <= 80000) {
            return Carbon::create(1899, 12, 30)->addDays((int) $value)->toDateString();
        }

        if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $value, $iso)) {
            return checkdate((int) $iso[2], (int) $iso[3], (int) $iso[1]) ? "{$iso[1]}-{$iso[2]}-{$iso[3]}" : null;
        }

        if (preg_match('/(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})/', $value, $local)) {
            return checkdate((int) $local[2], (int) $local[1], (int) $local[3]) ? sprintf('%04d-%02d-%02d', $local[3], $local[2], $local[1]) : null;
        }

        return null;
    }

    /**
     * Active workers of the company inside the user's data access, keyed "employee:ID".
     *
     * @return array<string, array{0: string, 1: Model}>
     */
    private function managedWorkers(User $user, Company $company): array
    {
        $workers = [];

        foreach (self::WORKER_TYPES as $type => $definition) {
            /** @var Builder<Model> $query */
            $query = $definition['model']::query();

            DataAccessService::scopeWorkers($query, $user, includeOwn: false)
                ->where('company_id', $company->id)
                ->where('status', 'active')
                ->get(['id', $definition['code'], 'full_name'])
                ->each(function (Model $worker) use ($type, &$workers): void {
                    $workers["{$type}:{$worker->getKey()}"] = [$type, $worker];
                });
        }

        return $workers;
    }

    /**
     * @param  array<string, array{0: string, 1: Model}>  $workers
     * @return array<string, list<array{0: string, 1: int}>> Upper-cased worker number => matching [type, id] pairs.
     */
    private function workerIndex(array $workers): array
    {
        $index = [];

        foreach ($workers as [$type, $worker]) {
            $number = Str::upper(trim((string) $worker->{self::WORKER_TYPES[$type]['code']}));

            if ($number !== '') {
                $index[$number][] = [$type, (int) $worker->getKey()];
            }
        }

        return $index;
    }

    /**
     * @param  array<string, list<array{0: string, 1: int}>>  $index
     * @return array{0: string, 1: int}|string [column, id], or why the worker was not found.
     */
    private function findWorker(array $index, string $identifier, string $typeText): array|string
    {
        $candidates = $index[Str::upper($identifier)] ?? [];
        $wantedType = collect(self::WORKER_TYPES)->search(fn (array $definition, string $type): bool => in_array(Str::upper($typeText), [Str::upper($definition['label']), Str::upper($type)], true));

        if ($wantedType !== false) {
            $candidates = array_values(array_filter($candidates, fn (array $candidate): bool => $candidate[0] === $wantedType));
        }

        return match (count($candidates)) {
            1 => ["{$candidates[0][0]}_id", $candidates[0][1]],
            0 => "pekerja aktif \"{$identifier}\" tidak ditemukan atau di luar akses Anda. Baris dilewati.",
            default => "nomor \"{$identifier}\" dipakai beberapa jenis pekerja. Isi kolom Jenis (Karyawan, Magang, atau Freelancer). Baris dilewati.",
        };
    }

    private function cellText(ShiftSchedule $schedule): string
    {
        if ($schedule->is_day_off) {
            return self::DAY_OFF_TEXT;
        }

        if (! $schedule->shift) {
            return '';
        }

        $text = filled($schedule->shift->code) ? (string) $schedule->shift->code : $schedule->shift->qualifiedName();

        return filled($schedule->section) ? "{$text} ({$schedule->section})" : $text;
    }

    private function writeLegend(XlsxWriter $writer, Company $company): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName(self::LEGEND_SHEET);
        $sheet->setColumnWidth(24, 1);
        $sheet->setColumnWidth(12, 2);
        $sheet->setColumnWidth(24, 3);
        $sheet->setColumnWidth(16, 4);

        $writer->addRow(Row::fromValues(['Brand', 'Kode', 'Nama Shift', 'Jam'], $this->headerStyle()));

        Shift::query()
            ->with('division')
            ->active()
            ->where('company_id', $company->id)
            ->get()
            ->sortBy(fn (Shift $shift): string => ($shift->division?->name ?? '').'|'.$shift->start_time)
            ->each(fn (Shift $shift) => $writer->addRow(Row::fromValues([
                $shift->division?->name ?? 'Semua brand',
                (string) $shift->code,
                $shift->name,
                substr((string) $shift->start_time, 0, 5).' - '.substr((string) $shift->end_time, 0, 5),
            ])));

        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['Isi sel'], $this->headerStyle()));

        foreach ([
            ['P, Pagi, 08:00 - 17:00', 'Shift (brand outlet didahulukan)'],
            ['MOMOYO · Pagi', 'Shift brand lain'],
            ['P (K)', 'Shift + section'],
            ['OFF', 'Libur'],
            ['-', 'Hapus jadwal'],
            ['Kosong', 'Tidak diubah'],
        ] as $line) {
            $writer->addRow(Row::fromValues($line));
        }
    }

    /**
     * @return list<Carbon>
     */
    private function dateRange(CarbonInterface $from, CarbonInterface $until): array
    {
        $dates = array_values(array_map(fn (CarbonInterface $date): Carbon => Carbon::parse($date->toDateString()), iterator_to_array(CarbonPeriod::create($from->toDateString(), $until->toDateString()))));

        if ($dates === [] || count($dates) > self::MAX_DAYS) {
            throw new InvalidArgumentException('Rentang tanggal harus 1 sampai '.self::MAX_DAYS.' hari.');
        }

        return $dates;
    }

    private function dateHeader(Carbon $date): string
    {
        return self::DAY_NAMES[$date->dayOfWeekIso].' '.$date->format('d-m-Y');
    }

    /**
     * Excel sheet names: at most 31 characters and none of []:*?/\.
     */
    private function sheetName(string $name): string
    {
        return Str::limit(trim(str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', $name)), 31, '');
    }

    /**
     * @param  array<string, true>  $usedNames
     */
    private function uniqueSheetName(string $name, array &$usedNames): string
    {
        $base = $this->sheetName($name) ?: 'Outlet';
        $candidate = $base;
        $suffix = 2;

        while (isset($usedNames[Str::upper($candidate)])) {
            $candidate = Str::limit($base, 27, '')." ({$suffix})";
            $suffix++;
        }

        $usedNames[Str::upper($candidate)] = true;

        return $candidate;
    }

    private function workerType(Model $worker): string
    {
        return match (true) {
            $worker instanceof Employee => 'employee',
            $worker instanceof Intern => 'intern',
            default => 'freelancer',
        };
    }

    /**
     * @return list<int>
     */
    private function divisionAndAncestorIds(?Division $division): array
    {
        $ids = [];
        $current = $division;

        while ($current && ! in_array((int) $current->id, $ids, true)) {
            $ids[] = (int) $current->id;
            $current = $current->parent_id ? Division::query()->find($current->parent_id) : null;
        }

        return $ids;
    }

    private function cellToString(mixed $value): string
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_float($value) && floor($value) === $value && abs($value) < PHP_INT_MAX => (string) (int) $value,
            is_bool($value) => $value ? '1' : '0',
            default => (string) $value,
        };
    }

    private function headerStyle(): Style
    {
        return (new Style)->setFontBold()->setBackgroundColor('D9EAD3');
    }

    private function dayOffStyle(): Style
    {
        return (new Style)->setBackgroundColor(Color::RED)->setFontColor(Color::WHITE)->setFontBold();
    }
}
