<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6: Performance — add indexes for common query patterns.
 *
 * Each indexIfExists() call is wrapped in a check so this migration
 * is safe to re-run on fresh installs where not all tables exist yet
 * (e.g. optional modules that were not migrated).
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- users ---
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (! $this->hasIndex('users', 'users_role_index')) {
                    $table->index('role');
                }
            });
        }

        // --- courses ---
        if (Schema::hasTable('courses')) {
            Schema::table('courses', function (Blueprint $table) {
                if (! $this->hasIndex('courses', 'courses_slug_index') && Schema::hasColumn('courses', 'slug')) {
                    $table->index('slug');
                }
                if (! $this->hasIndex('courses', 'courses_status_index') && Schema::hasColumn('courses', 'status')) {
                    $table->index('status');
                }
                if (! $this->hasIndex('courses', 'courses_track_id_index') && Schema::hasColumn('courses', 'track_id')) {
                    $table->index('track_id');
                }
                if (! $this->hasIndex('courses', 'courses_instructor_id_index') && Schema::hasColumn('courses', 'instructor_id')) {
                    $table->index('instructor_id');
                }
            });
        }

        // --- registrations ---
        if (Schema::hasTable('registrations')) {
            Schema::table('registrations', function (Blueprint $table) {
                if (! $this->hasIndex('registrations', 'registrations_status_index')) {
                    $table->index('status');
                }
                if (! $this->hasIndex('registrations', 'registrations_email_index')) {
                    $table->index('email');
                }
                if (! $this->hasIndex('registrations', 'registrations_user_id_index') && Schema::hasColumn('registrations', 'user_id')) {
                    $table->index('user_id');
                }
            });
        }

        // --- payments ---
        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table) {
                if (! $this->hasIndex('payments', 'payments_status_index')) {
                    $table->index('status');
                }
                if (! $this->hasIndex('payments', 'payments_provider_index')) {
                    $table->index('provider');
                }
                if (! $this->hasIndex('payments', 'payments_provider_status_index')) {
                    $table->index(['provider', 'status']);
                }
            });
        }

        // --- certificates ---
        if (Schema::hasTable('certificates')) {
            Schema::table('certificates', function (Blueprint $table) {
                if (! $this->hasIndex('certificates', 'certificates_user_id_index')) {
                    $table->index('user_id');
                }
                if (! $this->hasIndex('certificates', 'certificates_status_index')) {
                    $table->index('status');
                }
                if (! $this->hasIndex('certificates', 'certificates_verification_code_index') && Schema::hasColumn('certificates', 'verification_code')) {
                    $table->index('verification_code');
                }
            });
        }

        // --- lms_sessions ---
        if (Schema::hasTable('lms_sessions')) {
            Schema::table('lms_sessions', function (Blueprint $table) {
                if (! $this->hasIndex('lms_sessions', 'lms_sessions_course_id_index')) {
                    $table->index('course_id');
                }
                if (! $this->hasIndex('lms_sessions', 'lms_sessions_instructor_id_index') && Schema::hasColumn('lms_sessions', 'instructor_id')) {
                    $table->index('instructor_id');
                }
                if (! $this->hasIndex('lms_sessions', 'lms_sessions_status_index') && Schema::hasColumn('lms_sessions', 'status')) {
                    $table->index('status');
                }
            });
        }

        // --- assignments ---
        if (Schema::hasTable('assignments')) {
            Schema::table('assignments', function (Blueprint $table) {
                if (! $this->hasIndex('assignments', 'assignments_course_id_index')) {
                    $table->index('course_id');
                }
                if (! $this->hasIndex('assignments', 'assignments_due_date_index') && Schema::hasColumn('assignments', 'due_date')) {
                    $table->index('due_date');
                }
            });
        }

        // --- tasks ---
        if (Schema::hasTable('tasks')) {
            Schema::table('tasks', function (Blueprint $table) {
                if (! $this->hasIndex('tasks', 'tasks_status_index')) {
                    $table->index('status');
                }
                if (! $this->hasIndex('tasks', 'tasks_assigned_to_index') && Schema::hasColumn('tasks', 'assigned_to')) {
                    $table->index('assigned_to');
                }
                if (! $this->hasIndex('tasks', 'tasks_due_date_index') && Schema::hasColumn('tasks', 'due_date')) {
                    $table->index('due_date');
                }
                if (! $this->hasIndex('tasks', 'tasks_department_id_index') && Schema::hasColumn('tasks', 'department_id')) {
                    $table->index('department_id');
                }
            });
        }

        // --- knowledge_articles ---
        if (Schema::hasTable('knowledge_articles')) {
            Schema::table('knowledge_articles', function (Blueprint $table) {
                if (! $this->hasIndex('knowledge_articles', 'knowledge_articles_slug_index') && Schema::hasColumn('knowledge_articles', 'slug')) {
                    $table->index('slug');
                }
                if (! $this->hasIndex('knowledge_articles', 'knowledge_articles_visibility_index') && Schema::hasColumn('knowledge_articles', 'visibility')) {
                    $table->index('visibility');
                }
                if (! $this->hasIndex('knowledge_articles', 'knowledge_articles_category_id_index')) {
                    $table->index('category_id');
                }
            });
        }

        // --- documents ---
        if (Schema::hasTable('documents')) {
            Schema::table('documents', function (Blueprint $table) {
                if (! $this->hasIndex('documents', 'documents_visibility_index') && Schema::hasColumn('documents', 'visibility')) {
                    $table->index('visibility');
                }
                if (! $this->hasIndex('documents', 'documents_uploaded_by_index') && Schema::hasColumn('documents', 'uploaded_by')) {
                    $table->index('uploaded_by');
                }
            });
        }

        // --- activity_logs ---
        if (Schema::hasTable('activity_logs')) {
            Schema::table('activity_logs', function (Blueprint $table) {
                if (! $this->hasIndex('activity_logs', 'activity_logs_user_id_index')) {
                    $table->index('user_id');
                }
                if (! $this->hasIndex('activity_logs', 'activity_logs_entity_type_index') && Schema::hasColumn('activity_logs', 'entity_type')) {
                    $table->index('entity_type');
                }
                if (! $this->hasIndex('activity_logs', 'activity_logs_action_index')) {
                    $table->index('action');
                }
                if (! $this->hasIndex('activity_logs', 'activity_logs_created_at_index')) {
                    $table->index('created_at');
                }
            });
        }
    }

    public function down(): void
    {
        // Indexes are safe to leave; dropping is optional
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        try {
            $driver = \Illuminate\Support\Facades\DB::getDriverName();
            if ($driver === 'sqlite') {
                $indexes = \Illuminate\Support\Facades\DB::select("PRAGMA index_list(`{$table}`)");
                foreach ($indexes as $index) {
                    if ($index->name === $indexName) {
                        return true;
                    }
                }
                return false;
            }
            $indexes = \Illuminate\Support\Facades\DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);

            return count($indexes) > 0;
        } catch (\Throwable) {
            return false;
        }
    }
};
