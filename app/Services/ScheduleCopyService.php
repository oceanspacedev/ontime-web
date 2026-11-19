<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\LeaveRequest;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Copies a week of schedules onto another week (Monday to Sunday), so a manager starts from last
 * week's roster instead of an empty one. Shared by the panel and the mobile API.
 *
 * Only workers inside the user's data access are copied. Days that already have a schedule are
 * left alone; shifts and outlets that were switched off since and days outside a worker's contract are
 * skipped, so a copy never overwrites work or plans someone who has left. A working day without a shift
 * is copied too. Days before today are never written, and every copy is logged with the source "copy".
 * A day with several schedules is copied whole, each keeping its number; days are never merged. A
 * schedule whose hours a check-in of the target day without a schedule already covers is skipped as filled;
 * one whose number another check-in of that day holds takes the lowest free number, as assignDay() does.
 */
final class ScheduleCopyService
{
    public function __construct(private readonly ShiftAssignmentService $assignments) {}

    /**
     * @return array{copied: int, skipped_existing: int, skipped_unavailable: int, skipped_ended: int, skipped_past: int, skipped_leave: int, skipped_overlap: int, from: string, to: string}
     */
    public function copyWeek(User $user, CarbonInterface $sourceWeek, CarbonInterface $targetWeek, ?int $companyId = null): array
    {
        return RosterChangeContext::run('copy', null, fn (): array => $this->copy($user, $sourceWeek, $targetWeek, $companyId));
    }

    /**
     * @return array{copied: int, skipped_existing: int, skipped_unavailable: int, skipped_ended: int, skipped_past: int, skipped_leave: int, skipped_overlap: int, from: string, to: string}
     */
    private function copy(User $user, CarbonInterface $sourceWeek, CarbonInterface $targetWeek, ?int $companyId): array
    {
        $source = Carbon::parse($sourceWeek)->startOfWeek();
        $target = Carbon::parse($targetWeek)->startOfWeek();
        $result = ['copied' => 0, 'skipped_existing' => 0, 'skipped_unavailable' => 0, 'skipped_ended' => 0, 'skipped_past' => 0, 'skipped_leave' => 0, 'skipped_overlap' => 0, 'from' => $source->toDateString(), 'to' => $target->toDateString()];

        if ($source->equalTo($target) || ! $user->canManageWorkers()) {
            return $result;
        }

        $offsetDays = (int) $source->diffInDays($target, false);

        $schedules = ShiftSchedule::query()
            ->visibleTo($user)
            ->with([...ShiftSchedule::workerEagerLoads(), 'shift', 'location'])
            // A half-open range: Sunday still matches where dates are stored with a time (SQLite).
            ->where('date', '>=', $source->toDateString())
            ->where('date', '<', $source->copy()->addWeek()->toDateString())
            ->orderBy('date')
            ->orderBy('sequence')
            ->get();
        $targetState = $this->targetWeekState($schedules, $target);
        /** @var array<string, list<int>> $copiedSequences the numbers given to the copies of each target worker-date */
        $copiedSequences = [];

        foreach ($schedules as $schedule) {
            $worker = $schedule->getWorkerProfile();

            if (! $worker || ($companyId !== null && (int) $worker->company_id !== $companyId) || ! $user->canManageWorker($worker)) {
                $result['skipped_unavailable']++;

                continue;
            }

            // A working day without a shift (the default shift or the policy hours) is copied as it is; only a
            // shift or an outlet switched off since is skipped.
            if (! $schedule->is_day_off && $schedule->shift_id !== null && ! $schedule->shift?->is_active) {
                $result['skipped_unavailable']++;

                continue;
            }

            if (! $schedule->is_day_off && $schedule->company_location_id !== null && ! $schedule->location?->is_active) {
                $result['skipped_unavailable']++;

                continue;
            }

            $column = app(WorkScheduleService::class)->workerColumn($worker);
            $targetDate = $schedule->date->copy()->addDays($offsetDays);
            $date = $targetDate->toDateString();
            $workerKey = $column.':'.$worker->getKey();
            $dayKey = $workerKey.':'.$date;

            // A target day that already had any schedule is left alone; the rows of one source day are copied together.
            if (isset($targetState['filled'][$dayKey])) {
                $result['skipped_existing']++;

                continue;
            }

            // Outside the worker's contract (ended, not started yet) or too far ahead: never written.
            if ($this->assignments->dateError($worker, $targetDate) !== null) {
                $result['skipped_ended']++;

                continue;
            }

            // A day already gone is never planned after the fact by a copy.
            if ($date < today()->toDateString()) {
                $result['skipped_past']++;

                continue;
            }

            // A working schedule is not put on a day an approved leave of the worker is charged for.
            if (! $schedule->is_day_off && isset($targetState['leave'][$workerKey][$date])) {
                $result['skipped_leave']++;

                continue;
            }

            // A working schedule that would run into a working schedule of the day before or the next day
            // (the target week's edges, a day skipped as already filled, a copy made earlier in this loop) is
            // refused, as assignDay() and the import refuse it.
            if ($this->overlapsNeighbours($schedule, $targetState['working'][$workerKey] ?? [], $date)) {
                $result['skipped_overlap']++;

                continue;
            }

            $copy = new ShiftSchedule([
                $column => $worker->getKey(),
                'date' => $date,
                'sequence' => (int) ($schedule->sequence ?: 1),
                'shift_id' => $schedule->shift_id,
                'company_location_id' => $schedule->company_location_id,
                'is_day_off' => $schedule->is_day_off,
                'section' => $schedule->section,
                'notes' => $schedule->notes,
                'created_by' => $user->id,
            ]);

            // A check-in of that day (or a night shift of the day before) without a schedule already covers
            // these hours: the day counts as filled, as assignDay() refuses it.
            if (isset($targetState['unlinked'][$dayKey]) && $this->assignments->unlinkedAttendanceError($worker, $copy) !== null) {
                $result['skipped_existing']++;

                continue;
            }

            // A number held by a check-in of that day that does not overlap (a rejected one, company hours
            // earlier in the day) is not inherited: the copy takes the lowest free number, as assignDay() does.
            $taken = [...($copiedSequences[$dayKey] ?? []), ...($targetState['sequences'][$dayKey] ?? [])];

            if (in_array($copy->sequence, $taken, true)) {
                $copy->sequence = $this->lowestFree($taken);
            }

            $copy->save();
            $copiedSequences[$dayKey][] = (int) $copy->sequence;

            if (! $copy->is_day_off && $schedule->shift !== null) {
                $targetState['working'][$workerKey][$date][] = $schedule->shift;
            }

            $result['copied']++;
        }

        return $result;
    }

    /**
     * What the copy needs to know of the target week, read once per kind of worker instead of per copied
     * schedule: the worker-dates that already have a schedule (filled), the charged leave dates (leave),
     * the schedule numbers held by a check-in (sequences, a rejected one included) and the worker-dates
     * a check-in without a schedule could overlap (unlinked: its own date and the next, for a night shift),
     * and the working shifts from the Sunday before to the Monday after the week (working), so a copy is
     * checked against its neighbouring days.
     *
     * @param  EloquentCollection<int, ShiftSchedule>  $schedules  the source week's schedules
     * @return array{filled: array<string, true>, leave: array<string, array<string, true>>, sequences: array<string, list<int>>, unlinked: array<string, true>, working: array<string, array<string, list<Shift>>>}
     */
    private function targetWeekState(EloquentCollection $schedules, Carbon $target): array
    {
        $state = ['filled' => [], 'leave' => [], 'sequences' => [], 'unlinked' => [], 'working' => []];
        $from = $target->toDateString();
        $until = $target->copy()->addWeek()->toDateString();
        $dayOf = fn (mixed $date): string => substr((string) $date, 0, 10);

        foreach (['employee_id', 'intern_id', 'freelancer_id'] as $column) {
            $ids = $schedules->pluck($column)->filter()->unique()->values()->all();

            if ($ids === []) {
                continue;
            }

            foreach (array_chunk($ids, 1000) as $chunk) {
                ShiftSchedule::query()->with('shift')->whereIn($column, $chunk)
                    ->where('date', '>=', $target->copy()->subDay()->toDateString())
                    ->where('date', '<', $target->copy()->addWeek()->addDay()->toDateString())
                    ->get()
                    ->each(function (ShiftSchedule $row) use (&$state, $column, $from, $until): void {
                        $date = $row->date->toDateString();
                        $workerKey = $column.':'.$row->{$column};

                        if ($date >= $from && $date < $until) {
                            $state['filled'][$workerKey.':'.$date] = true;
                        }

                        if (! $row->is_day_off && $row->shift !== null) {
                            $state['working'][$workerKey][$date][] = $row->shift;
                        }
                    });

                Attendance::query()->whereIn($column, $chunk)->whereNotNull('check_in')
                    ->where('date', '>=', $target->copy()->subDay()->toDateString())->where('date', '<', $until)
                    ->toBase()->get([$column, 'date', 'schedule_sequence', 'shift_schedule_id', 'status'])
                    ->each(function (object $row) use (&$state, $column, $dayOf, $from): void {
                        $date = $dayOf($row->date);
                        $key = $column.':'.$row->{$column}.':';

                        if ($date >= $from) {
                            $state['sequences'][$key.$date][] = (int) ($row->schedule_sequence ?: 1);
                        }

                        if ($row->shift_schedule_id === null && $row->status !== AttendanceStatus::Rejected->value) {
                            $state['unlinked'][$key.$date] = true;
                            $state['unlinked'][$key.Carbon::parse($date)->addDay()->toDateString()] = true;
                        }
                    });

                foreach (LeaveRequest::chargedDatesByWorker($column, $chunk, $target, $target->copy()->addDays(6)) as $workerId => $dates) {
                    $state['leave'][$column.':'.$workerId] = $dates;
                }
            }
        }

        return $state;
    }

    /**
     * Whether the source schedule, copied onto the date, overlaps a working schedule of the worker on the
     * day before or the next day.
     *
     * @param  array<string, list<Shift>>  $workingByDate  the worker's working shifts around the target week
     */
    private function overlapsNeighbours(ShiftSchedule $schedule, array $workingByDate, string $date): bool
    {
        if ($schedule->is_day_off || $schedule->shift === null) {
            return false;
        }

        $neighbours = [];

        foreach ([Carbon::parse($date)->subDay()->toDateString(), Carbon::parse($date)->addDay()->toDateString()] as $neighbourDate) {
            foreach ($workingByDate[$neighbourDate] ?? [] as $shift) {
                $neighbours[] = ['shift' => $shift, 'date' => $neighbourDate];
            }
        }

        return $neighbours !== [] && $this->assignments->overlapsAdjacentDays($schedule->shift, $date, $neighbours);
    }

    /**
     * The lowest schedule number not in the list.
     *
     * @param  list<int>  $taken
     */
    private function lowestFree(array $taken): int
    {
        $sequence = 1;

        while (in_array($sequence, $taken, true)) {
            $sequence++;
        }

        return $sequence;
    }

    /**
     * "12 jadwal disalin. 3 hari dilewati karena sudah terisi."
     *
     * @param  array{copied: int, skipped_existing: int, skipped_unavailable: int, skipped_ended?: int, skipped_past?: int, skipped_leave?: int, skipped_overlap?: int}  $result
     */
    public function summary(array $result): string
    {
        $parts = ["{$result['copied']} jadwal disalin."];

        if ($result['skipped_existing'] > 0) {
            $parts[] = "{$result['skipped_existing']} hari dilewati karena sudah terisi.";
        }

        if ($result['skipped_unavailable'] > 0) {
            $parts[] = "{$result['skipped_unavailable']} dilewati karena shift nonaktif atau di luar akses Anda.";
        }

        if (($result['skipped_ended'] ?? 0) > 0) {
            $parts[] = "{$result['skipped_ended']} dilewati karena di luar masa kontrak pekerja.";
        }

        if (($result['skipped_past'] ?? 0) > 0) {
            $parts[] = "{$result['skipped_past']} dilewati karena tanggalnya sudah lewat.";
        }

        if (($result['skipped_leave'] ?? 0) > 0) {
            $parts[] = "{$result['skipped_leave']} dilewati karena pekerja sedang cuti.";
        }

        if (($result['skipped_overlap'] ?? 0) > 0) {
            $parts[] = "{$result['skipped_overlap']} dilewati karena jam shiftnya bertabrakan dengan jadwal di hari sebelum atau sesudahnya.";
        }

        return implode(' ', $parts);
    }
}
