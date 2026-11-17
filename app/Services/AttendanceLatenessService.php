<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\ShiftSchedule;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Judges an attendance row again against the schedule that applies now: its shift, its lateness
 * (status on_time/late, or the check-in snapshot of a row waiting for approval) and its early leave.
 *
 * Used whenever the facts behind the judgement change after the check-in: the roster of that date is
 * created, changed or removed ({@see ShiftSchedule} events), or an admin moves the check-in or check-out.
 * Rejected, leave and other non-working rows are never touched.
 */
class AttendanceLatenessService
{
    /** Statuses of a worked day whose lateness can be judged again. */
    private const RECALCULABLE = [AttendanceStatus::OnTime, AttendanceStatus::Late, AttendanceStatus::PendingApproval];

    private static bool $paused = false;

    public function __construct(private readonly WorkScheduleService $workSchedule) {}

    /**
     * Run the callback without recalculating attendance on roster changes, for a schedule written as a
     * consequence of a decision already taken on that attendance (for example an approved off-schedule check-in).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withoutRosterRecalculation(callable $callback): mixed
    {
        $previous = self::$paused;
        self::$paused = true;

        try {
            return $callback();
        } finally {
            self::$paused = $previous;
        }
    }

    public static function rosterRecalculationPaused(): bool
    {
        return self::$paused;
    }

    /**
     * The shift and lateness of the row's check-in under the schedule that applies now.
     *
     * When the roster says the worker is off that day (a day off, or a freelancer without a shift), the
     * row's own shift is kept (the cover shift of an off-schedule check-in, or the shift checked in to);
     * without one there is nothing to be late for. An off-schedule check-in is only judged against the
     * roster once that date's schedule names a real shift (the approved cover shift, or one the PIC set);
     * never against the worker's default shift or the company hours it was not called in for.
     *
     * @return array{shift_id: int|null, late_minutes: int}
     */
    public function judge(Attendance $attendance): array
    {
        $worker = $attendance->getWorkerProfile();

        if (! $worker || ! $attendance->check_in || ! $attendance->date) {
            return ['shift_id' => $attendance->shift_id, 'late_minutes' => 0];
        }

        $segment = (int) ($attendance->segment ?: 1);
        $hours = $this->workSchedule->resolve($worker, $attendance->date, $segment);
        $offRoster = $this->workSchedule->isOffSchedule($worker, $hours)
            || ($attendance->is_off_schedule && ! $this->workSchedule->hasRosteredShift($worker, $attendance->date));

        if ($offRoster) {
            $attendance->loadMissing('shift');

            if (! $attendance->shift) {
                return ['shift_id' => null, 'late_minutes' => 0];
            }

            $hours = $this->workSchedule->hoursForShift($attendance->shift, $worker, $segment);
        }

        return [
            'shift_id' => $hours['shift']?->id,
            'late_minutes' => $this->workSchedule->lateMinutes($hours, $attendance->check_in, $attendance->date),
        ];
    }

    /**
     * Judge the row again and save it. The row is read again under a lock, so an approval or a check-out
     * running at the same time is not overwritten with stale values.
     *
     * A row whose status or late minutes an admin set by hand ({@see Attendance::$status_overridden}) keeps
     * that verdict on a roster change; pass $force when the admin moved its times, which needs a new verdict.
     *
     * @return bool Whether the row changed.
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

            ['shift_id' => $shiftId, 'late_minutes' => $lateMinutes] = $this->judge($row);

            $row->shift_id = $shiftId;
            $row->unsetRelation('shift');
            $row->check_in_late_minutes = $lateMinutes;

            // A row waiting for approval is not late in reports yet; the approval applies the snapshot.
            if ($row->status !== AttendanceStatus::PendingApproval) {
                $row->late_minutes = $lateMinutes;
                $row->status = $lateMinutes > 0 ? AttendanceStatus::Late : AttendanceStatus::OnTime;
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

    /**
     * Recalculate every row of one worker on one date (both segments of a split shift).
     *
     * @return int The number of rows that changed.
     */
    public function recalculateWorkerDate(string $workerColumn, int $workerId, CarbonInterface|string $date): int
    {
        if (self::$paused) {
            return 0;
        }

        $date = $date instanceof CarbonInterface ? $date->toDateString() : substr($date, 0, 10);

        return Attendance::query()
            ->where($workerColumn, $workerId)
            ->whereDate('date', $date)
            ->whereNotNull('check_in')
            ->orderBy('segment')
            ->get()
            ->filter(fn (Attendance $row): bool => $this->recalculate($row))
            ->count();
    }

    /**
     * Recalculate the attendance a roster change affects: the worker on the schedule's date, and on its
     * previous date when the date (or the worker) itself moved.
     */
    public function recalculateForSchedule(ShiftSchedule $schedule, bool $deleted = false): void
    {
        if (self::$paused) {
            return;
        }

        $targets = [];

        foreach (['employee_id', 'intern_id', 'freelancer_id'] as $column) {
            if ($schedule->{$column}) {
                $targets[] = [$column, (int) $schedule->{$column}, $schedule->date];
            }

            if (! $deleted && $schedule->getOriginal($column) && ($schedule->wasChanged($column) || $schedule->wasChanged('date'))) {
                $targets[] = [$column, (int) $schedule->getOriginal($column), $schedule->getOriginal('date')];
            }
        }

        foreach ($targets as [$column, $workerId, $date]) {
            if ($date) {
                $this->recalculateWorkerDate($column, $workerId, $date);
            }
        }
    }
}
