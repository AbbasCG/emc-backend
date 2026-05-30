<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(\Illuminate\Foundation\Inspiring::quote());
})->purpose('Display an inspiring quote');

// ─── Production Scheduler ─────────────────────────────────────────────────────
// Run: php artisan schedule:run   (cron: * * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1)

// Prune read notifications older than 90 days — runs at 02:30 every night
Schedule::command('emc:prune-notifications --days=90')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/scheduler.log'));

// Prune failed jobs older than 7 days — runs every Sunday at 03:00
Schedule::command('queue:prune-failed --hours=168')
    ->weeklyOn(0, '03:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler.log'));

// Prune expired Sanctum tokens — daily at 03:30
Schedule::command('sanctum:prune-expired --hours=24')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler.log'));

// Rotate log files — trim laravel.log if it exceeds 100 MB
Schedule::call(function () {
    $logPath = storage_path('logs/laravel.log');
    if (file_exists($logPath) && filesize($logPath) > 100 * 1024 * 1024) {
        file_put_contents($logPath, '');
        \Illuminate\Support\Facades\Log::info('Log file rotated by scheduler (exceeded 100 MB).');
    }
})->dailyAt('04:00')->name('rotate-log')->withoutOverlapping();

// Optional: daily database backup at 01:00 (enable by setting ENABLE_SCHEDULED_BACKUP=true)
if (env('ENABLE_SCHEDULED_BACKUP', false)) {
    Schedule::command('emc:backup --db')
        ->dailyAt('01:00')
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/scheduler.log'));
}
