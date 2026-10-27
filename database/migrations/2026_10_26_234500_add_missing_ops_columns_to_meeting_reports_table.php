<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
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

        try {
            Schema::table('meeting_reports', function (Blueprint $table) {
                $table->index(['department_id', 'meeting_date']);
            });
        } catch (\Throwable) {
            // Index might already exist
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('meeting_reports', function (Blueprint $table) {
            $columnsToDrop = array_filter([
                'department_id', 'title', 'meeting_date', 'achieved',
                'planned', 'needs', 'needs_reason', 'decisions', 'notes', 'created_by',
            ], fn ($col) => Schema::hasColumn('meeting_reports', $col));

            if (! empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
