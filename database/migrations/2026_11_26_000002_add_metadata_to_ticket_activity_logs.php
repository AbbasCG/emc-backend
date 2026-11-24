<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured facts about an activity row. Today only the review rows use it: {reviewer_relation: [creator|assignee|submitter],
 * participated: bool, review_round: int}. Nullable and additive: every existing row keeps NULL and every reader copes with it.
 * The model hides the column everywhere except the payloads TicketAccess::redactInternal builds for internal viewers.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ticket_activity_logs') && ! Schema::hasColumn('ticket_activity_logs', 'metadata')) {
            Schema::table('ticket_activity_logs', function (Blueprint $table) {
                $table->json('metadata')->nullable()->after('details');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ticket_activity_logs') && Schema::hasColumn('ticket_activity_logs', 'metadata')) {
            Schema::table('ticket_activity_logs', function (Blueprint $table) {
                $table->dropColumn('metadata');
            });
        }
    }
};
