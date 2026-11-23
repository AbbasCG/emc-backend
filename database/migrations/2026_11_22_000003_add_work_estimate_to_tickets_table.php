<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planning estimate of a ticket as assigned WORK (additive, nullable, no data change).
 *
 *   task_scope       simple | medium | large | strategic  (same vocabulary as tasks.task_scope)
 *   task_points      the points the assignee's effort is expected to be worth (estimate ONLY: a ticket
 *                    never credits points; that stays the tasks module's job)
 *   estimated_hours  approximate duration in HOURS (the UI converts minutes exactly like the task form)
 *
 * No CHECK constraint on purpose: the rules (scope vocabulary, points range per scope, hours bounds)
 * live in PHP (TicketWorkEstimate) so one implementation serves MariaDB and SQLite alike. Legacy
 * tickets keep NULL; nothing is invented for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tickets')) {
            return;
        }

        Schema::table('tickets', function (Blueprint $table) {
            if (! Schema::hasColumn('tickets', 'task_scope')) {
                $table->string('task_scope', 16)->nullable()->after('priority');
            }
            if (! Schema::hasColumn('tickets', 'task_points')) {
                $table->unsignedInteger('task_points')->nullable()->after('task_scope');
            }
            if (! Schema::hasColumn('tickets', 'estimated_hours')) {
                $table->decimal('estimated_hours', 6, 2)->nullable()->after('task_points');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tickets')) {
            return;
        }

        foreach (['estimated_hours', 'task_points', 'task_scope'] as $column) {
            if (Schema::hasColumn('tickets', $column)) {
                Schema::table('tickets', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
