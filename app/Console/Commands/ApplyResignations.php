<?php

namespace App\Console\Commands;

use App\Models\Resignation;
use App\Services\WorkerAccountService;
use Illuminate\Console\Command;

class ApplyResignations extends Command
{
    protected $signature = 'employees:apply-resignations';

    protected $description = 'Deactivate employees whose approved resignation has passed its last working day';

    /**
     * Applies each approved resignation once: only within {@see WorkerAccountService::RESIGNATION_APPLY_GRACE_DAYS}
     * days after its last working day and while it still belongs to the current employment, so an employee
     * rehired after an old resignation is not switched off again every night.
     */
    public function handle(WorkerAccountService $workerAccounts): int
    {
        $applied = 0;

        Resignation::query()
            ->with('employee.user')
            ->where('status', 'approved')
            ->whereNotNull('last_working_day')
            ->whereDate('last_working_day', '<', today())
            ->whereDate('last_working_day', '>=', today()->subDays(WorkerAccountService::RESIGNATION_APPLY_GRACE_DAYS))
            ->whereHas('employee', fn ($employee) => $employee->where('status', '!=', 'inactive'))
            ->each(function (Resignation $resignation) use (&$applied, $workerAccounts): void {
                if (! $workerAccounts->resignationIsCurrent($resignation)) {
                    return;
                }

                // Still in the night shift of the last working day: applied by a later run, once checked out.
                if ($workerAccounts->hasOpenShiftOn($resignation->employee, $resignation->last_working_day)) {
                    return;
                }

                $resignation->applyResignation();
                $applied++;
            });

        $this->info("{$applied} pengunduran diri diterapkan.");

        return self::SUCCESS;
    }
}
