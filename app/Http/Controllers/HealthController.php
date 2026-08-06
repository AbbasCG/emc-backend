<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class HealthController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $start = microtime(true);

        $checks = [
            'database' => $this->checkDatabase(),
            'cache'    => $this->checkCache(),
            'queue'    => $this->checkQueue(),
            'storage'  => $this->checkStorage(),
        ];

        $anyFailed  = collect($checks)->contains(fn ($c) => $c['status'] === 'fail');
        $anyWarn    = collect($checks)->contains(fn ($c) => $c['status'] === 'warn');
        $httpStatus = $anyFailed ? 503 : 200;

        return response()->json([
            'status'         => $anyFailed ? 'fail' : ($anyWarn ? 'warn' : 'ok'),
            'timestamp'      => now()->toIso8601String(),
            'response_ms'    => round((microtime(true) - $start) * 1000, 1),
            'app_version'    => config('app.version', env('APP_VERSION', '1.0.0')),
            'laravel_version'=> app()->version(),
            'php_version'    => PHP_VERSION,
            'env'            => app()->environment(),
            'memory_mb'      => round(memory_get_usage(true) / 1024 / 1024, 1),
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 1),
            'checks'         => $checks,
        ], $httpStatus);
    }

    private function checkDatabase(): array
    {
        try {
            $start = microtime(true);
            DB::select('SELECT 1');
            return [
                'status'  => 'ok',
                'driver'  => config('database.default'),
                'latency' => round((microtime(true) - $start) * 1000, 1) . 'ms',
            ];
        } catch (\Throwable $e) {
            return ['status' => 'fail', 'error' => $e->getMessage()];
        }
    }

    private function checkCache(): array
    {
        try {
            $key   = '_health_' . uniqid();
            $start = microtime(true);
            Cache::put($key, 'ok', 10);
            $val = Cache::get($key);
            Cache::forget($key);

            if ($val !== 'ok') {
                return ['status' => 'fail', 'error' => 'Read-after-write mismatch'];
            }

            return [
                'status'  => 'ok',
                'driver'  => config('cache.default'),
                'latency' => round((microtime(true) - $start) * 1000, 1) . 'ms',
            ];
        } catch (\Throwable $e) {
            return ['status' => 'fail', 'error' => $e->getMessage()];
        }
    }

    private function checkQueue(): array
    {
        $driver = config('queue.default');

        try {
            if ($driver === 'sync') {
                return ['status' => 'warn', 'driver' => 'sync', 'note' => 'sync driver — jobs run inline, not suitable for production'];
            }

            if ($driver === 'database') {
                $pending = DB::table(config('queue.connections.database.table', 'jobs'))->count();
                $failed  = DB::table('failed_jobs')->count();
                return [
                    'status'       => $failed > 0 ? 'warn' : 'ok',
                    'driver'       => 'database',
                    'pending_jobs' => $pending,
                    'failed_jobs'  => $failed,
                ];
            }

            if ($driver === 'redis') {
                \Illuminate\Support\Facades\Redis::ping();
                return ['status' => 'ok', 'driver' => 'redis'];
            }

            return ['status' => 'ok', 'driver' => $driver];
        } catch (\Throwable $e) {
            return ['status' => 'fail', 'driver' => $driver, 'error' => $e->getMessage()];
        }
    }

    private function checkStorage(): array
    {
        try {
            $key   = '_health_check.txt';
            $start = microtime(true);
            Storage::disk('local')->put($key, 'ok');
            $readable = Storage::disk('local')->get($key) === 'ok';
            Storage::disk('local')->delete($key);

            if (! $readable) {
                return ['status' => 'fail', 'error' => 'Read-after-write failed'];
            }

            return [
                'status'  => 'ok',
                'disk'    => config('filesystems.default'),
                'latency' => round((microtime(true) - $start) * 1000, 1) . 'ms',
            ];
        } catch (\Throwable $e) {
            return ['status' => 'fail', 'error' => $e->getMessage()];
        }
    }
}
