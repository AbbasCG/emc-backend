<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

class HealthController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'cache'    => $this->checkCache(),
            'queue'    => $this->checkQueue(),
            'storage'  => $this->checkStorage(),
        ];

        $allOk      = collect($checks)->every(fn ($c) => $c['status'] === 'ok');
        $httpStatus = $allOk ? 200 : 503;

        return response()->json([
            'status'    => $allOk ? 'ok' : 'degraded',
            'timestamp' => now()->toIso8601String(),
            'version'   => env('APP_VERSION', '1.0.0'),
            'env'       => app()->environment(),
            'checks'    => $checks,
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
                return ['status' => 'ok', 'driver' => 'sync', 'note' => 'sync driver — jobs run inline'];
            }

            if ($driver === 'database') {
                $pending = DB::table(config('queue.connections.database.table', 'jobs'))->count();
                $failed  = DB::table('failed_jobs')->count();
                return [
                    'status'         => 'ok',
                    'driver'         => 'database',
                    'pending_jobs'   => $pending,
                    'failed_jobs'    => $failed,
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
