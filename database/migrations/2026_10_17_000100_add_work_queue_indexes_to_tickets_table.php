<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the ticket work queue (additive: no column, no data change).
 *
 * The queue filters by status first (open / closed / one or more statuses), sorts by creation or
 * deadline, and the statistics group by status. The table was created with only the primary key,
 * the unique ticket number and the foreign-key indexes, so every list, count and aggregate scanned
 * the whole table.
 *
 * No index here STARTS with a foreign-key column (department_id, assigned_to_id...). On MySQL a
 * foreign key reuses any index that begins with its column and the auto-created one can be
 * dropped silently, which would make down() fail with "needed in a foreign key constraint". The
 * single-column foreign-key indexes already serve "department = X" and "assignee = Y" lookups.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'tickets_status_created_idx');
            $table->index(['status', 'department_id'], 'tickets_status_dept_idx');
            $table->index(['status', 'assigned_to_id'], 'tickets_status_assignee_idx');
            $table->index('expected_resolution_time', 'tickets_due_idx');
            $table->index('resolved_at', 'tickets_resolved_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex('tickets_status_created_idx');
            $table->dropIndex('tickets_status_dept_idx');
            $table->dropIndex('tickets_status_assignee_idx');
            $table->dropIndex('tickets_due_idx');
            $table->dropIndex('tickets_resolved_idx');
        });
    }
};
