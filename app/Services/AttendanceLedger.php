<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\CompanyLocation;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\OvertimeRequest;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Services\Attendance\LedgerQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * The one engine behind every attendance figure: what each worker was planned for and what they did,
 * one line per session per business date, rolled up per worker-day, per worker and outlet, and in total.
 *
 * Read-only, and built from plain rows per pass of workers, so a month of a large Klien never holds models.
 *
 * - A schedule is one roster row; a session is one part of it (two for a split shift). A day without a
 *   working row has the worker's standing hours (default shift, else the company hours) when the company
 *   does not use shift rosters; in a company that does, only a fixed-hours worker has them.
 * - Scheduled days and hours ("Hari Terjadwal") are the days on the roster. Standing hours are expected
 *   (their no-show is an absence) but not rostered.
 * - A session is worked when its attendance row has a check-in and is not rejected; a pending one counts
 *   as worked and as pending. A rejected row contributes nothing.
 * - Each worker-day is exactly one of present (any session worked), leave (an approved leave charges the
 *   date and nothing was worked), off (a day-off row and nothing worked), absent (expected, up to
 *   yesterday, nothing worked), upcoming (expected, today or later) or not scheduled.
 * - Hours are computed per schedule ({@see AttendanceFigures}) and added up.
 * - A line belongs to the outlet it was worked at, else the outlet it was planned at, else the worker's
 *   home outlet of that calendar month (the outlet with most planned sessions in the month).
 * - A line is shown when its worker is in the viewer's data access or its outlet is; summaries and rows
 *   use the same rule, and every day count is in distinct worker-days.
 */
final class AttendanceLedger
{
    /** Workers handled per pass; each pass reads its schedules and attendance in bulk. */
    private const WORKERS_PER_PASS = 1000;

    /** Rows read per query. */
    private const CHUNK_SIZE = 2000;

    /** @var array<string, array{model: class-string<Model>, table: string, number: string, from: string, until: string|null}> */
    private const WORKER_TYPES = [
        'employee' => ['model' => Employee::class, 'table' => 'employees', 'number' => 'nip', 'from' => 'join_date', 'until' => null],
        'intern' => ['model' => Intern::class, 'table' => 'interns', 'number' => 'nis', 'from' => 'start_date', 'until' => 'end_date'],
        'freelancer' => ['model' => Freelancer::class, 'table' => 'freelancers', 'number' => 'freelancer_number', 'from' => 'start_date', 'until' => 'end_date'],
    ];

    /** Worker statuses that still expect attendance; an older resignation of such an employee is history. */
    private const WORKING_STATUSES = ['active', 'extended'];

    /** Counters of a set of lines; the *_days ones are 0 or 1 for one worker-day. */
    private const TALLY = [
        'scheduled_days' => 0, 'present_days' => 0, 'pending_days' => 0, 'late_days' => 0, 'late_minutes' => 0,
        'early_leave_days' => 0, 'early_leave_minutes' => 0, 'absent_days' => 0, 'off_days' => 0, 'leave_days' => 0,
        'missing_check_out_days' => 0, 'incomplete_days' => 0, 'scheduled_days_to_date' => 0,
        'sessions_scheduled' => 0, 'sessions_worked' => 0, 'sessions_missed' => 0,
        'scheduled_minutes' => 0, 'scheduled_minutes_to_date' => 0, 'normal_minutes' => 0, 'worked_minutes' => 0,
        'auto_overtime_minutes' => 0, 'request_overtime_minutes' => 0, 'overtime_minutes' => 0,
        'overtime_count' => 0, 'overtime_unattended_count' => 0,
    ];

    /**
     * Every visible line: one per planned or worked session, plus a line for a day off, for a leave day
     * without a session and for work outside any schedule.
     *
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function lines(LedgerQuery $query): LazyCollection
    {
        return LazyCollection::make(function () use ($query) {
            foreach ($this->workerDays($query) as $day) {
                foreach ($day['lines'] as $line) {
                    yield $line;
                }
            }
        });
    }

    /**
     * Worker-day roll-ups with their visible lines.
     *
     * @return LazyCollection<int, array{worker: array<string, mixed>, date: string, expected: bool, status: string, is_late: bool, is_pending: bool, lines: list<array<string, mixed>>, totals: array<string, int>, request_overtime_minutes: int, overtime_unattended: bool}>
     */
    public function days(LedgerQuery $query): LazyCollection
    {
        return LazyCollection::make(function () use ($query) {
            foreach ($this->workerDays($query) as $day) {
                unset($day['cells'], $day['cell_outlets']);

                yield $day;
            }
        });
    }

    /**
     * Totals of the range in distinct worker-days and summed minutes.
     *
     * @return array<string, int>
     */
    public function summary(LedgerQuery $query): array
    {
        $summary = self::TALLY;

        foreach ($this->workerDays($query, forTotalsOnly: true) as $day) {
            foreach ($day['totals'] as $key => $value) {
                $summary[$key] += $value;
            }
        }

        return $summary;
    }

    /**
     * One row per worker per outlet, and a grand total in distinct worker-days: a day at two outlets
     * counts once in each outlet row and once in the total.
     *
     * @return array{rows: list<array<string, mixed>>, total: array<string, int>}
     */
    public function outletRows(LedgerQuery $query): array
    {
        $rows = [];
        $total = self::TALLY;

        foreach ($this->workerDays($query) as $day) {
            foreach ($day['cells'] as $outletKey => $cell) {
                $rowKey = $day['worker']['type'].':'.$day['worker']['id'].'|'.$outletKey;
                $rows[$rowKey] ??= ['worker' => $day['worker'], 'outlet' => $day['cell_outlets'][$outletKey]] + self::TALLY;

                foreach ($cell as $key => $value) {
                    $rows[$rowKey][$key] += $value;
                }
            }

            foreach ($day['totals'] as $key => $value) {
                $total[$key] += $value;
            }
        }

        usort($rows, fn (array $a, array $b): int => [$a['outlet']['name'] ?? "\u{FFFF}", $a['worker']['name'], $a['worker']['id']] <=> [$b['outlet']['name'] ?? "\u{FFFF}", $b['worker']['name'], $b['worker']['id']]);

        return ['rows' => $rows, 'total' => $total];
    }

    // ── Worker-days ──────────────────────────────────────────────────────────

    /**
     * @param  bool  $forTotalsOnly  the caller reads the totals only, not the outlet of a line
     * @return \Generator<int, array<string, mixed>>
     */
    private function workerDays(LedgerQuery $query, bool $forTotalsOnly = false): \Generator
    {
        $workers = $this->workers($query);

        if ($workers === []) {
            return;
        }

        $from = $query->from->toDateString();
        $to = $query->to->toDateString();
        $dates = [];

        for ($cursor = $query->from; $cursor->lte($query->to); $cursor = $cursor->addDay()) {
            $dates[] = $cursor->toDateString();
        }

        $run = [
            'query' => $query,
            'from' => $from,
            'to' => $to,
            'now' => now()->getTimestamp(),
            'last_countable' => ExpectedWorkDays::lastCountableDay()->toDateString(),
            'today' => today()->toDateString(),
            'calendar' => ExpectedWorkDays::between($query->from->subDay(), $query->to->addDay()),
            'policies' => $this->policies(array_column($workers, 'company_id')),
            'unbounded' => $query->workerKeys !== null || ($query->viewer !== null && DataAccessService::workerFilter($query->viewer) === null),
        ];
        // The home outlet of a month needs the roster of the whole month. Totals for someone who sees
        // every outlet do not depend on it, so they read the dates asked for only.
        $run['whole_months'] = ! ($forTotalsOnly && $run['unbounded'] && $query->outletId === null);
        /** @var array<int, array<string, mixed>|null> $shifts */
        $shifts = [];
        /** @var array<int, array{id: int, name: string, in_scope: bool}|null> $outlets */
        $outlets = [];

        foreach (array_chunk($workers, self::WORKERS_PER_PASS, true) as $pass) {
            $data = $this->load($pass, $run, $shifts, $outlets);

            foreach ($pass as $key => $worker) {
                $days = [];

                foreach ($dates as $date) {
                    if ($day = $this->buildDay($worker, $date, $data, $run, $shifts, $outlets)) {
                        $days[$date] = $day;
                    }
                }

                foreach ($data['overtime'][$key] ?? [] as $date => $requests) {
                    $days[$date] = $this->withOvertime($days[$date] ?? null, $worker, $date, $requests, $days, $data, $run, $shifts, $outlets);
                }

                ksort($days);

                foreach ($days as $day) {
                    if ($visible = $this->visibleDay($day, $worker, $run, $outlets)) {
                        yield $visible;
                    }
                }
            }
        }
    }

    /**
     * The plan and the attendance of one worker on one business date, or null when there is nothing to
     * report (not scheduled, nothing recorded).
     *
     * @param  array<string, mixed>  $worker
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $run
     * @param  array<int, array<string, mixed>|null>  $shifts
     * @param  array<int, array{id: int, name: string, in_scope: bool}|null>  $outlets
     * @return array<string, mixed>|null
     */
    private function buildDay(array $worker, string $date, array &$data, array $run, array $shifts, array $outlets): ?array
    {
        $key = $worker['key'];
        $rows = $data['schedules'][$key][$date] ?? [];
        $attendances = $data['attendances'][$key][$date] ?? [];
        $onLeave = isset($data['leave'][$key][$date]);
        $policy = $run['policies'][$worker['company_id']] ?? self::defaultPolicy();
        $usesRoster = $policy['uses_shift_roster'];
        $inEmployment = ($worker['employed_from'] === null || $date >= $worker['employed_from'])
            && ($worker['employed_until'] === null || $date <= $worker['employed_until']);

        $sessions = [];
        $hasDayOffRow = false;
        $placement = null;

        foreach ($rows as $row) {
            if ($row['is_day_off']) {
                $hasDayOffRow = true;

                continue;
            }

            $shift = $row['shift_id'] !== null ? ($shifts[$row['shift_id']] ?? null) : null;

            if (! $shift) {
                // Neither a day off nor a shift: no row in a roster company, the standing hours at this outlet elsewhere.
                $placement ??= $row;

                continue;
            }

            foreach ($shift['segments'] as $index => [$startTime, $endTime]) {
                [$start, $end] = AttendanceFigures::window($date, $startTime, $endTime);
                $sessions[] = ['schedule_id' => $row['id'], 'sequence' => $row['sequence'], 'segment' => $index + 1, 'start' => $start, 'end' => $end, 'shift' => $shift, 'location_id' => $row['location_id'], 'section' => $row['section'], 'source' => 'schedule', 'planned' => true, 'expected' => $inEmployment];
            }
        }

        $legacyPlacement = $placement !== null && ! $usesRoster;
        $isDayOff = $sessions === [] && $hasDayOffRow && ! $legacyPlacement;
        $expected = $sessions !== [] && $inEmployment;

        if ($sessions === [] && ! $isDayOff) {
            $defaultShift = $worker['default_shift_id'] !== null ? ($shifts[$worker['default_shift_id']] ?? null) : null;
            $standingExpected = $legacyPlacement
                ? $inEmployment
                : $worker['counts_standing'] && $run['calendar']->isExpected(
                    $date,
                    null,
                    $worker['company_id'],
                    $worker['employed_from'],
                    $worker['employed_until'],
                    isset($data['rostered_since'][$key]) && $date >= $data['rostered_since'][$key],
                    $usesRoster,
                    // Fixed hours hold for the dates before the week of the first roster row, never restated after it.
                    $defaultShift !== null && (! isset($data['rostered_since'][$key]) || $date < $data['rostered_since'][$key]),
                );
            $hasStanding = $usesRoster ? $standingExpected : true;
            $expected = $standingExpected;

            if ($hasStanding && ($standingExpected || $attendances !== [])) {
                $segments = $defaultShift ? $defaultShift['segments'] : [[$policy['work_start_time'], $policy['work_end_time']]];

                foreach ($segments as $index => [$startTime, $endTime]) {
                    [$start, $end] = AttendanceFigures::window($date, $startTime, $endTime);
                    $sessions[] = ['schedule_id' => null, 'sequence' => 1, 'segment' => $index + 1, 'start' => $start, 'end' => $end, 'shift' => $defaultShift, 'location_id' => $legacyPlacement ? $placement['location_id'] : null, 'section' => $legacyPlacement ? $placement['section'] : null, 'source' => $defaultShift ? 'default' : 'policy', 'planned' => $legacyPlacement, 'expected' => $standingExpected];
                }
            }
        }

        if ($sessions === [] && $attendances === [] && ! $onLeave && ! $isDayOff) {
            return null;
        }

        // Attendance joins its session by the roster row it is linked to, else by (sequence, segment).
        $bySchedule = [];
        $bySequence = [];

        foreach ($sessions as $index => $session) {
            if ($session['schedule_id'] !== null) {
                $bySchedule[$session['schedule_id'].':'.$session['segment']] = $index;
            }

            $bySequence[$session['sequence'].':'.$session['segment']] ??= $index;
        }

        $matched = [];
        $unmatched = [];

        foreach ($attendances as $attendance) {
            if ($attendance['check_in'] === null && ! $attendance['worked']) {
                continue;
            }

            $index = ($attendance['schedule_id'] !== null ? ($bySchedule[$attendance['schedule_id'].':'.$attendance['segment']] ?? null) : null)
                ?? $bySequence[$attendance['sequence'].':'.$attendance['segment']] ?? null;

            if ($index !== null && ! isset($matched[$index])) {
                $matched[$index] = $attendance;
            } else {
                $unmatched[$attendance['sequence']][] = $attendance;
            }
        }

        $home = function () use ($worker, $date, &$data, $shifts, $outlets): ?int {
            return $this->homeOutlet($worker, substr($date, 0, 7), $data, $shifts, $outlets);
        };
        $lines = [];

        // Planned schedules, each with its parts.
        $groups = [];

        foreach ($sessions as $index => $session) {
            $groups[$session['sequence'].'|'.($session['schedule_id'] ?? '')][] = $index;
        }

        foreach ($groups as $indexes) {
            $worked = array_values(array_filter(array_map(fn (int $index): ?array => $matched[$index] ?? null, $indexes), fn (?array $attendance): bool => $attendance !== null && $attendance['worked']));

            if (! $sessions[$indexes[0]]['expected'] && ! $sessions[$indexes[0]]['planned'] && $worked === [] && array_filter($indexes, fn (int $index): bool => isset($matched[$index])) === []) {
                continue;
            }

            $snapshot = collect($worked)->firstWhere('has_snapshot', true);
            $parts = [];

            foreach ($indexes as $index) {
                $session = $sessions[$index];
                $attendance = $matched[$index] ?? null;
                $window = $attendance && $attendance['has_snapshot'] ? [$attendance['start'], $attendance['end']] : [$session['start'], $session['end']];
                $parts[$index] = $this->part($date, $session['segment'], $window, $attendance);
            }

            $figures = AttendanceFigures::forSchedule(
                $parts,
                $run['now'],
                $snapshot['sched_scheduled_minutes'] ?? null,
                $worked !== [] ? ($snapshot['sched_normal_minutes'] ?? null) : $sessions[$indexes[0]]['shift']['normal_minutes'] ?? null,
            );

            foreach ($indexes as $index) {
                $session = $sessions[$index];
                $attendance = $matched[$index] ?? null;
                $lines[] = $this->line($worker, $date, $session['planned'] || $session['expected'] ? 'work' : 'unscheduled', $session, $attendance, $parts[$index], $figures, $index, $outlets, $home);
            }
        }

        // Work outside any planned session (a day off worked, a cover without a roster row).
        foreach ($unmatched as $sequence => $group) {
            $parts = [];

            foreach ($group as $index => $attendance) {
                $shift = $attendance['shift_id'] !== null ? ($shifts[$attendance['shift_id']] ?? null) : null;
                $window = $attendance['has_snapshot']
                    ? [$attendance['start'], $attendance['end']]
                    : (AttendanceFigures::fallbackWindow($date, $attendance['segment'], $shift['hours'] ?? null, $shift === null && $worker['type'] !== 'freelancer' ? ['start' => $policy['work_start_time'], 'end' => $policy['work_end_time']] : null) ?? [null, null]);
                $parts[$index] = $this->part($date, $attendance['segment'], $window, $attendance);
            }

            $snapshot = collect($group)->first(fn (array $attendance): bool => $attendance['worked'] && $attendance['has_snapshot']);
            $figures = AttendanceFigures::forSchedule($parts, $run['now'], $snapshot['sched_scheduled_minutes'] ?? null, $snapshot['sched_normal_minutes'] ?? null);

            foreach ($group as $index => $attendance) {
                $shift = $attendance['shift_id'] !== null ? ($shifts[$attendance['shift_id']] ?? null) : null;
                $session = ['schedule_id' => $attendance['schedule_id'], 'sequence' => (int) $sequence, 'segment' => $attendance['segment'], 'shift' => $shift, 'location_id' => null, 'section' => null, 'source' => $attendance['source'] ?? 'none', 'planned' => false, 'expected' => false];
                $lines[] = $this->line($worker, $date, 'unscheduled', $session, $attendance, $parts[$index], $figures, $index, $outlets, $home);
            }
        }

        $isWorked = false;
        $isPending = false;
        $isLate = false;

        foreach ($lines as $line) {
            $isWorked = $isWorked || $line['is_worked'];
            $isPending = $isPending || $line['is_pending'];
            $isLate = $isLate || $line['is_late'];
        }

        $status = match (true) {
            $isWorked => 'present',
            $onLeave => 'leave',
            $isDayOff => 'off',
            $expected => $date <= $run['last_countable'] ? 'absent' : 'upcoming',
            default => 'not_scheduled',
        };

        foreach ($lines as $index => $line) {
            if ($line['status'] !== null) {
                continue;
            }

            $lines[$index]['status'] = match (true) {
                $status === 'leave' => 'leave',
                $date > $run['last_countable'] => 'upcoming',
                $line['is_expected'] => 'absent',
                default => 'off',
            };
        }

        if (in_array($status, ['leave', 'off'], true) && ! array_filter($lines, fn (array $line): bool => $line['status'] === $status)) {
            $lines[] = $this->markerLine($worker, $date, $status, $home(), $outlets);
        }

        if ($lines === []) {
            return null;
        }

        return ['worker' => $worker, 'date' => $date, 'expected' => $expected, 'status' => $status, 'is_late' => $isLate, 'is_pending' => $isPending, 'lines' => $lines, 'overtime' => []];
    }

    /**
     * @param  array{0: int|null, 1: int|null}  $window
     * @param  array<string, mixed>|null  $attendance
     * @return array<string, mixed>
     */
    private function part(string $date, int $segment, array $window, ?array $attendance): array
    {
        return [
            'segment' => $segment,
            'date' => $date,
            'start' => $window[0],
            'end' => $window[1],
            'check_in' => $attendance['check_in'] ?? null,
            'check_out' => $attendance['check_out'] ?? null,
            'worked' => $attendance['worked'] ?? false,
            'late_minutes' => $attendance['late'] ?? 0,
            'early_leave_minutes' => $attendance['early'] ?? 0,
            'has_later_check_in' => $attendance['has_later_check_in'] ?? false,
        ];
    }

    /**
     * @param  array<string, mixed>  $worker
     * @param  array<string, mixed>  $session
     * @param  array<string, mixed>|null  $attendance
     * @param  array<string, mixed>  $part
     * @param  array<string, mixed>  $figures
     * @param  array<int, array{id: int, name: string, in_scope: bool}|null>  $outlets
     * @param  \Closure(): (int|null)  $home
     * @return array<string, mixed>
     */
    private function line(array $worker, string $date, string $kind, array $session, ?array $attendance, array $part, array $figures, int|string $partKey, array $outlets, \Closure $home): array
    {
        $partFigures = $figures['parts'][$partKey];
        $worked = (bool) ($attendance['worked'] ?? false);
        $outletId = collect([$attendance['location_id'] ?? null, $session['location_id'] ?? null])
            ->first(fn (?int $id): bool => $id !== null && isset($outlets[$id])) ?? $home();
        $shift = $session['shift'];

        if ($attendance && filled($attendance['sched_shift_name'])) {
            $shiftLabel = ['id' => $attendance['shift_id'], 'name' => $attendance['sched_shift_name'], 'code' => $attendance['sched_shift_code']];
        } else {
            $shiftLabel = $shift ? ['id' => $shift['id'], 'name' => $shift['name'], 'code' => $shift['code']] : null;
        }

        return [
            'worker' => self::publicWorker($worker),
            'date' => $date,
            'kind' => $kind,
            'status' => match (true) {
                $attendance === null => null,
                $attendance['status'] === AttendanceStatus::Rejected->value => 'rejected',
                ! $worked => null,
                $attendance['pending'] => 'pending',
                default => 'present',
            },
            'schedule_id' => $session['schedule_id'],
            'sequence' => $session['sequence'],
            'segment' => $session['segment'],
            'shift' => $shiftLabel,
            'outlet' => $outletId !== null ? ['id' => $outletId, 'name' => $outlets[$outletId]['name']] : null,
            'section' => $attendance['sched_section'] ?? $session['section'],
            'source' => $attendance['source'] ?? $session['source'],
            'scheduled_start' => $part['start'] !== null ? date('Y-m-d H:i:s', $part['start']) : null,
            'scheduled_end' => $part['end'] !== null ? date('Y-m-d H:i:s', $part['end']) : null,
            'attendance_id' => $attendance['id'] ?? null,
            'check_in' => $attendance['check_in_at'] ?? null,
            'check_out' => $attendance['check_out_at'] ?? null,
            'scheduled_minutes' => $partFigures['scheduled_minutes'],
            'normal_minutes' => $partFigures['normal_minutes'],
            'worked_minutes' => $partFigures['worked_minutes'],
            'auto_overtime_minutes' => $partFigures['auto_overtime_minutes'],
            'late_minutes' => $worked ? $attendance['late'] : 0,
            'early_leave_minutes' => $worked ? $attendance['early'] : 0,
            'is_missing_check_out' => $partFigures['is_missing_check_out'],
            'is_incomplete' => $worked && $figures['is_incomplete'],
            'is_off_schedule' => (bool) ($attendance['is_off_schedule'] ?? false),
            'is_corrected' => (bool) ($attendance['is_corrected'] ?? false),
            'is_locked' => $session['schedule_id'] !== null && $attendance !== null && $attendance['check_in'] !== null && $attendance['schedule_id'] === $session['schedule_id'],
            'is_planned' => (bool) $session['planned'],
            'is_expected' => (bool) $session['expected'],
            'is_worked' => $worked,
            'is_pending' => $worked && $attendance['pending'],
            'is_late' => $worked && ($attendance['late'] > 0 || $attendance['status'] === AttendanceStatus::Late->value),
        ];
    }

    /**
     * The line of a day off, or of a leave day without a planned session, on the worker's home outlet.
     *
     * @param  array<string, mixed>  $worker
     * @param  array<int, array{id: int, name: string, in_scope: bool}|null>  $outlets
     * @return array<string, mixed>
     */
    private function markerLine(array $worker, string $date, string $status, ?int $outletId, array $outlets): array
    {
        return [
            'worker' => self::publicWorker($worker),
            'date' => $date,
            'kind' => $status,
            'status' => $status,
            'schedule_id' => null,
            'sequence' => 1,
            'segment' => 1,
            'shift' => null,
            'outlet' => $outletId !== null ? ['id' => $outletId, 'name' => $outlets[$outletId]['name']] : null,
            'section' => null,
            'source' => 'none',
            'scheduled_start' => null,
            'scheduled_end' => null,
            'attendance_id' => null,
            'check_in' => null,
            'check_out' => null,
            'scheduled_minutes' => 0,
            'normal_minutes' => 0,
            'worked_minutes' => 0,
            'auto_overtime_minutes' => 0,
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
            'is_missing_check_out' => false,
            'is_incomplete' => false,
            'is_off_schedule' => false,
            'is_corrected' => false,
            'is_locked' => false,
            'is_planned' => false,
            'is_expected' => false,
            'is_worked' => false,
            'is_pending' => false,
            'is_late' => false,
        ];
    }

    /**
     * Add the approved overtime requests of a date to its day. A request counts in full, or (from the
     * company's netting date on) without the part that lies inside a scheduled window of the worker on
     * that date or the dates next to it. It is booked to the outlet the worker worked at that day, else
     * the outlet planned, else the home outlet.
     *
     * @param  array<string, mixed>|null  $day
     * @param  array<string, mixed>  $worker
     * @param  list<array{minutes: int, range: array{0: int, 1: int}|null}>  $requests
     * @param  array<string, array<string, mixed>>  $days
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $run
     * @param  array<int, array<string, mixed>|null>  $shifts
     * @param  array<int, array{id: int, name: string, in_scope: bool}|null>  $outlets
     * @return array<string, mixed>
     */
    private function withOvertime(?array $day, array $worker, string $date, array $requests, array $days, array &$data, array $run, array $shifts, array $outlets): array
    {
        $day ??= ['worker' => $worker, 'date' => $date, 'expected' => false, 'status' => 'not_scheduled', 'is_late' => false, 'is_pending' => false, 'lines' => [], 'overtime' => []];
        $nettingFrom = ($run['policies'][$worker['company_id']] ?? self::defaultPolicy())['overtime_netting_from'];
        $windows = [];

        if ($nettingFrom !== null && $date >= $nettingFrom) {
            foreach ([date('Y-m-d', strtotime("{$date} -1 day")), $date, date('Y-m-d', strtotime("{$date} +1 day"))] as $near) {
                $nearDay = $near === $date ? $day : ($days[$near] ?? $this->buildDay($worker, $near, $data, $run, $shifts, $outlets));
                $windows = [...$windows, ...self::sessionWindows($nearDay['lines'] ?? [])];
            }
        }

        $outletId = null;
        $latest = null;

        foreach ($day['lines'] as $line) {
            if ($line['is_worked'] && ($latest === null || (string) $line['check_in'] >= $latest)) {
                $latest = (string) $line['check_in'];
                $outletId = $line['outlet']['id'] ?? null;
            }
        }

        if ($latest === null) {
            $planned = collect($day['lines'])->first(fn (array $line): bool => $line['is_planned'] || $line['is_expected']);
            $outletId = $planned
                ? ($planned['outlet']['id'] ?? null)
                : $this->homeOutlet($worker, substr($date, 0, 7), $data, $shifts, $outlets);
        }

        $minutes = 0;

        foreach ($requests as $request) {
            $minutes += $windows === [] ? $request['minutes'] : AttendanceFigures::netOvertimeMinutes($request['minutes'], $request['range'], $windows);
        }

        $day['overtime'] = ['count' => count($requests), 'minutes' => $minutes, 'unattended' => $day['status'] !== 'present', 'outlet_id' => $outletId];

        return $day;
    }

    /**
     * The scheduled windows among the lines of a day: every session planned, expected or worked.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{0: int, 1: int}>
     */
    private static function sessionWindows(array $lines): array
    {
        $windows = [];

        foreach ($lines as $line) {
            if ($line['scheduled_start'] !== null && ($line['is_planned'] || $line['is_expected'] || $line['is_worked'])) {
                $windows[] = [(int) strtotime($line['scheduled_start']), (int) strtotime($line['scheduled_end'])];
            }
        }

        return $windows;
    }

    /**
     * Minutes and moments of an overtime request row: the actual duration once filled in (from the start
     * time on), else the requested hours.
     *
     * @return array{minutes: int, range: array{0: int, 1: int}|null}
     */
    private static function overtimeEntry(string $date, ?string $startTime, ?string $endTime, ?int $durationMinutes, ?int $actualMinutes): array
    {
        $minutes = (int) ($actualMinutes ?? $durationMinutes);
        $range = null;

        if (filled($startTime)) {
            $start = (int) strtotime($date.' '.substr((string) $startTime, 0, 5).':00');
            $range = $actualMinutes === null && filled($endTime)
                ? AttendanceFigures::window($date, (string) $startTime, (string) $endTime)
                : [$start, $start + $minutes * 60];
        }

        return ['minutes' => $minutes, 'range' => $range];
    }

    /**
     * What each overtime request counts for in the reports, by request id: its minutes without the part
     * inside a scheduled window of the worker on that date or the dates next to it. Null for a request the
     * company does not net (no netting date, or a request dated before it): that one counts in full.
     *
     * The same windows and the same arithmetic as the request overtime of a day ({@see self::withOvertime()}).
     *
     * @param  iterable<OvertimeRequest>  $requests
     * @return array<int, int|null>
     */
    public function netOvertimeMinutes(iterable $requests): array
    {
        $net = [];
        /** @var array<string, list<array{id: int, date: string, minutes: int, range: array{0: int, 1: int}|null}>> $byWorker */
        $byWorker = [];

        foreach ($requests as $request) {
            $net[(int) $request->getKey()] = null;

            foreach (array_keys(self::WORKER_TYPES) as $type) {
                if ($request->{"{$type}_id"} !== null && $request->date !== null) {
                    $date = $request->date->toDateString();
                    $byWorker[$type.':'.(int) $request->{"{$type}_id"}][] = ['id' => (int) $request->getKey(), 'date' => $date]
                        + self::overtimeEntry($date, $request->start_time, $request->end_time, $request->duration_minutes, $request->actual_duration_minutes);

                    break;
                }
            }
        }

        if ($byWorker === []) {
            return $net;
        }

        // Only the companies that net requests need their schedules read.
        $companyOf = [];

        foreach (self::WORKER_TYPES as $type => $definition) {
            $ids = [];

            foreach (array_keys($byWorker) as $key) {
                if (str_starts_with($key, $type.':')) {
                    $ids[] = (int) substr($key, strlen($type) + 1);
                }
            }

            foreach (array_chunk($ids, self::WORKERS_PER_PASS) as $chunk) {
                foreach (DB::table($definition['table'])->whereIn('id', $chunk)->get(['id', 'company_id']) as $worker) {
                    $companyOf[$type.':'.(int) $worker->id] = $worker->company_id !== null ? (int) $worker->company_id : null;
                }
            }
        }

        $policies = $this->policies(array_values($companyOf));
        $netted = [];
        $dates = [];

        foreach ($byWorker as $key => $entries) {
            $nettingFrom = ($policies[$companyOf[$key] ?? 0] ?? self::defaultPolicy())['overtime_netting_from'];

            foreach ($entries as $entry) {
                if ($nettingFrom !== null && $entry['date'] >= $nettingFrom) {
                    $netted[$key][] = $entry;
                    $dates[] = $entry['date'];
                }
            }
        }

        if ($netted === []) {
            return $net;
        }

        $windows = [];
        $query = LedgerQuery::forWorkers(array_keys($netted), date('Y-m-d', strtotime(min($dates).' -1 day')), date('Y-m-d', strtotime(max($dates).' +1 day')));

        foreach ($this->workerDays($query) as $day) {
            $windows[$day['worker']['type'].':'.$day['worker']['id']][$day['date']] = self::sessionWindows($day['lines']);
        }

        foreach ($netted as $key => $entries) {
            foreach ($entries as $entry) {
                $near = [];

                foreach ([-1, 0, 1] as $offset) {
                    $near = [...$near, ...($windows[$key][date('Y-m-d', strtotime("{$entry['date']} {$offset} day"))] ?? [])];
                }

                $net[$entry['id']] = AttendanceFigures::netOvertimeMinutes($entry['minutes'], $entry['range'], $near);
            }
        }

        return $net;
    }

    /**
     * The day as the viewer may see it: the lines of a worker in their data access, or at an outlet in it,
     * with the counters per outlet and of the whole day. Null when nothing of the day is visible.
     *
     * @param  array<string, mixed>  $day
     * @param  array<string, mixed>  $worker
     * @param  array<string, mixed>  $run
     * @param  array<int, array{id: int, name: string, in_scope: bool}|null>  $outlets
     * @return array<string, mixed>|null
     */
    private function visibleDay(array $day, array $worker, array $run, array $outlets): ?array
    {
        /** @var LedgerQuery $query */
        $query = $run['query'];
        $isVisible = fn (?int $outletId): bool => ($worker['in_scope'] || $run['unbounded'] || ($outletId !== null && ($outlets[$outletId]['in_scope'] ?? false)))
            && ($query->outletId === null || $outletId === $query->outletId);

        $lines = [];
        $byOutlet = [];
        $cellOutlets = [];

        foreach ($day['lines'] as $line) {
            $outletId = $line['outlet']['id'] ?? null;

            if (! $isVisible($outletId)) {
                continue;
            }

            $lines[] = $line;
            $byOutlet[(string) $outletId][] = $line;
            $cellOutlets[(string) $outletId] = $line['outlet'];
        }

        $overtime = $day['overtime'];

        if ($overtime !== [] && ! $isVisible($overtime['outlet_id'])) {
            $overtime = [];
        }

        if ($lines === [] && $overtime === []) {
            return null;
        }

        if ($overtime !== []) {
            $outletKey = (string) $overtime['outlet_id'];
            $byOutlet[$outletKey] ??= [];
            $cellOutlets[$outletKey] ??= $overtime['outlet_id'] !== null ? ['id' => $overtime['outlet_id'], 'name' => $outlets[$overtime['outlet_id']]['name']] : null;
        }

        $cells = [];

        foreach ($byOutlet as $outletKey => $outletLines) {
            $cells[$outletKey] = $this->tally($outletLines, $day['status'], $day['date'], $run['last_countable'], $overtime !== [] && (string) $overtime['outlet_id'] === (string) $outletKey ? $overtime : []);
        }

        return [
            'worker' => self::publicWorker($worker),
            'date' => $day['date'],
            'expected' => $day['expected'],
            'status' => $day['status'],
            'is_late' => $day['is_late'],
            'is_pending' => $day['is_pending'],
            'lines' => $lines,
            'totals' => $this->tally($lines, $day['status'], $day['date'], $run['last_countable'], $overtime),
            'request_overtime_minutes' => $overtime['minutes'] ?? 0,
            'overtime_unattended' => (bool) ($overtime['unattended'] ?? false),
            'cells' => $cells,
            'cell_outlets' => $cellOutlets,
        ];
    }

    /**
     * Counters of the lines of one worker-day (all of them, or those at one outlet).
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $overtime
     * @return array<string, int>
     */
    private function tally(array $lines, string $dayStatus, string $date, string $lastCountable, array $overtime): array
    {
        $tally = self::TALLY;
        $flags = ['scheduled' => false, 'present' => false, 'pending' => false, 'late' => false, 'early' => false, 'absent' => false, 'off' => false, 'leave' => false, 'missing' => false, 'incomplete' => false];

        foreach ($lines as $line) {
            if ($line['is_planned']) {
                $flags['scheduled'] = true;
                $tally['sessions_scheduled']++;
                $tally['scheduled_minutes'] += $line['scheduled_minutes'];
                $tally['normal_minutes'] += $line['normal_minutes'];

                if ($date <= $lastCountable) {
                    $tally['scheduled_minutes_to_date'] += $line['scheduled_minutes'];
                }
            }

            if ($line['is_worked']) {
                $flags['present'] = true;
                $flags['pending'] = $flags['pending'] || $line['is_pending'];
                $flags['late'] = $flags['late'] || $line['is_late'];
                $flags['early'] = $flags['early'] || $line['early_leave_minutes'] > 0;
                $tally['sessions_worked']++;
                $tally['late_minutes'] += $line['late_minutes'];
                $tally['early_leave_minutes'] += $line['early_leave_minutes'];
                $tally['worked_minutes'] += $line['worked_minutes'];
                $tally['auto_overtime_minutes'] += $line['auto_overtime_minutes'];
            }

            $flags['missing'] = $flags['missing'] || $line['is_missing_check_out'];
            $flags['incomplete'] = $flags['incomplete'] || $line['is_incomplete'];
            $flags['absent'] = $flags['absent'] || ($dayStatus === 'absent' && $line['is_expected']);
            $flags['off'] = $flags['off'] || ($dayStatus === 'off' && $line['status'] === 'off');
            $flags['leave'] = $flags['leave'] || ($dayStatus === 'leave' && $line['status'] === 'leave');

            // A session of a present day that was not worked: no attendance, or a rejected one.
            $rejectedSession = $line['status'] === 'rejected' && ($line['is_planned'] || $line['is_expected']) && $date <= $lastCountable;

            if ($dayStatus === 'present' && ($line['status'] === 'absent' || $rejectedSession)) {
                $tally['sessions_missed']++;
            }
        }

        $tally['scheduled_days'] = (int) $flags['scheduled'];
        $tally['scheduled_days_to_date'] = (int) ($flags['scheduled'] && $date <= $lastCountable);
        $tally['present_days'] = (int) $flags['present'];
        $tally['pending_days'] = (int) $flags['pending'];
        $tally['late_days'] = (int) $flags['late'];
        $tally['early_leave_days'] = (int) $flags['early'];
        $tally['absent_days'] = (int) $flags['absent'];
        $tally['off_days'] = (int) $flags['off'];
        $tally['leave_days'] = (int) $flags['leave'];
        $tally['missing_check_out_days'] = (int) $flags['missing'];
        $tally['incomplete_days'] = (int) $flags['incomplete'];

        if ($overtime !== []) {
            $tally['overtime_count'] = $overtime['count'];
            $tally['request_overtime_minutes'] = $overtime['minutes'];
            $tally['overtime_unattended_count'] = $overtime['unattended'] ? $overtime['count'] : 0;
        }

        $tally['overtime_minutes'] = $tally['auto_overtime_minutes'] + $tally['request_overtime_minutes'];

        return $tally;
    }

    /**
     * The worker's home outlet of a calendar month: the outlet with the most planned sessions in that
     * month (the lowest id on a tie), or null without any. It never depends on the range asked for.
     *
     * @param  array<string, mixed>  $worker
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>|null>  $shifts
     * @param  array<int, array{id: int, name: string, in_scope: bool}|null>  $outlets
     */
    private function homeOutlet(array $worker, string $month, array &$data, array $shifts, array $outlets): ?int
    {
        $key = $worker['key'];

        if (array_key_exists($month, $data['home'][$key] ?? [])) {
            return $data['home'][$key][$month];
        }

        $counts = [];

        foreach ($data['schedules'][$key] ?? [] as $date => $rows) {
            if (substr($date, 0, 7) !== $month) {
                continue;
            }

            foreach ($rows as $row) {
                if ($row['is_day_off'] || $row['location_id'] === null || ! isset($outlets[$row['location_id']]) || ! ($shift = $shifts[$row['shift_id'] ?? 0] ?? null)) {
                    continue;
                }

                $counts[$row['location_id']] = ($counts[$row['location_id']] ?? 0) + count($shift['segments']);
            }
        }

        $home = null;

        foreach ($counts as $outletId => $count) {
            if ($home === null || $count > $counts[$home] || ($count === $counts[$home] && $outletId < $home)) {
                $home = $outletId;
            }
        }

        return $data['home'][$key][$month] = $home;
    }

    // ── Reading ──────────────────────────────────────────────────────────────

    /**
     * Schedules, attendance, leave and overtime of one pass of workers as plain arrays.
     *
     * @param  array<string, array<string, mixed>>  $workers
     * @param  array<string, mixed>  $run
     * @param  array<int, array<string, mixed>|null>  $shifts
     * @param  array<int, array{id: int, name: string, in_scope: bool}|null>  $outlets
     * @return array<string, mixed>
     */
    private function load(array $workers, array $run, array &$shifts, array &$outlets): array
    {
        /** @var LedgerQuery $query */
        $query = $run['query'];
        $idsByType = [];

        foreach ($workers as $worker) {
            $idsByType[$worker['type']][] = $worker['id'];
        }

        $ofWorkers = function (QueryBuilder $rows) use ($idsByType): QueryBuilder {
            return $rows->where(function (QueryBuilder $anyWorker) use ($idsByType): void {
                foreach ($idsByType as $type => $ids) {
                    $anyWorker->orWhereIn("{$type}_id", $ids);
                }
            });
        };
        $workerKey = function (object $row): ?string {
            foreach (array_keys(self::WORKER_TYPES) as $type) {
                if ($row->{"{$type}_id"} !== null) {
                    return $type.':'.(int) $row->{"{$type}_id"};
                }
            }

            return null;
        };
        $nextDay = fn (string $date): string => date('Y-m-d', strtotime("{$date} +1 day"));

        $data = ['schedules' => [], 'attendances' => [], 'leave' => [], 'overtime' => [], 'rostered_since' => [], 'home' => []];
        $shiftIds = array_filter(array_column($workers, 'default_shift_id'));
        $outletIds = [];

        // Whole calendar months (the home outlet) and a day on each side (overtime next to a shift).
        $scheduleFrom = ($run['whole_months'] ? $query->from->startOfMonth() : $query->from)->subDay()->toDateString();
        $scheduleUntil = ($run['whole_months'] ? $query->to->endOfMonth() : $query->to)->addDays(2)->toDateString();

        $ofWorkers(DB::table('shift_schedules'))
            ->where('date', '>=', $scheduleFrom)
            ->where('date', '<', $scheduleUntil)
            ->select(['id', 'date', 'sequence', 'employee_id', 'intern_id', 'freelancer_id', 'shift_id', 'company_location_id', 'is_day_off', 'section'])
            ->lazyById(self::CHUNK_SIZE, 'id')
            ->each(function (object $row) use (&$data, &$shiftIds, &$outletIds, $workerKey): void {
                if (! $key = $workerKey($row)) {
                    return;
                }

                $data['schedules'][$key][substr((string) $row->date, 0, 10)][] = [
                    'id' => (int) $row->id,
                    'sequence' => (int) ($row->sequence ?: 1),
                    'shift_id' => $row->shift_id !== null ? (int) $row->shift_id : null,
                    'location_id' => $row->company_location_id !== null ? (int) $row->company_location_id : null,
                    'is_day_off' => (bool) $row->is_day_off,
                    'section' => $row->section,
                ];

                if ($row->shift_id !== null) {
                    $shiftIds[] = (int) $row->shift_id;
                }

                if ($row->company_location_id !== null) {
                    $outletIds[] = (int) $row->company_location_id;
                }
            });

        foreach ($data['schedules'] as $key => $dates) {
            foreach ($dates as $date => $rows) {
                usort($rows, fn (array $a, array $b): int => [$a['sequence'], $a['id']] <=> [$b['sequence'], $b['id']]);
                $data['schedules'][$key][$date] = $rows;
            }
        }

        /** @var array<string, int> $lastCheckIn latest check-in per worker */
        $lastCheckIn = [];

        // A day on each side too: a request next to the range nets against the stored windows of the
        // sessions worked there, whatever range is asked for. Those days are never reported themselves.
        $nearFrom = date('Y-m-d', strtotime("{$run['from']} -1 day"));
        $nearTo = $nextDay($run['to']);

        $ofWorkers(DB::table('attendances'))
            ->where('date', '>=', $nearFrom)
            ->where('date', '<', $nextDay($nearTo))
            ->select(['id', 'date', 'employee_id', 'intern_id', 'freelancer_id', 'shift_id', 'shift_schedule_id', 'schedule_sequence', 'segment', 'company_location_id', 'check_in', 'check_out', 'late_minutes', 'check_in_late_minutes', 'early_leave_minutes', 'status', 'is_off_schedule', 'is_corrected', 'sched_start_at', 'sched_end_at', 'sched_scheduled_minutes', 'sched_normal_minutes', 'sched_shift_name', 'sched_shift_code', 'sched_section', 'sched_source', 'check_out_rejected_at'])
            ->lazyById(self::CHUNK_SIZE, 'id')
            ->each(function (object $row) use (&$data, &$shiftIds, &$outletIds, &$lastCheckIn, $workerKey, $run): void {
                if (! $key = $workerKey($row)) {
                    return;
                }

                $date = substr((string) $row->date, 0, 10);
                $inRange = $date >= $run['from'] && $date <= $run['to'];
                $checkIn = filled($row->check_in) ? (int) strtotime((string) $row->check_in) : null;
                $worked = AttendanceFigures::isWorked($row->status, $checkIn !== null);
                $late = AttendanceFigures::reportedLate($row->status, $row->late_minutes !== null ? (int) $row->late_minutes : null, $row->check_in_late_minutes !== null ? (int) $row->check_in_late_minutes : null);

                $data['attendances'][$key][$date][] = [
                    'id' => (int) $row->id,
                    'sequence' => (int) ($row->schedule_sequence ?: 1),
                    'segment' => (int) ($row->segment ?: 1),
                    'schedule_id' => $row->shift_schedule_id !== null ? (int) $row->shift_schedule_id : null,
                    'shift_id' => $row->shift_id !== null ? (int) $row->shift_id : null,
                    'location_id' => $row->company_location_id !== null ? (int) $row->company_location_id : null,
                    'status' => $row->status,
                    'check_in' => $checkIn,
                    'check_out' => filled($row->check_out) ? (int) strtotime((string) $row->check_out) : null,
                    'check_in_at' => filled($row->check_in) ? (string) $row->check_in : null,
                    'check_out_at' => filled($row->check_out) ? (string) $row->check_out : null,
                    'worked' => $worked,
                    'pending' => $row->status === AttendanceStatus::PendingApproval->value,
                    'late' => $late,
                    'early' => (int) ($row->early_leave_minutes ?? 0),
                    'is_off_schedule' => (bool) $row->is_off_schedule,
                    'is_corrected' => (bool) $row->is_corrected,
                    'has_snapshot' => $row->sched_source !== null,
                    'start' => filled($row->sched_start_at) ? (int) strtotime((string) $row->sched_start_at) : null,
                    'end' => filled($row->sched_end_at) ? (int) strtotime((string) $row->sched_end_at) : null,
                    'sched_scheduled_minutes' => $row->sched_scheduled_minutes !== null ? (int) $row->sched_scheduled_minutes : null,
                    'sched_normal_minutes' => $row->sched_normal_minutes !== null ? (int) $row->sched_normal_minutes : null,
                    'sched_shift_name' => $row->sched_shift_name,
                    'sched_shift_code' => $row->sched_shift_code,
                    'sched_section' => $row->sched_section,
                    'source' => $row->sched_source,
                    // A refused check-out leaves the row without one at once: it is no longer closable live.
                    'has_later_check_in' => filled($row->check_out_rejected_at ?? null),
                ];

                if ($inRange && $worked && $checkIn !== null) {
                    $lastCheckIn[$key] = max($lastCheckIn[$key] ?? 0, $checkIn);
                }

                if ($row->shift_id !== null) {
                    $shiftIds[] = (int) $row->shift_id;
                }

                if ($row->company_location_id !== null) {
                    $outletIds[] = (int) $row->company_location_id;
                }
            });

        // A range that ended a day or two ago: a check-in after it still closes an open row inside it.
        if ($run['to'] < $run['today'] && $nextDay($nextDay($run['to'])) >= $run['today'] && $lastCheckIn !== []) {
            $ofWorkers(DB::table('attendances'))
                ->where('date', '>=', $nextDay($run['to']))
                ->whereNotNull('check_in')
                ->where(fn (QueryBuilder $notRejected) => $notRejected->whereNull('status')->orWhere('status', '!=', AttendanceStatus::Rejected->value))
                ->get(['employee_id', 'intern_id', 'freelancer_id', 'check_in'])
                ->each(function (object $row) use (&$lastCheckIn, $workerKey): void {
                    if ($key = $workerKey($row)) {
                        $lastCheckIn[$key] = max($lastCheckIn[$key] ?? 0, (int) strtotime((string) $row->check_in));
                    }
                });
        }

        foreach ($data['attendances'] as $key => $dates) {
            foreach ($dates as $date => $rows) {
                foreach ($rows as $index => $row) {
                    if ($row['worked'] && $row['check_in'] !== null && $row['check_out'] === null && ($lastCheckIn[$key] ?? 0) > $row['check_in']) {
                        $data['attendances'][$key][$date][$index]['has_later_check_in'] = true;
                    }
                }
            }
        }

        // From the date of their first roster row on (an approved cover's own row does not count), a worker
        // is on a roster: the rule of SessionResolver, through ShiftSchedule::whereRosterRow().
        ShiftSchedule::whereRosterRow($ofWorkers(DB::table('shift_schedules')))
            ->select(['employee_id', 'intern_id', 'freelancer_id'])
            ->selectRaw('MIN(date) AS first_date')
            ->groupBy('employee_id', 'intern_id', 'freelancer_id')
            ->get()
            ->each(function (object $row) use (&$data, $workerKey): void {
                if (($key = $workerKey($row)) && filled($row->first_date)) {
                    $data['rostered_since'][$key] = substr((string) $row->first_date, 0, 10);
                }
            });

        // Approved leave charges its stored dates; a request from before those were stored charges every date of its range.
        $ofWorkers(DB::table('leave_requests'))
            ->where('status', 'approved')
            ->where('start_date', '<', $nextDay($nearTo))
            ->where('end_date', '>=', $nearFrom)
            ->get(['employee_id', 'intern_id', 'freelancer_id', 'start_date', 'end_date', 'charged_dates'])
            ->each(function (object $leave) use (&$data, $workerKey, $nearFrom, $nearTo, $nextDay): void {
                if (! ($key = $workerKey($leave)) || blank($leave->start_date) || blank($leave->end_date)) {
                    return;
                }

                $charged = is_string($leave->charged_dates) ? json_decode($leave->charged_dates, true) : null;

                if (is_array($charged)) {
                    foreach ($charged as $date) {
                        $date = substr((string) $date, 0, 10);

                        if ($date >= $nearFrom && $date <= $nearTo) {
                            $data['leave'][$key][$date] = true;
                        }
                    }

                    return;
                }

                $until = min(substr((string) $leave->end_date, 0, 10), $nearTo);

                for ($date = max(substr((string) $leave->start_date, 0, 10), $nearFrom); $date <= $until; $date = $nextDay($date)) {
                    $data['leave'][$key][$date] = true;
                }
            });

        // Approved overtime counts on its date: the actual duration once filled in, else the approved one.
        $ofWorkers(DB::table('overtime_requests'))
            ->where('status', 'approved')
            ->where('date', '>=', $run['from'])
            ->where('date', '<', $nextDay($run['to']))
            ->get(['employee_id', 'intern_id', 'freelancer_id', 'date', 'start_time', 'end_time', 'duration_minutes', 'actual_duration_minutes'])
            ->each(function (object $overtime) use (&$data, $workerKey): void {
                if (! $key = $workerKey($overtime)) {
                    return;
                }

                $date = substr((string) $overtime->date, 0, 10);

                $data['overtime'][$key][$date][] = self::overtimeEntry(
                    $date,
                    $overtime->start_time,
                    $overtime->end_time,
                    $overtime->duration_minutes !== null ? (int) $overtime->duration_minutes : null,
                    $overtime->actual_duration_minutes !== null ? (int) $overtime->actual_duration_minutes : null,
                );
            });

        $this->loadShifts(array_diff(array_unique($shiftIds), array_keys($shifts)), $shifts);
        $this->loadOutlets(array_diff(array_unique($outletIds), array_keys($outlets)), $outlets, $query);

        return $data;
    }

    /**
     * @param  array<int, int>  $ids
     * @param  array<int, array<string, mixed>|null>  $shifts
     */
    private function loadShifts(array $ids, array &$shifts): void
    {
        if ($ids === []) {
            return;
        }

        $shifts += array_fill_keys($ids, null);

        foreach (Shift::query()->whereIn('id', $ids)->get() as $shift) {
            $shifts[(int) $shift->id] = [
                'id' => (int) $shift->id,
                'name' => (string) $shift->name,
                'code' => filled($shift->code) ? (string) $shift->code : null,
                'segments' => array_map(fn (array $segment): array => [$segment['start'], $segment['end']], $shift->segments()),
                'hours' => [
                    'start' => (string) $shift->start_time,
                    'end' => (string) $shift->end_time,
                    'second_start' => $shift->hasSecondSegment() ? (string) $shift->second_start_time : null,
                    'second_end' => $shift->hasSecondSegment() ? (string) $shift->second_end_time : null,
                ],
                'scheduled_minutes' => $shift->scheduledMinutes(),
                'normal_minutes' => $shift->normal_minutes === null ? null : $shift->normalMinutes(),
            ];
        }
    }

    /**
     * @param  array<int, int>  $ids
     * @param  array<int, array{id: int, name: string, in_scope: bool}|null>  $outlets
     */
    private function loadOutlets(array $ids, array &$outlets, LedgerQuery $query): void
    {
        if ($ids === []) {
            return;
        }

        foreach (DB::table('company_locations')->whereIn('id', $ids)->get(['id', 'name', 'company_id', 'division_id']) as $outlet) {
            $outlets[(int) $outlet->id] = [
                'id' => (int) $outlet->id,
                'name' => (string) $outlet->name,
                'in_scope' => $query->viewer === null || DataAccessService::canAccessPlacement($query->viewer, $outlet->company_id, $outlet->division_id),
            ];
        }
    }

    /**
     * Working hours and report flags per company id.
     *
     * @param  array<int, int|null>  $companyIds
     * @return array<int, array{work_start_time: string, work_end_time: string, uses_shift_roster: bool, overtime_netting_from: string|null}>
     */
    private function policies(array $companyIds): array
    {
        $companyIds = array_values(array_unique(array_filter($companyIds)));

        if ($companyIds === []) {
            return [];
        }

        $policies = [];

        foreach (array_chunk($companyIds, self::WORKERS_PER_PASS) as $chunk) {
            foreach (DB::table('company_policies')->whereIn('company_id', $chunk)->get(['company_id', 'work_start_time', 'work_end_time', 'uses_shift_roster', 'overtime_netting_from']) as $policy) {
                $policies[(int) $policy->company_id] = [
                    'work_start_time' => (string) ($policy->work_start_time ?? '08:00:00'),
                    'work_end_time' => (string) ($policy->work_end_time ?? '17:00:00'),
                    'uses_shift_roster' => (bool) $policy->uses_shift_roster,
                    'overtime_netting_from' => filled($policy->overtime_netting_from) ? substr((string) $policy->overtime_netting_from, 0, 10) : null,
                ];
            }
        }

        return $policies;
    }

    /**
     * @return array{work_start_time: string, work_end_time: string, uses_shift_roster: bool, overtime_netting_from: string|null}
     */
    private static function defaultPolicy(): array
    {
        return ['work_start_time' => '08:00:00', 'work_end_time' => '17:00:00', 'uses_shift_roster' => false, 'overtime_netting_from' => null];
    }

    /**
     * The workers the report is about: those in the viewer's data access, plus workers from elsewhere who
     * were scheduled or checked in at an outlet in it during the range (shown for those lines only).
     *
     * @return array<string, array{key: string, type: string, id: int, number: string, name: string, company: string|null, division: string|null, company_id: int|null, division_id: int|null, default_shift_id: int|null, employed_from: string|null, employed_until: string|null, counts_standing: bool, in_scope: bool}>
     */
    private function workers(LedgerQuery $query): array
    {
        $from = $query->from->toDateString();
        $until = $query->to->addDay()->toDateString();
        $workers = [];

        if ($query->workerKeys !== null) {
            $idsByType = [];

            foreach ($query->workerKeys as $key) {
                [$type, $id] = array_pad(explode(':', $key, 2), 2, null);

                if (isset(self::WORKER_TYPES[$type]) && ctype_digit((string) $id)) {
                    $idsByType[$type][] = (int) $id;
                }
            }

            foreach ($idsByType as $type => $ids) {
                foreach (array_chunk($ids, self::WORKERS_PER_PASS) as $chunk) {
                    foreach ($this->workerRows(self::WORKER_TYPES[$type]['model']::query()->withoutGlobalScopes()->whereKey($chunk), $type, $query) as $worker) {
                        $workers[$worker['key']] = $worker + ['in_scope' => true];
                    }
                }
            }

            return $this->withEmployment($workers, $from);
        }

        if (! $viewer = $query->viewer) {
            return [];
        }

        $filter = DataAccessService::workerFilter($viewer);

        foreach (self::WORKER_TYPES as $type => $definition) {
            if ($query->workerType !== null && $query->workerType !== $type) {
                continue;
            }

            foreach ($this->workerRows(DataAccessService::scopeWorkers($definition['model']::query(), $viewer), $type, $query) as $worker) {
                $workers[$worker['key']] = $worker + ['in_scope' => true];
            }
        }

        if ($filter !== null && ($filter['companies'] !== [] || $filter['divisions'] !== [])) {
            $outletsInScope = fn (): Builder => CompanyLocation::query()->select('id')->where(function (Builder $inScope) use ($filter): void {
                if ($filter['companies'] !== []) {
                    $inScope->orWhereIn('company_id', $filter['companies']);
                }

                if ($filter['divisions'] !== []) {
                    $inScope->orWhereIn('division_id', $filter['divisions']);
                }
            });
            $lent = [];

            foreach (['shift_schedules', 'attendances'] as $table) {
                $rows = DB::table($table)
                    ->whereIn('company_location_id', $outletsInScope())
                    ->where('date', '>=', $from)
                    ->where('date', '<', $until)
                    ->distinct()
                    ->get(['employee_id', 'intern_id', 'freelancer_id']);

                foreach ($rows as $row) {
                    foreach (array_keys(self::WORKER_TYPES) as $type) {
                        if ($row->{"{$type}_id"} !== null && ! isset($workers[$type.':'.(int) $row->{"{$type}_id"}])) {
                            $lent[$type][(int) $row->{"{$type}_id"}] = true;
                        }
                    }
                }
            }

            foreach ($lent as $type => $ids) {
                if ($query->workerType !== null && $query->workerType !== $type) {
                    continue;
                }

                foreach (array_chunk(array_keys($ids), self::WORKERS_PER_PASS) as $chunk) {
                    foreach ($this->workerRows(self::WORKER_TYPES[$type]['model']::query()->whereKey($chunk), $type, $query) as $worker) {
                        $workers[$worker['key']] = $worker + ['in_scope' => false];
                    }
                }
            }
        }

        return $this->withEmployment($workers, $from);
    }

    /**
     * @param  Builder<Employee>|Builder<Intern>|Builder<Freelancer>  $builder
     * @return list<array<string, mixed>>
     */
    private function workerRows(Builder $builder, string $type, LedgerQuery $query): array
    {
        $definition = self::WORKER_TYPES[$type];
        $table = $definition['table'];

        return $builder
            ->when($query->companyId, fn (Builder $workers) => $workers->where("{$table}.company_id", $query->companyId))
            ->when($query->divisionId, fn (Builder $workers) => $workers->where("{$table}.division_id", $query->divisionId))
            ->when($query->workerId, fn (Builder $workers) => $workers->where("{$table}.id", $query->workerId))
            ->leftJoin('companies', 'companies.id', '=', "{$table}.company_id")
            ->leftJoin('divisions', 'divisions.id', '=', "{$table}.division_id")
            ->toBase()
            ->get(array_filter([
                "{$table}.id as id",
                "{$table}.company_id as company_id",
                "{$table}.division_id as division_id",
                "{$table}.shift_id as shift_id",
                "{$table}.full_name as full_name",
                "{$table}.status as status",
                "{$table}.{$definition['number']} as number",
                "{$table}.{$definition['from']} as employed_from",
                $definition['until'] ? "{$table}.{$definition['until']} as employed_until" : null,
                'companies.name as company_name',
                'divisions.name as division_name',
            ]))
            ->map(fn (object $worker): array => [
                'key' => $type.':'.(int) $worker->id,
                'type' => $type,
                'id' => (int) $worker->id,
                'number' => (string) $worker->number,
                'name' => (string) $worker->full_name,
                'company' => $worker->company_name,
                'division' => $worker->division_name,
                'company_id' => $worker->company_id !== null ? (int) $worker->company_id : null,
                'division_id' => $worker->division_id !== null ? (int) $worker->division_id : null,
                'default_shift_id' => $worker->shift_id !== null ? (int) $worker->shift_id : null,
                'status' => (string) $worker->status,
                'employed_from' => filled($worker->employed_from) ? substr((string) $worker->employed_from, 0, 10) : null,
                'employed_until' => filled($worker->employed_until ?? null) ? substr((string) $worker->employed_until, 0, 10) : null,
            ])
            ->all();
    }

    /**
     * Close each worker's employment window: an employee ends on the last working day of their latest
     * approved resignation (unless they work again). Standing hours (no roster) are only expected of a
     * worker who still works or whose employment ended on a known date inside or after the range.
     *
     * @param  array<string, array<string, mixed>>  $workers
     * @return array<string, array<string, mixed>>
     */
    private function withEmployment(array $workers, string $from): array
    {
        $employeeIds = [];

        foreach ($workers as $worker) {
            if ($worker['type'] === 'employee') {
                $employeeIds[] = $worker['id'];
            }
        }

        $lastWorkingDays = [];

        foreach (array_chunk($employeeIds, self::WORKERS_PER_PASS) as $chunk) {
            $rows = DB::table('resignations')
                ->whereIn('employee_id', $chunk)
                ->where('status', 'approved')
                ->whereNotNull('last_working_day')
                ->get(['employee_id', 'last_working_day']);

            foreach ($rows as $row) {
                $day = substr((string) $row->last_working_day, 0, 10);
                $lastWorkingDays[(int) $row->employee_id] = max($lastWorkingDays[(int) $row->employee_id] ?? '', $day);
            }
        }

        $today = today()->toDateString();

        foreach ($workers as $key => $worker) {
            $isWorking = in_array($worker['status'], self::WORKING_STATUSES, true);

            if ($worker['type'] === 'employee') {
                $until = $lastWorkingDays[$worker['id']] ?? null;
                // A rehired employee's old resignation does not end their current employment.
                $worker['employed_until'] = $until !== null && $until < $today && $isWorking ? null : $until;
            }

            $worker['counts_standing'] = $isWorking || ($worker['employed_until'] !== null && $worker['employed_until'] >= $from);
            $workers[$key] = $worker;
        }

        return $workers;
    }

    /**
     * @param  array<string, mixed>  $worker
     * @return array{type: string, id: int, number: string, name: string, company: string|null, division: string|null}
     */
    private static function publicWorker(array $worker): array
    {
        return [
            'type' => $worker['type'],
            'id' => $worker['id'],
            'number' => $worker['number'],
            'name' => $worker['name'],
            'company' => $worker['company'],
            'division' => $worker['division'],
        ];
    }
}
