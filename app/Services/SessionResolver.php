<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\CompanyLocation;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Services\Attendance\CheckInPick;
use App\Services\Attendance\WorkSession;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The work sessions a worker is planned for on a business date, their check-in and check-out windows,
 * and the schedule snapshot an attendance row keeps of the session it was checked in to.
 *
 * Read-only: nothing here writes, and reading an attendance row never fills in a missing snapshot.
 *
 * Sessions come from the dated schedule rows of the date (one per part of each working row). Without a
 * working row the worker's standing hours apply ({@see self::standingSession()}); a date with a day off
 * has no session. A row that is neither a day off nor names a shift counts as no row in a company that
 * uses shift rosters; elsewhere it is a legacy placement: the standing hours at its outlet and section
 * are planned (expected), for a freelancer without a default shift too and next to a day off, exactly
 * as {@see AttendanceLedger} plans them.
 */
class SessionResolver
{
    /** A session can be checked out live until this many hours after its scheduled end. */
    public const CHECK_OUT_GRACE_HOURS = 6;

    /** A session accepts check-in from this many minutes before it starts. */
    public const CHECK_IN_OPENS_MINUTES_BEFORE = 60;

    /** Largest value the unsigned small integer snapshot columns can hold. */
    private const MAX_SMALL_INTEGER = 65535;

    /** @var array<string, bool> holiday lookups by "date|company" */
    private array $holidays = [];

    /** @var array<string, string|null> Monday of the week of the worker's first schedule row (null: never rostered), by worker key */
    private array $rosteredSince = [];

    /**
     * Where a check-in tapped now lands. Candidates are the sessions of yesterday, today and tomorrow that
     * have no checked-in row (a rejected row consumes its session too):
     * 1. a named schedule (and part): among its parts without a check-in the one whose window is open now,
     *    else the next one (too early), else the last one that ended;
     * 2. else the session whose check-in window ({@see self::checkInWindow()}) contains now, the latest
     *    start when several do; a session of an earlier date that started long ago gives way to a
     *    session of today that follows on it and starts sooner than that (too early for it);
     * 3. else a later session of today not open yet: too early;
     * 4. else the latest session of today that ended without a check-in (booked late, the lenient rule);
     * 5. else every session already has a check-in, or the worker has no session today (off schedule).
     *
     * The open-row guard (an earlier session still running) is the caller's, see
     * {@see AttendanceCheckService}. Sessions of a split shift covered off schedule count as sessions of
     * their date, so the second part of a cover is offered like a rostered one.
     */
    public function pickForCheckIn(Employee|Intern|Freelancer $worker, CarbonInterface $now, ?int $scheduleId = null, ?int $segment = null): CheckInPick
    {
        $now = CarbonImmutable::instance($now);

        return $this->pickFrom($this->plannedAround($worker, $now), $now, $scheduleId, $segment);
    }

    /**
     * @param  list<array{session: WorkSession, attendance: Attendance|null}>  $plan
     * @param  bool  $lenient  a tap after every session of today has ended books the latest one of them;
     *                         the card ({@see current()}) does not describe that session
     */
    private function pickFrom(array $plan, CarbonImmutable $now, ?int $scheduleId = null, ?int $segment = null, bool $lenient = true): CheckInPick
    {
        $today = $this->dayOf($now);
        $isFree = fn (array $entry): bool => $entry['attendance']?->check_in === null;
        $book = fn (array $entry): CheckInPick => new CheckInPick(CheckInPick::SESSION, $entry['session']->date, $entry['session'], $entry['attendance']);

        if ($scheduleId !== null) {
            $parts = array_values(array_filter($plan, fn (array $entry): bool => $entry['session']->scheduleId === $scheduleId
                && ($segment === null || $entry['session']->segment === $segment)));

            if ($parts === []) {
                return new CheckInPick(CheckInPick::INVALID_SCHEDULE, $today);
            }

            $free = array_values(array_filter($parts, $isFree));

            if ($free === []) {
                return new CheckInPick(CheckInPick::ALREADY_IN, $parts[0]['session']->date);
            }

            // Without a part named, the parts of the schedule are picked as the sessions of a day are: the
            // part whose window is open now, else the next one not open yet, else the last one that ended.
            $openParts = $this->openAt($free, $now);

            if ($openParts !== []) {
                return $book($openParts[0]);
            }

            $laterPart = collect($free)->first(fn (array $entry): bool => $now->lessThan($this->checkInWindow($entry['session'])['opens_at']));

            if ($laterPart !== null) {
                return new CheckInPick(CheckInPick::TOO_EARLY, $laterPart['session']->date, $laterPart['session'], opensAt: $this->checkInWindow($laterPart['session'])['opens_at']);
            }

            return $book($free[array_key_last($free)]);
        }

        $candidates = array_values(array_filter($plan, $isFree));
        $open = $this->openAt($candidates, $now);

        if ($open !== []) {
            $nearer = $this->nearerSessionOfToday($open[0], $candidates, $now, $today);

            // A rotating roster: an early tap before today's shift opens is not a very late check-in to the
            // unattended shift of the evening before when today's start is nearer (06:00 after 22:00-06:00).
            if ($nearer !== null) {
                return new CheckInPick(CheckInPick::TOO_EARLY, $today, $nearer['session'], opensAt: $this->checkInWindow($nearer['session'])['opens_at']);
            }

            return $book($open[0]);
        }

        $ofToday = array_values(array_filter($candidates, fn (array $entry): bool => $entry['session']->date->equalTo($today)));
        $later = array_values(array_filter($ofToday, fn (array $entry): bool => $entry['session']->startAt->greaterThan($now)));

        if ($later !== []) {
            return new CheckInPick(CheckInPick::TOO_EARLY, $today, $later[0]['session'], opensAt: $this->checkInWindow($later[0]['session'])['opens_at']);
        }

        // A session of tomorrow whose check-in window opens shortly, still today (00:30, open from 23:30): a
        // tap just before it is early for that session, not a cover or a very late check-in to today's.
        $tomorrowSoon = collect($candidates)->first(function (array $entry) use ($now, $today): bool {
            $opensAt = $this->checkInWindow($entry['session'])['opens_at'];

            return $entry['session']->date->equalTo($today->addDay())
                && $now->lessThan($opensAt)
                && $opensAt->isSameDay($now)
                && $now->diffInMinutes($opensAt) <= self::CHECK_IN_OPENS_MINUTES_BEFORE;
        });

        if ($tomorrowSoon !== null) {
            return new CheckInPick(CheckInPick::TOO_EARLY, $tomorrowSoon['session']->date, $tomorrowSoon['session'], opensAt: $this->checkInWindow($tomorrowSoon['session'])['opens_at']);
        }

        if ($ofToday !== []) {
            return $lenient ? $book($ofToday[array_key_last($ofToday)]) : new CheckInPick(CheckInPick::ALREADY_IN, $today);
        }

        $hasSessionToday = array_filter($plan, fn (array $entry): bool => $entry['session']->date->equalTo($today)) !== [];

        return new CheckInPick($hasSessionToday ? CheckInPick::ALREADY_IN : CheckInPick::OFF_SCHEDULE, $today);
    }

    /**
     * The entries whose check-in window contains now, the latest start first.
     *
     * @param  list<array{session: WorkSession, attendance: Attendance|null}>  $entries
     * @return list<array{session: WorkSession, attendance: Attendance|null}>
     */
    private function openAt(array $entries, CarbonImmutable $now): array
    {
        $open = array_values(array_filter($entries, function (array $entry) use ($now): bool {
            $window = $this->checkInWindow($entry['session']);

            return $now->greaterThanOrEqualTo($window['opens_at']) && $now->lessThan($window['closes_at']);
        }));

        usort($open, fn (array $a, array $b): int => [$b['session']->startAt->getTimestamp(), $a['session']->sequence, $a['session']->segment] <=> [$a['session']->startAt->getTimestamp(), $b['session']->sequence, $b['session']->segment]);

        return $open;
    }

    /**
     * When the open session belongs to an earlier business date and has already started, the unattended
     * session of today that starts later, no later than the open session's scheduled end (it follows on
     * or overlaps it), and whose start is nearer to now than the open session's start; else null. Today's
     * session is then the one the tap is meant for, only too early.
     *
     * A tap inside last night's session that today's session does not follow on (18:00 - 02:00, then
     * 06:00) always books last night's session, late: the worker is in the middle of it. After its
     * scheduled end it is no longer open, and today's session is picked by the usual rules.
     *
     * @param  array{session: WorkSession, attendance: Attendance|null}  $open
     * @param  list<array{session: WorkSession, attendance: Attendance|null}>  $candidates
     * @return array{session: WorkSession, attendance: Attendance|null}|null
     */
    private function nearerSessionOfToday(array $open, array $candidates, CarbonImmutable $now, CarbonImmutable $today): ?array
    {
        $session = $open['session'];

        if (! $session->date->lessThan($today) || $now->lessThanOrEqualTo($session->startAt)) {
            return null;
        }

        $sinceStart = $now->getTimestamp() - $session->startAt->getTimestamp();

        return collect($candidates)->first(fn (array $entry): bool => $entry['session']->date->equalTo($today)
            && $entry['session']->startAt->greaterThan($now)
            && $entry['session']->startAt->lessThanOrEqualTo($session->endAt)
            && $entry['session']->startAt->getTimestamp() - $now->getTimestamp() < $sinceStart);
    }

    /**
     * The session the worker is on now, the one the home card and the web page describe: the open row that
     * can still be checked out live (until its scheduled end has passed and a later session without a
     * check-in has started); else the session a check-in now would land on; else the next session of today
     * without a check-in whose end is still ahead (a rejected row consumes its session); else the last
     * session of today. Without any session today, today's latest attendance row (a cover, a leave) and no session.
     *
     * @param  Attendance|null  $open  the worker's live-closable row ({@see AttendanceCheckService::closableAttendance()})
     * @return array{session: WorkSession|null, attendance: Attendance|null, date: CarbonImmutable, sessions: list<array{session: WorkSession, attendance: Attendance|null}>}
     */
    public function current(Employee|Intern|Freelancer $worker, CarbonInterface $now, ?Attendance $open = null): array
    {
        $now = CarbonImmutable::instance($now);
        $today = $this->dayOf($now);
        $plan = $this->plannedAround($worker, $today);
        $ofDate = fn (CarbonImmutable $date): array => array_values(array_filter($plan, fn (array $entry): bool => $entry['session']->date->equalTo($date)));
        $result = fn (?array $entry, CarbonImmutable $date, ?Attendance $attendance = null): array => [
            'session' => $entry['session'] ?? null,
            'attendance' => $attendance ?? $entry['attendance'] ?? null,
            'date' => $date,
            'sessions' => $ofDate($date),
        ];

        if ($open !== null && $open->date) {
            $openDate = $this->dayOf($open->date);
            $entry = collect($plan)->first(fn (array $entry): bool => $entry['attendance']?->is($open) ?? false);
            $end = $this->windowOf($open)['end'] ?? null;
            $superseded = $end !== null && $now->greaterThanOrEqualTo($end) && collect($plan)->contains(
                fn (array $other): bool => $other['attendance']?->check_in === null
                    && $other['session']->startAt->greaterThanOrEqualTo($end)
                    && $now->greaterThanOrEqualTo($other['session']->startAt)
                    && $now->lessThan($other['session']->endAt),
            );

            if (! $superseded) {
                return $result($entry, $openDate, $open);
            }
        }

        $pick = $this->pickFrom($plan, $now, lenient: false);

        if ($pick->outcome === CheckInPick::SESSION && $pick->session !== null) {
            return $result(['session' => $pick->session, 'attendance' => $pick->attendance], $pick->session->date);
        }

        $todays = $ofDate($today);
        $next = collect($todays)->first(fn (array $entry): bool => $entry['attendance']?->check_in === null && $entry['session']->endAt->greaterThan($now));

        if ($next !== null || $todays !== []) {
            // Every session of today is over: the last one that was attended, never an earlier one left unattended.
            $lastAttended = collect($todays)
                ->filter(fn (array $entry): bool => $entry['attendance']?->check_in !== null)
                ->sortBy(fn (array $entry): int => $entry['session']->endAt->getTimestamp())
                ->last();

            return $result($next ?? $lastAttended ?? $todays[array_key_last($todays)], $today);
        }

        $latest = Attendance::query()
            ->where($this->workerColumn($worker), $worker->getKey())
            ->whereDate('date', $today->toDateString())
            ->orderByDesc('check_in')
            ->orderByDesc('schedule_sequence')
            ->orderByDesc('segment')
            ->first();

        return $result(null, $today, $latest);
    }

    /**
     * Every session of yesterday, today and tomorrow with the attendance row that belongs to it (matched by
     * date, schedule sequence and segment), ordered by start. A split shift covered off schedule adds the
     * parts of its cover shift that the roster does not have.
     *
     * @return list<array{session: WorkSession, attendance: Attendance|null}>
     */
    public function plannedAround(Employee|Intern|Freelancer $worker, CarbonInterface $day): array
    {
        $day = $this->dayOf($day);
        $rows = Attendance::query()
            ->with(['shift', 'location'])
            ->where($this->workerColumn($worker), $worker->getKey())
            ->whereDate('date', '>=', $day->subDay()->toDateString())
            ->whereDate('date', '<=', $day->addDay()->toDateString())
            ->get()
            ->keyBy(fn (Attendance $row): string => $row->date->toDateString().'|'.((int) $row->schedule_sequence ?: 1).':'.((int) $row->segment ?: 1));
        $plan = [];

        foreach ([$day->subDay(), $day, $day->addDay()] as $date) {
            $sessions = [];

            foreach ($this->sessionsFor($worker, $date) as $session) {
                $sessions[$session->key()] = $session;
            }

            foreach ($rows as $row) {
                if (! $row->is_off_schedule || ! $row->shift || ! $row->date->isSameDay($date)) {
                    continue;
                }

                $sequence = (int) $row->schedule_sequence ?: 1;

                foreach (array_keys($row->shift->segments()) as $index) {
                    $key = $sequence.':'.($index + 1);
                    $sessions[$key] ??= $this->shiftSession($row->shift, $date, $index + 1, $this->policyTolerance($worker), 'cover', sequence: $sequence, location: $row->location);
                }
            }

            foreach ($sessions as $key => $session) {
                $plan[] = ['session' => $session, 'attendance' => $rows->get($date->toDateString().'|'.$key)];
            }
        }

        usort($plan, fn (array $a, array $b): int => [$a['session']->startAt->getTimestamp(), $a['session']->sequence, $a['session']->segment] <=> [$b['session']->startAt->getTimestamp(), $b['session']->sequence, $b['session']->segment]);

        return $plan;
    }

    /**
     * Every session of the worker on the business date, ordered by start.
     *
     * @param  Collection<int, ShiftSchedule>|null  $rows  the worker's schedule rows of the date with shift and
     *                                                     location loaded, when the caller read them in bulk
     * @return list<WorkSession>
     */
    public function sessionsFor(Employee|Intern|Freelancer $worker, CarbonInterface $date, ?Collection $rows = null): array
    {
        $day = $this->dayOf($date);

        $rows = $rows === null
            ? ShiftSchedule::query()
                ->with(['shift', 'location'])
                ->where($this->workerColumn($worker), $worker->getKey())
                ->whereDate('date', $day->toDateString())
                ->orderBy('sequence')
                ->orderBy('id')
                ->get()
            : $rows->sortBy(fn (ShiftSchedule $row): string => sprintf('%03d|%010d', (int) ($row->sequence ?: 1), (int) $row->getKey()))->values();

        $workingRows = $rows->filter(fn (ShiftSchedule $row): bool => ! $row->is_day_off && $row->shift !== null);

        if ($workingRows->isEmpty()) {
            // A legacy placement row (an outlet without a shift) in a company without shift rosters plans the
            // standing hours, a day off on the same date notwithstanding: the attendance ledger reads it so.
            $placement = $rows->first(fn (ShiftSchedule $row): bool => ! $row->is_day_off && $row->shift === null);
            $isLegacyPlacement = $placement !== null && ! $worker->company?->policy?->uses_shift_roster;

            if (! $isLegacyPlacement && $rows->contains(fn (ShiftSchedule $row): bool => (bool) $row->is_day_off)) {
                return [];
            }

            $standing = $this->standingSession($worker, $day, $isLegacyPlacement ? $placement : null);

            if (! $standing) {
                return [];
            }

            // A split default shift has a second part with the same placement.
            return $standing->shift && $standing->segmentCount > 1
                ? [$standing, $this->shiftSession($standing->shift, $day, 2, $standing->toleranceMinutes, $standing->source, location: $standing->location, section: $standing->section, isExpected: $standing->isExpected, isPlacement: $standing->isPlacement)]
                : [$standing];
        }

        $sessions = [];
        $policyTolerance = $this->policyTolerance($worker);

        foreach ($workingRows as $row) {
            foreach (array_keys($row->shift->segments()) as $index) {
                $sessions[] = $this->shiftSession(
                    $row->shift,
                    $day,
                    $index + 1,
                    $policyTolerance,
                    'schedule',
                    sequence: (int) ($row->sequence ?: 1),
                    scheduleId: (int) $row->getKey(),
                    location: $row->location,
                    section: $row->section,
                );
            }
        }

        usort($sessions, fn (WorkSession $a, WorkSession $b): int => [$a->startAt->getTimestamp(), $a->sequence, $a->segment] <=> [$b->startAt->getTimestamp(), $b->sequence, $b->segment]);

        return $sessions;
    }

    /**
     * The hours of a worker on a date without a working schedule row, or null when there are none.
     * The one answer to both "may the worker check in on standing hours" and "is the worker expected".
     *
     * Company without shift rosters: the worker's default shift, else the company hours for employees and
     * interns; a freelancer without a default shift has none, except on a legacy placement row (the company
     * hours at that outlet, as the attendance ledger plans them). These apply on any date, and
     * {@see WorkSession::$isExpected} is false on a weekend or a holiday unless a placement row plans the date.
     *
     * Company that uses shift rosters (blank = not scheduled): none, except for a worker with a default
     * shift on a date before the week of their first dated schedule row (fixed hours), on a weekday that
     * is not a holiday.
     *
     * For a split default shift this is its first part; {@see self::sessionsFor()} adds the second.
     *
     * @param  ShiftSchedule|null  $placement  a shiftless row of the date, whose outlet and section apply
     */
    public function standingSession(Employee|Intern|Freelancer $worker, CarbonInterface $date, ?ShiftSchedule $placement = null): ?WorkSession
    {
        $day = $this->dayOf($date);
        $policy = $worker->company?->policy;
        $shift = $worker->shift_id ? $worker->shift : null;
        $isWorkday = $day->isWeekday() && ! $this->isHoliday($day, $worker->company_id ? (int) $worker->company_id : null);
        $policyTolerance = $this->policyTolerance($worker);

        if ($policy?->uses_shift_roster) {
            if (! $shift || ! $isWorkday || $this->isRosteredOn($worker, $day)) {
                return null;
            }

            return $this->shiftSession($shift, $day, 1, $policyTolerance, 'default');
        }

        // On a roster (from the date of the first roster row on) only the rostered days are expected; the
        // standing hours still let the worker check in on a blank day, as the attendance ledger counts it.
        $rosteredSince = $this->rosteredSince($worker);
        // A legacy placement row (an outlet without a shift) plans the standing hours on its date, as the
        // attendance ledger counts it, whatever the weekday.
        $isExpected = $placement !== null
            || ($isWorkday && ($rosteredSince === null || $day->toDateString() < $rosteredSince));

        if ($shift) {
            return $this->shiftSession($shift, $day, 1, $policyTolerance, 'default', location: $placement?->location, section: $placement?->section, isExpected: $isExpected, isPlacement: $placement !== null);
        }

        if ($worker instanceof Freelancer && $placement === null) {
            return null;
        }

        [$start, $end] = $this->window($day, (string) ($policy?->work_start_time ?? '08:00:00'), (string) ($policy?->work_end_time ?? '17:00:00'));

        return new WorkSession(
            date: $day,
            sequence: 1,
            segment: 1,
            segmentCount: 1,
            startAt: $start,
            endAt: $end,
            toleranceMinutes: $policyTolerance,
            scheduledMinutes: (int) $start->diffInMinutes($end),
            normalMinutes: null,
            source: 'policy',
            location: $placement?->location,
            section: $placement?->section,
            isExpected: $isExpected,
            isPlacement: $placement !== null,
        );
    }

    /**
     * The session described by working hours in the shape of {@see WorkScheduleService::resolve()} or
     * {@see WorkScheduleService::hoursForShift()}, on the business date. Null for a day off, for hours
     * with source "none" (no schedule in a company that uses shift rosters), and for a freelancer without
     * any shift (the company hours are not theirs) unless a legacy placement row plans them (is_placement).
     *
     * @param  array<string, mixed>  $workingHours
     */
    public function sessionFromHours(Employee|Intern|Freelancer $worker, array $workingHours, CarbonInterface $date): ?WorkSession
    {
        /** @var Shift|null $shift */
        $shift = $workingHours['shift'] ?? null;

        if (($workingHours['is_day_off'] ?? false) || ($workingHours['source'] ?? null) === 'none' || (! $shift && $worker instanceof Freelancer && ! ($workingHours['is_placement'] ?? false))) {
            return null;
        }

        $day = $this->dayOf($date);
        [$start, $end] = $this->window($day, (string) $workingHours['start_time'], (string) $workingHours['end_time']);
        $scheduleId = $shift && ($workingHours['schedule_id'] ?? null) ? (int) $workingHours['schedule_id'] : null;

        return new WorkSession(
            date: $day,
            sequence: (int) ($workingHours['sequence'] ?? 1),
            segment: (int) ($workingHours['segment'] ?? 1),
            segmentCount: (int) ($workingHours['segment_count'] ?? 1),
            startAt: $start,
            endAt: $end,
            toleranceMinutes: (int) ($workingHours['late_tolerance_minutes'] ?? 0),
            scheduledMinutes: $shift ? $shift->scheduledMinutes() : (int) $start->diffInMinutes($end),
            normalMinutes: $this->normalMinutesOf($shift),
            source: match (true) {
                ($workingHours['source'] ?? null) === 'cover' => 'cover',
                $scheduleId !== null => 'schedule',
                $shift !== null => 'default',
                default => 'policy',
            },
            scheduleId: $scheduleId,
            shift: $shift,
            location: $workingHours['location'] ?? null,
            section: $workingHours['section'] ?? null,
            isPlacement: (bool) ($workingHours['is_placement'] ?? false),
        );
    }

    /**
     * When the session accepts a check-in: from an hour before its start until its end (exclusive).
     *
     * @return array{opens_at: CarbonImmutable, closes_at: CarbonImmutable}
     */
    public function checkInWindow(WorkSession $session): array
    {
        return [
            'opens_at' => $session->startAt->subMinutes(self::CHECK_IN_OPENS_MINUTES_BEFORE),
            'closes_at' => $session->endAt,
        ];
    }

    /**
     * The last moment a live check-out is accepted: the latest of the scheduled end plus
     * {@see self::CHECK_OUT_GRACE_HOURS} hours, the end of the business date and, for a session ending on
     * the next calendar day, the check-in plus {@see AttendanceCheckService::OVERNIGHT_OPEN_HOURS} hours.
     * A later session of the same day never shortens it. A row without any hours closes with its date.
     */
    public function checkOutClosesAt(WorkSession|Attendance $subject): ?CarbonImmutable
    {
        if ($subject instanceof WorkSession) {
            $day = $subject->date;
            $end = $subject->endAt;
            $checkIn = null;
        } else {
            if (! $subject->date) {
                return null;
            }

            $day = $this->dayOf($subject->date);
            $end = $this->windowOf($subject)['end'] ?? null;
            $checkIn = $subject->check_in ? CarbonImmutable::instance($subject->check_in) : null;
        }

        $closesAt = $day->endOfDay()->startOfSecond();

        if (! $end) {
            return $closesAt;
        }

        $closesAt = $closesAt->max($end->addHours(self::CHECK_OUT_GRACE_HOURS));

        if ($checkIn && ! $end->isSameDay($day)) {
            $closesAt = $closesAt->max($checkIn->addHours(AttendanceCheckService::OVERNIGHT_OPEN_HOURS));
        }

        return $closesAt;
    }

    /**
     * The scheduled window an attendance row is judged against: its snapshot, else (rows without one) the
     * hours of the shift stored on the row for its segment, else the company hours for an employee or an
     * intern, else null. Never writes.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}|null
     */
    public function windowOf(Attendance $attendance): ?array
    {
        if ($attendance->hasSnapshot()) {
            return $attendance->sched_start_at && $attendance->sched_end_at
                ? ['start' => CarbonImmutable::instance($attendance->sched_start_at), 'end' => CarbonImmutable::instance($attendance->sched_end_at)]
                : null;
        }

        if (! $attendance->date) {
            return null;
        }

        $day = $this->dayOf($attendance->date);
        $shift = $attendance->shift_id
            ? ($attendance->relationLoaded('shift') ? $attendance->shift : Shift::query()->find($attendance->shift_id))
            : null;

        if ($shift) {
            $useSecond = (int) $attendance->segment === 2 && $shift->hasSecondSegment();
            [$start, $end] = $this->window($day, (string) ($useSecond ? $shift->second_start_time : $shift->start_time), (string) ($useSecond ? $shift->second_end_time : $shift->end_time));

            return ['start' => $start, 'end' => $end];
        }

        $worker = $attendance->employee ?? $attendance->intern;

        if (! $worker) {
            return null;
        }

        $policy = $worker->company?->policy;
        [$start, $end] = $this->window($day, (string) ($policy?->work_start_time ?? '08:00:00'), (string) ($policy?->work_end_time ?? '17:00:00'));

        return ['start' => $start, 'end' => $end];
    }

    /**
     * The snapshot columns an attendance row keeps of the session it was checked in to. Null (no hours
     * apply: a freelancer without any shift, or a day off worked without a covered shift) gives the
     * source "none" with empty hours.
     *
     * @param  string|null  $source  overrides the session's own source (an off-schedule check-in is always "cover")
     * @param  string|null  $outletName  overrides the session's outlet (the outlet stored on the row)
     * @return array{shift_schedule_id: int|null, schedule_sequence: int, sched_start_at: CarbonImmutable|null, sched_end_at: CarbonImmutable|null, sched_tolerance_minutes: int|null, sched_scheduled_minutes: int|null, sched_normal_minutes: int|null, sched_shift_name: string|null, sched_shift_code: string|null, sched_outlet_name: string|null, sched_section: string|null, sched_source: string}
     */
    public function snapshotFor(?WorkSession $session, ?string $source = null, ?string $outletName = null): array
    {
        $shift = $session?->shift;
        $outletName ??= $session?->location?->name;

        return [
            'shift_schedule_id' => $session?->scheduleId,
            'schedule_sequence' => $session?->sequence ?? 1,
            'sched_start_at' => $session?->startAt,
            'sched_end_at' => $session?->endAt,
            'sched_tolerance_minutes' => $session ? $this->smallInteger($session->toleranceMinutes) : null,
            'sched_scheduled_minutes' => $session ? $this->smallInteger($session->scheduledMinutes) : null,
            'sched_normal_minutes' => $session?->normalMinutes === null ? null : $this->smallInteger($session->normalMinutes),
            'sched_shift_name' => $shift ? mb_substr((string) $shift->name, 0, 100) : null,
            'sched_shift_code' => $shift && filled($shift->code) ? mb_substr((string) $shift->code, 0, 30) : null,
            'sched_outlet_name' => filled($outletName) ? mb_substr((string) $outletName, 0, 150) : null,
            'sched_section' => filled($session?->section) ? mb_substr((string) $session->section, 0, 100) : null,
            'sched_source' => $source ?? $session?->source ?? 'none',
        ];
    }

    private function shiftSession(
        Shift $shift,
        CarbonImmutable $day,
        int $segment,
        int $policyTolerance,
        string $source,
        int $sequence = 1,
        ?int $scheduleId = null,
        ?CompanyLocation $location = null,
        ?string $section = null,
        bool $isExpected = true,
        bool $isPlacement = false,
    ): WorkSession {
        $segment = $segment === 2 && $shift->hasSecondSegment() ? 2 : 1;
        [$start, $end] = $this->window(
            $day,
            (string) ($segment === 2 ? $shift->second_start_time : $shift->start_time),
            (string) ($segment === 2 ? $shift->second_end_time : $shift->end_time),
        );

        return new WorkSession(
            date: $day,
            sequence: $sequence,
            segment: $segment,
            segmentCount: $shift->hasSecondSegment() ? 2 : 1,
            startAt: $start,
            endAt: $end,
            toleranceMinutes: (int) ($shift->late_tolerance_minutes ?? $policyTolerance),
            scheduledMinutes: $shift->scheduledMinutes(),
            normalMinutes: $this->normalMinutesOf($shift),
            source: $source,
            scheduleId: $scheduleId,
            shift: $shift,
            location: $location,
            section: $section,
            isExpected: $isExpected,
            isPlacement: $isPlacement,
        );
    }

    /**
     * Absolute start and end of working hours on a business date; an end at or before the start is on the next day.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(CarbonImmutable $day, string $startTime, string $endTime): array
    {
        $start = $day->setTimeFromTimeString($startTime);
        $end = $day->setTimeFromTimeString($endTime);

        if (substr($endTime, 0, 5) <= substr($startTime, 0, 5)) {
            $end = $end->addDay();
        }

        return [$start, $end];
    }

    /**
     * The shift's normal minutes as frozen on attendance: null when the shift has none set (no automatic overtime).
     */
    private function normalMinutesOf(?Shift $shift): ?int
    {
        return $shift === null || $shift->normal_minutes === null ? null : $shift->normalMinutes();
    }

    private function policyTolerance(Employee|Intern|Freelancer $worker): int
    {
        return (int) ($worker->company?->policy?->late_tolerance_minutes ?? 15);
    }

    /**
     * Whether the worker is on a roster on the date: from the date of their first roster row on (any row, a
     * day off or a placement included, but not an approved cover's own row: the attendance ledger reads the
     * roster the same way). Dates before it keep their fixed hours, so a later row never restates them.
     */
    private function isRosteredOn(Employee|Intern|Freelancer $worker, CarbonImmutable $day): bool
    {
        $since = $this->rosteredSince($worker);

        return $since !== null && $day->toDateString() >= $since;
    }

    /**
     * Read the first schedule row of many workers of one kind at once ({@see self::rosteredSince()}), so a
     * caller going through a whole workforce does not ask once per worker.
     *
     * @param  'employee_id'|'intern_id'|'freelancer_id'  $column
     * @param  array<int, int|string>  $workerIds
     */
    public function primeRosteredSince(string $column, array $workerIds): void
    {
        $firsts = [];

        foreach (array_chunk(array_values($workerIds), 1000) as $chunk) {
            $firsts += ShiftSchedule::whereRosterRow(ShiftSchedule::query())
                ->whereIn($column, $chunk)
                ->groupBy($column)
                ->selectRaw("{$column} as worker_id, min(date) as first_date")
                ->pluck('first_date', 'worker_id')
                ->all();
        }

        foreach ($workerIds as $workerId) {
            $first = $firsts[$workerId] ?? null;
            $this->rosteredSince[$column.':'.$workerId] = $first ? substr((string) $first, 0, 10) : null;
        }
    }

    /**
     * Forget the remembered first schedule row of one worker: a row of theirs was written or removed.
     *
     * @param  'employee_id'|'intern_id'|'freelancer_id'  $column
     */
    public function forgetRosteredSince(string $column, int $workerId): void
    {
        unset($this->rosteredSince[$column.':'.$workerId]);
    }

    /**
     * The date (Y-m-d) of the worker's first roster row ({@see ShiftSchedule::whereRosterRow()}: an approved
     * cover's own row does not count), or null when they never had one. Dates before it keep their fixed
     * hours, the earlier weekdays of the same week included, so a later or a cover row never restates a
     * reported day. Read once per worker by this resolver.
     */
    private function rosteredSince(Employee|Intern|Freelancer $worker): ?string
    {
        $key = $this->workerColumn($worker).':'.$worker->getKey();

        if (! array_key_exists($key, $this->rosteredSince)) {
            $first = ShiftSchedule::whereRosterRow(ShiftSchedule::query())->where($this->workerColumn($worker), $worker->getKey())->min('date');
            $this->rosteredSince[$key] = $first ? substr((string) $first, 0, 10) : null;
        }

        return $this->rosteredSince[$key];
    }

    private function isHoliday(CarbonImmutable $day, ?int $companyId): bool
    {
        $date = $day->toDateString();

        return $this->holidays[$date.'|'.$companyId] ??= ExpectedWorkDays::between($day, $day)->isHoliday($date, $companyId);
    }

    private function dayOf(CarbonInterface $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date->toDateString());
    }

    private function smallInteger(int $value): int
    {
        return max(0, min(self::MAX_SMALL_INTEGER, $value));
    }

    private function workerColumn(Employee|Intern|Freelancer $worker): string
    {
        return match (true) {
            $worker instanceof Employee => 'employee_id',
            $worker instanceof Intern => 'intern_id',
            default => 'freelancer_id',
        };
    }
}
