<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use Carbon\CarbonInterface;

/**
 * Decides which working hours apply to a worker on a date:
 * 1. the dated shift schedule (a shift, or a day off),
 * 2. otherwise the worker's default shift,
 * 3. otherwise the company policy hours.
 */
class WorkScheduleService
{
    /**
     * @return array{shift: Shift|null, is_day_off: bool, start_time: string, end_time: string, late_tolerance_minutes: int, source: string}
     */
    public function resolve(Employee|Intern|Freelancer $worker, CarbonInterface $date): array
    {
        $policy = $worker->company?->policy;
        $policyHours = [
            'shift' => null,
            'is_day_off' => false,
            'start_time' => (string) ($policy?->work_start_time ?? '08:00:00'),
            'end_time' => (string) ($policy?->work_end_time ?? '17:00:00'),
            'late_tolerance_minutes' => (int) ($policy?->late_tolerance_minutes ?? 15),
            'source' => 'policy',
        ];

        $schedule = ShiftSchedule::query()
            ->with('shift')
            ->where($this->workerColumn($worker), $worker->getKey())
            ->whereDate('date', $date->toDateString())
            ->first();

        if ($schedule?->is_day_off) {
            return ['is_day_off' => true, 'source' => 'schedule'] + $policyHours;
        }

        $shift = $schedule?->shift ?? ($worker->shift_id ? $worker->shift : null);

        if (! $shift) {
            return $policyHours;
        }

        return [
            'shift' => $shift,
            'is_day_off' => false,
            'start_time' => (string) $shift->start_time,
            'end_time' => (string) $shift->end_time,
            'late_tolerance_minutes' => (int) ($shift->late_tolerance_minutes ?? $policyHours['late_tolerance_minutes']),
            'source' => $schedule ? 'schedule' : 'default',
        ];
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
