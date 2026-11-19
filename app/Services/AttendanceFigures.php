<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\CompanyLocation;
use App\Models\CompanyPolicy;
use App\Models\Shift;
use Illuminate\Support\Facades\DB;

/**
 * The figures of an attendance row and of the schedule it belongs to: scheduled, normal and worked
 * minutes, automatic overtime, lateness, early leave, a missing check-out and an incomplete day.
 *
 * The arithmetic is pure and works on plain values (moments are Unix timestamps), so the ledger and the
 * attendance API give the same numbers. Nothing here writes, and reading a row never fills in a
 * missing schedule snapshot.
 *
 * A schedule is one roster row (or the standing hours of a day); it has one part, or two for a split
 * shift. Normal minutes and automatic overtime belong to the whole schedule:
 * automatic overtime = clamp(worked inside the scheduled windows - normal, 0, scheduled - normal).
 */
final class AttendanceFigures
{
    /** A schedule worked for less than this share of its scheduled minutes is incomplete. */
    public const INCOMPLETE_BELOW_PERCENT = 50;

    /** Statuses of a row that stands for work even without a stored check-in time. */
    private const WORKED_STATUSES = ['on_time', 'late', 'pending_approval'];

    /**
     * What {@see self::preload()} read for an attendance, kept until the model is released. Nothing is
     * kept by id, so a shift or an outlet edited later is read again with the next row.
     *
     * @var \WeakMap<Attendance, array{siblings: list<object>, has_later_check_in: bool, shift: array{name: string, code: string|null, hours: array{start: string, end: string, second_start: string|null, second_end: string|null}, scheduled_minutes: int}|null, outlet_name: string|null, policy_hours: array{start: string, end: string}|null}>|null
     */
    private static ?\WeakMap $preloaded = null;

    // ── Pure arithmetic ──────────────────────────────────────────────────────

    /**
     * Start and end moments of working hours on a business date; an end at or before the start is on the next day.
     *
     * @param  string  $date  Y-m-d
     * @return array{0: int, 1: int}
     */
    public static function window(string $date, string $startTime, string $endTime): array
    {
        $startTime = substr($startTime, 0, 5);
        $endTime = substr($endTime, 0, 5);
        $start = (int) strtotime("{$date} {$startTime}:00");
        $end = (int) strtotime("{$date} {$endTime}:00".($endTime <= $startTime ? ' +1 day' : ''));

        return [$start, $end];
    }

    /**
     * The window a row without a schedule snapshot is read against: the hours of the shift stored on the
     * row for its segment, else the company hours (given for employees and interns only), else none.
     *
     * @param  array{start: string, end: string, second_start?: string|null, second_end?: string|null}|null  $shiftHours
     * @param  array{start: string, end: string}|null  $policyHours
     * @return array{0: int, 1: int}|null
     */
    public static function fallbackWindow(string $date, int $segment, ?array $shiftHours, ?array $policyHours): ?array
    {
        if ($shiftHours !== null) {
            $useSecond = $segment === 2 && filled($shiftHours['second_start'] ?? null) && filled($shiftHours['second_end'] ?? null);

            return $useSecond
                ? self::window($date, (string) $shiftHours['second_start'], (string) $shiftHours['second_end'])
                : self::window($date, $shiftHours['start'], $shiftHours['end']);
        }

        return $policyHours !== null ? self::window($date, $policyHours['start'], $policyHours['end']) : null;
    }

    /**
     * The last moment a live check-out is accepted ({@see SessionResolver::checkOutClosesAt()}): the latest
     * of the scheduled end plus the grace hours, the end of the business date and, for a window ending on
     * the next calendar day, the check-in plus the hours an overnight row stays open.
     */
    public static function closesAt(string $date, ?int $endAt, ?int $checkInAt): int
    {
        $closesAt = (int) strtotime("{$date} 23:59:59");

        if ($endAt === null) {
            return $closesAt;
        }

        $closesAt = max($closesAt, $endAt + SessionResolver::CHECK_OUT_GRACE_HOURS * 3600);

        if ($checkInAt !== null && date('Y-m-d', $endAt) !== $date) {
            $closesAt = max($closesAt, $checkInAt + AttendanceCheckService::OVERNIGHT_OPEN_HOURS * 3600);
        }

        return $closesAt;
    }

    /**
     * Whether a row stands for work: checked in (or stored as on time, late or waiting for approval) and
     * not rejected. A rejected row is never work, presence or lateness.
     */
    public static function isWorked(?string $status, bool $hasCheckIn): bool
    {
        if ($status === AttendanceStatus::Rejected->value) {
            return false;
        }

        return $hasCheckIn || in_array($status, self::WORKED_STATUSES, true);
    }

    /**
     * Lateness for reports: while a check-in waits for approval the lateness judged at check-in, else the stored one.
     */
    public static function reportedLate(?string $status, ?int $lateMinutes, ?int $checkInLateMinutes): int
    {
        if ($status === AttendanceStatus::PendingApproval->value) {
            return (int) ($checkInLateMinutes ?? $lateMinutes ?? 0);
        }

        return (int) ($lateMinutes ?? 0);
    }

    /**
     * Automatic overtime of a schedule: time worked inside its windows above the normal minutes, never
     * more than the scheduled minutes above normal. No normal minutes = none.
     */
    public static function autoOvertime(int $workedInWindowMinutes, ?int $normalMinutes, int $scheduledMinutes): int
    {
        if ($normalMinutes === null) {
            return 0;
        }

        return max(0, min($workedInWindowMinutes - $normalMinutes, $scheduledMinutes - $normalMinutes));
    }

    /**
     * Figures of one schedule from its parts (worked or not), in any order.
     *
     * @param  list<array{segment: int, date: string, start: int|null, end: int|null, check_in?: int|null, check_out?: int|null, worked?: bool, late_minutes?: int, early_leave_minutes?: int, has_later_check_in?: bool}>  $parts
     * @param  int  $now  the moment the figures are read at
     * @param  int|null  $scheduledMinutes  scheduled minutes of the whole schedule; null = the sum of the part windows
     * @param  int|null  $normalMinutes  normal minutes of the whole schedule; null = no automatic overtime
     * @return array{scheduled_minutes: int, normal_minutes: int, worked_minutes: int, worked_in_window_minutes: int, auto_overtime_minutes: int, late_minutes: int, early_leave_minutes: int, is_missing_check_out: bool, is_incomplete: bool, parts: array<int, array{scheduled_minutes: int, normal_minutes: int, worked_minutes: int, auto_overtime_minutes: int, is_missing_check_out: bool, is_open: bool}>}
     */
    public static function forSchedule(array $parts, int $now, ?int $scheduledMinutes = null, ?int $normalMinutes = null): array
    {
        $order = array_keys($parts);
        usort($order, fn (int|string $a, int|string $b): int => [$parts[$a]['segment'], $parts[$a]['start'] ?? 0] <=> [$parts[$b]['segment'], $parts[$b]['start'] ?? 0]);

        $windowTotal = 0;
        $worked = 0;
        $inWindow = 0;
        $late = 0;
        $early = 0;
        $missing = false;
        $open = false;
        $anyWorked = false;
        $lastWorked = null;
        $figures = [];

        foreach ($order as $index) {
            $part = $parts[$index];
            $start = $part['start'] ?? null;
            $end = $part['end'] ?? null;
            $partScheduled = $start !== null && $end !== null ? max(0, intdiv($end - $start, 60)) : 0;
            $windowTotal += $partScheduled;

            $figures[$index] = ['scheduled_minutes' => $partScheduled, 'normal_minutes' => 0, 'worked_minutes' => 0, 'auto_overtime_minutes' => 0, 'is_missing_check_out' => false, 'is_open' => false];

            if (! ($part['worked'] ?? false)) {
                continue;
            }

            $anyWorked = true;
            $lastWorked = $index;
            $late += (int) ($part['late_minutes'] ?? 0);
            $early += (int) ($part['early_leave_minutes'] ?? 0);
            $checkIn = $part['check_in'] ?? null;
            $checkOut = $part['check_out'] ?? null;

            if ($checkIn !== null && $checkOut === null) {
                if (($part['has_later_check_in'] ?? false) || $now > self::closesAt($part['date'], $end, $checkIn)) {
                    $missing = true;
                    $figures[$index]['is_missing_check_out'] = true;
                } else {
                    $open = true;
                    $figures[$index]['is_open'] = true;
                }

                continue;
            }

            if ($checkIn === null || $checkOut === null) {
                continue;
            }

            $minutes = max(0, intdiv($checkOut - $checkIn, 60));
            $figures[$index]['worked_minutes'] = $minutes;
            $worked += $minutes;
            $inWindow += $start !== null && $end !== null
                ? max(0, intdiv(min($checkOut, $end) - max($checkIn, $start), 60))
                : $minutes;
        }

        $scheduled = $scheduledMinutes ?? $windowTotal;
        $normal = $normalMinutes === null ? $scheduled : min($normalMinutes, $scheduled);
        $overtime = $anyWorked ? self::autoOvertime($inWindow, $normalMinutes === null ? null : $normal, $scheduled) : 0;

        if ($lastWorked !== null) {
            $figures[$lastWorked]['auto_overtime_minutes'] = $overtime;
        }

        // Normal minutes fill the parts in order, so the parts add up to the schedule.
        $remaining = $normal;

        foreach ($order as $index) {
            $figures[$index]['normal_minutes'] = min($figures[$index]['scheduled_minutes'], $remaining);
            $remaining -= $figures[$index]['normal_minutes'];
        }

        return [
            'scheduled_minutes' => $scheduled,
            'normal_minutes' => $normal,
            'worked_minutes' => $worked,
            'worked_in_window_minutes' => $inWindow,
            'auto_overtime_minutes' => $overtime,
            'late_minutes' => $late,
            'early_leave_minutes' => $early,
            'is_missing_check_out' => $missing,
            // A schedule still being worked is not incomplete yet.
            'is_incomplete' => $anyWorked && ($missing || (! $open && $scheduled > 0 && $worked * 100 < $scheduled * self::INCOMPLETE_BELOW_PERCENT)),
            'parts' => $figures,
        ];
    }

    /**
     * Approved overtime request minutes that count next to the shift: the part of the request that lies
     * inside a scheduled window is already in the shift's hours and is taken off.
     *
     * @param  array{0: int, 1: int}|null  $range  start and end moments of the request
     * @param  list<array{0: int, 1: int}>  $windows  scheduled windows of the worker around that date
     */
    public static function netOvertimeMinutes(int $minutes, ?array $range, array $windows): int
    {
        if ($range === null || $windows === []) {
            return max(0, $minutes);
        }

        usort($windows, fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $overlap = 0;
        $cursor = $range[0];

        foreach ($windows as [$start, $end]) {
            $from = max($cursor, $start, $range[0]);
            $until = min($end, $range[1]);

            if ($until > $from) {
                $overlap += $until - $from;
                $cursor = $until;
            }
        }

        return max(0, $minutes - intdiv($overlap, 60));
    }

    // ── Attendance rows (API) ────────────────────────────────────────────────

    /**
     * Read once what the rows of a list need next to their own columns: the shift, outlet and company
     * hours of rows without a schedule snapshot, the other part of a split shift (its automatic overtime
     * belongs to the last worked part) and whether a still open row was followed by a later check-in.
     * Each is one query for the whole list, and only when a row needs it.
     *
     * @param  iterable<mixed>  $attendances
     */
    public static function preload(iterable $attendances): void
    {
        self::$preloaded ??= new \WeakMap;
        $now = now()->getTimestamp();
        $shiftIds = [];
        $outletIds = [];
        $companyIds = [];
        /** @var list<array{0: Attendance, 1: int|null}> $rows each with the company whose hours apply */
        $rows = [];

        foreach ($attendances as $attendance) {
            if (! $attendance instanceof Attendance || isset(self::$preloaded[$attendance])) {
                continue;
            }

            $companyId = null;

            if ($attendance->shift_id && (! $attendance->hasSnapshot() || blank($attendance->sched_shift_name))) {
                $shiftIds[] = (int) $attendance->shift_id;
            }

            if ($attendance->company_location_id && blank($attendance->sched_outlet_name)) {
                $outletIds[] = (int) $attendance->company_location_id;
            }

            if (! $attendance->hasSnapshot() && ! $attendance->shift_id && ($companyId = self::officeCompanyId($attendance))) {
                $companyIds[] = $companyId;
            }

            $rows[] = [$attendance, $companyId];
        }

        if ($rows === []) {
            return;
        }

        $shifts = self::shifts($shiftIds);
        $outletNames = self::outletNames($outletIds);
        $policyHours = self::policyHours($companyIds);
        $needSiblings = [];
        $needLater = [];

        foreach ($rows as [$attendance, $companyId]) {
            self::$preloaded[$attendance] = [
                'siblings' => [],
                // A refused check-out leaves the row without one at once: it is no longer closable live.
                'has_later_check_in' => $attendance->check_out_rejected_at !== null,
                'shift' => $attendance->shift_id ? ($shifts[(int) $attendance->shift_id] ?? null) : null,
                'outlet_name' => $attendance->company_location_id ? ($outletNames[(int) $attendance->company_location_id] ?? null) : null,
                'policy_hours' => $companyId !== null ? ($policyHours[$companyId] ?? ['start' => '08:00', 'end' => '17:00']) : null,
            ];
            $window = self::windowOf($attendance);

            // Only a split shift with normal hours shares its overtime between two rows.
            if ($attendance->sched_normal_minutes !== null && $window !== null
                && intdiv($window[1] - $window[0], 60) < (int) $attendance->sched_scheduled_minutes) {
                $needSiblings[] = $attendance;
            }

            if ($attendance->date && $attendance->check_in && ! $attendance->check_out && self::isWorked($attendance->status?->value, true)
                && $now <= self::closesAt($attendance->date->toDateString(), $window[1] ?? null, $attendance->check_in->getTimestamp())) {
                $needLater[] = $attendance;
            }
        }

        foreach (self::otherRowsOf($needSiblings, sameDay: true) as [$attendance, $other]) {
            if ((int) $other->schedule_sequence === (int) ($attendance->schedule_sequence ?: 1)) {
                $entry = self::$preloaded[$attendance];
                $entry['siblings'][] = $other;
                self::$preloaded[$attendance] = $entry;
            }
        }

        foreach (self::otherRowsOf($needLater, sameDay: false) as [$attendance, $other]) {
            if ($other->check_in !== null && $other->status !== AttendanceStatus::Rejected->value
                && strtotime((string) $other->check_in) > $attendance->check_in->getTimestamp()) {
                $entry = self::$preloaded[$attendance];
                $entry['has_later_check_in'] = true;
                self::$preloaded[$attendance] = $entry;
            }
        }
    }

    /**
     * The schedule and the figures of one attendance row, as the API shows them. The numbers are the
     * ones the ledger reports for the same row.
     *
     * @return array{schedule_id: int|null, schedule_sequence: int, shift: array{id: int|null, name: string, code: string|null}|null, outlet: array{id: int, name: string|null}|null, scheduled_start: string|null, scheduled_end: string|null, worked_minutes: int, normal_minutes: int|null, overtime_minutes: int, reported_late_minutes: int, is_missing_check_out: bool, pending_punch: string|null, schedule_label: string|null}
     */
    public static function forAttendance(Attendance $attendance): array
    {
        if (self::$preloaded === null || ! isset(self::$preloaded[$attendance])) {
            self::preload([$attendance]);
        }

        $preloaded = self::$preloaded[$attendance];
        $now = now()->getTimestamp();
        $window = self::windowOf($attendance);
        $status = $attendance->status?->value;
        $late = self::reportedLate($status, $attendance->late_minutes !== null ? (int) $attendance->late_minutes : null, $attendance->check_in_late_minutes);

        $parts = ['self' => self::partOf($attendance, $window, $late, $preloaded['has_later_check_in'])];

        foreach ($preloaded['siblings'] as $index => $sibling) {
            $date = substr((string) $sibling->date, 0, 10);
            $parts["sibling{$index}"] = [
                'segment' => (int) $sibling->segment,
                'date' => $date,
                'start' => $sibling->sched_start_at !== null ? (int) strtotime((string) $sibling->sched_start_at) : null,
                'end' => $sibling->sched_end_at !== null ? (int) strtotime((string) $sibling->sched_end_at) : null,
                'check_in' => $sibling->check_in !== null ? (int) strtotime((string) $sibling->check_in) : null,
                'check_out' => $sibling->check_out !== null ? (int) strtotime((string) $sibling->check_out) : null,
                'worked' => self::isWorked($sibling->status, $sibling->check_in !== null),
            ];
        }

        $hasHours = $window !== null;
        $scheduled = $attendance->sched_scheduled_minutes ?? $preloaded['shift']['scheduled_minutes'] ?? null;
        $figures = self::forSchedule($parts, $now, $scheduled !== null ? (int) $scheduled : null, $attendance->sched_normal_minutes);
        $shift = self::shiftLabelOf($attendance);
        $outletName = filled($attendance->sched_outlet_name) ? (string) $attendance->sched_outlet_name : $preloaded['outlet_name'];
        $hours = $hasHours ? date('H:i', $window[0]).' - '.date('H:i', $window[1]) : null;

        return [
            'schedule_id' => $attendance->shift_schedule_id !== null ? (int) $attendance->shift_schedule_id : null,
            'schedule_sequence' => (int) ($attendance->schedule_sequence ?: 1),
            'shift' => $shift,
            'outlet' => $attendance->company_location_id ? ['id' => (int) $attendance->company_location_id, 'name' => $outletName] : null,
            'scheduled_start' => $hasHours ? date('H:i', $window[0]) : null,
            'scheduled_end' => $hasHours ? date('H:i', $window[1]) : null,
            'worked_minutes' => $figures['parts']['self']['worked_minutes'],
            'normal_minutes' => $hasHours ? $figures['parts']['self']['normal_minutes'] : null,
            'overtime_minutes' => $figures['parts']['self']['auto_overtime_minutes'],
            'reported_late_minutes' => $late,
            'is_missing_check_out' => $figures['parts']['self']['is_missing_check_out'],
            'pending_punch' => $attendance->pending_punch,
            'schedule_label' => implode(' · ', array_filter([$shift['name'] ?? ($hasHours ? 'Jam kerja' : null), $hours, $outletName])) ?: null,
        ];
    }

    /**
     * The scheduled window of a row: its snapshot, else the fallback window.
     *
     * @return array{0: int, 1: int}|null
     */
    private static function windowOf(Attendance $attendance): ?array
    {
        if ($attendance->hasSnapshot()) {
            return $attendance->sched_start_at && $attendance->sched_end_at
                ? [$attendance->sched_start_at->getTimestamp(), $attendance->sched_end_at->getTimestamp()]
                : null;
        }

        if (! $attendance->date) {
            return null;
        }

        $preloaded = self::$preloaded[$attendance] ?? null;

        return self::fallbackWindow(
            $attendance->date->toDateString(),
            (int) ($attendance->segment ?: 1),
            $preloaded['shift']['hours'] ?? null,
            $preloaded['policy_hours'] ?? null,
        );
    }

    /**
     * @param  array{0: int, 1: int}|null  $window
     * @return array{segment: int, date: string, start: int|null, end: int|null, check_in: int|null, check_out: int|null, worked: bool, late_minutes: int, early_leave_minutes: int, has_later_check_in: bool}
     */
    private static function partOf(Attendance $attendance, ?array $window, int $late, bool $hasLaterCheckIn): array
    {
        return [
            'segment' => (int) ($attendance->segment ?: 1),
            'date' => $attendance->date?->toDateString() ?? now()->toDateString(),
            'start' => $window[0] ?? null,
            'end' => $window[1] ?? null,
            'check_in' => $attendance->check_in?->getTimestamp(),
            'check_out' => $attendance->check_out?->getTimestamp(),
            'worked' => self::isWorked($attendance->status?->value, $attendance->check_in !== null),
            'late_minutes' => $late,
            'early_leave_minutes' => (int) ($attendance->early_leave_minutes ?? 0),
            'has_later_check_in' => $hasLaterCheckIn,
        ];
    }

    /**
     * The shift of a row by the name kept in its snapshot, else the shift stored on the row.
     *
     * @return array{id: int|null, name: string, code: string|null}|null
     */
    private static function shiftLabelOf(Attendance $attendance): ?array
    {
        if (filled($attendance->sched_shift_name)) {
            return ['id' => $attendance->shift_id !== null ? (int) $attendance->shift_id : null, 'name' => (string) $attendance->sched_shift_name, 'code' => $attendance->sched_shift_code];
        }

        $shift = self::$preloaded[$attendance]['shift'] ?? null;

        return $shift ? ['id' => (int) $attendance->shift_id, 'name' => $shift['name'], 'code' => $shift['code']] : null;
    }

    /**
     * Company whose hours apply to a row without a shift: the worker's, for an employee or an intern.
     * Reads the worker loaded on the row, or just the company id.
     */
    private static function officeCompanyId(Attendance $attendance): ?int
    {
        foreach (['employee' => 'employees', 'intern' => 'interns'] as $relation => $table) {
            if (! $attendance->{"{$relation}_id"}) {
                continue;
            }

            $companyId = $attendance->relationLoaded($relation)
                ? $attendance->getRelation($relation)?->company_id
                : DB::table($table)->where('id', $attendance->{"{$relation}_id"})->value('company_id');

            return $companyId !== null ? (int) $companyId : null;
        }

        return null;
    }

    /**
     * Other attendance rows of the workers of the given rows: of the same business date, or (later
     * check-ins) from the row's date on. One query for all of them.
     *
     * @param  list<Attendance>  $attendances
     * @return list<array{0: Attendance, 1: object}>
     */
    private static function otherRowsOf(array $attendances, bool $sameDay): array
    {
        if ($attendances === []) {
            return [];
        }

        $idsByColumn = [];
        $dates = [];

        foreach ($attendances as $attendance) {
            foreach (['employee_id', 'intern_id', 'freelancer_id'] as $column) {
                if ($attendance->{$column}) {
                    $idsByColumn[$column][] = (int) $attendance->{$column};
                }
            }

            $dates[] = $attendance->date->toDateString();
        }

        $query = DB::table('attendances')
            ->where('date', '>=', min($dates))
            ->where(function ($workers) use ($idsByColumn): void {
                foreach ($idsByColumn as $column => $ids) {
                    $workers->orWhereIn($column, array_values(array_unique($ids)));
                }
            });

        if ($sameDay) {
            $query->where('date', '<', date('Y-m-d', strtotime(max($dates).' +1 day')));
        }

        $others = $query->get(['id', 'employee_id', 'intern_id', 'freelancer_id', 'date', 'schedule_sequence', 'segment', 'check_in', 'check_out', 'status', 'sched_start_at', 'sched_end_at']);
        $pairs = [];

        foreach ($attendances as $attendance) {
            foreach ($others as $other) {
                if ((int) $other->id === (int) $attendance->getKey()
                    || (int) $other->employee_id !== (int) $attendance->employee_id
                    || (int) $other->intern_id !== (int) $attendance->intern_id
                    || (int) $other->freelancer_id !== (int) $attendance->freelancer_id) {
                    continue;
                }

                if ($sameDay && substr((string) $other->date, 0, 10) !== $attendance->date->toDateString()) {
                    continue;
                }

                $pairs[] = [$attendance, $other];
            }
        }

        return $pairs;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array{name: string, code: string|null, hours: array{start: string, end: string, second_start: string|null, second_end: string|null}, scheduled_minutes: int}>
     */
    private static function shifts(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        $shifts = [];

        if ($ids === []) {
            return $shifts;
        }

        foreach (Shift::query()->whereIn('id', $ids)->get() as $shift) {
            $shifts[(int) $shift->id] = [
                'name' => (string) $shift->name,
                'code' => filled($shift->code) ? (string) $shift->code : null,
                'hours' => [
                    'start' => (string) $shift->start_time,
                    'end' => (string) $shift->end_time,
                    'second_start' => $shift->hasSecondSegment() ? (string) $shift->second_start_time : null,
                    'second_end' => $shift->hasSecondSegment() ? (string) $shift->second_end_time : null,
                ],
                'scheduled_minutes' => $shift->scheduledMinutes(),
            ];
        }

        return $shifts;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private static function outletNames(array $ids): array
    {
        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return [];
        }

        return CompanyLocation::query()->whereIn('id', $ids)->toBase()->get(['id', 'name'])
            ->mapWithKeys(fn (object $outlet): array => [(int) $outlet->id => (string) $outlet->name])
            ->all();
    }

    /**
     * @param  list<int>  $companyIds
     * @return array<int, array{start: string, end: string}>
     */
    private static function policyHours(array $companyIds): array
    {
        $companyIds = array_values(array_unique($companyIds));

        if ($companyIds === []) {
            return [];
        }

        return CompanyPolicy::query()->whereIn('company_id', $companyIds)->toBase()->get(['company_id', 'work_start_time', 'work_end_time'])
            ->mapWithKeys(fn (object $policy): array => [(int) $policy->company_id => [
                'start' => (string) ($policy->work_start_time ?? '08:00'),
                'end' => (string) ($policy->work_end_time ?? '17:00'),
            ]])
            ->all();
    }
}
