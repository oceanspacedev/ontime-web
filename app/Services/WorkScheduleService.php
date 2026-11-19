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
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Decides which working hours apply to a worker on a date:
 * 1. the dated shift schedule (a shift, or a day off),
 * 2. otherwise the worker's default shift,
 * 3. otherwise the company policy hours.
 * In a company that uses shift rosters a day without a working row has no hours (source "none", the
 * company hours are returned only to keep the shape), except for a fixed-hours worker's default shift.
 *
 * A split shift has two segments; pass the segment (1 or 2) to get its hours, so lateness is judged per segment.
 * A schedule may also name the outlet (company location) and section the worker covers that day.
 *
 * An attendance row that keeps the schedule it was checked in to (the sched_* snapshot) is judged against
 * that snapshot; only a row without one is judged against the hours that apply now.
 */
class WorkScheduleService
{
    /**
     * schedule_id is the working roster row the hours come from (null for a day off, a default shift or the
     * company hours), sequence its number within the date; start_at and end_at are the absolute window of
     * the segment on the date (the end on the next day when it is at or before the start); scheduled_minutes
     * and normal_minutes cover the whole shift (normal_minutes null = the shift has none set).
     *
     * @return array{shift: Shift|null, is_day_off: bool, start_time: string, end_time: string, late_tolerance_minutes: int, source: string, location: CompanyLocation|null, section: string|null, segment: int, segment_count: int, schedule_id: int|null, sequence: int, start_at: CarbonImmutable, end_at: CarbonImmutable, scheduled_minutes: int, normal_minutes: int|null, is_placement: bool}
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
            'schedule_id' => null,
            'sequence' => 1,
            'is_placement' => false,
        ];
        $policyHours += $this->window($date, $policyHours['start_time'], $policyHours['end_time']);
        $policyHours += ['scheduled_minutes' => (int) $policyHours['start_at']->diffInMinutes($policyHours['end_at']), 'normal_minutes' => null];

        $schedule = $this->rowOfDate($worker, $date, (bool) $policy?->uses_shift_roster);

        if ($schedule?->is_day_off) {
            return ['is_day_off' => true, 'source' => 'schedule'] + $policyHours;
        }

        $placement = [
            'location' => $schedule?->location,
            'section' => $schedule?->section,
            // A legacy placement row (an outlet without a shift) plans the standing hours, for a freelancer too.
            'is_placement' => ! $policy?->uses_shift_roster && $schedule !== null && ! $schedule->shift,
        ];

        // A company that uses shift rosters: a day without a working row is not scheduled, unless the worker
        // keeps fixed hours (a default shift and never rostered). The same rule as SessionResolver::standingSession().
        if ($policy?->uses_shift_roster && ! $schedule?->shift && ! app(SessionResolver::class)->standingSession($worker, $date)) {
            return [...$policyHours, ...$placement, 'source' => 'none'];
        }

        $shift = $schedule?->shift ?? ($worker->shift_id ? $worker->shift : null);

        if (! $shift) {
            return [...$policyHours, ...$placement];
        }

        // A split shift has two segments; asking for one the shift lacks falls back to the first.
        $segment = $segment === 2 && $shift->hasSecondSegment() ? 2 : 1;
        $startTime = (string) ($segment === 2 ? $shift->second_start_time : $shift->start_time);
        $endTime = (string) ($segment === 2 ? $shift->second_end_time : $shift->end_time);

        return [
            'shift' => $shift,
            'is_day_off' => false,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'segment' => $segment,
            'segment_count' => $shift->hasSecondSegment() ? 2 : 1,
            'late_tolerance_minutes' => (int) ($shift->late_tolerance_minutes ?? $policyHours['late_tolerance_minutes']),
            'source' => $schedule ? 'schedule' : 'default',
            ...$placement,
            // Only a row that names the shift itself is the schedule the hours come from.
            'schedule_id' => $schedule?->shift ? (int) $schedule->getKey() : null,
            'sequence' => $schedule?->shift ? (int) ($schedule->sequence ?: 1) : 1,
            ...$this->window($date, $startTime, $endTime),
            'scheduled_minutes' => $shift->scheduledMinutes(),
            'normal_minutes' => $shift->normal_minutes === null ? null : $shift->normalMinutes(),
        ];
    }

    /**
     * The one schedule row of the date the hours come from, picked the way {@see SessionResolver::sessionsFor()}
     * and the attendance ledger read the date: the primary working row ({@see ShiftSchedule::inDayOrder()});
     * else, in a company without shift rosters, a legacy placement row (an outlet without a shift, which
     * outweighs a day off there); else a day off; else a placement row (no hours in a roster company).
     */
    private function rowOfDate(Employee|Intern|Freelancer $worker, CarbonInterface $date, bool $usesRoster): ?ShiftSchedule
    {
        $rows = ShiftSchedule::query()
            ->with(['shift', 'location'])
            ->where($this->workerColumn($worker), $worker->getKey())
            ->whereDate('date', $date->toDateString())
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        $working = ShiftSchedule::inDayOrder($rows->filter(fn (ShiftSchedule $row): bool => ! $row->is_day_off && $row->shift !== null))->first();
        $placement = $rows->first(fn (ShiftSchedule $row): bool => ! $row->is_day_off && $row->shift === null);
        $dayOff = $rows->first(fn (ShiftSchedule $row): bool => (bool) $row->is_day_off);

        return $working ?? (! $usesRoster && $placement ? $placement : ($dayOff ?? $placement));
    }

    /**
     * Absolute start and end of working hours on a business date; an end at or before the start is on the next day.
     *
     * @return array{start_at: CarbonImmutable, end_at: CarbonImmutable}
     */
    private function window(CarbonInterface $date, string $startTime, string $endTime): array
    {
        $day = CarbonImmutable::parse($date->toDateString());
        $end = $day->setTimeFromTimeString($endTime);

        return [
            'start_at' => $day->setTimeFromTimeString($startTime),
            'end_at' => substr($endTime, 0, 5) <= substr($startTime, 0, 5) ? $end->addDay() : $end,
        ];
    }

    /**
     * The segment of the session the worker is on now and its attendance row (null before checking in to
     * it): {@see SessionResolver::current()}, the same session the today endpoint and a check-in use. On a
     * split shift it is segment 2 once segment 1 is closed. Read at the current moment.
     *
     * @return array{segment: int, attendance: Attendance|null}
     */
    public function currentSegment(User $user, Employee|Intern|Freelancer $worker, ?CarbonInterface $date = null): array
    {
        $current = app(SessionResolver::class)->current($worker, now(), app(AttendanceCheckService::class)->closableAttendance($user));
        $attendance = $current['attendance']?->loadMissing(['employee', 'intern', 'freelancer', 'location']);

        return [
            'segment' => $current['session']->segment ?? (int) ($attendance?->segment ?: 1),
            'attendance' => $attendance,
        ];
    }

    /**
     * Minutes after the shift start, or 0 when within the tolerance or on a day off.
     *
     * The shift starts on the business date, which is the check-in's own date unless given: a check-in
     * after midnight for a night shift that started the evening before passes that evening's date.
     *
     * No hours at all (source "none": a day without a schedule in a company that uses shift rosters) is never late.
     *
     * @param  array{is_day_off: bool, start_time: string, late_tolerance_minutes: int, source?: string}  $workingHours
     */
    public function lateMinutes(array $workingHours, CarbonInterface $checkIn, ?CarbonInterface $businessDate = null): int
    {
        if ($workingHours['is_day_off'] || ($workingHours['source'] ?? null) === 'none') {
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
     * Minutes the row's check-in falls after the scheduled start kept in its snapshot, by the same rule as
     * {@see self::lateMinutes()}; 0 without a check-in or when the snapshot has no hours. Only for a row
     * with a snapshot ({@see Attendance::hasSnapshot()}).
     */
    public function snapshotLateMinutes(Attendance $attendance): int
    {
        // An off-schedule check-in without a covered shift has no start to be late for (as in earlyLeaveMinutes()).
        if (! $attendance->check_in || ! $attendance->sched_start_at || $attendance->sched_source === 'none' || ($attendance->is_off_schedule && ! $attendance->shift_id)) {
            return 0;
        }

        $start = $attendance->sched_start_at;

        if ($attendance->check_in->lessThanOrEqualTo($start->copy()->addMinutes((int) $attendance->sched_tolerance_minutes))) {
            return 0;
        }

        return max(1, intdiv((int) $start->diffInSeconds($attendance->check_in, true), 60));
    }

    /**
     * The hours of one segment of the given shift, for a check-in that covers a shift outside the
     * worker's own schedule ("Masuk di luar jadwal"). They carry no date, so no absolute window.
     *
     * @return array{shift: Shift, is_day_off: bool, start_time: string, end_time: string, late_tolerance_minutes: int, source: string, location: CompanyLocation|null, section: string|null, segment: int, segment_count: int, schedule_id: null, sequence: int, scheduled_minutes: int, normal_minutes: int|null}
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
            'schedule_id' => null,
            'sequence' => 1,
            'scheduled_minutes' => $shift->scheduledMinutes(),
            'normal_minutes' => $shift->normal_minutes === null ? null : $shift->normalMinutes(),
        ];
    }

    /**
     * Whether the resolved hours mean the worker is not expected to work: a dated day off, a day without
     * any session in a company that uses shift rosters (source "none", any worker type), or a freelancer
     * with neither a dated shift nor a default shift (freelancers only work when rostered). Elsewhere
     * employees and interns without a schedule work the default shift or the company hours; so does a
     * freelancer on a legacy placement row (is_placement), as the attendance ledger plans it.
     *
     * @param  array{is_day_off: bool, source: string, shift?: Shift|null, is_placement?: bool}  $workingHours
     */
    public function isOffSchedule(Employee|Intern|Freelancer $worker, array $workingHours): bool
    {
        if ($workingHours['is_day_off'] || $workingHours['source'] === 'none') {
            return true;
        }

        return $worker instanceof Freelancer
            && ($workingHours['shift'] ?? null) === null
            && $workingHours['source'] === 'policy'
            && ! ($workingHours['is_placement'] ?? false);
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
     * or 0 when at or after it, on a day off, or without a check-out.
     *
     * A row with a schedule snapshot is judged against the end kept in it (0 when it has no hours, or for
     * an off-schedule check-in without a covered shift). A row without one: its own shift (the covered
     * shift of an off-schedule check-in included) is preferred over today's roster; without a shift the
     * dated schedule or the company hours apply, except for an off-schedule check-in or a worker who is
     * not rostered at all. A segment ending at or before its start ends the next day.
     */
    public function earlyLeaveMinutes(Attendance $attendance): int
    {
        if (! $attendance->check_out || ! $attendance->date) {
            return 0;
        }

        if ($attendance->hasSnapshot()) {
            $end = $attendance->sched_end_at;

            if (! $end || $attendance->sched_source === 'none' || ($attendance->is_off_schedule && ! $attendance->shift_id)) {
                return 0;
            }

            return $attendance->check_out->lessThan($end) ? (int) floor($attendance->check_out->diffInSeconds($end, true) / 60) : 0;
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
