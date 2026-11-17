<?php

namespace App\Console\Commands;

use App\Services\ShiftSwapService;
use Illuminate\Console\Command;

class ExpireShiftSwapRequests extends Command
{
    protected $signature = 'shift-swaps:expire';

    protected $description = 'Expire open shift swap requests whose date has passed';

    /**
     * Scheduled just after midnight. Between runs, lists and the approval inbox already show an overdue
     * request as expired (and keep it out of the inbox) without writing anything.
     */
    public function handle(ShiftSwapService $shiftSwaps): int
    {
        $expired = $shiftSwaps->expireOverdue();

        $this->info("{$expired} pengajuan tukar shift kedaluwarsa.");

        return self::SUCCESS;
    }
}
