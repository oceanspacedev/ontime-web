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
     * The shift starts on the business date, which is the check-in's own date unless given: a check-in
     * after midnight for a night shift that started the evening before passes that evening's date.
     *
     * @param  array{is_day_off: bool, start_time: string, late_tolerance_minutes: int}  $workingHours
     */
    public function lateMinutes(array $workingHours, CarbonInterface $checkIn, ?CarbonInterface $businessDate = null): int
    {
        if ($workingHours['is_day_off']) {
            return 0;
        }

        $start = $checkIn->copy()->setDateFrom($businessDate ?? $checkIn)->setTimeFromTimeString($workingHours['start_time']);

        // Judged to the second: with a 0-minute tolerance 08:00:45 is already past the window.
        if ($checkIn->lessThanOrEqualTo($start->copy()->addMinutes($workingHours['late_tolerance_minutes']))) {
            return 0;
        }

        // Whole minutes after the start, rounded down (08:15:30 is 15 minutes late), but never 0 once
        // the window has passed, so a late check-in always reads as at least 1 minute late.
        return max(1, intdiv((int) $start->diffInSeconds($checkIn, true), 60));
    }

    /**
     * The hours of one segment of the given shift, for a check-in that covers a shift outside the
     * worker's own schedule ("Masuk di luar jadwal").
     *
     * @return array{shift: Shift, is_day_off: bool, start_time: string, end_time: string, late_tolerance_minutes: int, source: string, location: CompanyLocation|null, section: string|null, segment: int, segment_count: int}
     */
    public function hoursForShift(Shift $shift, Employee|Intern|Freelancer $worker, int $segment = 1, ?CompanyLocation $location = null): array
    {
        $segment = $segment === 2 && $shift->hasSecondSegment() ? 2 : 1;

        return [
            'shift' => $shift,
            'is_day_off' => false,
            'start_time' => (string) ($segment === 2 ? $shift->second_start_time : $shift->start_time),
            'end_time' => (string) ($segment === 2 ? $shift->second_end_time : $shift->end_time),
            'late_tolerance_minutes' => (int) ($shift->late_tolerance_minutes ?? $worker->company?->policy?->late_tolerance_minutes ?? 15),
            'source' => 'cover',
            'location' => $location,
            'section' => null,
            'segment' => $segment,
            'segment_count' => $shift->hasSecondSegment() ? 2 : 1,
        ];
    }

    /**
     * Whether the resolved hours mean the worker is not expected to work: a dated day off, or a
     * freelancer with neither a dated shift nor a default shift (freelancers only work when rostered).
     * Employees and interns without a schedule work the default shift or the company hours.
     *
     * @param  array{is_day_off: bool, source: string, shift?: Shift|null}  $workingHours
     */
    public function isOffSchedule(Employee|Intern|Freelancer $worker, array $workingHours): bool
    {
        if ($workingHours['is_day_off']) {
            return true;
        }

        return $worker instanceof Freelancer && ($workingHours['shift'] ?? null) === null && $workingHours['source'] === 'policy';
    }

    /**
     * Whether the worker's dated schedule of the date names a working shift (not a day off, not empty).
     */
    public function hasRosteredShift(Employee|Intern|Freelancer $worker, CarbonInterface $date): bool
    {
        return ShiftSchedule::query()
            ->where($this->workerColumn($worker), $worker->getKey())
            ->whereDate('date', $date->toDateString())
            ->where('is_day_off', false)
            ->whereNotNull('shift_id')
            ->exists();
    }

    /**
     * Minutes the check-out falls before the scheduled end of the row's shift segment ("pulang cepat"),
     * or 0 when at or after it, on a day off, or without a check-out. The row's own shift (the snapshot
     * taken at check-in, or the covered shift of an off-schedule check-in) is preferred over today's
     * roster; without one the dated schedule or the company hours apply, except for an off-schedule
     * check-in or a worker who is not rostered at all. A segment ending at or before its start ends the next day.
     */
    public function earlyLeaveMinutes(Attendance $attendance): int
    {
        if (! $attendance->check_out || ! $attendance->date) {
            return 0;
        }

        $segment = (int) ($attendance->segment ?: 1);
        $shift = $attendance->shift_id
            ? ($attendance->relationLoaded('shift') ? $attendance->shift : Shift::query()->find($attendance->shift_id))
            : null;

        if ($shift) {
            $useSecond = $segment === 2 && $shift->hasSecondSegment();
            $startTime = (string) ($useSecond ? $shift->second_start_time : $shift->start_time);
            $endTime = (string) ($useSecond ? $shift->second_end_time : $shift->end_time);
        } else {
            $worker = $attendance->employee ?? $attendance->intern ?? $attendance->freelancer;

            // An off-schedule check-in without a covered shift has no scheduled end to leave early from.
            if (! $worker || $attendance->is_off_schedule) {
                return 0;
            }

            $hours = $this->resolve($worker, $attendance->date, $segment);

            // A day off, or a freelancer without any shift: the company hours are not theirs to leave early from.
            if ($this->isOffSchedule($worker, $hours)) {
                return 0;
            }

            $startTime = (string) $hours['start_time'];
            $endTime = (string) $hours['end_time'];
        }

        $end = $attendance->date->copy()->setTimeFromTimeString($endTime);

        if (substr($endTime, 0, 5) <= substr($startTime, 0, 5)) {
            $end->addDay();
        }

        // Whole minutes only: leaving 30 seconds before the end is not early.
        $checkOut = $attendance->check_out;

        return $checkOut->lessThan($end) ? (int) floor($checkOut->diffInSeconds($end, true) / 60) : 0;
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
