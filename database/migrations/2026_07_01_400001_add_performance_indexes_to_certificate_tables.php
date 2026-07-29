<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── certificates ────────────────────────────────────────────────────────
        Schema::table('certificates', function (Blueprint $table) {
            // course_id, track_id, workshop_id, learning_path_id — FK columns get implicit
            // InnoDB indexes, but add explicit ones for compound query patterns.
            if (! $this->indexExists('certificates', 'certificates_course_id_index')) {
                $table->index('course_id');
            }
            if (! $this->indexExists('certificates', 'certificates_workshop_id_index')) {
                $table->index('workshop_id');
            }
            if (! $this->indexExists('certificates', 'certificates_learning_path_id_index')) {
                $table->index('learning_path_id');
            }
            if (! $this->indexExists('certificates', 'certificates_certificate_template_id_index')) {
                $table->index('certificate_template_id');
            }
            if (! $this->indexExists('certificates', 'certificates_approval_status_index')) {
                $table->index('approval_status');
            }
            if (! $this->indexExists('certificates', 'certificates_issued_at_index')) {
                $table->index('issued_at');
            }
            if (! $this->indexExists('certificates', 'certificates_batch_id_index')) {
                $table->index('batch_id');
            }
            if (! $this->indexExists('certificates', 'certificates_issued_by_index')) {
                $table->index('issued_by');
            }
            // Composite: list queries filter by user_id + status constantly
            if (! $this->indexExists('certificates', 'certificates_user_status_index')) {
                $table->index(['user_id', 'status'], 'certificates_user_status_index');
            }
            // Composite: admin list filters by status + approval_status
            if (! $this->indexExists('certificates', 'certificates_status_approval_index')) {
                $table->index(['status', 'approval_status'], 'certificates_status_approval_index');
            }
        });

        // ── certificate_templates ───────────────────────────────────────────────
        Schema::table('certificate_templates', function (Blueprint $table) {
            if (! $this->indexExists('certificate_templates', 'certificate_templates_is_default_index')) {
                $table->index('is_default');
            }
            if (! $this->indexExists('certificate_templates', 'certificate_templates_is_active_index')) {
                $table->index('is_active');
            }
            if (! $this->indexExists('certificate_templates', 'certificate_templates_designer_mode_index')) {
                $table->index('designer_mode');
            }
            // Composite: resolveTemplate() always queries is_default + is_active together
            if (! $this->indexExists('certificate_templates', 'certificate_templates_default_active_index')) {
                $table->index(['is_default', 'is_active'], 'certificate_templates_default_active_index');
            }
        });

        // ── certificate_approvals ───────────────────────────────────────────────
        Schema::table('certificate_approvals', function (Blueprint $table) {
            if (! $this->indexExists('certificate_approvals', 'certificate_approvals_approved_by_index')) {
                $table->index('approved_by');
            }
            if (! $this->indexExists('certificate_approvals', 'certificate_approvals_status_index')) {
                $table->index('status');
            }
        });

        // ── certificate_generation_batches ──────────────────────────────────────
        Schema::table('certificate_generation_batches', function (Blueprint $table) {
            if (! $this->indexExists('certificate_generation_batches', 'certificate_generation_batches_status_index')) {
                $table->index('status');
            }
            if (! $this->indexExists('certificate_generation_batches', 'certificate_generation_batches_created_by_index')) {
                $table->index('created_by');
            }
            if (! $this->indexExists('certificate_generation_batches', 'certificate_generation_batches_created_at_index')) {
                $table->index('created_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropIndexIfExists('certificates_course_id_index');
            $table->dropIndexIfExists('certificates_workshop_id_index');
            $table->dropIndexIfExists('certificates_learning_path_id_index');
            $table->dropIndexIfExists('certificates_certificate_template_id_index');
            $table->dropIndexIfExists('certificates_approval_status_index');
            $table->dropIndexIfExists('certificates_issued_at_index');
            $table->dropIndexIfExists('certificates_batch_id_index');
            $table->dropIndexIfExists('certificates_issued_by_index');
            $table->dropIndexIfExists('certificates_user_status_index');
            $table->dropIndexIfExists('certificates_status_approval_index');
        });

        Schema::table('certificate_templates', function (Blueprint $table) {
            $table->dropIndexIfExists('certificate_templates_is_default_index');
            $table->dropIndexIfExists('certificate_templates_is_active_index');
            $table->dropIndexIfExists('certificate_templates_designer_mode_index');
            $table->dropIndexIfExists('certificate_templates_default_active_index');
        });

        Schema::table('certificate_approvals', function (Blueprint $table) {
            $table->dropIndexIfExists('certificate_approvals_approved_by_index');
            $table->dropIndexIfExists('certificate_approvals_status_index');
        });

        Schema::table('certificate_generation_batches', function (Blueprint $table) {
            $table->dropIndexIfExists('certificate_generation_batches_status_index');
            $table->dropIndexIfExists('certificate_generation_batches_created_by_index');
            $table->dropIndexIfExists('certificate_generation_batches_created_at_index');
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        try {
            $driver = \Illuminate\Support\Facades\DB::getDriverName();
            if ($driver === 'sqlite') {
                $indexes = \Illuminate\Support\Facades\DB::select("PRAGMA index_list(`{$table}`)");
                foreach ($indexes as $i) {
                    if ($i->name === $index) {
                        return true;
                    }
                }
                return false;
            }
            return collect(\Illuminate\Support\Facades\DB::select(
                "SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index]
            ))->isNotEmpty();
        } catch (\Throwable) {
            return false;
        }
    }
};
