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

// Delete the private files of expired form-response exports (kept 24 hours) — hourly
Schedule::command('forms:purge-exports')
    ->hourly()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler.log'));

// Smart Forms: remind reviewers of responses still open after each form's reminder delay — once a day, at 08:00
Schedule::command('forms:remind-pending')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler.log'));

// Smart Forms: delete responses older than the retention period their form's owner chose (forms without one are never touched)
Schedule::command('forms:purge-responses')
    ->dailyAt('03:15')
    ->withoutOverlapping()
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

// Session reminders — 24h and 1h before upcoming sessions (CourseSession + LmsSession)
Schedule::command('emc:send-session-reminders')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/scheduler.log'));

// Daily: log ended courses (no DB mutation — computed status handles display).
// Add --close-registration to also flip registration_open=false on expired courses.
Schedule::command('courses:mark-ended')
    ->dailyAt('05:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler.log'));

// Archive expired audit logs — runs on the 1st of each month at 04:30
// Logs older than the retention period (default 24 months) are moved to audit_logs_archive.
// Use AUDIT_RETENTION_MONTHS env var to override. Never deletes permanently.
Schedule::command('audit-logs:archive')
    ->monthlyOn(1, '04:30')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/scheduler.log'));

// Optional: daily database backup at 01:00 (enable by setting ENABLE_SCHEDULED_BACKUP=true)
// --prune-days=30 removes backup files older than 30 days after each successful run.
if (env('ENABLE_SCHEDULED_BACKUP', false)) {
    Schedule::command('emc:backup --db --prune-days=30')
        ->dailyAt('01:00')
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/scheduler.log'));
}
