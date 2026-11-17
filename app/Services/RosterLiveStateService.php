<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\CompanyLocation;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\Shift;
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

    /** Group of people who checked in on the day without a schedule. */
    public const KIND_UNSCHEDULED = 'unscheduled';

    /** Group of people still on last night's shift who have no schedule today. */
    public const KIND_OVERNIGHT = 'overnight';

    /** Same window as AttendanceCheckService: an overnight row stays open this long after its check-in. */
    private const OVERNIGHT_OPEN_HOURS = 20;

    /** Hours after last night's planned end during which an open row still means the person is working. */
    private const OVERNIGHT_GRACE_HOURS = 4;

    /** Roster states that mean the person has no attendance of the day yet. */
    private const NOT_STARTED = [OutletRosterService::STATE_UPCOMING, OutletRosterService::STATE_MISSING];

    /** @var array<string, class-string<Employee|Intern|Freelancer>> */
    private const WORKER_MODELS = ['employee' => Employee::class, 'intern' => Intern::class, 'freelancer' => Freelancer::class];

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
        $listed = [];
        $offToday = [];

        foreach ($outlets as $outletIndex => $outlet) {
            foreach ($outlet['entries'] as $entryIndex => $entry) {
                $key = $this->workerKey($entry['worker']);
                $entry = $this->withSplitBreak($entry, $date, $isToday);
                $row = $overnight[$key] ?? null;

                if ($row !== null && $entry['check_in'] === null) {
                    $onShift = $this->isStillOnOvernightShift($row);

                    if ($onShift && in_array($entry['state'], [OutletRosterService::STATE_DAY_OFF, 'cuti'], true)) {
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
            ->reject(fn (Attendance $row, string $key): bool => isset($listed[$key]) || isset($unscheduled[$key]))
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

        if (count($segments) < 2 || count($rows) !== 1 || ($rows[0]['check_out'] ?? null) === null || ! in_array($entry['state'], self::NOT_STARTED, true)) {
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
            $entry['note'] .= $stateToday === 'cuti' ? ' · hari ini cuti' : ' · hari ini libur';
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
     * When the row's shift (or, without a shift, the company hours) ends: the morning after the row's date.
     */
    private function plannedEnd(Attendance $row): ?Carbon
    {
        $shift = $row->shift;
        $end = match (true) {
            $shift !== null && (int) $row->segment === 2 && $shift->hasSecondSegment() => $shift->second_end_time,
            $shift !== null => $shift->end_time,
            default => $this->worker($row)?->company?->policy?->work_end_time,
        };

        if (! $end) {
            return null;
        }

        return Carbon::parse($row->date)->addDay()->setTimeFromTimeString(substr((string) $end, 0, 5));
    }

    /**
     * Yesterday's rows of a shift crossing midnight, checked in recently and still open, keyed "type:id".
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
            ->where('check_in', '>=', now()->subHours(self::OVERNIGHT_OPEN_HOURS))
            ->where('status', '!=', AttendanceStatus::Rejected)
            ->with(['shift', 'location', ...$this->workerEagerLoads()])
            ->orderBy('segment')
            ->get()
            ->filter(fn (Attendance $row): bool => $this->worker($row) !== null && $this->crossesMidnight($row))
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
        return $this->visibleAttendances($user, $filters)
            ->whereDate('date', $date->toDateString())
            ->whereNotNull('check_in')
            ->where('status', '!=', AttendanceStatus::Rejected)
            ->with(['shift', 'location', ...$this->workerEagerLoads()])
            ->orderBy('segment')
            ->get()
            ->filter(fn (Attendance $row): bool => $this->worker($row) !== null && ! isset($listed[$this->keyOf($row)]))
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
                // Off the dated roster: on their default shift, or with no schedule at all.
                $entry['note'] = $this->worker($last)?->shift_id ? 'Shift default' : 'Tanpa jadwal';

                return $entry;
            })
            ->sortBy(fn (array $entry): string => $entry['worker']['name'])
            ->all();
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
            $row->check_out !== null => OutletRosterService::STATE_DONE,
            $row->status === AttendanceStatus::PendingApproval => OutletRosterService::STATE_WAITING,
            $row->status === AttendanceStatus::Late => OutletRosterService::STATE_LATE,
            default => OutletRosterService::STATE_PRESENT,
        };
    }

    /**
     * The row's shift ends the next day; without a shift, the worker's company hours do.
     */
    private function crossesMidnight(Attendance $row): bool
    {
        $shift = $row->shift;

        if ($shift) {
            if ((int) $row->segment === 2 && $shift->hasSecondSegment()) {
                return substr((string) $shift->second_end_time, 0, 5) <= substr((string) $shift->second_start_time, 0, 5);
            }

            return $shift->crossesMidnight();
        }

        $policy = $this->worker($row)?->company?->policy;

        if (! $policy?->work_start_time || ! $policy->work_end_time) {
            return false;
        }

        return substr((string) $policy->work_end_time, 0, 5) <= substr((string) $policy->work_start_time, 0, 5);
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
        $counts = array_fill_keys([...array_keys(OutletRosterService::STATE_LABELS), self::STATE_BREAK, 'cuti'], 0);
        $scheduled = 0;
        $checkedIn = 0;

        foreach ($entries as $entry) {
            $counts[$entry['state']] = ($counts[$entry['state']] ?? 0) + 1;

            if (in_array($entry['state'], [OutletRosterService::STATE_DAY_OFF, 'cuti'], true)) {
                continue;
            }

            $scheduled++;

            // A refused attendance is not a presence: it does not count as checked in.
            if ($entry['check_in'] !== null && ! in_array($entry['state'], [...self::NOT_STARTED, OutletRosterService::STATE_REJECTED], true)) {
                $checkedIn++;
            }
        }

        return ['scheduled' => $scheduled, 'checked_in' => $checkedIn] + $counts;
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
