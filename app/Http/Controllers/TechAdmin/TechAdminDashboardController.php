<?php

namespace App\Http\Controllers\TechAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\LearningPath;
use App\Models\Registration;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;

class TechAdminDashboardController extends Controller
{
    public function dashboard(): JsonResponse
    {
        return response()->json([
            'success'          => true,
            'system'           => $this->systemHealth(),
            'counts'           => $this->counts(),
            'security'         => $this->security(),
            'recent_audit_logs'=> $this->recentAuditLogs(),
        ]);
    }

    // ── System health ────────────────────────────────────────────────────────

    private function systemHealth(): array
    {
        // Database
        $dbStatus = 'ok';
        try {
            DB::select('SELECT 1');
        } catch (Throwable) {
            $dbStatus = 'error';
        }

        // Cache / Redis
        $cacheStatus = 'ok';
        try {
            Cache::put('_tech_health_probe', 1, 5);
            if (Cache::get('_tech_health_probe') !== 1) $cacheStatus = 'degraded';
            Cache::forget('_tech_health_probe');
        } catch (Throwable) {
            $cacheStatus = 'error';
        }

        // Queue (check failed_jobs table)
        $queueStatus = 'ok';
        try {
            $failedCount = DB::table('failed_jobs')->count();
            if ($failedCount > 0) $queueStatus = 'warning';
        } catch (Throwable) {
            $queueStatus = 'unknown';
        }

        // Storage
        $storageStatus = 'ok';
        try {
            Storage::disk('public')->put('_health_probe.tmp', '1');
            Storage::disk('public')->delete('_health_probe.tmp');
        } catch (Throwable) {
            $storageStatus = 'error';
        }

        // Mail — check if a driver is configured (never expose credentials)
        $mailDriver  = config('mail.default', '');
        $mailHost    = config('mail.mailers.smtp.host', '');
        $mailStatus  = (filled($mailDriver) && $mailDriver !== 'log' && filled($mailHost))
            ? 'configured'
            : ($mailDriver === 'log' ? 'log-only' : 'not-configured');

        return [
            'api'      => 'ok',
            'database' => $dbStatus,
            'cache'    => $cacheStatus,
            'queue'    => $queueStatus,
            'storage'  => $storageStatus,
            'mail'     => $mailStatus,
        ];
    }

    // ── Platform counts ──────────────────────────────────────────────────────

    private function counts(): array
    {
        $roles       = 0;
        $permissions = 0;
        try {
            if (class_exists(Role::class)) {
                $roles       = Role::count();
            }
            if (class_exists(\App\Models\Permission::class)) {
                $permissions = \App\Models\Permission::count();
            } elseif (class_exists(\Spatie\Permission\Models\Permission::class)) {
                $permissions = \Spatie\Permission\Models\Permission::count();
            }
        } catch (Throwable) {}

        $auditLogsToday = 0;
        try {
            $auditLogsToday = AuditLog::whereDate('created_at', today())->count();
        } catch (Throwable) {}

        $uploads = 0;
        try {
            $uploads = DB::table('file_uploads')->count();
        } catch (Throwable) {}

        return [
            'users'            => User::count(),
            'active_users'     => User::where('is_active', true)->count(),
            'roles'            => $roles,
            'permissions'      => $permissions,
            'courses'          => Course::count(),
            'learning_paths'   => LearningPath::count(),
            'registrations'    => Registration::count(),
            'audit_logs_today' => $auditLogsToday,
            'uploads'          => $uploads,
        ];
    }

    // ── Security ─────────────────────────────────────────────────────────────

    private function security(): array
    {
        $failedLoginsToday = 0;
        try {
            // Check if a failed_login_logs table exists
            $failedLoginsToday = DB::table('failed_login_logs')
                ->whereDate('created_at', today())
                ->count();
        } catch (Throwable) {
            // Table may not exist
        }

        $fullAccessUsers = User::whereIn('role', ['super_admin', 'tech_admin'])->count();

        $impersonationUsers = 0;
        try {
            $impersonationUsers = User::whereIn('role', ['super_admin', 'tech_admin', 'admin'])->count();
        } catch (Throwable) {}

        return [
            'failed_logins_today'          => $failedLoginsToday,
            'full_access_users'            => $fullAccessUsers,
            'impersonation_permission_users'=> $impersonationUsers,
        ];
    }

    // ── Recent audit logs ────────────────────────────────────────────────────

    private function recentAuditLogs(): array
    {
        try {
            return AuditLog::latest()
                ->limit(10)
                ->get()
                ->map(fn (AuditLog $log) => [
                    'id'          => $log->id,
                    'actor'       => $log->user_name,
                    'actor_role'  => $log->user_role,
                    'action'      => $log->action,
                    'action_meta' => $log->action_meta,
                    'entity_type' => $log->entity_type,
                    'entity_name' => $log->entity_name,
                    'route'       => $log->route,
                    'method'      => $log->method,
                    'created_at'  => $log->created_at,
                ])
                ->toArray();
        } catch (Throwable) {
            return [];
        }
    }
}
