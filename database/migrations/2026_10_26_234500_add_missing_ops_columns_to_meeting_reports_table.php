<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A REPAIR migration. Every column below is created by 2026_08_16_000850_create_meeting_reports_table, so on a database
        // migrated normally this does nothing; it only restores the columns on a database that drifted from that history.
        Schema::table('meeting_reports', function (Blueprint $table) {
            if (! Schema::hasColumn('meeting_reports', 'department_id')) {
                $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            }
            if (! Schema::hasColumn('meeting_reports', 'title')) {
                $table->string('title')->nullable();
            }
            if (! Schema::hasColumn('meeting_reports', 'meeting_date')) {
                $table->date('meeting_date')->nullable();
            }
            if (! Schema::hasColumn('meeting_reports', 'achieved')) {
                $table->text('achieved')->nullable();
            }
            if (! Schema::hasColumn('meeting_reports', 'planned')) {
                $table->text('planned')->nullable();
            }
            if (! Schema::hasColumn('meeting_reports', 'needs')) {
                $table->text('needs')->nullable();
            }
            if (! Schema::hasColumn('meeting_reports', 'needs_reason')) {
                $table->text('needs_reason')->nullable();
            }
            if (! Schema::hasColumn('meeting_reports', 'decisions')) {
                $table->text('decisions')->nullable();
            }
            if (! Schema::hasColumn('meeting_reports', 'notes')) {
                $table->text('notes')->nullable();
            }
            if (! Schema::hasColumn('meeting_reports', 'created_by')) {
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            }
        });

        if (! Schema::hasIndex('meeting_reports', ['department_id', 'meeting_date'])) {
            Schema::table('meeting_reports', function (Blueprint $table) {
                $table->index(['department_id', 'meeting_date']);
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty. These columns (and their index) belong to 2026_08_16_000850_create_meeting_reports_table, which
        // owns dropping them; this migration only repairs databases that lacked them. Rolling it back must NEVER drop them:
        // they hold the meeting reports' title, notes, decisions, department and author. Same convention as
        // 2026_08_21_200002_create_meeting_reports_table.
    }
};
