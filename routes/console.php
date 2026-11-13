<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('sanctum:prune-idle')->daily();
Schedule::command('workers:close-expired-contracts')->dailyAt('00:15');
Schedule::command('employees:apply-resignations')->dailyAt('00:20');
Schedule::command('approvals:remind-stalled')->dailyAt('08:00');
Schedule::command('attendance:send-reminders')->everyFiveMinutes()->withoutOverlapping();
