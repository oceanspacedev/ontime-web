<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\CompanyLocation;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\LeaveRequest;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What the coordinator's "Hari" view needs on top of the dated schedules: the live state of people
 * between the segments of a split shift, people still working last night's shift after midnight, and
 * people who checked in without a schedule. Counts are rebuilt from the entries, so the header ("3 dari
 * 4 orang sudah absen") always agrees with the rows below it. An entry carries a "note" only when
 * there is something to explain, so a plain scheduled entry keeps the shape older apps read.
 */
final class RosterLiveStateService
{
    /** Between the two segments of a split shift: checked in and out of segment 1, segment 2 still to come. */
    public const STATE_BREAK = 'jeda';

    /** Roster state of a scheduled worker on approved leave who has not checked in. */
    public const STATE_LEAVE = 'cuti';

    /** Group of people who checked in on the day without a schedule. */
    public const KIND_UNSCHEDULED = 'unscheduled';

    /** Group of people still on last night's shift who have no schedule today. */
    public const KIND_OVERNIGHT = 'overnight';

    /** Hours after last night's planned end during which an open row still means the person is working. */
    private const OVERNIGHT_GRACE_HOURS = 4;

    /** Roster states that mean the person has no attendance of the day yet. */
    private const NOT_STARTED = [OutletRosterService::STATE_UPCOMING, OutletRosterService::STATE_MISSING];

    /** @var array<string, class-string<Employee|Intern|Freelancer>> */
    private const WORKER_MODELS = ['employee' => Employee::class, 'intern' => Intern::class, 'freelancer' => Freelancer::class];

    public function __construct(private readonly OutletRosterService $roster) {}

    /**
     * The coordinator's day as the app and the panel show it: the dated roster
     * ({@see OutletRosterService::roster()}) with edit and leave flags, the live state on top, and a summary
     * of distinct people.
     *
     * @param  array{company_id?: int|null, division_id?: int|null, location_id?: int|null}  $filters
     * @return array{outlets: list<array<string, mixed>>, summary: array{people_scheduled: int, people_checked_in: int}}
     */
    public function roster(User $user, CarbonInterface $date, array $filters = []): array
    {
        $outlets = $this->roster->roster($user, $date, $filters);
        $entries = collect($outlets)->flatMap(fn (array $outlet): array => $outlet['entries']);
        $editable = $this->editableWorkers($user, $entries->pluck('worker'));
        $onLeave = $this->workersOnLeave($entries->pluck('worker'), $date);
        $outlets = array_map(fn (array $outlet): array => $this->withLeaveAndEditFlags($outlet, $editable, $onLeave), $outlets);
        $outlets = $this->apply($user, $date, $outlets, $filters);

        return ['outlets' => $outlets, 'summary' => $this->summary($outlets)];
    }

    /**
     * Distinct people of the day: everyone with at least one schedule they are expected to work (no day off,
     * no leave), and of those everyone who checked in to at least one of them (or is still on last night's
     * shift). Someone working at two outlets counts once.
     *
     * @param  list<array<string, mixed>>  $outlets
     * @return array{people_scheduled: int, people_checked_in: int}
     */
    public function summary(array $outlets): array
    {
        $scheduled = [];
        $checkedIn = [];

        foreach ($outlets as $outlet) {
            foreach ($outlet['entries'] as $entry) {
                $key = $this->workerKey($entry['worker']);

                if (! isset($outlet['kind']) && ! in_array($entry['state'], [OutletRosterService::STATE_DAY_OFF, self::STATE_LEAVE], true)) {
                    $scheduled[$key] = true;
                }

                if ($this->countsAsCheckedIn($entry)) {
                    $checkedIn[$key] = true;
                }
            }
        }

        return [
            'people_scheduled' => count($scheduled),
            'people_checked_in' => count(array_intersect_key($checkedIn, $scheduled)),
        ];
    }

    /**
     * Keys ("freelancer:12") of the listed workers whose schedule the user may change. Workers lent in
     * from another brand are only shown; their own brand plans them.
     *
     * @param  Collection<int, array{type: string, id: int}>  $workers
     * @return array<string, true>
     */
    public function editableWorkers(User $user, Collection $workers): array
    {
        $editable = [];

        foreach ($workers->groupBy('type') as $type => $ofType) {
            self::WORKER_MODELS[$type]::query()
                ->whereKey($ofType->pluck('id')->unique()->all())
                ->get()
                ->filter(fn ($worker): bool => $user->canManageWorker($worker))
                ->each(function ($worker) use ($type, &$editable): void {
                    $editable["{$type}:{$worker->getKey()}"] = true;
                });
        }

        return $editable;
    }

    /**
     * Keys of the listed workers on approved leave that day (an approved request, or the leave row it wrote).
     *
     * @param  Collection<int, array{type: string, id: int}>  $workers
     * @return array<string, true>
     */
    private function workersOnLeave(Collection $workers, CarbonInterface $date): array
    {
        $onLeave = [];
        $day = $date->toDateString();

        foreach ($workers->groupBy('type') as $type => $ofType) {
            $ids = $ofType->pluck('id')->unique()->all();
            $column = "{$type}_id";

            LeaveRequest::query()
                ->where('status', 'approved')
                ->whereIn($column, $ids)
                ->whereDate('start_date', '<=', $day)
                ->whereDate('end_date', '>=', $day)
                ->pluck($column)
                ->merge(Attendance::query()->whereIn($column, $ids)->whereDate('date', $day)->where('status', AttendanceStatus::Leave)->pluck($column))
                ->each(function ($id) use ($type, &$onLeave): void {
                    $onLeave["{$type}:{$id}"] = true;
                });
        }

        return $onLeave;
    }

    /**
     * Marks each entry editable or not and shows a worker on approved leave who has not checked in as
     * "cuti" instead of "belum absen", on every schedule of that day, so the outlet's counts and the home
     * card stay right.
     *
     * @param  array{location: mixed, counts: array<string, int>, entries: list<array<string, mixed>>}  $outlet
     * @param  array<string, true>  $editable
     * @param  array<string, true>  $onLeave
     * @return array<string, mixed>
     */
    private function withLeaveAndEditFlags(array $outlet, array $editable, array $onLeave): array
    {
        $outlet['counts'][self::STATE_LEAVE] = 0;

        foreach ($outlet['entries'] as $index => $entry) {
            $key = $this->workerKey($entry['worker']);
            $outlet['entries'][$index]['can_edit'] = isset($editable[$key]);

            if (isset($onLeave[$key]) && in_array($entry['state'], [OutletRosterService::STATE_MISSING, OutletRosterService::STATE_UPCOMING], true)) {
                $outlet['entries'][$index]['state'] = self::STATE_LEAVE;
                $outlet['counts'][$entry['state']]--;
                $outlet['counts'][self::STATE_LEAVE]++;
                $outlet['counts']['scheduled']--;
            }
        }

        return $outlet;
    }

    /**
     * @param  list<array{location: mixed, counts: array<string, int>, entries: list<array<string, mixed>>}>  $outlets  The roster, leave and edit flags applied.
     * @param  array{company_id?: int|null, division_id?: int|null, location_id?: int|null}  $filters
     * @return list<array<string, mixed>>
     */
    public function apply(User $user, CarbonInterface $date, array $outlets, array $filters = []): array
    {
        $date = Carbon::parse($date)->startOfDay();
        $isToday = $date->isSameDay(today());
        $overnight = $isToday ? $this->openOvernightRows($user, $date, $filters) : collect();
        $earliest = $this->earliestEntries($outlets);
        $listed = [];
        $offToday = [];

        foreach ($outlets as $outletIndex => $outlet) {
            foreach ($outlet['entries'] as $entryIndex => $entry) {
                $key = $this->workerKey($entry['worker']);
                $entry = $this->withSplitBreak($entry, $date, $isToday);
                // Last night's open row concerns the person's first schedule of today only.
                $row = ($earliest[$key] ?? null) === "{$outletIndex}:{$entryIndex}" ? ($overnight[$key] ?? null) : null;

                if ($row !== null && $entry['check_in'] === null) {
                    $onShift = $this->isStillOnOvernightShift($row);

                    if ($onShift && in_array($entry['state'], [OutletRosterService::STATE_DAY_OFF, self::STATE_LEAVE], true)) {
                        // Off today but still on last night's shift: listed with last night's people, not as "Libur".
                        $offToday[$key] = $entry['state'];
                        unset($outlets[$outletIndex]['entries'][$entryIndex]);

                        continue;
                    }

                    $entry = $onShift && $entry['state'] === OutletRosterService::STATE_UPCOMING
                        ? $this->withOvernightRow($entry, $row)
                        : $this->withUnclosedOvernightNote($entry, $row);
                }

                $listed[$key] = true;

                if ($entry['state'] === OutletRosterService::STATE_DAY_OFF && $entry['check_in'] !== null) {
                    $entry['note'] = 'Masuk di hari libur';
                }

                $outlets[$outletIndex]['entries'][$entryIndex] = $entry;
            }

            $outlets[$outletIndex]['entries'] = array_values($outlets[$outletIndex]['entries']);
            $outlets[$outletIndex]['counts'] = $this->counts($outlets[$outletIndex]['entries']);
        }

        $outlets = array_filter($outlets, fn (array $outlet): bool => $outlet['entries'] !== []);

        $unscheduled = $this->unscheduledEntries($user, $date, $filters, $listed);

        if ($unscheduled !== []) {
            $outlets[] = $this->group(self::KIND_UNSCHEDULED, $unscheduled);
        }

        $carriedOver = $overnight
            ->reject(fn (Attendance $row, string $key): bool => isset($listed[$key]) || isset($unscheduled[$key]));
        AttendanceFigures::preload($carriedOver->values());

        $carriedOver = $carriedOver
            ->map(fn (Attendance $row, string $key): array => $this->withOffTodayNote(
                $this->withOvernightRow($this->entryFor($user, $row), $row),
                $offToday[$key] ?? null,
            ))
            ->values()
            ->all();

        if ($carriedOver !== []) {
            $outlets[] = $this->group(self::KIND_OVERNIGHT, $carriedOver);
        }

        return array_values($outlets);
    }

    /**
     * Where each person's earliest schedule of the day is listed ("outlet index:entry index"), by key.
     *
     * @param  list<array{entries: list<array<string, mixed>>}>  $outlets
     * @return array<string, string>
     */
    private function earliestEntries(array $outlets): array
    {
        $earliest = [];
        $starts = [];

        foreach ($outlets as $outletIndex => $outlet) {
            foreach ($outlet['entries'] as $entryIndex => $entry) {
                $key = $this->workerKey($entry['worker']);
                $start = ($entry['shift']['start_time'] ?? '99:99').'|'.sprintf('%010d', (int) ($entry['schedule_id'] ?? 0));

                if (! isset($starts[$key]) || $start < $starts[$key]) {
                    $starts[$key] = $start;
                    $earliest[$key] = "{$outletIndex}:{$entryIndex}";
                }
            }
        }

        return $earliest;
    }

    /**
     * Segment 1 of a split shift is done and segment 2 has not been checked in: before segment 2 starts
     * the person is on a break (and counts as checked in), after it they are missing segment 2.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function withSplitBreak(array $entry, Carbon $date, bool $isToday): array
    {
        $segments = $entry['segments'] ?? [];
        $rows = $entry['attendances'] ?? [];

        // Only segment 1 worked leaves segment 2 to come; a row of segment 2 alone is that segment done.
        if (count($segments) < 2 || count($rows) !== 1 || (int) ($rows[0]['segment'] ?? 1) !== 1 || ($rows[0]['check_out'] ?? null) === null || ! in_array($entry['state'], self::NOT_STARTED, true)) {
            return $entry;
        }

        $secondStart = $segments[1]['start'];

        if ($isToday && $entry['state'] === OutletRosterService::STATE_UPCOMING) {
            $entry['state'] = self::STATE_BREAK;
            $entry['note'] = "Jeda · segmen 2 mulai {$secondStart}";

            return $entry;
        }

        $entry['note'] = $date->lessThan(today()) ? 'Tidak absen segmen 2' : "Belum absen segmen 2 (mulai {$secondStart})";

        return $entry;
    }

    /**
     * Last night's shift is still running after midnight: the person is working, not "belum mulai".
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function withOvernightRow(array $entry, Attendance $row): array
    {
        $entry['state'] = $this->stateOf($row);
        $entry['check_in'] = $row->check_in?->format('H:i');
        $entry['check_out'] = null;
        $entry['late_minutes'] = (int) $row->late_minutes;
        $entry['note'] = 'Shift semalam ('.$row->date->translatedFormat('j M').') · belum absen pulang';

        return $entry;
    }

    /**
     * Today's own entry stays as it is (a missed shift is still "belum absen"); the note says why the
     * person cannot check in: last night's row is still open.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function withUnclosedOvernightNote(array $entry, Attendance $row): array
    {
        $entry['note'] = 'Shift semalam ('.$row->date->translatedFormat('j M').') belum absen pulang';

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function withOffTodayNote(array $entry, ?string $stateToday): array
    {
        if ($stateToday !== null) {
            $entry['note'] .= $stateToday === self::STATE_LEAVE ? ' · hari ini cuti' : ' · hari ini libur';
        }

        return $entry;
    }

    /**
     * Last night's row is still within its shift (plus a grace for running late or overtime). Past that,
     * an open row is a forgotten check-out rather than someone at work.
     */
    private function isStillOnOvernightShift(Attendance $row): bool
    {
        $plannedEnd = $this->plannedEnd($row);

        return $plannedEnd === null || now()->lessThanOrEqualTo($plannedEnd->addHours(self::OVERNIGHT_GRACE_HOURS));
    }

    /**
     * When the row's scheduled hours end: its schedule snapshot, else its shift or the company hours
     * ({@see SessionResolver::windowOf()}). For last night's row this is late yesterday or this morning.
     */
    private function plannedEnd(Attendance $row): ?Carbon
    {
        $end = app(SessionResolver::class)->windowOf($row)['end'] ?? null;

        return $end ? Carbon::instance($end) : null;
    }

    /**
     * Yesterday's open rows that can still be checked out live (a night shift, or a closing shift that ended
     * late in the evening, see {@see AttendanceCheckService::isLiveClosable()}), keyed "type:id".
     *
     * @param  array{company_id?: int|null, division_id?: int|null, location_id?: int|null}  $filters
     * @return Collection<string, Attendance>
     */
    private function openOvernightRows(User $user, Carbon $date, array $filters): Collection
    {
        return $this->visibleAttendances($user, $filters)
            ->whereDate('date', $date->copy()->subDay()->toDateString())
            ->whereNotNull('check_in')
            ->whereNull('check_out')
            ->where('status', '!=', AttendanceStatus::Rejected)
            ->with(['shift', 'location', ...$this->workerEagerLoads()])
            ->orderBy('segment')
            ->get()
            ->filter(fn (Attendance $row): bool => $this->worker($row) !== null && app(AttendanceCheckService::class)->isLiveClosable($row))
            ->keyBy(fn (Attendance $row): string => $this->keyOf($row));
    }

    /**
     * People who checked in on the date without being on the roster, one entry per person.
     *
     * @param  array{company_id?: int|null, division_id?: int|null, location_id?: int|null}  $filters
     * @param  array<string, true>  $listed
     * @return array<string, array<string, mixed>>
     */
    private function unscheduledEntries(User $user, Carbon $date, array $filters, array $listed): array
    {
        $rows = $this->visibleAttendances($user, $filters)
            ->whereDate('date', $date->toDateString())
            ->whereNotNull('check_in')
            ->where('status', '!=', AttendanceStatus::Rejected)
            ->with(['shift', 'location', ...$this->workerEagerLoads()])
            ->orderBy('schedule_sequence')
            ->orderBy('segment')
            ->get()
            ->filter(fn (Attendance $row): bool => $this->worker($row) !== null);
        // The figures (overtime, missing check-out) of the whole group are read at once.
        AttendanceFigures::preload($rows);
        $scheduleRows = $this->scheduleRowCounts($rows->filter(fn (Attendance $row): bool => isset($listed[$this->keyOf($row)]) && $row->shift_schedule_id === null), $date);

        // A listed person's row belongs to one of their entries when it is linked to a schedule, or (rows from
        // before the link existed) when they have only one schedule that day; any other row is "Tanpa jadwal".
        return $rows
            ->filter(fn (Attendance $row): bool => ! isset($listed[$this->keyOf($row)])
                || ($row->shift_schedule_id === null && ($scheduleRows[$this->keyOf($row)] ?? 0) !== 1))
            ->groupBy(fn (Attendance $row): string => $this->keyOf($row))
            ->map(function (Collection $rows) use ($user): array {
                /** @var Attendance $last */
                $last = $rows->last();
                $entry = $this->entryFor($user, $last);
                $entry['check_in'] = $rows->first()->check_in?->format('H:i');
                $entry['late_minutes'] = (int) $rows->sum('late_minutes');
                $entry['attendances'] = $rows->map(fn (Attendance $row): array => [
                    'segment' => (int) $row->segment,
                    'check_in' => $row->check_in?->format('H:i'),
                    'check_out' => $row->check_out?->format('H:i'),
                    'late_minutes' => (int) $row->late_minutes,
                ])->values()->all();
                $entry['note'] = $this->unscheduledNote($last);

                return $entry;
            })
            ->sortBy(fn (array $entry): string => $entry['worker']['name'])
            ->all();
    }

    /**
     * Why a person off the dated roster is listed: on their default shift, or with no schedule at all. In a
     * company that uses shift rosters a blank day is not scheduled, so only a check-in kept on the default
     * shift (a fixed-hours worker) reads "Shift default".
     */
    private function unscheduledNote(Attendance $row): string
    {
        $worker = $this->worker($row);

        if ($worker?->loadMissing('company.policy')->company?->policy?->uses_shift_roster) {
            return $row->sched_source === 'default' ? 'Shift default' : 'Tanpa jadwal';
        }

        return $worker?->shift_id ? 'Shift default' : 'Tanpa jadwal';
    }

    /**
     * How many schedule rows the workers of the attendance rows have on the date, keyed "type:id".
     *
     * @param  Collection<int, Attendance>  $rows
     * @return array<string, int>
     */
    private function scheduleRowCounts(Collection $rows, Carbon $date): array
    {
        $counts = [];

        foreach (array_keys(self::WORKER_MODELS) as $type) {
            $ids = $rows->pluck("{$type}_id")->filter()->unique()->values();

            if ($ids->isEmpty()) {
                continue;
            }

            ShiftSchedule::query()
                ->whereIn("{$type}_id", $ids)
                ->whereDate('date', $date->toDateString())
                ->selectRaw("{$type}_id as worker_id, count(*) as total")
                ->groupBy("{$type}_id")
                ->toBase()
                ->get()
                ->each(function (object $row) use ($type, &$counts): void {
                    $counts["{$type}:{$row->worker_id}"] = (int) $row->total;
                });
        }

        return $counts;
    }

    /**
     * Attendance rows the user may see, narrowed by the roster filters (outlet, company, brand).
     *
     * @param  array{company_id?: int|null, division_id?: int|null, location_id?: int|null}  $filters
     * @return Builder<Attendance>
     */
    private function visibleAttendances(User $user, array $filters): Builder
    {
        $brandIds = DataAccessService::coordinatedDivisionIds($user);
        $query = Attendance::query()->where(function (Builder $visible) use ($user, $brandIds): void {
            $visible->where(fn (Builder $own) => DataAccessService::scopeWorkerRecords($own, $user, array_keys(self::WORKER_MODELS)));

            if ($brandIds !== null) {
                $visible->orWhereIn('company_location_id', CompanyLocation::query()->select('id')->whereIn('division_id', $brandIds));
            }
        });

        $locationId = $filters['location_id'] ?? null;
        $companyId = $filters['company_id'] ?? null;
        $divisionId = $filters['division_id'] ?? null;

        return $query
            ->when($locationId, fn (Builder $atOutlet) => $atOutlet->where('company_location_id', $locationId))
            ->when($companyId, fn (Builder $inCompany) => $inCompany->where(fn (Builder $workers) => $this->whereWorkerIn($workers, 'company_id', (int) $companyId)))
            ->when($divisionId, fn (Builder $inBrand) => $inBrand->where(fn (Builder $brand) => $this->whereWorkerIn($brand, 'division_id', (int) $divisionId)
                ->orWhereIn('company_location_id', CompanyLocation::query()->select('id')->where('division_id', $divisionId))));
    }

    /**
     * @param  Builder<Attendance>  $query
     * @return Builder<Attendance>
     */
    private function whereWorkerIn(Builder $query, string $column, int $value): Builder
    {
        $query->whereRaw('1 = 0');

        foreach (self::WORKER_MODELS as $type => $model) {
            $query->orWhereIn("{$type}_id", $model::query()->select('id')->where($column, $value));
        }

        return $query;
    }

    /**
     * A roster entry for a person known only from an attendance row.
     *
     * @return array<string, mixed>
     */
    private function entryFor(User $user, Attendance $row): array
    {
        $worker = $this->worker($row);
        $type = $this->typeOf($row);
        $number = match ($type) {
            'employee' => $worker->nip,
            'intern' => $worker->nis,
            default => $worker->freelancer_number,
        };
        $shift = $row->shift;
        $figures = $row->status === AttendanceStatus::Rejected ? null : AttendanceFigures::forAttendance($row);

        return [
            'schedule_id' => null,
            'worker' => ['type' => $type, 'id' => (int) $worker->getKey(), 'company_id' => (int) $worker->company_id, 'number' => (string) $number, 'name' => (string) $worker->full_name],
            'shift' => $shift ? $this->shiftData($shift) : null,
            'location_id' => $row->company_location_id,
            'borrowed_from' => null,
            'section' => null,
            'state' => $this->stateOf($row),
            'check_in' => $row->check_in?->format('H:i'),
            'check_out' => $row->check_out?->format('H:i'),
            'late_minutes' => (int) $row->late_minutes,
            'segments' => $shift?->segments() ?? [],
            'attendances' => [],
            'can_edit' => $user->canManageWorker($worker),
            'outlet_name' => $row->location?->name,
            'overtime_minutes' => $figures['overtime_minutes'] ?? 0,
            'locked' => false,
            'schedule_count' => 0,
            'is_missing_check_out' => (bool) ($figures['is_missing_check_out'] ?? false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function shiftData(Shift $shift): array
    {
        return [
            'id' => (int) $shift->id,
            'name' => $shift->name,
            'code' => $shift->code,
            'label' => $shift->label(),
            'start_time' => substr((string) $shift->start_time, 0, 5),
            'end_time' => substr((string) $shift->end_time, 0, 5),
            'talenta_code' => $shift->talenta_code,
            'segments' => $shift->segments(),
        ];
    }

    /**
     * The roster state an attendance row stands for, with the same order of checks as the roster.
     */
    private function stateOf(Attendance $row): string
    {
        return match (true) {
            $row->status === AttendanceStatus::Rejected => OutletRosterService::STATE_REJECTED,
            $row->status === AttendanceStatus::PendingApproval => OutletRosterService::STATE_WAITING,
            $row->check_out !== null => OutletRosterService::STATE_DONE,
            $row->status === AttendanceStatus::Late => OutletRosterService::STATE_LATE,
            default => OutletRosterService::STATE_PRESENT,
        };
    }

    /**
     * Counts per state, "scheduled" (everyone expected to work: no day off, no leave) and "checked_in"
     * (of those, everyone at work or done: a check-in and a state other than "belum"/"nanti"), all taken
     * from the entries themselves. A split-shift worker who missed segment 2 is "belum" only, so the
     * header ("N dari M sudah absen") and "N belum absen" never count the same person twice.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, int>
     */
    private function counts(array $entries): array
    {
        $counts = array_fill_keys([...array_keys(OutletRosterService::STATE_LABELS), self::STATE_BREAK, self::STATE_LEAVE], 0);
        $scheduled = 0;
        $checkedIn = 0;

        foreach ($entries as $entry) {
            $counts[$entry['state']] = ($counts[$entry['state']] ?? 0) + 1;

            if (in_array($entry['state'], [OutletRosterService::STATE_DAY_OFF, self::STATE_LEAVE], true)) {
                continue;
            }

            $scheduled++;

            if ($this->countsAsCheckedIn($entry)) {
                $checkedIn++;
            }
        }

        return ['scheduled' => $scheduled, 'checked_in' => $checkedIn] + $counts;
    }

    /**
     * An entry of someone at work or done: a check-in and a state other than "belum"/"nanti". A refused
     * attendance is not a presence, and a day off or leave is not a scheduled day.
     *
     * @param  array<string, mixed>  $entry
     */
    private function countsAsCheckedIn(array $entry): bool
    {
        return $entry['check_in'] !== null
            && ! in_array($entry['state'], [...self::NOT_STARTED, OutletRosterService::STATE_REJECTED, OutletRosterService::STATE_DAY_OFF, self::STATE_LEAVE], true);
    }

    /**
     * @param  array<array-key, array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    private function group(string $kind, array $entries): array
    {
        $entries = array_values($entries);
        $counts = $this->counts($entries);
        // Nobody here was scheduled: they are listed and counted apart from the scheduled people.
        $counts['scheduled'] = 0;

        return ['location' => null, 'kind' => $kind, 'counts' => $counts, 'entries' => $entries];
    }

    /**
     * @return list<string>
     */
    private function workerEagerLoads(): array
    {
        return ['employee.company.policy', 'intern.company.policy', 'freelancer.company.policy'];
    }

    private function worker(Attendance $row): Employee|Intern|Freelancer|null
    {
        return $row->employee ?? $row->intern ?? $row->freelancer;
    }

    private function typeOf(Attendance $row): string
    {
        return match (true) {
            $row->employee_id !== null => 'employee',
            $row->intern_id !== null => 'intern',
            default => 'freelancer',
        };
    }

    private function keyOf(Attendance $row): string
    {
        return $this->typeOf($row).':'.$this->worker($row)?->getKey();
    }

    /**
     * @param  array{type: string, id: int}  $worker
     */
    private function workerKey(array $worker): string
    {
        return "{$worker['type']}:{$worker['id']}";
    }
}
