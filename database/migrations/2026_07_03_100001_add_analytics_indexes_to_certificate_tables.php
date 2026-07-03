<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds compound indexes required by CertificateAnalyticsService queries.
 *
 * The 2026_07_01_400001 migration added single-column indexes (status, issued_at, etc.).
 * Analytics queries combine multiple columns in WHERE clauses, so compound indexes are
 * needed to avoid full-table scans on large datasets.
 *
 * Also adds index on activity_logs for the download analytics join.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── certificates: compound indexes for analytics queries ────────────────
        Schema::table('certificates', function (Blueprint $table) {
            // getOverviewStats(), getTrendStats(): filter by status + date range
            if (! $this->indexExists('certificates', 'cert_status_issued_at_idx')) {
                $table->index(['status', 'issued_at'], 'cert_status_issued_at_idx');
            }
            // getCourseCertificateStats(), getSourceAnalytics(): type + status
            if (! $this->indexExists('certificates', 'cert_type_status_idx')) {
                $table->index(['certificate_type', 'status', 'issued_at'], 'cert_type_status_idx');
            }
            // getApprovalMetrics(): pending approvals
            if (! $this->indexExists('certificates', 'cert_approval_status_created_idx')) {
                $table->index(['approval_status', 'created_at'], 'cert_approval_status_created_idx');
            }
            // Automation duplicate check: findExisting() lockForUpdate()
            // Note: related_type is intentionally excluded — combining it with certificate_type
            // and status (all VARCHAR(255)) would push the composite key past MySQL's 3072-byte
            // index key length limit under utf8mb4 (1071 "Specified key was too long").
            if (! $this->indexExists('certificates', 'cert_automation_dedup_idx')) {
                $table->index(['user_id', 'certificate_type', 'related_id', 'status'], 'cert_automation_dedup_idx');
            }
        });

        // ── activity_logs: index for download analytics ─────────────────────────
        // getTrendStats() and getDownloadMetrics() join activity_logs on entity_type + action
        if (Schema::hasTable('activity_logs') && ! $this->indexExists('activity_logs', 'activity_logs_entity_action_created_idx')) {
            Schema::table('activity_logs', function (Blueprint $table) {
                $table->index(['entity_type', 'action', 'created_at'], 'activity_logs_entity_action_created_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropIndexIfExists('cert_status_issued_at_idx');
            $table->dropIndexIfExists('cert_type_status_idx');
            $table->dropIndexIfExists('cert_approval_status_created_idx');
            $table->dropIndexIfExists('cert_automation_dedup_idx');
        });

        if (Schema::hasTable('activity_logs')) {
            Schema::table('activity_logs', function (Blueprint $table) {
                $table->dropIndexIfExists('activity_logs_entity_action_created_idx');
            });
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        return collect(DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index]))->isNotEmpty();
    }
};
