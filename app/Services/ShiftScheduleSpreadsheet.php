<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\LeaveRequest;
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
use Illuminate\Support\Collection as SupportCollection;
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
 *
 * A worker may have several schedules on one date (up to three): one on each outlet's sheet, or "P+S" in
 * one cell for two at the same outlet. Their hours may not overlap.
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

    /** Joins the shifts of one outlet in one cell: "P+S" is two schedules at that outlet on that date. */
    private const JOIN_TEXT = '+';

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
     * The import's worker-dates not written yet, with their entries: a neighbouring day is read as the file
     * will leave it while it is still pending, and from the database once written ({@see self::overlapsPlannedNeighbours()}).
     *
     * @var array<string, list<array{column: string, worker_id: int, date: string, action: string, shift_id: int|null, location_id: int|null, section: string|null, origin: string}>>
     */
    private array $pendingEntries = [];

    /** @var array<string, list<array{location_id: int|null, shift_id: int}>> */
    private array $pendingDropped = [];

    /**
     * Write the schedules of a company between two dates to a temporary .xlsx file and return its path.
     */
    public function export(User $user, Company $company, CarbonInterface $from, CarbonInterface $until, bool $includeUnscheduled = false): string
    {
        $dates = $this->dateRange($from, $until);
        // The shifts the import reads cells against, so each cell is written the way it reads back.
        $this->shifts = null;
        $this->brandIds = DataAccessService::coordinatedDivisionIds($user);

        $schedules = ShiftSchedule::query()
            ->visibleTo($user)
            ->whereWorkerInCompanies([$company->id])
            ->whereDate('date', '>=', $dates[0]->toDateString())
            ->whereDate('date', '<=', end($dates)->toDateString())
            ->with(['employee', 'intern', 'freelancer', 'shift.division'])
            ->get();

        /** @var array<string, array<string, array{worker: Model, type: string, cells: array<string, list<ShiftSchedule>>}>> $sheets */
        $sheets = [];

        // Each schedule on its outlet's sheet; several at one outlet on one date share the cell ("P+S").
        foreach (ShiftSchedule::inDayOrder($schedules) as $schedule) {
            $worker = $schedule->getWorkerProfile();

            if (! $worker) {
                continue;
            }

            $type = $this->workerType($worker);
            $sheetKey = (string) ($schedule->company_location_id ?? '');
            $sheets[$sheetKey]["{$type}:{$worker->getKey()}"] ??= ['worker' => $worker, 'type' => $type, 'cells' => []];
            $sheets[$sheetKey]["{$type}:{$worker->getKey()}"]['cells'][$schedule->date->toDateString()][] = $schedule;
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
            ->with('division')
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
                    $ofDay = collect($row['cells'][$date->toDateString()] ?? []);
                    $values[] = $this->joinedCellText($ofDay, $company, $locations->firstWhere('id', (int) $key));

                    if ($ofDay->contains(fn (ShiftSchedule $schedule): bool => (bool) $schedule->is_day_off)) {
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
        /** @var array<string, list<array{column: string, worker_id: int, date: string, action: string, shift_id: int|null, location_id: int|null, section: string|null, origin: string, cell: string, on_leave: bool}>> $entries */
        $entries = [];
        /** @var array<string, list<array{location_id: int|null, shift_id: int}>> $dropped shift parts refused while reading, per worker and date */
        $dropped = [];
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
        /** @var array<string, array<string, true>> $leaveDates charged leave dates per sheet and worker, read once */
        $leaveDates = [];

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

                    if ($matches->count() === 1 && ! $matches->first()->is_active) {
                        $issues[] = "Sheet \"{$sheetName}\": outlet ini sudah nonaktif. Sheet dilewati.";

                        continue;
                    }

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

                        foreach ($columns['unreadable'] as $index => $header) {
                            $issues[] = "Sheet \"{$sheetName}\" kolom ".($index + 1).": \"{$header}\" bukan tanggal yang valid. Kolom dilewati.";
                        }

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

                        $parsed = $this->parseCellParts($text, $company, $location);

                        if (is_string($parsed)) {
                            $issues[] = "{$where}, {$date}: {$parsed}";

                            continue;
                        }

                        if ($parsed[0]['action'] !== 'clear' && ($dateError = app(ShiftAssignmentService::class)->dateError($workers[substr($column, 0, -3).":{$workerId}"][1], Carbon::parse($date))) !== null) {
                            $issues[] = "{$where}, {$date}: {$dateError} Sel dilewati.";

                            continue;
                        }

                        // A working schedule is not put on a day an approved leave of the worker is charged for; the
                        // check is made when a schedule would be created or changed (write()), so a cell that only
                        // repeats the day's schedule (a re-imported export) is simply unchanged.
                        $leaveKey = "{$sheetName}|{$column}:{$workerId}";
                        $leaveDates[$leaveKey] ??= LeaveRequest::chargedDatesByWorker($column, [$workerId], Carbon::parse(min($columns['dates'])), Carbon::parse(max($columns['dates'])))[$workerId] ?? [];

                        $key = "{$column}:{$workerId}:{$date}";

                        foreach ($parsed as $part) {
                            $entry = ['column' => $column, 'worker_id' => $workerId, 'date' => $date, 'location_id' => $location?->id, 'origin' => $sheetName, 'cell' => "{$sheetName}#{$rowNumber}", 'on_leave' => isset($leaveDates[$leaveKey][$date])] + $part;

                            if (($conflict = $this->entryConflict($entries[$key] ?? [], $entry)) !== null) {
                                $issues[] = "{$where}, {$date}: {$conflict}";

                                // The schedule this part names is still in the file: it is never removed as missing from its cell.
                                if ($entry['action'] === 'shift') {
                                    $dropped[$key][] = ['location_id' => $entry['location_id'], 'shift_id' => (int) $entry['shift_id']];
                                }

                                continue;
                            }

                            $entries[$key][] = $entry;
                        }
                    }
                }
            }
        } finally {
            $reader->close();
        }

        $counts = RosterChangeContext::run('import', null, function () use ($user, $entries, $dropped, &$issues): array {
            return $this->save($user, $entries, $dropped, $issues);
        });

        return $counts + [
            'issues' => array_slice($issues, 0, self::MAX_ISSUES),
            'issue_count' => count($issues),
        ];
    }

    /**
     * Why a cell's entry cannot join the entries already read for the same worker and date, or null. Up to
     * {@see ShiftAssignmentService::MAX_SCHEDULES_PER_DAY} working schedules are kept (one per outlet sheet,
     * or "A+B" in one cell) when their hours do not overlap; a day off goes with nothing else, and the worker
     * appears once per sheet. The first entry wins, as before.
     *
     * @param  list<array{action: string, shift_id: int|null, location_id: int|null, origin: string, cell: string}>  $accepted
     * @param  array{action: string, shift_id: int|null, location_id: int|null, origin: string, cell: string}  $entry
     */
    private function entryConflict(array $accepted, array $entry): ?string
    {
        $sameSheet = collect($accepted)->first(fn (array $other): bool => $other['origin'] === $entry['origin'] && $other['cell'] !== $entry['cell']);
        $working = collect($accepted)->where('action', 'shift');
        $alreadyScheduled = fn (array $other): string => "pekerja ini sudah dijadwalkan di sheet \"{$other['origin']}\" pada tanggal yang sama. Yang dipakai jadwal pertama.";

        if ($sameSheet !== null) {
            return $alreadyScheduled($sameSheet);
        }

        if ($entry['action'] === 'clear') {
            return null;
        }

        if ($entry['action'] === 'day_off' && ($other = collect($accepted)->first(fn (array $other): bool => $other['action'] !== 'clear')) !== null) {
            return $alreadyScheduled($other);
        }

        if ($entry['action'] === 'shift' && ($other = collect($accepted)->firstWhere('action', 'day_off')) !== null) {
            return $alreadyScheduled($other);
        }

        if ($entry['action'] !== 'shift') {
            return null;
        }

        if ($working->count() >= ShiftAssignmentService::MAX_SCHEDULES_PER_DAY) {
            return ShiftAssignmentService::MAX_SCHEDULES_MESSAGE.' Sel dilewati.';
        }

        if ($working->contains(fn (array $other): bool => $other['shift_id'] === $entry['shift_id'] && $other['location_id'] === $entry['location_id'])) {
            return ShiftAssignmentService::DUPLICATE_SCHEDULE_MESSAGE.' Sel dilewati.';
        }

        $shift = $this->shifts?->firstWhere('id', $entry['shift_id']);

        foreach ($working as $other) {
            $otherShift = $this->shifts?->firstWhere('id', $other['shift_id']);

            if ($shift && $otherShift && app(ShiftAssignmentService::class)->shiftsOverlap($shift, $otherShift, $entry['date'])) {
                return "jam shift ini bertabrakan dengan jadwal di sheet \"{$other['origin']}\" pada tanggal yang sama. Yang dipakai jadwal pertama.";
            }
        }

        return null;
    }

    /**
     * Write the entries, one worker and date at a time. A day before today and a schedule that already has
     * attendance are never changed; each is reported as an issue line instead.
     *
     * One cell for a day with at most one schedule works as it always did: the schedule is created, changed
     * or removed. Otherwise, or when a part of the day's cells was refused while reading, the cells are
     * matched with the day's schedules by outlet ({@see self::saveSeveral()}).
     *
     * @param  array<string, list<array{column: string, worker_id: int, date: string, action: string, shift_id: int|null, location_id: int|null, section: string|null, origin: string, cell: string, on_leave: bool}>>  $entries
     * @param  array<string, list<array{location_id: int|null, shift_id: int}>>  $dropped
     * @param  list<string>  $issues
     * @return array{created: int, updated: int, deleted: int, unchanged: int}
     */
    private function save(User $user, array $entries, array $dropped, array &$issues): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'unchanged' => 0];
        $today = today()->toDateString();

        $this->pendingEntries = $entries;
        $this->pendingDropped = $dropped;

        DB::transaction(function () use ($user, $entries, $dropped, $today, &$counts, &$issues): void {
            foreach ($entries as $key => $ofDay) {
                $rows = $this->rowsOf($ofDay[0]);

                if (count($ofDay) === 1 && $rows->count() <= 1 && ! isset($dropped[$key])) {
                    $this->saveSingle($user, $ofDay[0], $rows, $today, $counts, $issues);
                } else {
                    $this->saveSeveral($user, $ofDay, $rows, $today, $counts, $issues, $dropped[$key] ?? []);
                }

                unset($this->pendingEntries[$key]);
            }
        });

        $this->pendingEntries = [];
        $this->pendingDropped = [];

        return $counts;
    }

    /**
     * The worker's schedules of the entry's date.
     *
     * @param  array{column: string, worker_id: int, date: string}  $entry
     * @return Collection<int, ShiftSchedule>
     */
    private function rowsOf(array $entry): Collection
    {
        return ShiftSchedule::query()->with('shift')->where($entry['column'], $entry['worker_id'])->whereDate('date', $entry['date'])->orderBy('sequence')->orderBy('id')->get();
    }

    /**
     * One cell for a day with no schedule or one: create, change or remove that schedule (the file shape
     * before several schedules a day existed, which keeps behaving the same).
     *
     * @param  array{column: string, worker_id: int, date: string, action: string, shift_id: int|null, location_id: int|null, section: string|null, origin: string}  $entry
     * @param  Collection<int, ShiftSchedule>  $rows
     * @param  array{created: int, updated: int, deleted: int, unchanged: int}  $counts
     * @param  list<string>  $issues
     */
    private function saveSingle(User $user, array $entry, Collection $rows, string $today, array &$counts, array &$issues): void
    {
        $where = "Sheet \"{$entry['origin']}\", {$entry['date']}";
        $schedule = $rows->first();

        if ($entry['action'] === 'clear') {
            if (! $schedule) {
                return;
            }

            if ($refusal = $this->refusal($schedule, $entry['date'], $today)) {
                $issues[] = "{$where}: {$refusal}";

                return;
            }

            $schedule->delete();
            $counts['deleted']++;

            return;
        }

        $this->write($user, $entry, $schedule, $rows, $today, $counts, $issues);
    }

    /**
     * Several cells for one worker and date, or one cell for a day that already has several schedules.
     * A "-" removes the schedules at that cell's outlet only; a day off needs the day to have no other
     * schedule; each shift changes the schedule at its outlet (the same shift first, {@see self::matchEntries()})
     * or is added as a further schedule with the next free number. A cell lists all of its outlet's schedules
     * that day, so one there it no longer names is removed (or kept with an issue line when it is locked or
     * the date is gone). A schedule whose part of the cell was refused while reading (an overlap, a fourth
     * schedule, a duplicate) is still named by the file, so it stays, with an issue line saying so; a row
     * without a shift (a placement, which a cell cannot show) stays too. Schedules at outlets the file does
     * not mention stay as they are.
     *
     * @param  list<array{column: string, worker_id: int, date: string, action: string, shift_id: int|null, location_id: int|null, section: string|null, origin: string, on_leave?: bool}>  $entries
     * @param  Collection<int, ShiftSchedule>  $rows
     * @param  array{created: int, updated: int, deleted: int, unchanged: int}  $counts
     * @param  list<string>  $issues
     * @param  list<array{location_id: int|null, shift_id: int}>  $dropped  shift parts of the day refused while reading
     */
    private function saveSeveral(User $user, array $entries, Collection $rows, string $today, array &$counts, array &$issues, array $dropped = []): void
    {
        $date = $entries[0]['date'];

        foreach ($entries as $entry) {
            if ($entry['action'] !== 'clear') {
                continue;
            }

            foreach ($rows->filter(fn (ShiftSchedule $row): bool => $this->locationOf($row) === $entry['location_id']) as $row) {
                if ($refusal = $this->refusal($row, $date, $today)) {
                    $issues[] = "Sheet \"{$entry['origin']}\", {$date}: {$refusal}";

                    continue;
                }

                $row->delete();
                $counts['deleted']++;
            }
        }

        $rows = $this->rowsOf($entries[0]);
        $dayOff = collect($entries)->firstWhere('action', 'day_off');

        if ($dayOff !== null) {
            if ($rows->contains(fn (ShiftSchedule $row): bool => ! $row->is_day_off) || $rows->count() > 1) {
                $issues[] = "Sheet \"{$dayOff['origin']}\", {$date}: ".ShiftAssignmentService::DAY_OFF_WITH_OTHERS_MESSAGE.' Sel dilewati.';

                return;
            }

            $this->write($user, $dayOff, $rows->first(), $rows, $today, $counts, $issues);

            return;
        }

        $plan = $this->matchEntries(collect($entries)->where('action', 'shift')->values()->all(), $rows);
        $matchedIds = collect($plan)->map(fn (array $pair): ?int => $pair[1]?->id)->filter()->all();
        $namedOutlets = collect($plan)->map(fn (array $pair): ?int => $pair[0]['location_id'])->unique()->all();

        // A cell lists every schedule of its outlet that day, so a schedule there that it no longer names goes.
        foreach ($rows as $row) {
            if ($row->is_day_off || $row->shift_id === null || in_array($row->id, $matchedIds, true) || ! in_array($this->locationOf($row), $namedOutlets, true)) {
                continue;
            }

            $origin = collect($plan)->first(fn (array $pair): bool => $pair[0]['location_id'] === $this->locationOf($row))[0]['origin'];

            if (collect($dropped)->contains(fn (array $part): bool => $part['location_id'] === $this->locationOf($row) && $part['shift_id'] === (int) $row->shift_id)) {
                $issues[] = "Sheet \"{$origin}\", {$date}: jadwal \"{$this->cellText($row)}\" tetap ada, karena bagian selnya dilewati.";

                continue;
            }

            if ($refusal = $this->refusal($row, $date, $today)) {
                $issues[] = "Sheet \"{$origin}\", {$date}: jadwal \"{$this->cellText($row)}\" tidak ada lagi di sel, tetapi tidak dihapus: {$refusal}";

                continue;
            }

            $row->delete();
            $counts['deleted']++;
        }

        // Changes first, so a new schedule is checked against the day as the file leaves it.
        usort($plan, fn (array $first, array $second): int => ($first[1] === null) <=> ($second[1] === null));

        foreach ($plan as [$entry, $schedule]) {
            $this->write($user, $entry, $schedule, $this->rowsOf($entry), $today, $counts, $issues);
        }
    }

    /**
     * Pair each shift entry with the schedule it changes, or null for a new one. Every entry first takes the
     * schedule with its own outlet and shift; only the entries left over then take another schedule at their
     * outlet, and after that a day off. So "P+Siang" over an existing "Siang" keeps that schedule and adds P.
     *
     * @param  list<array{column: string, worker_id: int, date: string, action: string, shift_id: int|null, location_id: int|null, section: string|null, origin: string}>  $entries
     * @param  Collection<int, ShiftSchedule>  $rows
     * @return list<array{0: array{column: string, worker_id: int, date: string, action: string, shift_id: int|null, location_id: int|null, section: string|null, origin: string}, 1: ShiftSchedule|null}>
     */
    private function matchEntries(array $entries, Collection $rows): array
    {
        $pool = $rows->keyBy('id');
        $matches = array_fill(0, count($entries), null);
        $passes = [
            fn (ShiftSchedule $row, array $entry): bool => ! $row->is_day_off && $this->locationOf($row) === $entry['location_id'] && (int) $row->shift_id === $entry['shift_id'],
            fn (ShiftSchedule $row, array $entry): bool => ! $row->is_day_off && $this->locationOf($row) === $entry['location_id'],
            fn (ShiftSchedule $row, array $entry): bool => (bool) $row->is_day_off,
        ];

        foreach ($passes as $fits) {
            foreach ($entries as $index => $entry) {
                if ($matches[$index] !== null) {
                    continue;
                }

                $match = $pool->first(fn (ShiftSchedule $row): bool => $fits($row, $entry));

                if ($match) {
                    $pool->forget($match->id);
                    $matches[$index] = $match;
                }
            }
        }

        return array_map(fn (array $entry, ?ShiftSchedule $match): array => [$entry, $match], $entries, $matches);
    }

    /**
     * Save one cell's shift or day off to the given schedule, or to a new one with the next free number,
     * after the same checks as the other roster writers.
     *
     * @param  array{column: string, worker_id: int, date: string, action: string, shift_id: int|null, location_id: int|null, section: string|null, origin: string, on_leave?: bool}  $entry
     * @param  Collection<int, ShiftSchedule>  $rows  The worker's schedules of the date as they are now.
     * @param  array{created: int, updated: int, deleted: int, unchanged: int}  $counts
     * @param  list<string>  $issues
     */
    private function write(User $user, array $entry, ?ShiftSchedule $schedule, Collection $rows, string $today, array &$counts, array &$issues): void
    {
        $where = "Sheet \"{$entry['origin']}\", {$entry['date']}";
        $assignments = app(ShiftAssignmentService::class);
        $dayOff = $entry['action'] === 'day_off';
        $schedule ??= new ShiftSchedule([$entry['column'] => $entry['worker_id'], 'date' => $entry['date']]);
        $schedule->fill([
            'shift_id' => $dayOff ? null : $entry['shift_id'],
            'company_location_id' => $dayOff ? null : $entry['location_id'],
            'is_day_off' => $dayOff,
            'section' => $dayOff ? null : $entry['section'],
        ]);
        $others = $rows->reject(fn (ShiftSchedule $row): bool => $schedule->exists && $row->is($schedule))->values();

        if (! $schedule->exists) {
            $schedule->created_by = $user->id;

            // Never the number of a check-in without a schedule of that day: the schedule would inherit it.
            if ($worker = $this->workerOf($entry)) {
                $schedule->sequence = $assignments->newScheduleSequence($worker, $entry['date'], $rows);
            }
        }

        if ($schedule->exists && ! $schedule->isDirty()) {
            $counts['unchanged']++;

            return;
        }

        if ($refusal = $this->refusal($schedule, $entry['date'], $today)) {
            $issues[] = "{$where}: {$refusal}";

            return;
        }

        if (! $dayOff) {
            $shapeError = match (true) {
                ! $schedule->exists && $others->count() >= ShiftAssignmentService::MAX_SCHEDULES_PER_DAY => ShiftAssignmentService::MAX_SCHEDULES_MESSAGE,
                $others->contains(fn (ShiftSchedule $row): bool => (bool) $row->is_day_off) => ShiftAssignmentService::ADD_ON_DAY_OFF_MESSAGE,
                default => $assignments->dayShapeError($others, $entry['date'], (int) $entry['shift_id'], $entry['location_id']),
            };

            // The neighbouring days are read as the file leaves them, and only when this row's hours change.
            if ($shapeError === null && (! $schedule->exists || $schedule->isDirty(['shift_id', 'is_day_off'])) && $this->overlapsPlannedNeighbours($entry)) {
                $shapeError = ShiftAssignmentService::ADJACENT_OVERLAP_MESSAGE;
            }

            if ($shapeError !== null) {
                $issues[] = "{$where}: {$shapeError} Sel dilewati.";

                return;
            }

            // A working schedule is not created or changed on a day an approved leave of the worker is charged for.
            if ($entry['on_leave'] ?? false) {
                $issues[] = "{$where}: ".ShiftAssignmentService::ON_LEAVE_MESSAGE.' Sel dilewati.';

                return;
            }
        }

        // Same guard as the other roster writers: a check-in without a schedule already in these hours.
        if (! $dayOff && ($worker = $this->workerOf($entry)) && ($error = $assignments->unlinkedAttendanceError($worker, $schedule)) !== null) {
            $issues[] = "{$where}: {$error} Sel dilewati.";

            return;
        }

        $counts[$schedule->exists ? 'updated' : 'created']++;
        $schedule->save();
    }

    /**
     * Whether the entry's shift overlaps a working schedule of the worker on the day before or the next day,
     * as the import leaves those days: a day of the file not written yet counts with the shifts its cells
     * plan ({@see self::plannedShifts()}), any other day with what the database holds. So a week reworked in
     * one file is not refused because of a neighbouring schedule a later cell of the same file changes.
     *
     * @param  array{column: string, worker_id: int, date: string, shift_id: int|null}  $entry
     */
    private function overlapsPlannedNeighbours(array $entry): bool
    {
        $shift = $this->shiftById((int) $entry['shift_id']);

        if (! $shift) {
            return false;
        }

        $neighbours = [];

        foreach ([Carbon::parse($entry['date'])->subDay()->toDateString(), Carbon::parse($entry['date'])->addDay()->toDateString()] as $date) {
            $key = "{$entry['column']}:{$entry['worker_id']}:{$date}";
            $shifts = isset($this->pendingEntries[$key])
                ? $this->plannedShifts($key)
                : $this->rowsOf(['column' => $entry['column'], 'worker_id' => $entry['worker_id'], 'date' => $date])
                    ->filter(fn (ShiftSchedule $row): bool => ! $row->is_day_off && $row->shift !== null)
                    ->map(fn (ShiftSchedule $row): Shift => $row->shift)
                    ->values()
                    ->all();

            foreach ($shifts as $neighbourShift) {
                $neighbours[] = ['shift' => $neighbourShift, 'date' => $date];
            }
        }

        return $neighbours !== [] && app(ShiftAssignmentService::class)->overlapsAdjacentDays($shift, $entry['date'], $neighbours);
    }

    /**
     * The working shifts a worker-date of the file not written yet will hold once the import writes it, by
     * the same rules as {@see self::saveSingle()} and {@see self::saveSeveral()}: a date gone and a locked
     * schedule stay; a lone cell over a day with at most one schedule replaces it; otherwise the schedules at
     * outlets the cells do not name, and those whose part was refused while reading, stay next to the cells'
     * shifts. A day off over a day with several schedules is refused, so they stay.
     *
     * @return list<Shift>
     */
    private function plannedShifts(string $key): array
    {
        $ofDay = $this->pendingEntries[$key];
        $date = $ofDay[0]['date'];
        $rows = $this->rowsOf($ofDay[0]);
        $working = $rows->filter(fn (ShiftSchedule $row): bool => ! $row->is_day_off && $row->shift !== null);
        $shiftsOf = fn (Collection $schedules): array => $schedules->map(fn (ShiftSchedule $row): Shift => $row->shift)->values()->all();
        $fileShifts = collect($ofDay)->where('action', 'shift')->map(fn (array $entry): ?Shift => $this->shiftById((int) $entry['shift_id']))->filter()->values()->all();

        if ($date < today()->toDateString()) {
            return $shiftsOf($working);
        }

        $locked = $working->filter(fn (ShiftSchedule $row): bool => $row->isLocked());

        if (count($ofDay) === 1 && $rows->count() <= 1 && ! isset($this->pendingDropped[$key])) {
            return $locked->isNotEmpty() ? $shiftsOf($locked) : $fileShifts;
        }

        if (collect($ofDay)->contains('action', 'day_off')) {
            return $shiftsOf($working);
        }

        $named = collect($ofDay)->pluck('location_id')->unique()->all();
        $dropped = $this->pendingDropped[$key] ?? [];
        $kept = $working->filter(fn (ShiftSchedule $row): bool => ! in_array($this->locationOf($row), $named, true)
            || $row->isLocked()
            || collect($dropped)->contains(fn (array $part): bool => $part['location_id'] === $this->locationOf($row) && $part['shift_id'] === (int) $row->shift_id));

        return [...$shiftsOf($kept), ...$fileShifts];
    }

    private function shiftById(int $shiftId): ?Shift
    {
        return $this->shifts?->firstWhere('id', $shiftId) ?? Shift::query()->find($shiftId);
    }

    private function locationOf(ShiftSchedule $row): ?int
    {
        return $row->company_location_id === null ? null : (int) $row->company_location_id;
    }

    /**
     * The worker an entry is for.
     *
     * @param  array{column: string, worker_id: int}  $entry
     */
    private function workerOf(array $entry): Employee|Intern|Freelancer|null
    {
        $model = match ($entry['column']) {
            'employee_id' => Employee::class,
            'intern_id' => Intern::class,
            default => Freelancer::class,
        };

        return $model::query()->withoutGlobalScopes()->find($entry['worker_id']);
    }

    /**
     * Why the import leaves this day alone, or null: a date already gone, or a schedule with attendance.
     */
    private function refusal(ShiftSchedule $schedule, string $date, string $today): ?string
    {
        if ($date < $today) {
            return 'tanggal sudah lewat, jadwal tidak diubah lewat impor. Sel dilewati.';
        }

        if ($schedule->isLocked()) {
            return ShiftSchedule::LOCKED_MESSAGE.' Sel dilewati.';
        }

        return null;
    }

    /**
     * A cell as one entry, or as several shifts at the sheet's outlet written "A+B" (each part may carry its
     * own section, "P (K)+S"). A text that is itself a known shift is read whole first.
     *
     * @return non-empty-list<array{action: string, shift_id: int|null, section: string|null}>|string The entries, or why the cell was skipped.
     */
    private function parseCellParts(string $text, Company $company, ?CompanyLocation $location): array|string
    {
        $whole = $this->parseCell($text, $company, $location);

        if (! is_string($whole) || (! str_contains($text, self::JOIN_TEXT) && ! str_starts_with($text, '['))) {
            return is_string($whole) ? $whole : [$whole];
        }

        $pieces = array_map('trim', $this->joinPieces($text));

        if (in_array('', $pieces, true)) {
            return $whole;
        }

        $bracketed = array_map(fn (string $piece): bool => (bool) preg_match('/^\[.*\]$/su', $piece), $pieces);
        $entries = [];
        $index = 0;

        // A part written in square brackets ("[P+S]+[M]", as the export writes a cell that would read
        // otherwise) stands alone. Otherwise the longest run of pieces that reads as one entry wins, so a "+"
        // inside a shift name also reads back in a file written before the brackets.
        while ($index < count($pieces)) {
            $entry = null;
            $part = $pieces[$index];
            $taken = 1;
            $longest = 1;

            while (! $bracketed[$index] && $index + $longest < count($pieces) && ! $bracketed[$index + $longest]) {
                $longest++;
            }

            for ($length = $longest; $length >= 1; $length--) {
                $candidate = $bracketed[$index] ? trim(mb_substr($pieces[$index], 1, -1)) : trim(implode(self::JOIN_TEXT, array_slice($pieces, $index, $length)));
                $parsed = $this->parseCell($candidate, $company, $location);

                if (! is_string($parsed)) {
                    [$entry, $part, $taken] = [$parsed, $candidate, $length];

                    break;
                }

                if ($length === 1) {
                    return $parsed;
                }
            }

            if ($entry['action'] !== 'shift') {
                return "\"{$text}\": \"{$part}\" tidak bisa digabung dengan shift lain dalam satu sel. Sel dilewati.";
            }

            $entries[] = $entry;
            $index += $taken;
        }

        return $entries;
    }

    /**
     * The cell text cut at each "+" outside brackets: a section may hold a "+" ("P (K+L)+S"), and so may a
     * part in square brackets ("[P+S]+M").
     *
     * @return non-empty-list<string>
     */
    private function joinPieces(string $text): array
    {
        $pieces = [''];
        $depth = 0;

        foreach (mb_str_split($text) as $character) {
            if ($character === '(' || $character === '[') {
                $depth++;
            } elseif ($character === ')' || $character === ']') {
                $depth = max(0, $depth - 1);
            }

            if ($character === self::JOIN_TEXT && $depth === 0) {
                $pieces[] = '';

                continue;
            }

            $pieces[array_key_last($pieces)] .= $character;
        }

        return $pieces;
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
     * Header cells written like a date that is not a real one (30-02-2026) land in "unreadable", so
     * the import can report the skipped column instead of dropping its cells silently.
     *
     * @param  list<string>  $cells
     * @return array{id: int, type: int|null, dates: array<int, string>, unreadable: array<int, string>}
     */
    private function readHeader(array $cells): array
    {
        $columns = ['id' => 0, 'type' => null, 'dates' => [], 'unreadable' => []];

        foreach ($cells as $index => $cell) {
            $label = Str::upper(trim($cell));

            if (in_array($label, [Str::upper(self::HEADER_ID), 'NIP', 'NO PEKERJA', 'ID'], true)) {
                $columns['id'] = $index;
            } elseif ($label === Str::upper(self::HEADER_TYPE)) {
                $columns['type'] = $index;
            } elseif ($date = $this->parseHeaderDate($cell)) {
                $columns['dates'][$index] = $date;
            } elseif (preg_match('/\d{1,4}\s*[\/.-]\s*\d{1,2}\s*[\/.-]\s*\d{1,4}/', $cell)) {
                $columns['unreadable'][$index] = trim($cell);
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
                ->get()
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

    /**
     * The cell of one worker, outlet and date: its schedules joined with "+" ("P+S"). When that text would
     * read back as other schedules (a shift coded "P+S" next to the shifts "P" and "S", a "+" in a shift
     * name), each part is written in square brackets ("[P]+[S]"), which the import reads part by part.
     *
     * @param  SupportCollection<int, ShiftSchedule>  $ofDay
     */
    private function joinedCellText(SupportCollection $ofDay, Company $company, ?CompanyLocation $location): string
    {
        $parts = $ofDay->map(fn (ShiftSchedule $schedule): string => $this->cellText($schedule))->filter()->values();
        $plain = $parts->implode(self::JOIN_TEXT);

        if ($parts->count() <= 1) {
            return $plain;
        }

        $intended = $ofDay->filter(fn (ShiftSchedule $schedule): bool => ! $schedule->is_day_off && $schedule->shift !== null)
            ->map(fn (ShiftSchedule $schedule): array => [(int) $schedule->shift_id, filled($schedule->section) ? Str::limit((string) $schedule->section, 50, '') : null])
            ->values()
            ->all();
        $parsed = $this->parseCellParts($plain, $company, $location);

        if (! is_string($parsed) && array_map(fn (array $entry): array => [$entry['shift_id'], $entry['section']], $parsed) === $intended) {
            return $plain;
        }

        return $parts->map(fn (string $part): string => "[{$part}]")->implode(self::JOIN_TEXT);
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
            ['P+S', 'Dua jadwal di outlet ini'],
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
