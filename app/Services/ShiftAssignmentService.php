<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\ShiftSchedule;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Change one worker's schedule for one day: a shift (with outlet and section), a day off, or nothing.
 */
final class ShiftAssignmentService
{
    public const MODE_SHIFT = 'shift';

    public const MODE_DAY_OFF = 'off';

    public const MODE_CLEAR = 'clear';

    public function __construct(private readonly SchedulingOptions $options) {}

    /**
     * @return ShiftSchedule|null The saved schedule, or null when it was cleared.
     *
     * @throws AuthorizationException When the worker is outside the user's data access.
     * @throws ValidationException When the shift or outlet is not one the user may pick.
     */
    public function assignDay(
        User $user,
        Employee|Intern|Freelancer $worker,
        string $date,
        string $mode,
        ?int $shiftId = null,
        ?int $locationId = null,
        ?string $section = null,
    ): ?ShiftSchedule {
        if (! $user->canManageWorker($worker)) {
            throw new AuthorizationException('Pekerja ini di luar akses Anda.');
        }

        $column = match (true) {
            $worker instanceof Employee => 'employee_id',
            $worker instanceof Intern => 'intern_id',
            default => 'freelancer_id',
        };
        $schedule = ShiftSchedule::query()->where($column, $worker->getKey())->whereDate('date', $date)->first();

        if ($mode === self::MODE_CLEAR) {
            $schedule?->delete();

            return null;
        }

        $dayOff = $mode === self::MODE_DAY_OFF;

        if (! $dayOff) {
            if (! $this->options->shifts($user, $worker->company_id, borrowing: true)->contains('id', $shiftId)) {
                throw ValidationException::withMessages(['shift_id' => 'Shift ini tidak bisa dipilih untuk pekerja tersebut.']);
            }

            if ($locationId !== null && ! $this->options->locations($user, $worker->company_id, borrowing: true)->contains('id', $locationId)) {
                throw ValidationException::withMessages(['company_location_id' => 'Outlet ini tidak bisa dipilih.']);
            }
        }

        $schedule ??= new ShiftSchedule([$column => $worker->getKey(), 'date' => $date, 'created_by' => $user->id]);
        $schedule->fill([
            'shift_id' => $dayOff ? null : $shiftId,
            'company_location_id' => $dayOff ? null : $locationId,
            'is_day_off' => $dayOff,
            'section' => $dayOff ? null : (trim((string) $section) ?: null),
        ])->save();

        return $schedule;
    }
}
