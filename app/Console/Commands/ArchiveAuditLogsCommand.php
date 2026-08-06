<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ArchiveAuditLogsCommand extends Command
{
    protected $signature = 'audit-logs:archive
                            {--dry-run   : Preview counts without moving records}
                            {--before=   : Archive logs older than this date (YYYY-MM-DD)}
                            {--chunk=    : Records per chunk (default from config)}';

    protected $description = 'Move expired audit logs to audit_logs_archive (never deletes permanently)';

    public function handle(): int
    {
        $isDryRun  = (bool) $this->option('dry-run');
        $chunkSize = (int) ($this->option('chunk') ?: config('audit-logs.archive_chunk', 1000));
        $cutoff    = $this->resolveCutoff();

        if ($isDryRun) {
            $this->warn('[dry-run] No records will be moved.');
        }

        $this->info("Archiving audit logs created before {$cutoff->toDateTimeString()} ...");

        $query = DB::table('audit_logs')->where('created_at', '<', $cutoff);

        $total = $query->count();
        $this->info("Found {$total} logs eligible for archiving.");

        if ($total === 0 || $isDryRun) {
            return self::SUCCESS;
        }

        $moved   = 0;
        $nowTime = now()->toDateTimeString();

        $query->orderBy('id')->chunk($chunkSize, function ($logs) use (&$moved, $nowTime) {
            $rows = $logs->map(fn ($l) => [
                'original_id'         => $l->id,
                'user_id'             => $l->user_id,
                'user_name'           => $l->user_name,
                'user_email'          => $l->user_email ?? null,
                'user_role'           => $l->user_role ?? null,
                'action'              => $l->action,
                'entity_type'         => $l->entity_type,
                'entity_id'           => $l->entity_id ?? null,
                'entity_name'         => $l->entity_name ?? null,
                'description'         => $l->description ?? null,
                'old_values'          => $l->old_values ?? null,
                'new_values'          => $l->new_values ?? null,
                'changed_fields'      => $l->changed_fields ?? null,
                'metadata'            => $l->metadata ?? null,
                'ip_address'          => $l->ip_address ?? null,
                'user_agent'          => $l->user_agent ?? null,
                'route'               => $l->route ?? null,
                'method'              => $l->method ?? null,
                'original_created_at' => $l->created_at,
                'archived_at'         => $nowTime,
            ])->toArray();

            DB::table('audit_logs_archive')->insert($rows);
            DB::table('audit_logs')->whereIn('id', collect($logs)->pluck('id'))->delete();

            $moved += count($rows);
            $this->line("  Moved {$moved} records...");
        });

        $this->info("Done. {$moved} audit logs archived to audit_logs_archive.");

        return self::SUCCESS;
    }

    private function resolveCutoff(): Carbon
    {
        if ($before = $this->option('before')) {
            return Carbon::parse($before)->startOfDay();
        }

        $retentionMonths = (int) config('audit-logs.default_retention_months', 24);

        return now()->subMonths($retentionMonths)->startOfDay();
    }
}
