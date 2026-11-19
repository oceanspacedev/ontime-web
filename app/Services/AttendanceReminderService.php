<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
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

        $sessions = app(SessionResolver::class);

        // Yesterday too, for shifts that end after midnight.
        foreach ([$now->copy()->subDay()->startOfDay(), $now->copy()->startOfDay()] as $date) {
            foreach ($this->shiftsOn($date, $sessions) as $slot) {
                $kind = $this->dueKind($slot, $now);

                if ($kind === null || ! $this->claim($slot['user']->id, $date, $this->claimKey($kind, $slot))) {
                    continue;
                }

                [$title, $body, $action] = $this->message($kind, $slot, $now);
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
     * The key a reminder is recorded under, once per user and date: the kind for the first schedule of the
     * day ("shift_soon"), with the part of a split shift ("shift_soon_2") and, for a further schedule of the
     * day, its number ("shift_soon_s2", "shift_soon_2_s2").
     *
     * @param  array{segment: int, sequence: int}  $slot
     */
    private function claimKey(string $kind, array $slot): string
    {
        $key = $slot['segment'] > 1 ? "{$kind}_{$slot['segment']}" : $kind;

        return $slot['sequence'] > 1 ? "{$key}_s{$slot['sequence']}" : $key;
    }

    /**
     * Every shift session on the date ({@see SessionResolver::sessionsFor()}: each part of each schedule, or
     * the default shift) of the workers with an account, with that session's attendance (matched by schedule
     * number and part) and the day's leave. Company hours without a shift get no reminders.
     *
     * @return list<array{worker: Model, user: User, date: Carbon, shift: Shift, segment: int, sequence: int, start: Carbon, end: Carbon, tolerance: int, location: CompanyLocation|null, attendance: Attendance|null, on_leave: bool}>
     */
    private function shiftsOn(Carbon $date, SessionResolver $sessions): array
    {
        $slots = [];

        foreach (self::WORKER_TYPES as $type => $model) {
            $column = "{$type}_id";
            $scheduled = ShiftSchedule::query()
                ->whereNotNull($column)
                ->whereDate('date', $date->toDateString())
                ->distinct()
                ->pluck($column);

            $workers = $model::query()
                ->with(['user.tenant', 'shift', 'company.policy'])
                ->whereNotNull('user_id')
                ->whereNotIn('status', WorkerAccountService::ENDED_STATUSES)
                ->where(fn ($query) => $query->whereNotNull('shift_id')->orWhereIn('id', $scheduled))
                ->get()
                ->filter(fn (Model $worker): bool => $worker->user !== null && self::maySignIn($worker->user));

            if ($workers->isEmpty()) {
                continue;
            }

            // Read once for all workers of this kind, not once per worker (the scheduler runs every 5 minutes).
            $rows = ShiftSchedule::query()
                ->with(['shift', 'location'])
                ->whereIn($column, $workers->modelKeys())
                ->whereDate('date', $date->toDateString())
                ->get()
                ->groupBy($column);
            $sessions->primeRosteredSince($column, $workers->modelKeys());
            $attendances = Attendance::query()->whereIn($column, $workers->modelKeys())->whereDate('date', $date->toDateString())->get()->groupBy($column);
            // The dates a leave charges, as check-in and the reports read them: a date of its range it does
            // not charge (a day off then, rostered since) is a working day like any other.
            $onLeave = LeaveRequest::chargedDatesByWorker($column, $workers->modelKeys(), $date, $date);

            foreach ($workers as $worker) {
                foreach ($sessions->sessionsFor($worker, $date, $rows->get($worker->getKey(), collect())) as $session) {
                    if (! $session->shift) {
                        continue;
                    }

                    $slots[] = [
                        'worker' => $worker,
                        'user' => $worker->user,
                        'date' => $date,
                        'shift' => $session->shift,
                        'segment' => $session->segment,
                        'sequence' => $session->sequence,
                        'start' => Carbon::instance($session->startAt),
                        'end' => Carbon::instance($session->endAt),
                        'tolerance' => $session->toleranceMinutes,
                        'location' => $session->location,
                        'attendance' => $attendances->get($worker->getKey())?->first(
                            fn (Attendance $row): bool => (int) ($row->schedule_sequence ?: 1) === $session->sequence && (int) ($row->segment ?: 1) === $session->segment,
                        ),
                        'on_leave' => isset($onLeave[$worker->getKey()][$date->toDateString()]),
                    ];
                }
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

        // A rejected check-in consumed its session: nothing to check out.
        if ($slot['attendance']?->status === AttendanceStatus::Rejected) {
            return null;
        }

        $checkedIn = $slot['attendance']?->check_in !== null;
        // A check-out the approver rejected cannot be made again: no "time to leave", and the forgotten
        // check-out reminder offers a correction instead ({@see self::message()}).
        $checkOutRejected = $slot['attendance']?->check_out_rejected_at !== null;
        $checkedOut = $slot['attendance']?->check_out !== null;
        // Due from $at until the window closes, or earlier when $until comes first (a "shift soon"
        // reminder makes no sense once the shift has started).
        $due = fn (Carbon $at, ?Carbon $until = null): bool => $now->greaterThanOrEqualTo($at)
            && $now->lessThan(min($at->copy()->addMinutes(self::SEND_WINDOW_MINUTES), $until ?? $at->copy()->addMinutes(self::SEND_WINDOW_MINUTES)));

        return match (true) {
            ! $checkedIn && $due($slot['start']->copy()->subMinutes(self::SOON_MINUTES), $slot['start']) => self::SHIFT_SOON,
            ! $checkedIn && $due($slot['start']->copy()->addMinutes($slot['tolerance'] + 1)) => self::NOT_CHECKED_IN,
            // Only a session that can still be checked out live: a later check-in made it a missing check-out.
            $checkedIn && ! $checkedOut && ! $checkOutRejected && $due($slot['end']) && $this->isLiveClosable($slot) => self::TIME_TO_LEAVE,
            $checkedIn && ! $checkedOut && $due($slot['end']->copy()->addMinutes(self::FORGOT_AFTER_MINUTES)) => self::FORGOT_CHECK_OUT,
            default => null,
        };
    }

    /**
     * Whether the slot's open row can still be checked out live: the worker has not checked in anywhere
     * since (a later check-in turns it into a missing check-out, see
     * {@see AttendanceCheckService::isLiveClosable()}); how long it stays open is the message's concern.
     *
     * @param  array{attendance: Attendance|null}  $slot
     */
    private function isLiveClosable(array $slot): bool
    {
        $row = $slot['attendance'];

        if ($row === null || $row->check_in === null) {
            return false;
        }

        return Attendance::query()
            ->where(array_filter(['employee_id' => $row->employee_id, 'intern_id' => $row->intern_id, 'freelancer_id' => $row->freelancer_id]))
            ->whereKeyNot($row->getKey())
            ->where('check_in', '>', $row->check_in)
            ->where('status', '!=', AttendanceStatus::Rejected->value)
            ->doesntExist();
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
     * A forgotten check-out is still checked out live until the shift's end plus the check-out grace hours
     * ({@see SessionResolver::CHECK_OUT_GRACE_HOURS}), also after midnight. Noticed only on a later day and
     * past that window, it is not checked out any more: the app shows "Kemarin belum absen pulang" with a
     * link to the correction form, and so does the push ("correction" opens the home screen with that notice).
     *
     * A check-out the approver rejected is not offered again: the push asks for a correction.
     *
     * @param  array{shift: Shift, start: Carbon, end: Carbon, location: CompanyLocation|null, attendance: Attendance|null}  $slot
     * @return array{0: string, 1: string, 2: string} Title, body, and what the app should open.
     */
    private function message(string $kind, array $slot, Carbon $now): array
    {
        $shift = $slot['shift']->name;
        $start = $slot['start']->format('H:i');
        $end = $slot['end']->format('H:i');
        $at = $slot['location'] ? " di {$slot['location']->name}" : '';

        if ($kind === self::FORGOT_CHECK_OUT && $slot['attendance']?->check_out_rejected_at !== null) {
            return ['Absen pulang ditolak', "Absen pulang shift {$shift} ditolak atasan. Ajukan koreksi dengan jam pulang yang sebenarnya.", 'correction'];
        }

        $withinGrace = $now->isSameDay($slot['end']) || $now->lessThanOrEqualTo($slot['end']->copy()->addHours(SessionResolver::CHECK_OUT_GRACE_HOURS));
        $closable = $kind === self::FORGOT_CHECK_OUT && $withinGrace && $this->isLiveClosable($slot);

        return match ($kind) {
            self::SHIFT_SOON => ["Shift {$shift} mulai {$start}", "Jangan lupa absen masuk{$at}.", 'check_in'],
            self::NOT_CHECKED_IN => ['Anda belum absen masuk', "Shift {$shift} mulai {$start}. Absen sekarang{$at}.", 'check_in'],
            self::TIME_TO_LEAVE => ['Waktunya pulang', "Shift {$shift} selesai {$end}. Jangan lupa absen pulang.", 'check_out'],
            self::FORGOT_CHECK_OUT => match (true) {
                $withinGrace && $closable => ['Anda belum absen pulang', "Shift {$shift} selesai {$end}. Absen pulang sekarang, atau ajukan koreksi bila sudah pulang.", 'check_out'],
                // Checked in elsewhere since: this session can only be closed by a correction.
                $withinGrace => ['Anda belum absen pulang', "Shift {$shift} selesai {$end}. Ajukan koreksi dengan jam pulang yang sebenarnya.", 'correction'],
                default => ['Kemarin belum absen pulang', "Shift {$shift} selesai {$end}. Ajukan koreksi dengan jam pulang yang sebenarnya. Absen masuk hari ini tetap bisa.", 'correction'],
            },
            default => ['Anda belum absen pulang', "Shift {$shift} selesai {$end}. Absen pulang sekarang, atau ajukan koreksi bila sudah pulang.", 'check_out'],
        };
    }

    /**
     * Same rule as {@see User::canSignIn()}, read from the eager-loaded Klien: a reminder is only worth
     * sending to an account that can open the app and act on it.
     */
    private static function maySignIn(User $user): bool
    {
        return $user->is_active !== false && ($user->tenant_id === null || (bool) $user->tenant?->is_active);
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
        $coordinators = User::role('Koordinator')->with('tenant')->where('is_active', true)->get()
            ->filter(fn (User $user): bool => self::maySignIn($user) && DataAccessService::isCoordinator($user));

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
