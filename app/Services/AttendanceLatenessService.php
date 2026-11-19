<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\CompanyLocation;
use Illuminate\Support\Facades\DB;

/**
 * Judges an attendance row again against the schedule it was checked in to: its lateness (status
 * on_time/late, or the check-in verdict of a row waiting for approval) and its early leave.
 *
 * D4: the schedule is the one kept in the row's snapshot (the sched_* columns), so a roster or shift
 * master edit made after the check-in never changes the result, and nothing here rewrites the row's
 * shift or snapshot. Used only when the facts of the attendance itself change: an admin moves the check-in
 * or check-out, or a correction is applied. Rejected, leave and other non-working rows are never touched.
 */
class AttendanceLatenessService
{
    /** Statuses of a worked day whose lateness can be judged again. */
    private const RECALCULABLE = [AttendanceStatus::OnTime, AttendanceStatus::Late, AttendanceStatus::PendingApproval];

    public function __construct(
        private readonly WorkScheduleService $workSchedule,
        private readonly SessionResolver $sessions,
    ) {}

    /**
     * Run the callback. Roster changes no longer re-judge attendance (D4), so there is nothing to pause;
     * kept so existing callers keep working.
     *
     * @deprecated Roster changes never recalculate attendance; call the callback directly.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withoutRosterRecalculation(callable $callback): mixed
    {
        return $callback();
    }

    /**
     * @deprecated Roster changes never recalculate attendance, so this is always false.
     */
    public static function rosterRecalculationPaused(): bool
    {
        return false;
    }

    /**
     * The shift and lateness of the row's check-in under the schedule it was checked in to.
     *
     * A row with a schedule snapshot is judged against it ({@see WorkScheduleService::snapshotLateMinutes()}),
     * and keeps its shift. A row without one (written before snapshots existed, or a fixture) is judged
     * against its own shift when it has one; only a row without a shift falls back to the schedule that
     * applies now: when the roster says the worker is off that day (a day off, or a freelancer without a
     * shift) there is nothing to be late for, and an off-schedule check-in is never judged against the
     * worker's default shift or the company hours it was not called in for.
     *
     * hours are the working hours the row was judged against ({@see WorkScheduleService::resolve()} shape),
     * or null when there are none or the snapshot was used.
     *
     * @return array{shift_id: int|null, late_minutes: int, hours: array<string, mixed>|null}
     */
    public function judge(Attendance $attendance): array
    {
        $worker = $attendance->getWorkerProfile();

        if (! $worker || ! $attendance->check_in || ! $attendance->date) {
            return ['shift_id' => $attendance->shift_id, 'late_minutes' => 0, 'hours' => null];
        }

        if ($attendance->hasSnapshot()) {
            return ['shift_id' => $attendance->shift_id, 'late_minutes' => $this->workSchedule->snapshotLateMinutes($attendance), 'hours' => null];
        }

        $segment = (int) ($attendance->segment ?: 1);
        $attendance->loadMissing('shift');

        if ($attendance->shift) {
            $hours = $this->workSchedule->hoursForShift($attendance->shift, $worker, $segment);
        } else {
            $hours = $this->workSchedule->resolve($worker, $attendance->date, $segment);
            $offRoster = $this->workSchedule->isOffSchedule($worker, $hours)
                || ($attendance->is_off_schedule && ! $this->workSchedule->hasRosteredShift($worker, $attendance->date));

            if ($offRoster) {
                return ['shift_id' => null, 'late_minutes' => 0, 'hours' => null];
            }
        }

        return [
            'shift_id' => $hours['shift']?->id,
            'late_minutes' => $this->workSchedule->lateMinutes($hours, $attendance->check_in, $attendance->date),
            'hours' => $hours,
        ];
    }

    /**
     * The schedule snapshot of a checked-in row for the working hours it is judged against (null = none).
     * An off-schedule check-in is always a cover; hours taken from the row's own shift on a day the roster
     * gives no shift count as that shift without a roster link. The outlet is the one stored on the row.
     *
     * @param  array<string, mixed>|null  $hours
     * @return array<string, mixed>
     */
    public function snapshotFor(Attendance $attendance, ?array $hours): array
    {
        $worker = $attendance->getWorkerProfile();
        $session = $hours && $worker && $attendance->date ? $this->sessions->sessionFromHours($worker, $hours, $attendance->date) : null;

        $source = match (true) {
            (bool) $attendance->is_off_schedule => 'cover',
            $session?->source === 'cover' => 'default',
            default => null,
        };

        $outletName = $attendance->company_location_id
            ? CompanyLocation::query()->whereKey($attendance->company_location_id)->value('name')
            : null;

        return $this->sessions->snapshotFor($session, $source, $outletName);
    }

    /**
     * Give a checked-in row that has no schedule snapshot yet the one of the hours it is judged against,
     * without judging it again: after an admin moved its times or gave it a check-in. The shift stored on
     * the row decides (linked to the roster row of the date when that names the same shift); without one,
     * the schedule that applies now (and its shift is stored). A row that has a snapshot keeps it.
     *
     * @return bool Whether a snapshot was written.
     */
    public function writeMissingSnapshot(Attendance $attendance): bool
    {
        return DB::transaction(function () use ($attendance): bool {
            $row = Attendance::query()->with(['employee', 'intern', 'freelancer', 'shift'])->whereKey($attendance->getKey())->lockForUpdate()->first();
            $worker = $row?->getWorkerProfile();

            if (! $row || ! $row->check_in || $row->hasSnapshot() || ! $worker) {
                return false;
            }

            $segment = (int) ($row->segment ?: 1);

            if ($row->shift) {
                // The roster row of the date is linked when it names the row's own shift (the backfill rule).
                $rostered = $row->is_off_schedule ? null : $this->workSchedule->resolve($worker, $row->date, $segment);
                $hours = $rostered && $rostered['source'] === 'schedule' && ($rostered['shift']?->id === $row->shift->id)
                    ? $rostered
                    : $this->workSchedule->hoursForShift($row->shift, $worker, $segment);
            } else {
                ['shift_id' => $shiftId, 'hours' => $hours] = $this->judge($row);
                $row->shift_id = $shiftId;
            }

            $row->fillSnapshot($this->snapshotFor($row, $hours))->saveQuietly();
            $attendance->setRawAttributes($row->getAttributes(), true);

            return true;
        });
    }

    /**
     * Judge the row again and save it: the explicit admin or correction operation after the row's own
     * times changed. The row is read again under a lock, so an approval or a check-out running at the same
     * time is not overwritten with stale values. Its shift and schedule snapshot are never rewritten.
     *
     * A row whose status or late minutes an admin set by hand ({@see Attendance::$status_overridden}) keeps
     * that verdict; pass $force when the admin moved its times, which needs a new verdict.
     *
     * @return bool Whether the judged result (lateness, status, early leave) changed.
     */
    public function recalculate(Attendance $attendance, bool $force = false): bool
    {
        return DB::transaction(function () use ($attendance, $force): bool {
            $row = Attendance::query()->with(['employee', 'intern', 'freelancer'])->whereKey($attendance->getKey())->lockForUpdate()->first();

            if (! $row || ! $row->check_in || ! in_array($row->status, self::RECALCULABLE, true)) {
                return false;
            }

            if ($row->status_overridden && ! $force) {
                return false;
            }

            $row->status_overridden = false;

            ['late_minutes' => $lateMinutes] = $this->judge($row);

            $row->check_in_late_minutes = $lateMinutes;

            // A row waiting for approval is not late in reports yet; the approval applies the verdict.
            // When only its check-out waits, the check-in was settled: its verdict is judged anew here
            // and kept as the status the approval or rejection of the check-out brings back.
            if ($row->status !== AttendanceStatus::PendingApproval) {
                $row->late_minutes = $lateMinutes;
                $row->status = $lateMinutes > 0 ? AttendanceStatus::Late : AttendanceStatus::OnTime;
            } elseif ($row->isCheckOutRound()) {
                $row->rejudgeSettledCheckIn($lateMinutes);
            }

            $row->early_leave_minutes = $row->check_out ? $this->workSchedule->earlyLeaveMinutes($row) : null;

            if (! $row->isDirty()) {
                return false;
            }

            $row->save();
            $attendance->setRawAttributes($row->getAttributes(), true);

            return true;
        });
    }
}
