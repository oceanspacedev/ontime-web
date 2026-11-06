<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\ShiftSchedule;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;

/**
 * Login accounts and the contract lifecycle of workers: open an account, end a contract (the account
 * is switched off, never deleted) and take the worker back on with the same account.
 */
final class WorkerAccountService
{
    /** Statuses of workers who have left: they may not check in. Any other status still works. */
    public const ENDED_STATUSES = ['inactive', 'resigned', 'completed', 'terminated'];

    /**
     * Create a login account for the worker and send the credentials over WhatsApp.
     *
     * @return array{user: User, email: string, password: string, whatsapp: array<string, mixed>}
     *
     * @throws InvalidArgumentException When the email is already used.
     */
    public function createAccount(Employee|Intern|Freelancer $worker, ?string $email = null, ?string $role = null, ?string $password = null): array
    {
        $email = Str::lower(trim($email ?: ($worker->email ?: $this->fallbackEmail($worker))));
        $role ??= $this->defaultRole($worker);
        $password ??= Str::password(10, symbols: false);

        if (User::query()->where('email', $email)->exists()) {
            throw new InvalidArgumentException("Email {$email} sudah dipakai akun lain.");
        }

        $user = DB::transaction(function () use ($worker, $email, $role, $password): User {
            $user = User::create([
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

        $whatsapp = WhatsAppNotificationService::sendAccountCredentials($worker->full_name, $worker->phone, $email, $password, $role);

        return ['user' => $user, 'email' => $email, 'password' => $password, 'whatsapp' => $whatsapp];
    }

    /**
     * End the contract on the given day: the worker stops being active, their account is switched off
     * and signed out, and schedules after that day are removed.
     */
    public function endContract(Intern|Freelancer $worker, ?CarbonInterface $lastDay = null): void
    {
        $lastDay ??= today();

        DB::transaction(function () use ($worker, $lastDay): void {
            $endDate = $worker->end_date && $worker->end_date->lessThan($lastDay) ? $worker->end_date : $lastDay;

            $worker->update(['status' => 'completed', 'end_date' => $endDate->toDateString()]);

            ShiftSchedule::query()
                ->where($worker instanceof Intern ? 'intern_id' : 'freelancer_id', $worker->getKey())
                ->whereDate('date', '>', $lastDay->toDateString())
                ->delete();

            if ($worker->user) {
                $worker->user->update(['is_active' => false]);
                $worker->user->tokens()->delete();
            }
        });
    }

    /**
     * Take the worker back on: active again, no end date, and the same account switched back on.
     */
    public function reactivate(Intern|Freelancer $worker): void
    {
        DB::transaction(function () use ($worker): void {
            $worker->update(['status' => 'active', 'end_date' => null]);
            $worker->user?->update(['is_active' => true]);
        });
    }

    public function isActive(Employee|Intern|Freelancer $worker): bool
    {
        return ! in_array($worker->status, self::ENDED_STATUSES, true);
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
