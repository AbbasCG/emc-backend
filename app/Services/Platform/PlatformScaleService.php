<?php

namespace App\Services\Platform;

use App\Models\Course;
use App\Models\Instructor;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class PlatformScaleService
{
    public function snapshot(): array
    {
        return Cache::remember('platform_scale', 900, fn () => array_merge(
            $this->counts(),
            $this->infrastructure(),
        ));
    }

    private function counts(): array
    {
        return [
            'users'         => User::count(),
            'courses'       => Course::count(),
            'instructors'   => Instructor::count(),
            'registrations' => Registration::count(),
        ];
    }

    private function infrastructure(): array
    {
        return array_merge(
            $this->diskMetrics(),
            $this->databaseMetrics(),
            $this->uploadMetrics(),
            $this->queueMetrics(),
        );
    }

    private function diskMetrics(): array
    {
        $path  = storage_path();
        $total = @disk_total_space($path);
        $free  = @disk_free_space($path);

        if ($total === false || $free === false || $total <= 0) {
            return [
                'storage_used_gb'      => null,
                'storage_total_gb'     => null,
                'storage_used_percent' => null,
            ];
        }

        $used = max(0, $total - $free);

        return [
            'storage_used_gb'      => round($used / 1073741824, 2),
            'storage_total_gb'     => round($total / 1073741824, 2),
            'storage_used_percent' => round(($used / $total) * 100, 1),
        ];
    }

    private function databaseMetrics(): array
    {
        try {
            $connection = config('database.default');
            $dbName     = config("database.connections.{$connection}.database");

            if (! $dbName) {
                return ['database_size_gb' => null];
            }

            $row = DB::selectOne(
                'SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024 / 1024, 2) AS size_gb
                 FROM information_schema.tables
                 WHERE table_schema = ?',
                [$dbName]
            );

            return [
                'database_size_gb' => $row?->size_gb !== null ? (float) $row->size_gb : null,
            ];
        } catch (Throwable) {
            return ['database_size_gb' => null];
        }
    }

    private function uploadMetrics(): array
    {
        try {
            return ['uploads_count' => DB::table('file_uploads')->count()];
        } catch (Throwable) {
            return ['uploads_count' => null];
        }
    }

    private function queueMetrics(): array
    {
        try {
            return ['queue_pending' => DB::table('jobs')->count()];
        } catch (Throwable) {
            return ['queue_pending' => null];
        }
    }
}
