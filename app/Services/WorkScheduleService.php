<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\CompanyLocation;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Decides which working hours apply to a worker on a date:
 * 1. the dated shift schedule (a shift, or a day off),
 * 2. otherwise the worker's default shift,
 * 3. otherwise the company policy hours.
 *
 * A split shift has two segments; pass the segment (1 or 2) to get its hours, so lateness is judged per segment.
 * A schedule may also name the outlet (company location) and section the worker covers that day.
 */
class WorkScheduleService
{
    /**
     * @return array{shift: Shift|null, is_day_off: bool, start_time: string, end_time: string, late_tolerance_minutes: int, source: string, location: CompanyLocation|null, section: string|null, segment: int, segment_count: int}
     */
    public function resolve(Employee|Intern|Freelancer $worker, CarbonInterface $date, int $segment = 1): array
    {
        $policy = $worker->company?->policy;
        $policyHours = [
            'shift' => null,
            'is_day_off' => false,
            'start_time' => (string) ($policy?->work_start_time ?? '08:00:00'),
            'end_time' => (string) ($policy?->work_end_time ?? '17:00:00'),
            'late_tolerance_minutes' => (int) ($policy?->late_tolerance_minutes ?? 15),
            'source' => 'policy',
            'location' => null,
            'section' => null,
            'segment' => 1,
            'segment_count' => 1,
        ];

        $schedule = ShiftSchedule::query()
            ->with(['shift', 'location'])
            ->where($this->workerColumn($worker), $worker->getKey())
            ->whereDate('date', $date->toDateString())
            ->first();

        if ($schedule?->is_day_off) {
            return ['is_day_off' => true, 'source' => 'schedule'] + $policyHours;
        }

        $shift = $schedule?->shift ?? ($worker->shift_id ? $worker->shift : null);
        $placement = [
            'location' => $schedule?->location,
            'section' => $schedule?->section,
        ];

        if (! $shift) {
            return [...$policyHours, ...$placement];
        }

        // A split shift has two segments; asking for one the shift lacks falls back to the first.
        $segment = $segment === 2 && $shift->hasSecondSegment() ? 2 : 1;

        return [
            'shift' => $shift,
            'is_day_off' => false,
            'start_time' => (string) ($segment === 2 ? $shift->second_start_time : $shift->start_time),
            'end_time' => (string) ($segment === 2 ? $shift->second_end_time : $shift->end_time),
            'segment' => $segment,
            'segment_count' => $shift->hasSecondSegment() ? 2 : 1,
            'late_tolerance_minutes' => (int) ($shift->late_tolerance_minutes ?? $policyHours['late_tolerance_minutes']),
            'source' => $schedule ? 'schedule' : 'default',
            ...$placement,
        ];
    }

    /**
     * The segment the worker is on today and its attendance row (null before checking in to it).
     * On a split shift it is segment 2 once segment 1 is checked out.
     *
     * @return array{segment: int, attendance: Attendance|null}
     */
    public function currentSegment(User $user, Employee|Intern|Freelancer $worker, CarbonInterface $date): array
    {
        $rows = Attendance::byWorker($user)->with(['employee', 'intern', 'freelancer', 'location'])->whereDate('date', $date->toDateString())->orderBy('segment')->get();
        $first = $rows->firstWhere('segment', 1);

        if ($first?->check_out && $this->resolve($worker, $date)['segment_count'] > 1) {
            return ['segment' => 2, 'attendance' => $rows->firstWhere('segment', 2)];
        }

        return ['segment' => 1, 'attendance' => $first ?? $rows->first()];
    }

    /**
     * Minutes after the shift start, or 0 when within the tolerance or on a day off.
     *
     * @param  array{is_day_off: bool, start_time: string, late_tolerance_minutes: int}  $workingHours
     */
    public function lateMinutes(array $workingHours, CarbonInterface $checkIn): int
    {
        if ($workingHours['is_day_off']) {
            return 0;
        }

        $start = $checkIn->copy()->setTimeFromTimeString($workingHours['start_time']);

        if ($checkIn->lessThanOrEqualTo($start->copy()->addMinutes($workingHours['late_tolerance_minutes']))) {
            return 0;
        }

        return (int) max(0, $start->diffInMinutes($checkIn));
    }

    public function workerColumn(Employee|Intern|Freelancer $worker): string
    {
        return match (true) {
            $worker instanceof Employee => 'employee_id',
            $worker instanceof Intern => 'intern_id',
            default => 'freelancer_id',
        };
    }
}
