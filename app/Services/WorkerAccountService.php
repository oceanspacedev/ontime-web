<?php

namespace App\Services;

use App\Enums\SystemRole;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\Resignation;
use App\Models\ShiftSchedule;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;

use function Illuminate\Support\defer;

/**
 * Login accounts and the contract lifecycle of workers: open an account, end a contract (the account
 * is switched off, never deleted) and take the worker back on with the same account.
 */
final class WorkerAccountService
{
    /** Statuses of workers who have left: they may not check in. Any other status still works. */
    public const ENDED_STATUSES = ['inactive', 'resigned', 'completed', 'terminated'];

    /**
     * Days after the last working day in which the nightly employees:apply-resignations still applies an
     * approved resignation. A resignation is applied on approval when its last day has already passed, or
     * by the first nightly run after that day; the window only covers nights the scheduler missed. An
     * employee still active after it was rehired, so the old resignation no longer touches them.
     */
    public const RESIGNATION_APPLY_GRACE_DAYS = 7;

    /** Emails are unique on the whole platform, so the error never says whose account (or Klien) holds it. */
    public const EMAIL_TAKEN_MESSAGE = 'Email login sudah terpakai. Isi email lain.';

    /** Numbers are unique among active workers and accounts of the whole platform, so the error never says whose. */
    public const PHONE_TAKEN_MESSAGE = 'Nomor HP sudah dipakai pekerja atau akun aktif lain. Isi nomor lain.';

    /** An account is linked to at most one employee, intern or freelancer. */
    public const ACCOUNT_LINKED_MESSAGE = 'Akun ini sudah terhubung ke data karyawan, magang, atau freelance lain. Pilih akun lain.';

    /** Roles of a worker account. An account holding only these belongs to a worker profile and nothing else. */
    public const WORKER_ROLES = [SystemRole::Employee->value, SystemRole::Intern->value, SystemRole::Freelancer->value];

    /** Column added by {@see self::withWorkHistoryFlag()}: 1 when the worker has history, 0 when not. */
    public const WORK_HISTORY_FLAG = 'has_work_history';

    /** Shown on check-in when the worker has left (ended status or contract past its end date). */
    public const INACTIVE_MESSAGE = 'Status kerja Anda tidak aktif. Hubungi koordinator atau HR.';

    /**
     * Create a login account for the worker and send the credentials over WhatsApp.
     *
     * @return array{user: User, email: string, password: string, whatsapp: array<string, mixed>}
     *
     * @throws InvalidArgumentException When the email is already used, or the worker's number belongs to another active worker or account.
     */
    public function createAccount(Employee|Intern|Freelancer $worker, ?string $email = null, ?string $role = null, ?string $password = null): array
    {
        $email = Str::lower(trim($email ?: $this->loginEmailFor($worker)));
        $role ??= $this->defaultRole($worker);
        $password ??= Str::password(10, symbols: false);

        // WhatsApp OTP login finds the account by number: a second active account on it locks both people out.
        if ($this->phoneIsTaken($worker->phone, $worker)) {
            throw new InvalidArgumentException(self::PHONE_TAKEN_MESSAGE);
        }

        if (User::query()->where('email', $email)->exists()) {
            throw new InvalidArgumentException(self::EMAIL_TAKEN_MESSAGE);
        }

        try {
            $user = $this->storeAccount($worker, $email, $role, $password);
        } catch (UniqueConstraintViolationException) {
            // Another request took the same email between the check above and the insert.
            throw new InvalidArgumentException(self::EMAIL_TAKEN_MESSAGE);
        }

        $whatsapp = WhatsAppNotificationService::sendAccountCredentials($worker->full_name, $worker->phone, $email, $password);

        return ['user' => $user, 'email' => $email, 'password' => $password, 'whatsapp' => $whatsapp];
    }

    private function storeAccount(Employee|Intern|Freelancer $worker, string $email, string $role, string $password): User
    {
        return DB::transaction(function () use ($worker, $email, $role, $password): User {
            $user = User::create([
                // The account belongs to the Klien of the worker's company.
                'tenant_id' => $worker->company()->value('tenant_id'),
                'name' => $worker->full_name,
                'email' => $email,
                'phone' => $worker->phone,
                'password' => Hash::make($password),
                'is_active' => true,
            ]);

            $user->assignRole(Role::findOrCreate($role, 'web'));
            $worker->update(['user_id' => $user->id]);

            return $user;
        });
    }

    /**
     * End the contract on the given day: the worker stops being active, their account is switched off
     * and signed out (app tokens revoked and, after the response, the phone unlinked from push), and
     * schedules after that day are removed.
     *
     * @param  string  $status  The ended status to record, one of {@see self::ENDED_STATUSES}.
     */
    public function endContract(Intern|Freelancer $worker, ?CarbonInterface $lastDay = null, string $status = 'completed'): void
    {
        $lastDay ??= today();

        if (! in_array($status, self::ENDED_STATUSES, true)) {
            throw new InvalidArgumentException("Status [{$status}] does not end a contract.");
        }

        DB::transaction(function () use ($worker, $lastDay, $status): void {
            $endDate = $worker->end_date && $worker->end_date->lessThan($lastDay) ? $worker->end_date : $lastDay;

            $worker->update(['status' => $status, 'end_date' => $endDate->toDateString()]);

            ShiftSchedule::query()
                ->where($worker instanceof Intern ? 'intern_id' : 'freelancer_id', $worker->getKey())
                ->whereDate('date', '>', $lastDay->toDateString())
                ->delete();

            if ($worker->user) {
                $worker->user->update(['is_active' => false]);
                $worker->user->tokens()->delete();

                $user = $worker->user;
                DB::afterCommit(fn () => defer(fn () => app(OneSignalPushService::class)->forgetUser($user)));
            }
        });
    }

    /**
     * Keep the login account in step with a contract HR edited by hand (status or end date in the edit form).
     *
     * An ended status, or an end date already behind us, ends the contract exactly like {@see self::endContract()}:
     * account switched off, its app tokens revoked and later schedules removed. Moving a worker out of an
     * ended status into a working one switches the same account back on, like {@see self::reactivate()}.
     */
    public function syncContractStatus(Intern|Freelancer $worker, ?string $previousStatus): void
    {
        $hasEndedStatus = in_array($worker->status, self::ENDED_STATUSES, true);
        $isPastEndDate = $worker->end_date !== null && $worker->end_date->lessThan(today());

        if ($hasEndedStatus || $isPastEndDate) {
            $this->endContract(
                $worker,
                $isPastEndDate ? $worker->end_date : today(),
                $hasEndedStatus ? $worker->status : 'completed',
            );

            return;
        }

        if (in_array($previousStatus, self::ENDED_STATUSES, true)) {
            $worker->user?->update(['is_active' => true]);
        }
    }

    /**
     * Whether an approved resignation still belongs to the employee's current employment, so the nightly
     * job may (still) apply it and it blocks filing another one.
     *
     * It does not once the employee was taken back on: they joined again after its last working day, or its
     * last working day lies more than {@see self::RESIGNATION_APPLY_GRACE_DAYS} days back while they are
     * active, which only happens when HR set them active again after it was applied.
     */
    public function resignationIsCurrent(Resignation $resignation): bool
    {
        if ($resignation->status !== 'approved') {
            return false;
        }

        if ($resignation->last_working_day === null) {
            return true;
        }

        $joinDate = $resignation->employee?->join_date;

        if ($joinDate !== null && $joinDate->greaterThan($resignation->last_working_day)) {
            return false;
        }

        return $resignation->last_working_day->greaterThanOrEqualTo(today()->subDays(self::RESIGNATION_APPLY_GRACE_DAYS));
    }

    /**
     * Whether the employee already has a resignation in progress (pending) or one approved for the current
     * employment; a rehired employee whose old resignation was applied may file a new one.
     */
    public function hasOpenResignation(Employee $employee): bool
    {
        return $employee->resignations()
            ->whereIn('status', ['pending', 'approved'])
            ->get()
            ->contains(fn (Resignation $resignation): bool => $resignation->status === 'pending'
                || $this->resignationIsCurrent($resignation->setRelation('employee', $employee)));
    }

    /**
     * Take the worker back on: active again, no end date, and the same account switched back on.
     *
     * @throws InvalidArgumentException When the number was given to another active worker or account while the contract was closed.
     */
    public function reactivate(Intern|Freelancer $worker): void
    {
        if ($this->phoneIsTaken($worker->phone, $worker)) {
            throw new InvalidArgumentException(self::PHONE_TAKEN_MESSAGE);
        }

        DB::transaction(function () use ($worker): void {
            $worker->update(['status' => 'active', 'end_date' => null]);
            $worker->user?->update(['is_active' => true]);
        });
    }

    /**
     * Whether the worker may work on the given day (today by default): the contract or employment has
     * started (start date, or join date for an employee) and has not ended (see {@see self::hasEnded()}).
     */
    public function isActive(Employee|Intern|Freelancer $worker, ?CarbonInterface $workDate = null): bool
    {
        return $this->inactiveReason($worker, $workDate) === null;
    }

    /**
     * Why the worker may not work on the given day (today by default), or null when they may.
     * The message is shown to the worker on check-in, so it says what to do next.
     */
    public function inactiveReason(Employee|Intern|Freelancer $worker, ?CarbonInterface $workDate = null): ?string
    {
        if ($this->hasEnded($worker, $workDate)) {
            return self::INACTIVE_MESSAGE;
        }

        if (! $this->hasStarted($worker, $workDate)) {
            $startDate = $this->startDateOf($worker)->locale('id')->translatedFormat('d F Y');

            return $worker instanceof Employee
                ? "Tanggal bergabung Anda {$startDate}. Absen baru bisa dilakukan mulai tanggal itu."
                : "Kontrak Anda baru mulai {$startDate}. Absen baru bisa dilakukan mulai tanggal itu.";
        }

        return null;
    }

    /**
     * Whether the worker has left: an ended status or, for an intern or freelancer, a contract end date
     * before the given day (even before the nightly close switches the status). A contract that has not
     * started yet has not ended, so it is ended with "Akhiri Kontrak", not taken back on.
     */
    public function hasEnded(Employee|Intern|Freelancer $worker, ?CarbonInterface $workDate = null): bool
    {
        if (in_array($worker->status, self::ENDED_STATUSES, true)) {
            return true;
        }

        $workDate ??= today();

        return ! $worker instanceof Employee && $worker->end_date !== null && $worker->end_date->lessThan($workDate->copy()->startOfDay());
    }

    /**
     * The last day the worker worked (or works): the contract end date of an intern or freelancer, or the last
     * working day of an approved resignation of an employee. Null when no such day is known.
     */
    public function lastWorkingDayOf(Employee|Intern|Freelancer $worker): ?CarbonInterface
    {
        if (! $worker instanceof Employee) {
            return $worker->end_date;
        }

        return $worker->resignations()
            ->where('status', 'approved')
            ->whereNotNull('last_working_day')
            ->latest('last_working_day')
            ->first()
            ?->last_working_day;
    }

    /**
     * Whether a row of the worker dated on or before their last working day may still be checked out after
     * the employment ended: a night shift of the last day closes the next morning, and a contract ended in the
     * middle of the last shift still records its end time.
     */
    public function mayCloseShiftOf(Employee|Intern|Freelancer $worker, CarbonInterface $attendanceDate): bool
    {
        if (! $this->hasStarted($worker, $attendanceDate)) {
            return false;
        }

        $lastDay = $this->lastWorkingDayOf($worker);

        return $lastDay !== null && $attendanceDate->copy()->startOfDay()->lessThanOrEqualTo($lastDay->copy()->startOfDay());
    }

    /**
     * Whether the worker is still in the shift of the given (last) day: a row of that day checked in, not checked
     * out, and still open (a night shift crossing midnight). The nightly close waits for it to be checked out.
     */
    public function hasOpenShiftOn(Employee|Intern|Freelancer $worker, CarbonInterface $date): bool
    {
        return Attendance::query()
            ->where($this->workerColumn($worker), $worker->getKey())
            ->whereDate('date', $date->toDateString())
            ->whereNotNull('check_in')
            ->whereNull('check_out')
            ->where('check_in', '>=', now()->subHours(AttendanceCheckService::OVERNIGHT_OPEN_HOURS))
            ->exists();
    }

    /**
     * Whether the contract (or, for an employee, the employment) has started by the given day. Without a
     * start date the worker counts as started.
     */
    public function hasStarted(Employee|Intern|Freelancer $worker, ?CarbonInterface $workDate = null): bool
    {
        $startDate = $this->startDateOf($worker);
        $workDate ??= today();

        return $startDate === null || $startDate->lessThanOrEqualTo($workDate->copy()->startOfDay());
    }

    /**
     * The first day the worker may work: the join date of an employee, the contract start of an intern or freelancer.
     */
    public function startDateOf(Employee|Intern|Freelancer $worker): ?CarbonInterface
    {
        return $worker instanceof Employee ? $worker->join_date : $worker->start_date;
    }

    /**
     * Why the worker may not be deleted, or null when deleting is safe. A worker with attendance, overtime,
     * leave, correction or resignation history keeps it for payroll and reports: their contract is ended
     * (or the employee set inactive) instead, because deleting would cascade the history away.
     *
     * @param  bool  $useLoadedFlag  Read the flag {@see self::withWorkHistoryFlag()} loaded with the row (a table) instead of querying.
     */
    public function deletionBlockedReason(Employee|Intern|Freelancer $worker, bool $useLoadedFlag = false): ?string
    {
        $hasHistory = $useLoadedFlag && array_key_exists(self::WORK_HISTORY_FLAG, $worker->getAttributes())
            ? (bool) $worker->getAttribute(self::WORK_HISTORY_FLAG)
            : $this->hasWorkHistory($worker);

        if (! $hasHistory) {
            return null;
        }

        return $worker instanceof Employee
            ? 'Karyawan ini sudah punya riwayat absensi, lembur, cuti, koreksi, atau resign yang dipakai untuk laporan gaji. Ubah statusnya menjadi Non-Aktif atau Resign, jangan dihapus.'
            : 'Data ini sudah punya riwayat absensi, lembur, cuti, koreksi, atau resign yang dipakai untuk laporan gaji. Akhiri kontraknya, jangan dihapus.';
    }

    /**
     * Whether any attendance, overtime, leave, correction or resignation row belongs to the worker.
     */
    public function hasWorkHistory(Employee|Intern|Freelancer $worker): bool
    {
        $column = $this->workerColumn($worker);

        foreach ($this->historyModels($worker) as $history) {
            if ($history::query()->where($column, $worker->getKey())->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add the {@see self::WORK_HISTORY_FLAG} column to a workers query (one correlated subquery per history
     * table, no extra round trip), so a table can show the delete guard of every row without querying per row.
     *
     * @template TModel of Employee|Intern|Freelancer
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function withWorkHistoryFlag(Builder $query): Builder
    {
        /** @var Employee|Intern|Freelancer $worker */
        $worker = $query->getModel();
        $column = $this->workerColumn($worker);
        $subqueries = array_map(
            fn (string $history) => $history::query()->toBase()->selectRaw('1')->whereColumn((new $history)->qualifyColumn($column), $worker->getQualifiedKeyName())->limit(1),
            $this->historyModels($worker),
        );

        if ($query->getQuery()->columns === null) {
            $query->select($worker->qualifyColumn('*'));
        }

        return $query->selectRaw(
            'coalesce('.implode(', ', array_map(fn ($subquery): string => "({$subquery->toSql()})", $subqueries)).', 0) as '.self::WORK_HISTORY_FLAG,
            array_merge(...array_map(fn ($subquery): array => $subquery->getBindings(), $subqueries)),
        );
    }

    /**
     * @return list<class-string<Attendance|OvertimeRequest|LeaveRequest|AttendanceCorrection|Resignation>>
     */
    private function historyModels(Employee|Intern|Freelancer $worker): array
    {
        $histories = [Attendance::class, OvertimeRequest::class, LeaveRequest::class, AttendanceCorrection::class];

        // Only employees resign; interns and freelancers end their contract.
        if ($worker instanceof Employee) {
            $histories[] = Resignation::class;
        }

        return $histories;
    }

    /**
     * After a worker without history was deleted: switch off and sign out the account it was linked to,
     * so it no longer holds the phone number. An account that still belongs to another profile, or that
     * also has a non-worker role (an Admin who was also on the payroll), is left alone.
     */
    public function releaseAccountOf(Employee|Intern|Freelancer $worker): void
    {
        $user = $worker->user_id ? User::query()->find($worker->user_id) : null;

        if (! $user || $this->accountLinkBlockedReason($user->getKey()) !== null || $user->roles()->whereNotIn('name', self::WORKER_ROLES)->exists()) {
            return;
        }

        $user->update(['is_active' => false]);
        $user->tokens()->delete();
        DB::afterCommit(fn () => defer(fn () => app(OneSignalPushService::class)->forgetUser($user)));
    }

    /**
     * Mark an employee as left (HR deleted them upstream but they have history) and switch off their
     * worker account like {@see self::releaseAccountOf()} does.
     */
    public function deactivateEmployee(Employee $employee, string $status = 'inactive'): void
    {
        DB::transaction(function () use ($employee, $status): void {
            $employee->update(['status' => $status]);

            $user = $employee->user;

            if (! $user || $user->roles()->whereNotIn('name', self::WORKER_ROLES)->exists()) {
                return;
            }

            $user->update(['is_active' => false]);
            $user->tokens()->delete();
            DB::afterCommit(fn () => defer(fn () => app(OneSignalPushService::class)->forgetUser($user)));
        });
    }

    /**
     * Whether saving the worker as filled in would give them a number another active worker or account
     * holds. Only checked when the save makes them hold the number anew: a new active worker, a changed
     * number, or an ended worker taken back on. An unrelated edit of a worker who already shares a number
     * (data from before this rule) is not blocked, and an ended worker holds no number.
     */
    public function phoneConflicts(Employee|Intern|Freelancer $worker): bool
    {
        if ($this->hasEnded($worker) || blank($worker->phone)) {
            return false;
        }

        if ($worker->exists) {
            $before = $worker->newInstance()->setRawAttributes($worker->getRawOriginal(), true);
            $phoneChanged = self::normalizePhone($worker->phone) !== self::normalizePhone($before->getAttributes()['phone'] ?? null);

            if (! $phoneChanged && ! $this->hasEnded($before)) {
                return false;
            }
        }

        return $this->phoneIsTaken($worker->phone, $worker);
    }

    /**
     * The phone number in the stored form (628...), or null when it holds no digits. Same rule as the
     * models' phone mutator, so "0812...", "62812..." and "+62 812..." are one number.
     */
    public static function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        if ($digits === '' || $digits === null) {
            return null;
        }

        return match (true) {
            str_starts_with($digits, '0') => '62'.substr($digits, 1),
            str_starts_with($digits, '8') => '62'.$digits,
            default => $digits,
        };
    }

    /**
     * Whether the number already belongs to another active worker (employee, intern or freelancer whose
     * contract has not ended) or another active login account anywhere on the platform. WhatsApp OTP
     * login finds the account by number, so a shared number would lock both people out. An account counts
     * only while its worker still works, or when it is not a worker account at all (Admin, PIC).
     *
     * @param  Employee|Intern|Freelancer|null  $ignore  The worker being edited: their own number and account do not count.
     */
    public function phoneIsTaken(?string $phone, Employee|Intern|Freelancer|null $ignore = null): bool
    {
        $normalized = self::normalizePhone($phone);

        if ($normalized === null) {
            return false;
        }

        // Older rows may still hold the local "08..." form.
        $variants = array_values(array_unique(array_filter([
            $normalized,
            str_starts_with($normalized, '62') ? '0'.substr($normalized, 2) : null,
        ])));

        $ignoredKey = fn (string $class): ?int => $ignore instanceof $class && $ignore->exists ? (int) $ignore->getKey() : null;

        $activeEmployee = Employee::query()
            ->whereIn('phone', $variants)
            ->whereNotIn('status', self::ENDED_STATUSES)
            ->when($ignoredKey(Employee::class), fn ($query, int $key) => $query->whereKeyNot($key))
            ->exists();

        if ($activeEmployee) {
            return true;
        }

        foreach ([Intern::class, Freelancer::class] as $contractWorker) {
            $taken = $this->whereContractNotEnded($contractWorker::query()->whereIn('phone', $variants))
                ->when($ignoredKey($contractWorker), fn ($query, int $key) => $query->whereKeyNot($key))
                ->exists();

            if ($taken) {
                return true;
            }
        }

        $ignoredAccount = $ignore?->exists ? $ignore->getOriginal('user_id') : null;

        return User::query()
            ->whereIn('phone', $variants)
            // Same rule as User::canSignIn(): only an account switched off explicitly is inactive.
            ->where(fn ($account) => $account->where('is_active', true)->orWhereNull('is_active'))
            ->when($ignoredAccount, fn ($query, int|string $key) => $query->whereKeyNot($key))
            // An account holds the number for a worker who still works, or for anyone who is not only a worker
            // (an Admin, a PIC). The account of a worker who left (or was deleted) does not: they may come back on it.
            ->where(fn ($account) => $account
                ->whereHas('employee', fn ($employee) => $employee->whereNotIn('status', self::ENDED_STATUSES))
                ->orWhereHas('intern', fn ($intern) => $this->whereContractNotEnded($intern))
                ->orWhereHas('freelancer', fn ($freelancer) => $this->whereContractNotEnded($freelancer))
                ->orWhere(fn ($unlinked) => $unlinked
                    ->whereDoesntHave('employee')
                    ->whereDoesntHave('intern')
                    ->whereDoesntHave('freelancer')
                    // A worker account left behind by a deleted profile is free; any other account is not.
                    ->where(fn ($roles) => $roles
                        ->whereDoesntHave('roles', fn ($role) => $role->whereIn('name', self::WORKER_ROLES))
                        ->orWhereHas('roles', fn ($role) => $role->whereNotIn('name', self::WORKER_ROLES)))))
            ->exists();
    }

    /**
     * @param  Builder<Intern>|Builder<Freelancer>  $query
     * @return Builder<Intern>|Builder<Freelancer>
     */
    private function whereContractNotEnded(Builder $query): Builder
    {
        return $query
            ->whereNotIn('status', self::ENDED_STATUSES)
            ->where(fn ($contract) => $contract->whereNull('end_date')->orWhereDate('end_date', '>=', today()->toDateString()));
    }

    /**
     * Why the account cannot be linked to the worker, or null when it can: one account belongs to at most
     * one worker profile, otherwise a check-in would land on the wrong employee, intern or freelancer row.
     *
     * @param  Employee|Intern|Freelancer|null  $worker  The worker being edited (null while creating).
     */
    public function accountLinkBlockedReason(int|string|null $userId, Employee|Intern|Freelancer|null $worker = null): ?string
    {
        if (blank($userId)) {
            return null;
        }

        foreach ([Employee::class, Intern::class, Freelancer::class] as $profile) {
            $linked = $profile::query()
                ->where('user_id', $userId)
                ->when($worker instanceof $profile && $worker->exists, fn ($query) => $query->whereKeyNot($worker->getKey()))
                ->exists();

            if ($linked) {
                return self::ACCOUNT_LINKED_MESSAGE;
            }
        }

        return null;
    }

    private function workerColumn(Employee|Intern|Freelancer $worker): string
    {
        return match (true) {
            $worker instanceof Employee => 'employee_id',
            $worker instanceof Intern => 'intern_id',
            default => 'freelancer_id',
        };
    }

    /**
     * The email a new account signs in with: the worker's own, else one made from their number.
     *
     * Worker numbers are only unique inside one Klien, so a made-up email already used on the platform
     * gets a numeric suffix ("fl-pfq-0004-2@freelance.local") until it is free.
     */
    public function loginEmailFor(Employee|Intern|Freelancer $worker): string
    {
        if (filled($worker->email)) {
            return Str::lower(trim($worker->email));
        }

        $fallback = Str::lower(trim($this->fallbackEmail($worker)));
        [$localPart, $domain] = explode('@', $fallback, 2);
        $candidate = $fallback;

        for ($suffix = 2; User::query()->where('email', $candidate)->exists(); $suffix++) {
            $candidate = "{$localPart}-{$suffix}@{$domain}";
        }

        return $candidate;
    }

    private function fallbackEmail(Employee|Intern|Freelancer $worker): string
    {
        return match (true) {
            $worker instanceof Employee => Str::lower($worker->nip).'@employee.local',
            $worker instanceof Intern => Str::lower($worker->nis).'@magang.local',
            default => Str::lower($worker->freelancer_number).'@freelance.local',
        };
    }

    private function defaultRole(Employee|Intern|Freelancer $worker): string
    {
        return match (true) {
            $worker instanceof Employee => 'Employee',
            $worker instanceof Intern => 'Intern',
            default => 'Freelancer',
        };
    }
}
