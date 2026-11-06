<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\CompanyLocation;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\LeaveRequest;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Notifications\AttendanceReminderNotification;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Reminders built from the shift that applies on the day, checked against attendance right before
 * sending, so a shift changed at the last minute or a check-in from another device is respected.
 *
 * Only workers on a shift (a dated schedule or a default shift) get reminders; office hours from the
 * company policy have no working days, so they would ring on weekends too.
 */
final class AttendanceReminderService
{
    public const SHIFT_SOON = 'shift_soon';

    public const NOT_CHECKED_IN = 'not_checked_in';

    public const TIME_TO_LEAVE = 'time_to_leave';

    public const FORGOT_CHECK_OUT = 'forgot_check_out';

    public const TEAM_MISSING = 'team_missing';

    /** Minutes before the shift starts for the "shift soon" reminder. */
    public const SOON_MINUTES = 15;

    /** Minutes after the shift ends before "forgot to check out". */
    public const FORGOT_AFTER_MINUTES = 120;

    /** A reminder still goes out this long after it was due (the scheduler runs every 5 minutes). */
    public const SEND_WINDOW_MINUTES = 30;

    private const WORKER_TYPES = [
        'employee' => Employee::class,
        'intern' => Intern::class,
        'freelancer' => Freelancer::class,
    ];

    /**
     * Send the reminders that are due at $now. Returns how many went out per kind.
     *
     * @return array<string, int>
     */
    public function sendDue(CarbonInterface $now): array
    {
        $now = Carbon::parse($now);
        $sent = array_fill_keys([self::SHIFT_SOON, self::NOT_CHECKED_IN, self::TIME_TO_LEAVE, self::FORGOT_CHECK_OUT, self::TEAM_MISSING], 0);
        $missing = [];

        // Yesterday too, for shifts that end after midnight.
        foreach ([$now->copy()->subDay()->startOfDay(), $now->copy()->startOfDay()] as $date) {
            foreach ($this->shiftsOn($date) as $slot) {
                $kind = $this->dueKind($slot, $now);

                if ($kind === null || ! $this->claim($slot['user']->id, $date, $kind)) {
                    continue;
                }

                [$title, $body, $action] = $this->message($kind, $slot);
                Notification::send($slot['user'], new AttendanceReminderNotification($title, $body, [
                    'kind' => 'attendance_reminder',
                    'reminder' => $kind,
                    'action' => $action,
                    'date' => $date->toDateString(),
                ]));
                $sent[$kind]++;

                if ($kind === self::NOT_CHECKED_IN) {
                    $missing[] = $slot;
                }
            }
        }

        $sent[self::TEAM_MISSING] = $this->notifyCoordinators($missing);

        return $sent;
    }

    /**
     * Workers with a shift on the date, their account, and that day's attendance and leave.
     *
     * @return list<array{worker: Model, user: User, date: Carbon, shift: Shift, start: Carbon, end: Carbon, tolerance: int, location: CompanyLocation|null, attendance: Attendance|null, on_leave: bool}>
     */
    private function shiftsOn(Carbon $date): array
    {
        $slots = [];

        foreach (self::WORKER_TYPES as $type => $model) {
            $column = "{$type}_id";
            $schedules = ShiftSchedule::query()
                ->with(['shift', 'location'])
                ->whereNotNull($column)
                ->whereDate('date', $date->toDateString())
                ->get()
                ->keyBy($column);

            $workers = $model::query()
                ->with(['user', 'shift', 'company.policy'])
                ->whereNotNull('user_id')
                ->whereNotIn('status', WorkerAccountService::ENDED_STATUSES)
                ->where(fn ($query) => $query->whereNotNull('shift_id')->orWhereIn('id', $schedules->keys()))
                ->get()
                ->filter(fn (Model $worker): bool => $worker->user !== null && $worker->user->is_active !== false);

            if ($workers->isEmpty()) {
                continue;
            }

            $attendances = Attendance::query()->whereIn($column, $workers->modelKeys())->whereDate('date', $date->toDateString())->get()->keyBy($column);
            $onLeave = LeaveRequest::query()
                ->whereIn($column, $workers->modelKeys())
                ->where('status', 'approved')
                ->whereDate('start_date', '<=', $date->toDateString())
                ->whereDate('end_date', '>=', $date->toDateString())
                ->pluck($column)
                ->flip();

            foreach ($workers as $worker) {
                $schedule = $schedules->get($worker->getKey());

                if ($schedule?->is_day_off) {
                    continue;
                }

                $shift = $schedule?->shift ?? $worker->shift;

                if (! $shift) {
                    continue;
                }

                $start = $date->copy()->setTimeFromTimeString((string) $shift->start_time);
                $end = $date->copy()->setTimeFromTimeString((string) $shift->end_time);

                if ($shift->crossesMidnight()) {
                    $end->addDay();
                }

                $slots[] = [
                    'worker' => $worker,
                    'user' => $worker->user,
                    'date' => $date,
                    'shift' => $shift,
                    'start' => $start,
                    'end' => $end,
                    'tolerance' => (int) ($shift->late_tolerance_minutes ?? $worker->company?->policy?->late_tolerance_minutes ?? 15),
                    'location' => $schedule?->location,
                    'attendance' => $attendances->get($worker->getKey()),
                    'on_leave' => $onLeave->has($worker->getKey()),
                ];
            }
        }

        return $slots;
    }

    /**
     * The one reminder due for this shift right now, if any.
     *
     * @param  array{start: Carbon, end: Carbon, tolerance: int, attendance: Attendance|null, on_leave: bool}  $slot
     */
    private function dueKind(array $slot, Carbon $now): ?string
    {
        if ($slot['on_leave']) {
            return null;
        }

        $checkedIn = $slot['attendance']?->check_in !== null;
        $checkedOut = $slot['attendance']?->check_out !== null;
        // Due from $at until the window closes, or earlier when $until comes first (a "shift soon"
        // reminder makes no sense once the shift has started).
        $due = fn (Carbon $at, ?Carbon $until = null): bool => $now->greaterThanOrEqualTo($at)
            && $now->lessThan(min($at->copy()->addMinutes(self::SEND_WINDOW_MINUTES), $until ?? $at->copy()->addMinutes(self::SEND_WINDOW_MINUTES)));

        return match (true) {
            ! $checkedIn && $due($slot['start']->copy()->subMinutes(self::SOON_MINUTES), $slot['start']) => self::SHIFT_SOON,
            ! $checkedIn && $due($slot['start']->copy()->addMinutes($slot['tolerance'] + 1)) => self::NOT_CHECKED_IN,
            $checkedIn && ! $checkedOut && $due($slot['end']) => self::TIME_TO_LEAVE,
            $checkedIn && ! $checkedOut && $due($slot['end']->copy()->addMinutes(self::FORGOT_AFTER_MINUTES)) => self::FORGOT_CHECK_OUT,
            default => null,
        };
    }

    /**
     * Record the reminder; false when it was already sent, so concurrent runs never send it twice.
     */
    private function claim(int $userId, Carbon $date, string $kind): bool
    {
        return DB::table('attendance_reminders')->insertOrIgnore([
            'user_id' => $userId,
            'date' => $date->toDateString(),
            'kind' => $kind,
            'created_at' => now(),
        ]) === 1;
    }

    /**
     * @param  array{shift: Shift, start: Carbon, end: Carbon, location: CompanyLocation|null}  $slot
     * @return array{0: string, 1: string, 2: string} Title, body, and what the app should open.
     */
    private function message(string $kind, array $slot): array
    {
        $shift = $slot['shift']->name;
        $start = $slot['start']->format('H:i');
        $end = $slot['end']->format('H:i');
        $at = $slot['location'] ? " di {$slot['location']->name}" : '';

        return match ($kind) {
            self::SHIFT_SOON => ["Shift {$shift} mulai {$start}", "Jangan lupa absen masuk{$at}.", 'check_in'],
            self::NOT_CHECKED_IN => ['Anda belum absen masuk', "Shift {$shift} mulai {$start}. Absen sekarang{$at}.", 'check_in'],
            self::TIME_TO_LEAVE => ['Waktunya pulang', "Shift {$shift} selesai {$end}. Jangan lupa absen pulang.", 'check_out'],
            default => ['Anda belum absen pulang', "Shift {$shift} selesai {$end}. Absen pulang sekarang, atau ajukan koreksi bila sudah pulang.", 'check_out'],
        };
    }

    /**
     * One push per coordinator listing the people in their brands who just passed the check-in tolerance.
     *
     * @param  list<array{worker: Model}>  $missing
     */
    private function notifyCoordinators(array $missing): int
    {
        if ($missing === []) {
            return 0;
        }

        $sent = 0;
        $coordinators = User::role('Koordinator')->where('is_active', true)->get()
            ->filter(fn (User $user): bool => DataAccessService::isCoordinator($user));

        foreach ($coordinators as $coordinator) {
            /** @var Collection<int, string> $names */
            $names = collect($missing)
                ->filter(fn (array $slot): bool => $coordinator->canManageWorker($slot['worker']))
                ->map(fn (array $slot): string => (string) $slot['worker']->full_name)
                ->values();

            if ($names->isEmpty()) {
                continue;
            }

            $more = $names->count() > 3 ? ' dan '.($names->count() - 3).' lainnya' : '';
            Notification::send($coordinator, new AttendanceReminderNotification(
                "{$names->count()} orang belum absen masuk",
                $names->take(3)->implode(', ').$more.'.',
                ['kind' => 'team_missing'],
            ));
            $sent++;
        }

        return $sent;
    }
}
