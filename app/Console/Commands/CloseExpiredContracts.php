<?php

namespace App\Console\Commands;

use App\Models\Freelancer;
use App\Models\Intern;
use App\Services\WorkerAccountService;
use Illuminate\Console\Command;

class CloseExpiredContracts extends Command
{
    protected $signature = 'workers:close-expired-contracts';

    protected $description = 'End the contracts of freelancers and interns whose end date has passed';

    public function handle(WorkerAccountService $accounts): int
    {
        $closed = 0;

        foreach ([Freelancer::class, Intern::class] as $model) {
            $model::query()
                ->with('user')
                ->whereNotIn('status', WorkerAccountService::ENDED_STATUSES)
                ->whereNotNull('end_date')
                ->whereDate('end_date', '<', today())
                ->each(function (Freelancer|Intern $worker) use ($accounts, &$closed): void {
                    $accounts->endContract($worker, $worker->end_date);
                    $closed++;
                });
        }

        $this->info("{$closed} kontrak diakhiri.");

        return self::SUCCESS;
    }
}
