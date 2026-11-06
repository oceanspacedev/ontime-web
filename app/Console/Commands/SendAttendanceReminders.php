<?php

namespace App\Console\Commands;

use App\Services\AttendanceReminderService;
use Illuminate\Console\Command;

class SendAttendanceReminders extends Command
{
    protected $signature = 'attendance:send-reminders';

    protected $description = 'Push shift reminders: shift soon, missing check-in or check-out, and time to leave';

    public function handle(AttendanceReminderService $reminders): int
    {
        $sent = $reminders->sendDue(now());

        $this->info(collect($sent)->map(fn (int $count, string $kind): string => "{$kind}={$count}")->implode(' '));

        return self::SUCCESS;
    }
}
