<?php

namespace App\Console\Commands;

use App\Services\ApprovalFlowService;
use Illuminate\Console\Command;

class RemindStalledApprovals extends Command
{
    protected $signature = 'approvals:remind-stalled {--days=2 : Days a request may wait on its stage before a reminder}';

    protected $description = 'Remind approvers (and Admins when nobody holds the stage) of requests waiting too long';

    public function handle(ApprovalFlowService $approvals): int
    {
        $reminded = $approvals->remindStalled(max(1, (int) $this->option('days')));

        $this->info("{$reminded} pengajuan diingatkan.");

        return self::SUCCESS;
    }
}
