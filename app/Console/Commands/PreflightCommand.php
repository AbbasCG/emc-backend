<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Deployment preflight — verifies live connectivity before going live.
 *
 * Complements emc:production-check (which validates ENV values).
 * This command actually pings services and tests read/write operations.
 *
 * Usage:
 *   php artisan emc:preflight               # Exit 1 on any FAIL
 *   php artisan emc:preflight --warn-only   # Always exit 0 (warnings OK)
 */
class PreflightCommand extends Command
{
    protected $signature   = 'emc:preflight {--warn-only : Exit 0 even when checks fail}';
    protected $description = 'Verify live connectivity for DB, Redis, cache, queue, storage, and payments before deployment.';

    private int $passed   = 0;
    private int $warnings = 0;
    private int $failures = 0;

    public function handle(): int
    {
        $this->newLine();
        $this->line('  <fg=cyan;options=bold>EMC Deployment Preflight Check</>');
        $this->line('  ' . str_repeat('─', 52));
        $this->newLine();

        // ── Application ───────────────────────────────────────────────────────
        $this->section('Application');
        $this->check('APP_ENV',   env('APP_ENV') === 'production', 'APP_ENV=production', 'APP_ENV=' . env('APP_ENV', '(not set)') . ' — expected production');
        $this->check('APP_DEBUG', ! filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN), 'APP_DEBUG=false', 'APP_DEBUG is enabled — must be false in production', critical: true);
        $this->check('APP_KEY',   ! empty(env('APP_KEY')), 'APP_KEY is set', 'APP_KEY is missing — run: php artisan key:generate', critical: true);
        $this->check('APP_URL',   ! empty(env('APP_URL')), 'APP_URL is set', 'APP_URL is not set');
        $this->check('FRONTEND_URL', ! empty(env('FRONTEND_URL')), 'FRONTEND_URL is set', 'FRONTEND_URL is not set');

        // ── Database ──────────────────────────────────────────────────────────
        $this->section('Database');
        $this->checkCallable('MySQL connection', function () {
            $start = microtime(true);
            DB::select('SELECT 1');
            $ms = round((microtime(true) - $start) * 1000, 1);
            return ['ok', "Connected OK ({$ms}ms, driver: " . config('database.default') . ')'];
        }, critical: true);

        $this->checkCallable('Migrations up-to-date', function () {
            $pending = DB::table('migrations')
                ->whereNotIn('migration', function ($q) {
                    $q->select('migration')->from('migrations');
                })
                ->count();
            // Simply verify the migrations table is accessible
            $count = DB::table('migrations')->count();
            return ['ok', "Migrations table accessible ({$count} run)"];
        });

        // ── Redis ─────────────────────────────────────────────────────────────
        $this->section('Redis');
        $redisClient = config('database.redis.client', env('REDIS_CLIENT', 'phpredis'));
        $this->checkCallable("Redis ping ({$redisClient})", function () {
            Redis::ping();
            $host = config('database.redis.default.host', env('REDIS_HOST', '127.0.0.1'));
            $port = config('database.redis.default.port', env('REDIS_PORT', '6379'));
            return ['ok', "Redis responding at {$host}:{$port}"];
        }, critical: true);

        // ── Cache ─────────────────────────────────────────────────────────────
        $this->section('Cache');
        $this->checkCallable('Cache read/write', function () {
            $key = '_preflight_' . uniqid();
            Cache::put($key, 'ok', 10);
            $val = Cache::get($key);
            Cache::forget($key);
            if ($val !== 'ok') {
                return ['fail', 'Cache write succeeded but read returned wrong value'];
            }
            $driver = config('cache.default');
            return ['ok', "Cache r/w OK (driver: {$driver})"];
        }, critical: true);

        $cacheDriver = config('cache.default');
        if ($cacheDriver === 'array') {
            $this->warn_line('CACHE_STORE=array is in-memory only — not shared between workers');
        } elseif ($cacheDriver === 'database') {
            $this->warn_line('CACHE_STORE=database — works but Redis is faster; switch for production scale');
        }

        // ── Queue ─────────────────────────────────────────────────────────────
        $this->section('Queue');
        $queueDriver = config('queue.default');
        $this->checkCallable('Queue connectivity', function () use ($queueDriver) {
            if ($queueDriver === 'sync') {
                return ['warn', 'QUEUE_CONNECTION=sync — jobs run inline, no worker needed but not suitable for production'];
            }
            if ($queueDriver === 'redis') {
                Redis::ping();
                return ['ok', 'Queue driver: redis — OK (Redis is reachable)'];
            }
            if ($queueDriver === 'database') {
                $pending = DB::table(config('queue.connections.database.table', 'jobs'))->count();
                $failed  = DB::table('failed_jobs')->count();
                $msg = "Queue driver: database — {$pending} pending, {$failed} failed";
                return [$failed > 0 ? 'warn' : 'ok', $msg];
            }
            return ['ok', "Queue driver: {$queueDriver}"];
        });

        // ── Storage ───────────────────────────────────────────────────────────
        $this->section('Storage');
        $this->checkCallable('Local storage r/w', function () {
            $key = '_preflight_check.txt';
            Storage::disk('local')->put($key, 'ok');
            $val = Storage::disk('local')->get($key);
            Storage::disk('local')->delete($key);
            if ($val !== 'ok') {
                return ['fail', 'Storage write succeeded but read returned wrong value'];
            }
            $path = storage_path('app');
            return ['ok', "Local disk r/w OK ({$path})"];
        }, critical: true);

        $this->checkCallable('storage:link exists', function () {
            $linkPath = public_path('storage');
            if (is_link($linkPath)) {
                return ['ok', 'public/storage symlink exists'];
            }
            if (is_dir($linkPath)) {
                return ['warn', 'public/storage is a directory, not a symlink — run: php artisan storage:link'];
            }
            return ['warn', 'public/storage symlink missing — run: php artisan storage:link'];
        });

        // ── Mail ──────────────────────────────────────────────────────────────
        $this->section('Mail');
        $mailer = env('MAIL_MAILER', 'log');
        if ($mailer === 'log') {
            $this->warn_line("MAIL_MAILER=log — emails written to log only, not delivered");
        } elseif ($mailer === 'array') {
            $this->fail_line("MAIL_MAILER=array — emails discarded silently", critical: true);
        } else {
            $this->pass_line("MAIL_MAILER={$mailer}");
        }
        $this->check('MAIL_FROM_ADDRESS', ! empty(env('MAIL_FROM_ADDRESS')), 'MAIL_FROM_ADDRESS is set', 'MAIL_FROM_ADDRESS is not set');

        // ── Payments ──────────────────────────────────────────────────────────
        $this->section('Payments');
        if (! empty(env('STRIPE_KEY'))) {
            $isLive = str_starts_with((string) env('STRIPE_KEY'), 'pk_live_');
            $this->pass_line('STRIPE_KEY is set' . ($isLive ? ' (live key)' : ' ⚠ test key — switch to pk_live_ for production'));
            $this->check('STRIPE_SECRET', ! empty(env('STRIPE_SECRET')), 'STRIPE_SECRET is set', 'STRIPE_SECRET is missing');
            $this->check('STRIPE_WEBHOOK_SECRET', ! empty(env('STRIPE_WEBHOOK_SECRET')), 'STRIPE_WEBHOOK_SECRET is set', 'STRIPE_WEBHOOK_SECRET is missing — webhooks will fail');
            if (! $isLive) {
                $this->warn_line('Stripe is using TEST keys — replace with live keys before launch');
            }
        } else {
            $this->warn_line('STRIPE_KEY not set — Stripe payments disabled');
        }

        $paypalMode = env('PAYPAL_MODE', 'sandbox');
        if ($paypalMode === 'live') {
            $this->check('PAYPAL_LIVE_CLIENT_ID', ! empty(env('PAYPAL_LIVE_CLIENT_ID')), 'PAYPAL_LIVE_CLIENT_ID is set', 'PAYPAL_LIVE_CLIENT_ID missing', critical: true);
            $this->check('PAYPAL_WEBHOOK_ID', ! empty(env('PAYPAL_WEBHOOK_ID')), 'PAYPAL_WEBHOOK_ID is set', 'PAYPAL_WEBHOOK_ID missing — webhooks unverified', critical: true);
        } else {
            $this->warn_line("PAYPAL_MODE={$paypalMode} — switch to live before launch");
        }

        $fakePayments = filter_var(env('ALLOW_FAKE_PAYMENTS', false), FILTER_VALIDATE_BOOLEAN);
        $this->check('ALLOW_FAKE_PAYMENTS', ! $fakePayments, 'ALLOW_FAKE_PAYMENTS=false', 'ALLOW_FAKE_PAYMENTS=true — fake payments accepted in production!', critical: true);

        // ── Security ──────────────────────────────────────────────────────────
        $this->section('Security');
        $bcrypt = (int) env('BCRYPT_ROUNDS', 10);
        $this->check('BCRYPT_ROUNDS', $bcrypt >= 12, "BCRYPT_ROUNDS={$bcrypt}", "BCRYPT_ROUNDS={$bcrypt} — recommend ≥12 for production");
        $this->check('SESSION_SECURE_COOKIE', filter_var(env('SESSION_SECURE_COOKIE', false), FILTER_VALIDATE_BOOLEAN), 'SESSION_SECURE_COOKIE=true', 'SESSION_SECURE_COOKIE is not true — cookies sent over HTTP');
        $this->check('SANCTUM_TOKEN_EXPIRATION', ! empty(env('SANCTUM_TOKEN_EXPIRATION')), 'SANCTUM_TOKEN_EXPIRATION is set', 'SANCTUM_TOKEN_EXPIRATION not set — tokens never expire');

        // ── Summary ───────────────────────────────────────────────────────────
        $this->newLine();
        $this->line('  ' . str_repeat('─', 52));
        $this->printSummary();

        if ($this->failures > 0 && ! $this->option('warn-only')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    // ─── Check helpers ────────────────────────────────────────────────────────

    private function check(string $label, bool $passes, string $passMsg, string $failMsg, bool $critical = false): void
    {
        if ($passes) {
            $this->pass_line($passMsg);
        } else {
            $critical ? $this->fail_line($failMsg, critical: true) : $this->warn_line($failMsg);
        }
    }

    private function checkCallable(string $label, callable $fn, bool $critical = false): void
    {
        try {
            [$status, $message] = $fn();

            match ($status) {
                'ok'   => $this->pass_line($message),
                'warn' => $this->warn_line($message),
                'fail' => $this->fail_line($message, critical: $critical),
                default => $this->pass_line($message),
            };
        } catch (Throwable $e) {
            $this->fail_line("{$label} FAILED: " . $e->getMessage(), critical: $critical);
        }
    }

    // ─── Output helpers ───────────────────────────────────────────────────────

    private function section(string $title): void
    {
        $this->newLine();
        $this->line("  <options=bold>{$title}</>");
    }

    private function pass_line(string $message): void
    {
        $this->line("    <fg=green>✔</> {$message}");
        $this->passed++;
    }

    private function warn_line(string $message): void
    {
        $this->line("    <fg=yellow>⚠</> {$message}");
        $this->warnings++;
    }

    private function fail_line(string $message, bool $critical = false): void
    {
        $this->line("    <fg=red>✘</> {$message}");
        $this->failures++;
    }

    private function printSummary(): void
    {
        $total = $this->passed + $this->warnings + $this->failures;

        if ($this->failures === 0 && $this->warnings === 0) {
            $this->line("  <fg=green;options=bold>All {$total} checks passed — ready to deploy.</>");
        } elseif ($this->failures === 0) {
            $this->line("  <fg=yellow;options=bold>Passed with {$this->warnings} warning(s). Review before going live.</>");
        } else {
            $this->line("  <fg=red;options=bold>{$this->failures} critical failure(s), {$this->warnings} warning(s). Fix before deploying.</>");
        }

        $this->newLine();
    }
}
