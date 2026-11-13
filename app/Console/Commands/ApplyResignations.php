<?php

namespace App\Console\Commands;

use App\Models\Resignation;
use Illuminate\Console\Command;

class ApplyResignations extends Command
{
    protected $signature = 'employees:apply-resignations';

    protected $description = 'Deactivate employees whose approved resignation has passed its last working day';

    public function handle(): int
    {
        $applied = 0;

        Resignation::query()
            ->with('employee.user')
            ->where('status', 'approved')
            ->whereNotNull('last_working_day')
            ->whereDate('last_working_day', '<', today())
            ->whereHas('employee', fn ($employee) => $employee->where('status', '!=', 'inactive'))
            ->each(function (Resignation $resignation) use (&$applied): void {
                $resignation->applyResignation();
                $applied++;
            });

        $this->info("{$applied} pengunduran diri diterapkan.");

        return self::SUCCESS;
    }
}
