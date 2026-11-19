<?php

namespace App\Services;

use App\Models\Shift;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Gives attendance that was checked in before the schedule snapshot existed the snapshot it would have
 * had: the hours, tolerance and names of the shift stored on the row (or the company hours), and a link
 * to the roster row when that is unambiguous.
 *
 * Only rows with a check-in and without a snapshot are handled, so it can be run again after an
 * interruption and a second run changes nothing. It never touches the judged result of a row
 * (status, late_minutes, check_in_late_minutes, early_leave_minutes), and it leaves the normal minutes
 * empty so past months never gain automatic overtime.
 */
final class AttendanceSnapshotBackfill
{
    public const CHUNK_SIZE = 1000;

    /**
     * Largest value attendances.sched_tolerance_minutes can hold (unsigned small integer). The shift and
     * policy columns it is copied from are wider, and the policy one is signed.
     */
    public const MAX_TOLERANCE_MINUTES = 65535;

    private const WORKER_TABLES = [
        'employee_id' => 'employees',
        'intern_id' => 'interns',
        'freelancer_id' => 'freelancers',
    ];

    /**
     * @return array{filled: int, linked: int, remaining: int} remaining = checked-in rows still without a snapshot (must be 0)
     */
    public function __invoke(): array
    {
        return $this->run();
    }

    /**
     * @return array{filled: int, linked: int, remaining: int} remaining = checked-in rows still without a snapshot (must be 0)
     */
    public function run(): array
    {
        $filled = 0;
        $linked = 0;

        DB::table('attendances')
            ->whereNotNull('check_in')
            ->whereNull('sched_source')
            ->select(['id', 'employee_id', 'intern_id', 'freelancer_id', 'shift_id', 'company_location_id', 'date', 'segment', 'is_off_schedule'])
            ->chunkById(self::CHUNK_SIZE, function (Collection $rows) use (&$filled, &$linked): void {
                $shifts = $this->keyedRows('shifts', $rows->pluck('shift_id'), ['id', 'name', 'code', 'start_time', 'end_time', 'second_start_time', 'second_end_time', 'late_tolerance_minutes']);
                $outletNames = $this->keyedRows('company_locations', $rows->pluck('company_location_id'), ['id', 'name']);
                $schedules = $this->schedulesFor($rows);
                $policies = $this->policiesFor($rows);

                // One commit per chunk instead of one per row. A chunk that fails rolls back as a whole
                // and is picked up again by the next run, because its rows still have no snapshot.
                DB::transaction(function () use ($rows, $shifts, $outletNames, $schedules, $policies, &$filled, &$linked): void {
                    $chunkFilled = 0;
                    $chunkLinked = 0;

                    foreach ($rows as $row) {
                        $snapshot = $this->snapshotFor($row, $shifts, $outletNames, $schedules, $policies);

                        $updated = DB::table('attendances')->where('id', $row->id)->whereNull('sched_source')->update($snapshot);

                        $chunkFilled += $updated;
                        $chunkLinked += $updated > 0 && $snapshot['shift_schedule_id'] !== null ? 1 : 0;
                    }

                    $filled += $chunkFilled;
                    $linked += $chunkLinked;
                });
            });

        return [
            'filled' => $filled,
            'linked' => $linked,
            'remaining' => DB::table('attendances')->whereNotNull('check_in')->whereNull('sched_source')->count(),
        ];
    }

    /**
     * @param  Collection<int, object>  $shifts  shift rows by id
     * @param  Collection<int, object>  $outletNames  outlet rows by id
     * @param  array<string, list<object>>  $schedules  working roster rows by "column:worker:date"
     * @param  array<string, object|null>  $policies  company policy by "column:worker"
     * @return array<string, int|string|null>
     */
    private function snapshotFor(object $row, Collection $shifts, Collection $outletNames, array $schedules, array $policies): array
    {
        $workerColumn = $this->workerColumn($row);
        $workerId = $workerColumn ? (int) $row->{$workerColumn} : null;
        $date = substr((string) $row->date, 0, 10);
        $shift = $row->shift_id ? $shifts->get((int) $row->shift_id) : null;
        $policy = $policies["{$workerColumn}:{$workerId}"] ?? null;

        // A link is made only when the date has exactly one working roster row and it carries the shift
        // the attendance was judged against (for a cover check-in: the cover shift). Never a day off.
        $workingRows = $schedules["{$workerColumn}:{$workerId}:{$date}"] ?? [];
        $schedule = $shift && count($workingRows) === 1 && (int) $workingRows[0]->shift_id === (int) $shift->id
            ? $workingRows[0]
            : null;

        $start = $end = $scheduledMinutes = null;
        $source = 'none';

        if ($shift) {
            $usesSecondPart = (int) $row->segment === 2 && filled($shift->second_start_time) && filled($shift->second_end_time);
            [$start, $end] = $this->window($date, (string) ($usesSecondPart ? $shift->second_start_time : $shift->start_time), (string) ($usesSecondPart ? $shift->second_end_time : $shift->end_time));
            $scheduledMinutes = (new Shift)->forceFill([
                'start_time' => $shift->start_time,
                'end_time' => $shift->end_time,
                'second_start_time' => $shift->second_start_time,
                'second_end_time' => $shift->second_end_time,
            ])->scheduledMinutes();
            $source = $schedule ? 'schedule' : 'default';
        } elseif ($workerColumn !== null && $workerColumn !== 'freelancer_id') {
            [$start, $end] = $this->window($date, (string) ($policy->work_start_time ?? '08:00:00'), (string) ($policy->work_end_time ?? '17:00:00'));
            $scheduledMinutes = (int) $start->diffInMinutes($end);
            $source = 'policy';
        }

        if ($row->is_off_schedule) {
            $source = 'cover';
        }

        return [
            'shift_schedule_id' => $schedule?->id,
            'sched_start_at' => $start?->format('Y-m-d H:i:s'),
            'sched_end_at' => $end?->format('Y-m-d H:i:s'),
            'sched_tolerance_minutes' => max(0, min(self::MAX_TOLERANCE_MINUTES, (int) ($shift?->late_tolerance_minutes ?? $policy?->late_tolerance_minutes ?? 15))),
            'sched_scheduled_minutes' => $scheduledMinutes,
            'sched_normal_minutes' => null,
            'sched_shift_name' => $shift ? mb_substr((string) $shift->name, 0, 100) : null,
            'sched_shift_code' => $shift && filled($shift->code) ? mb_substr((string) $shift->code, 0, 30) : null,
            'sched_outlet_name' => $row->company_location_id && $outletNames->has((int) $row->company_location_id)
                ? mb_substr((string) $outletNames->get((int) $row->company_location_id)->name, 0, 150)
                : null,
            'sched_section' => $schedule && filled($schedule->section) ? mb_substr((string) $schedule->section, 0, 100) : null,
            'sched_source' => $source,
        ];
    }

    /**
     * Absolute start and end of working hours on a date; an end at or before the start is on the next day.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function window(string $date, string $startTime, string $endTime): array
    {
        $start = Carbon::parse($date.' '.substr($startTime, 0, 5).':00');
        $end = Carbon::parse($date.' '.substr($endTime, 0, 5).':00');

        if ($end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        return [$start, $end];
    }

    private function workerColumn(object $row): ?string
    {
        foreach (array_keys(self::WORKER_TABLES) as $column) {
            if ($row->{$column}) {
                return $column;
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, mixed>  $ids
     * @param  list<string>  $columns
     * @return Collection<int, object>
     */
    private function keyedRows(string $table, Collection $ids, array $columns): Collection
    {
        $ids = $ids->filter()->unique()->values();

        return $ids->isEmpty()
            ? collect()
            : DB::table($table)->whereIn('id', $ids)->get($columns)->keyBy(fn (object $record): int => (int) $record->id);
    }

    /**
     * Working (not day-off) roster rows of the chunk's workers and dates, by "column:worker:date".
     *
     * Read one date at a time, for the workers that have attendance on that date. Chunks follow the
     * attendance id, not the date, so one old date among recent ids must not pull in every roster row
     * of the months in between.
     *
     * @param  Collection<int, object>  $rows
     * @return array<string, list<object>>
     */
    private function schedulesFor(Collection $rows): array
    {
        $schedules = [];

        foreach ($rows->groupBy(fn (object $row): string => substr((string) $row->date, 0, 10)) as $date => $rowsOfDate) {
            $nextDate = Carbon::parse($date)->addDay()->toDateString();

            foreach (array_keys(self::WORKER_TABLES) as $column) {
                $workerIds = $rowsOfDate->pluck($column)->filter()->unique()->values();

                if ($workerIds->isEmpty()) {
                    continue;
                }

                DB::table('shift_schedules')
                    ->whereIn($column, $workerIds)
                    ->where('date', '>=', $date)
                    ->where('date', '<', $nextDate)
                    ->where('is_day_off', false)
                    ->orderBy('id')
                    ->get(['id', $column, 'date', 'shift_id', 'section'])
                    ->each(function (object $schedule) use ($column, $date, &$schedules): void {
                        $schedules[$column.':'.(int) $schedule->{$column}.':'.$date][] = $schedule;
                    });
            }
        }

        return $schedules;
    }

    /**
     * Company policy of each worker in the chunk, by "column:worker" (null when the company has none).
     *
     * @param  Collection<int, object>  $rows
     * @return array<string, object|null>
     */
    private function policiesFor(Collection $rows): array
    {
        $companyOfWorker = [];

        foreach (self::WORKER_TABLES as $column => $table) {
            $workerIds = $rows->pluck($column)->filter()->unique()->values();

            if ($workerIds->isEmpty()) {
                continue;
            }

            foreach (DB::table($table)->whereIn('id', $workerIds)->get(['id', 'company_id']) as $worker) {
                $companyOfWorker["{$column}:".(int) $worker->id] = (int) $worker->company_id;
            }
        }

        $companyIds = array_values(array_unique($companyOfWorker));
        $policies = $companyIds === []
            ? collect()
            : DB::table('company_policies')
                ->whereIn('company_id', $companyIds)
                ->orderByDesc('id')
                ->get(['company_id', 'work_start_time', 'work_end_time', 'late_tolerance_minutes'])
                ->keyBy(fn (object $policy): int => (int) $policy->company_id);

        return array_map(fn (int $companyId): ?object => $policies->get($companyId), $companyOfWorker);
    }
}
