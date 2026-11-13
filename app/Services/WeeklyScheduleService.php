<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\ShiftSchedule;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * One week (Monday to Sunday) of the schedules a manager oversees, one line per worker with a cell
 * per day: the same grid shape as the outlets' weekly spreadsheets.
 */
final class WeeklyScheduleService
{
    /**
     * @return array{
     *     start: string,
     *     end: string,
     *     days: list<string>,
     *     workers: list<array{
     *         worker: array{type: string, id: int, company_id: int, number: string, name: string},
     *         days: array<string, array{schedule_id: int, is_day_off: bool, shift: array{id: int, name: string, code: string|null, talenta_code: string|null, label: string, segments: list<array{start: string, end: string}>}|null, location_id: int|null, outlet: string|null, section: string|null, borrowed_from: string|null}>,
     *     }>,
     * }
     */
    public function week(User $user, CarbonInterface $date): array
    {
        $start = Carbon::parse($date)->startOfWeek();
        $end = $start->copy()->endOfWeek();
        $days = collect(range(0, 6))->map(fn (int $offset): string => $start->copy()->addDays($offset)->toDateString())->all();

        $schedules = ShiftSchedule::query()
            ->visibleTo($user)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->with([...ShiftSchedule::workerEagerLoads(), 'shift.division', 'location'])
            ->get()
            ->filter(fn (ShiftSchedule $schedule): bool => $schedule->getWorkerProfile() !== null);

        $workers = [];

        foreach ($schedules as $schedule) {
            $worker = $schedule->getWorkerProfile();
            [$type, $number] = match (true) {
                $worker instanceof Employee => ['employee', $worker->nip],
                $worker instanceof Intern => ['intern', $worker->nis],
                $worker instanceof Freelancer => ['freelancer', $worker->freelancer_number],
            };
            $key = "{$type}:{$worker->getKey()}";
            $shift = $schedule->shift;

            $workers[$key] ??= [
                'worker' => ['type' => $type, 'id' => (int) $worker->getKey(), 'company_id' => (int) $worker->company_id, 'number' => (string) $number, 'name' => (string) $worker->full_name],
                'days' => [],
            ];
            $workers[$key]['days'][$schedule->date->toDateString()] = [
                'schedule_id' => (int) $schedule->id,
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
            ];
        }

        return [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'days' => $days,
            'workers' => collect($workers)->sortBy(fn (array $row): string => $row['worker']['name'])->values()->all(),
        ];
    }
}
