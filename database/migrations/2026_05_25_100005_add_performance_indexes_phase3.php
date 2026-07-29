<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ai_messages.conversation_id
        if (Schema::hasTable('ai_messages') && ! $this->hasIndex('ai_messages', 'ai_messages_conversation_id_index')) {
            Schema::table('ai_messages', function (Blueprint $table) {
                $table->index('conversation_id');
            });
        }

        // ai_conversations.user_id
        if (Schema::hasTable('ai_conversations') && ! $this->hasIndex('ai_conversations', 'ai_conversations_user_id_index')) {
            Schema::table('ai_conversations', function (Blueprint $table) {
                $table->index('user_id');
            });
        }

        // course_materials.course_id
        if (Schema::hasTable('course_materials') && ! $this->hasIndex('course_materials', 'course_materials_course_id_index')) {
            Schema::table('course_materials', function (Blueprint $table) {
                $table->index('course_id');
            });
        }

        // lms_sessions.session_date
        if (Schema::hasTable('lms_sessions') && ! $this->hasIndex('lms_sessions', 'lms_sessions_session_date_index')) {
            Schema::table('lms_sessions', function (Blueprint $table) {
                $table->index('session_date');
            });
        }

        // platform_notifications(user_id, is_read) — composite
        if (Schema::hasTable('platform_notifications') && ! $this->hasIndex('platform_notifications', 'platform_notifications_user_id_is_read_index')) {
            Schema::table('platform_notifications', function (Blueprint $table) {
                $table->index(['user_id', 'is_read']);
            });
        }

        // course_sessions(course_id, status) — composite
        if (Schema::hasTable('course_sessions') && ! $this->hasIndex('course_sessions', 'course_sessions_course_id_status_index')) {
            Schema::table('course_sessions', function (Blueprint $table) {
                $table->index(['course_id', 'status']);
            });
        }

        // course_assignments(course_id, is_visible) — composite
        if (Schema::hasTable('course_assignments') && ! $this->hasIndex('course_assignments', 'course_assignments_course_id_is_visible_index')) {
            Schema::table('course_assignments', function (Blueprint $table) {
                $table->index(['course_id', 'is_visible']);
            });
        }
    }

    public function down(): void
    {
        // Dropping indexes is safe to skip; include for clean rollbacks
        if (Schema::hasTable('ai_messages')) {
            Schema::table('ai_messages', fn (Blueprint $t) => $t->dropIndexIfExists('ai_messages_conversation_id_index'));
        }
        if (Schema::hasTable('ai_conversations')) {
            Schema::table('ai_conversations', fn (Blueprint $t) => $t->dropIndexIfExists('ai_conversations_user_id_index'));
        }
        if (Schema::hasTable('course_materials')) {
            Schema::table('course_materials', fn (Blueprint $t) => $t->dropIndexIfExists('course_materials_course_id_index'));
        }
        if (Schema::hasTable('lms_sessions')) {
            Schema::table('lms_sessions', fn (Blueprint $t) => $t->dropIndexIfExists('lms_sessions_session_date_index'));
        }
        if (Schema::hasTable('platform_notifications')) {
            Schema::table('platform_notifications', fn (Blueprint $t) => $t->dropIndexIfExists('platform_notifications_user_id_is_read_index'));
        }
        if (Schema::hasTable('course_sessions')) {
            Schema::table('course_sessions', fn (Blueprint $t) => $t->dropIndexIfExists('course_sessions_course_id_status_index'));
        }
        if (Schema::hasTable('course_assignments')) {
            Schema::table('course_assignments', fn (Blueprint $t) => $t->dropIndexIfExists('course_assignments_course_id_is_visible_index'));
        }
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        try {
            $driver = DB::getDriverName();
            if ($driver === 'sqlite') {
                $indexes = DB::select("PRAGMA index_list(`{$table}`)");
                foreach ($indexes as $index) {
                    if ($index->name === $indexName) {
                        return true;
                    }
                }
                return false;
            }
            return count(DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName])) > 0;
        } catch (\Throwable) {
            return false;
        }
    }
};
