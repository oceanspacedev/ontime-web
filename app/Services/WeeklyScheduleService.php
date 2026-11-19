<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\LeaveRequest;
use App\Models\ShiftSchedule;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * One week (Monday to Sunday) of the schedules a manager oversees, one line per worker with a cell
 * per day: the same grid shape as the outlets' weekly spreadsheets. A day with several schedules keeps
 * one cell (the primary schedule, {@see ShiftSchedule::inDayOrder()}) and lists them all in day_schedules.
 * leave_dates lists every date of the week an approved leave of the worker is charged for, with or without
 * a schedule; a worker on leave without any schedule that week is listed too (with no cells).
 */
final class WeeklyScheduleService
{
    /**
     * @param  array{company_id?: int|null, division_id?: int|null, location_id?: int|null}  $filters
     * @return array{
     *     start: string,
     *     end: string,
     *     days: list<string>,
     *     workers: list<array{
     *         worker: array{type: string, id: int, company_id: int, number: string, name: string},
     *         days: array<string, array{schedule_id: int, sequence: int, is_day_off: bool, shift: array{id: int, name: string, code: string|null, talenta_code: string|null, label: string, segments: list<array{start: string, end: string}>}|null, location_id: int|null, outlet: string|null, section: string|null, borrowed_from: string|null, locked: bool, on_leave: bool, more: int}>,
     *         day_schedules: array<string, list<array<string, mixed>>>,
     *         leave_dates: list<string>,
     *     }>,
     * }
     */
    public function week(User $user, CarbonInterface $date, array $filters = []): array
    {
        $start = Carbon::parse($date)->startOfWeek();
        $end = $start->copy()->endOfWeek();
        $days = collect(range(0, 6))->map(fn (int $offset): string => $start->copy()->addDays($offset)->toDateString())->all();

        // A half-open range: the last day still matches where dates are stored with a time (SQLite).
        $schedules = OutletRosterService::filterSchedules(ShiftSchedule::query()->visibleTo($user), $filters)
            ->where('date', '>=', $start->toDateString())
            ->where('date', '<', $end->copy()->addDay()->toDateString())
            ->with([...ShiftSchedule::workerEagerLoads(), 'shift.division', 'location'])
            ->withExists(['attendances as is_locked' => fn (Builder $attendance) => ShiftSchedule::lockingAttendances($attendance)])
            ->orderBy('date')
            ->orderBy('sequence')
            ->get()
            ->filter(fn (ShiftSchedule $schedule): bool => $schedule->getWorkerProfile() !== null);

        $workers = [];

        foreach ($schedules->groupBy(fn (ShiftSchedule $schedule): string => $this->workerKey($schedule->getWorkerProfile()).'|'.$schedule->date->toDateString()) as $ofDay) {
            $ofDay = ShiftSchedule::inDayOrder($ofDay);
            /** @var ShiftSchedule $primary */
            $primary = $ofDay->first();
            $worker = $primary->getWorkerProfile();
            [$type, $number] = match (true) {
                $worker instanceof Employee => ['employee', $worker->nip],
                $worker instanceof Intern => ['intern', $worker->nis],
                $worker instanceof Freelancer => ['freelancer', $worker->freelancer_number],
            };
            $key = $this->workerKey($worker);
            $date = $primary->date->toDateString();

            $workers[$key] ??= [
                'worker' => ['type' => $type, 'id' => (int) $worker->getKey(), 'company_id' => (int) $worker->company_id, 'number' => (string) $number, 'name' => (string) $worker->full_name],
                'days' => [],
                'day_schedules' => [],
                'leave_dates' => [],
            ];
            $cells = $ofDay->map(fn (ShiftSchedule $schedule): array => $this->cell($schedule))->values()->all();
            // The released app reads one cell per day: the primary schedule, with how many more there are.
            $workers[$key]['days'][$date] = [...$cells[0], 'more' => count($cells) - 1];
            $workers[$key]['day_schedules'][$date] = $cells;
        }

        $workers = $this->markLeave($this->withWorkersOnLeave($user, $workers, $start, $end, $filters), $start, $end);

        return [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'days' => $days,
            'workers' => collect($workers)->sortBy(fn (array $row): string => $row['worker']['name'])->values()->all(),
        ];
    }

    /**
     * One schedule as a cell of the week.
     *
     * @return array{schedule_id: int, sequence: int, is_day_off: bool, shift: array<string, mixed>|null, location_id: int|null, outlet: string|null, section: string|null, borrowed_from: string|null, locked: bool, on_leave: bool}
     */
    private function cell(ShiftSchedule $schedule): array
    {
        $shift = $schedule->shift;

        return [
            'schedule_id' => (int) $schedule->id,
            'sequence' => (int) ($schedule->sequence ?: 1),
            'is_day_off' => (bool) $schedule->is_day_off,
            'shift' => $shift ? [
                'id' => (int) $shift->id,
                'name' => $shift->name,
                'code' => $shift->code,
                'talenta_code' => $shift->talenta_code,
                'label' => $shift->label(),
                'segments' => $shift->segments(),
            ] : null,
            'location_id' => $schedule->company_location_id,
            'outlet' => $schedule->location?->name,
            'section' => $schedule->section,
            'borrowed_from' => $schedule->borrowedFromBrand(),
            'locked' => (bool) $schedule->is_locked,
            // Filled by markLeave() from the approved leave charged on the date.
            'on_leave' => false,
        ];
    }

    /**
     * Adds the active workers in the user's view (and the company or brand filter) with an approved leave in
     * the week but no schedule in it, with no cells, so the leave shows on the grid. Such a row is kept only
     * when the leave is charged on a date of the week ({@see self::markLeave()}): a leave over the weekend
     * alone adds no empty row. With an outlet filter only workers with a schedule at that outlet are listed,
     * as before.
     *
     * @param  array<string, array<string, mixed>>  $workers
     * @param  array{company_id?: int|null, division_id?: int|null, location_id?: int|null}  $filters
     * @return array<string, array<string, mixed>>
     */
    private function withWorkersOnLeave(User $user, array $workers, CarbonInterface $start, CarbonInterface $end, array $filters): array
    {
        if ($filters['location_id'] ?? null) {
            return $workers;
        }

        $leaves = LeaveRequest::query()
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->where(fn (Builder $visible) => DataAccessService::scopeWorkerRecords($visible, $user, ['employee', 'intern', 'freelancer']))
            ->with(['employee', 'intern', 'freelancer'])
            ->get();

        foreach ($leaves as $leave) {
            $worker = $leave->getWorkerProfile();

            if (! $worker || isset($workers[$this->workerKey($worker)]) || $worker->status !== 'active') {
                continue;
            }

            if ((($filters['company_id'] ?? null) && (int) $worker->company_id !== (int) $filters['company_id'])
                || (($filters['division_id'] ?? null) && (int) $worker->division_id !== (int) $filters['division_id'])) {
                continue;
            }

            [$type, $number] = match (true) {
                $worker instanceof Employee => ['employee', $worker->nip],
                $worker instanceof Intern => ['intern', $worker->nis],
                $worker instanceof Freelancer => ['freelancer', $worker->freelancer_number],
            };

            $workers[$this->workerKey($worker)] = [
                'worker' => ['type' => $type, 'id' => (int) $worker->getKey(), 'company_id' => (int) $worker->company_id, 'number' => (string) $number, 'name' => (string) $worker->full_name],
                'days' => [],
                'day_schedules' => [],
                'leave_dates' => [],
                'leave_only' => true,
            ];
        }

        return $workers;
    }

    /**
     * Sets on_leave on every cell of a date an approved leave of the worker is charged for
     * ({@see LeaveRequest::chargedDatesByWorker()}, the same list the reports read), and lists those dates
     * in leave_dates, the dates without a cell included. A row added only for its leave
     * ({@see self::withWorkersOnLeave()}) whose leave charges no date of the week is dropped.
     *
     * @param  array<string, array{worker: array{type: string, id: int}, days: array<string, array<string, mixed>>, day_schedules: array<string, list<array<string, mixed>>>}>  $workers
     * @return array<string, array{worker: array{type: string, id: int}, days: array<string, array<string, mixed>>, day_schedules: array<string, list<array<string, mixed>>>}>
     */
    private function markLeave(array $workers, CarbonInterface $start, CarbonInterface $end): array
    {
        $charged = [];

        foreach (collect($workers)->groupBy(fn (array $row): string => $row['worker']['type']) as $type => $ofType) {
            $charged[$type] = LeaveRequest::chargedDatesByWorker("{$type}_id", $ofType->pluck('worker.id')->all(), $start, $end);
        }

        foreach ($workers as $key => $row) {
            $dates = $charged[$row['worker']['type']][$row['worker']['id']] ?? [];

            $leaveDates = array_keys($dates);
            sort($leaveDates);
            $leaveOnly = $row['leave_only'] ?? false;
            unset($workers[$key]['leave_only']);

            if ($leaveOnly && $leaveDates === []) {
                unset($workers[$key]);

                continue;
            }

            $workers[$key]['leave_dates'] = $leaveDates;

            foreach ($leaveDates as $date) {
                if (isset($row['days'][$date])) {
                    $workers[$key]['days'][$date]['on_leave'] = true;
                    $workers[$key]['day_schedules'][$date] = array_map(fn (array $cell): array => [...$cell, 'on_leave' => true], $row['day_schedules'][$date]);
                }
            }
        }

        return $workers;
    }

    private function workerKey(Employee|Intern|Freelancer $worker): string
    {
        return $worker::class.':'.$worker->getKey();
    }
}
