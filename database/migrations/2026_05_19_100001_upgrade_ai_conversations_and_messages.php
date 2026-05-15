<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 — add type, visibility, and ai fields to Phase 5 AI tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_conversations', 'type')) {
                $table->string('type')->default('student_assistant')->after('title');
            }
            if (! Schema::hasColumn('ai_conversations', 'visibility')) {
                $table->string('visibility')->default('private')->after('type');
            }
        });

        Schema::table('ai_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_messages', 'token_usage')) {
                $table->unsignedInteger('token_usage')->nullable()->after('metadata');
            }
            if (! Schema::hasColumn('ai_messages', 'model')) {
                $table->string('model')->nullable()->after('token_usage');
            }
        });
    }

    public function down(): void
    {
        // No destructive rollback
    }
};
