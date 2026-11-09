<?php

namespace App\Console\Commands;

use App\Support\IdleApiTokens;
use Illuminate\Console\Command;

class PruneIdleApiTokens extends Command
{
    protected $signature = 'sanctum:prune-idle';

    protected $description = 'Delete API tokens unused for longer than sanctum.idle_expiration';

    public function handle(): int
    {
        $deleted = IdleApiTokens::prune();

        $this->info("Deleted {$deleted} idle API token(s).");

        return self::SUCCESS;
    }
}
