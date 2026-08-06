<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class ProductionCheckCommand extends Command
{
    protected $signature   = 'emc:production-check {--warn-only : Exit 0 even when failures exist}';
    protected $description = 'Verify all production environment requirements are met.';

    private int $failures = 0;
    private int $warnings = 0;

    public function handle(): int
    {
        $this->newLine();
        $this->line('  <fg=cyan;options=bold>EMC Production Readiness Check</>');
        $this->line('  ' . str_repeat('─', 50));
        $this->newLine();

        $this->checkSection('Application');
        $this->checkEnv('APP_ENV', 'production', critical: true);
        $this->checkEnvFalsy('APP_DEBUG', critical: true);
        $this->checkEnvExists('APP_KEY', critical: true);
        $this->checkEnvExists('APP_URL', critical: true);
        $this->checkEnvExists('FRONTEND_URL', critical: true);

        $this->checkSection('Database');
        $this->checkDbConnection();

        $this->checkSection('Cache & Queue');
        $this->checkCacheConnection();
        $this->checkEnvNotValue('CACHE_STORE', 'array', 'CACHE_STORE=array is not persistent', warn: true);
        $this->checkQueueConnection();
        $this->checkEnvNotValue('QUEUE_CONNECTION', 'sync', 'QUEUE_CONNECTION=sync disables async job processing', warn: true);

        $this->checkSection('Mail');
        $this->checkEnvNotValue('MAIL_MAILER', 'log', 'MAIL_MAILER=log — emails will be written to log only', warn: true);
        $this->checkEnvNotValue('MAIL_MAILER', 'array', 'MAIL_MAILER=array — emails will be discarded', critical: true);
        $this->checkEnvExists('MAIL_FROM_ADDRESS', critical: true);

        $this->checkSection('Storage');
        $this->checkStorageWritable();
        $this->checkEnvExists('FILESYSTEM_DISK');

        $this->checkSection('Payments — Stripe');
        $this->checkEnvExists('STRIPE_KEY', warn: true);
        $this->checkEnvExists('STRIPE_SECRET', warn: true);
        $this->checkEnvExists('STRIPE_WEBHOOK_SECRET', warn: true);

        $this->checkSection('Payments — PayPal');
        $paypalMode = env('PAYPAL_MODE', 'sandbox');
        if ($paypalMode === 'live') {
            $this->checkEnvExists('PAYPAL_LIVE_CLIENT_ID', critical: true);
            $this->checkEnvExists('PAYPAL_LIVE_CLIENT_SECRET', critical: true);
            $this->checkEnvExists('PAYPAL_WEBHOOK_ID', critical: true);
        } else {
            $this->warn("  ⚠  PAYPAL_MODE={$paypalMode} (switch to live for production)");
            $this->warnings++;
        }

        $this->checkSection('Logging');
        $logLevel = strtolower((string) env('LOG_LEVEL', 'debug'));
        if ($logLevel === 'debug') {
            $this->warnLine('LOG_LEVEL=debug — verbose logging enabled; use warning or error in production');
        } else {
            $this->passLine("LOG_LEVEL={$logLevel}");
        }
        $this->checkEnvNotValue('LOG_CHANNEL', 'null', 'LOG_CHANNEL=null — all logs are silenced', critical: true);

        $this->checkSection('Security');
        $this->checkEnvExists('SANCTUM_TOKEN_EXPIRATION');
        $bcrypt = (int) env('BCRYPT_ROUNDS', 10);
        if ($bcrypt < 12) {
            $this->warnLine("BCRYPT_ROUNDS={$bcrypt} (recommend ≥12 for production)");
        } else {
            $this->passLine("BCRYPT_ROUNDS={$bcrypt}");
        }
        $fakePayments = filter_var(env('ALLOW_FAKE_PAYMENTS', false), FILTER_VALIDATE_BOOLEAN);
        if ($fakePayments) {
            $this->failLine('ALLOW_FAKE_PAYMENTS=true — fake payments are accepted; must be disabled in production');
        } else {
            $this->passLine('ALLOW_FAKE_PAYMENTS is disabled');
        }
        $sessionSecure = filter_var(env('SESSION_SECURE_COOKIE', false), FILTER_VALIDATE_BOOLEAN);
        if (! $sessionSecure && env('APP_ENV') === 'production') {
            $this->warnLine('SESSION_SECURE_COOKIE is not true — cookies may be sent over HTTP');
        } else {
            $this->passLine('SESSION_SECURE_COOKIE=' . ($sessionSecure ? 'true' : 'false (non-production)'));
        }

        $this->checkSection('Error Tracking');
        if (env('SENTRY_LARAVEL_DSN')) {
            $this->passLine('SENTRY_LARAVEL_DSN is configured');
        } else {
            $this->warnLine('SENTRY_LARAVEL_DSN not set — errors will only appear in logs');
        }

        $this->newLine();
        $this->line('  ' . str_repeat('─', 50));
        $this->printSummary();

        if ($this->failures > 0 && ! $this->option('warn-only')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    // ─── Checks ──────────────────────────────────────────────────────────────

    private function checkDbConnection(): void
    {
        try {
            DB::select('SELECT 1');
            $driver = config('database.default');
            $this->passLine("Database connection OK ({$driver})");
        } catch (\Throwable $e) {
            $this->failLine('Database connection FAILED: ' . $e->getMessage());
        }
    }

    private function checkCacheConnection(): void
    {
        try {
            $key = '_emc_health_' . uniqid();
            Cache::put($key, 'ok', 5);
            $val = Cache::get($key);
            Cache::forget($key);

            if ($val === 'ok') {
                $store = config('cache.default');
                $this->passLine("Cache read/write OK ({$store})");
            } else {
                $this->failLine('Cache write succeeded but read returned wrong value');
            }
        } catch (\Throwable $e) {
            $this->failLine('Cache connection FAILED: ' . $e->getMessage());
        }
    }

    private function checkQueueConnection(): void
    {
        $driver = config('queue.default');

        try {
            if ($driver === 'database') {
                DB::table(config('queue.connections.database.table', 'jobs'))->count();
                $this->passLine("Queue connection OK (database — jobs table accessible)");
            } elseif ($driver === 'redis') {
                \Illuminate\Support\Facades\Redis::ping();
                $this->passLine('Queue connection OK (redis — ping successful)');
            } elseif ($driver === 'sync') {
                $this->warnLine('Queue driver is sync — jobs run inline (not recommended for production)');
            } else {
                $this->passLine("Queue driver: {$driver} (no connectivity test available)");
            }
        } catch (\Throwable $e) {
            $this->failLine("Queue connection FAILED ({$driver}): " . $e->getMessage());
        }
    }

    private function checkStorageWritable(): void
    {
        try {
            $key = '_emc_health_check.txt';
            Storage::disk('local')->put($key, 'ok');
            $readable = Storage::disk('local')->get($key) === 'ok';
            Storage::disk('local')->delete($key);

            if ($readable) {
                $this->passLine('Local storage is readable/writable');
            } else {
                $this->failLine('Local storage write succeeded but read failed');
            }
        } catch (\Throwable $e) {
            $this->failLine('Local storage is NOT writable: ' . $e->getMessage());
        }
    }

    private function checkEnv(string $key, string $expected, bool $critical = false, bool $warn = false): void
    {
        $value = env($key);
        if ($value === $expected) {
            $this->passLine("{$key}={$expected}");
        } else {
            $message = "{$key}=" . ($value ?? '(not set)') . " (expected {$expected})";
            $critical ? $this->failLine($message) : $this->warnLine($message);
        }
    }

    private function checkEnvFalsy(string $key, bool $critical = false): void
    {
        $raw = env($key);
        $isFalsy = in_array(strtolower((string) $raw), ['false', '0', ''], true);
        if ($isFalsy) {
            $this->passLine("{$key}=false");
        } else {
            $message = "{$key}={$raw} (must be false in production)";
            $critical ? $this->failLine($message) : $this->warnLine($message);
        }
    }

    private function checkEnvExists(string $key, bool $critical = false, bool $warn = false): void
    {
        $value = env($key);
        if (! empty($value)) {
            $this->passLine("{$key} is set");
        } else {
            $message = "{$key} is not set";
            if ($critical) {
                $this->failLine($message);
            } elseif ($warn) {
                $this->warnLine($message);
            } else {
                $this->warnLine($message);
            }
        }
    }

    private function checkEnvNotValue(string $key, string $bad, string $message, bool $critical = false, bool $warn = false): void
    {
        $value = env($key);
        if ($value === $bad) {
            $critical ? $this->failLine($message) : $this->warnLine($message);
        } else {
            $this->passLine("{$key}={$value}");
        }
    }

    // ─── Output helpers ───────────────────────────────────────────────────────

    private function checkSection(string $title): void
    {
        $this->newLine();
        $this->line("  <options=bold>{$title}</>");
    }

    private function passLine(string $message): void
    {
        $this->line("    <fg=green>✔</> {$message}");
    }

    private function warnLine(string $message): void
    {
        $this->line("    <fg=yellow>⚠</> {$message}");
        $this->warnings++;
    }

    private function failLine(string $message): void
    {
        $this->line("    <fg=red>✘</> {$message}");
        $this->failures++;
    }

    private function printSummary(): void
    {
        if ($this->failures === 0 && $this->warnings === 0) {
            $this->line('  <fg=green;options=bold>All checks passed. Ready for production.</>');
        } elseif ($this->failures === 0) {
            $this->line("  <fg=yellow;options=bold>Passed with {$this->warnings} warning(s). Review before going live.</>");
        } else {
            $this->line("  <fg=red;options=bold>{$this->failures} critical failure(s), {$this->warnings} warning(s). NOT ready for production.</>");
        }
        $this->newLine();
    }
}
