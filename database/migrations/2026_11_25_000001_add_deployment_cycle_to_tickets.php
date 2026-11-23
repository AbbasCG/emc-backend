<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The review-and-live-verification cycle of tickets that REQUIRE A DEPLOYMENT (additive, no backfill).
 *
 *   tickets.requires_deployment   the flag a manager sets while the ticket is still being worked on (default false: every
 *                                 existing ticket keeps the old review -> approval -> credit flow)
 *   tickets.review_approved_by_id / review_approved_at   who approved the work for deployment, and when
 *   tickets.deployed_by_id / deployed_at / live_url      who put it on the live site, when (server time) and the page to test
 *   tickets.verified_by_id / verified_at                 the assignee's confirmation on the live site (what finally credits)
 *   tickets.review_round          how many times the work was submitted for review (1 = first round)
 *
 * Status needs no change (tickets.status is a plain string(50): APPROVED_FOR_DEPLOY and PENDING_VERIFICATION fit).
 * The approved values themselves (task_scope, task_points, actual_minutes) reuse the existing columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tickets')) {
            return;
        }

        Schema::table('tickets', function (Blueprint $table) {
            if (! Schema::hasColumn('tickets', 'requires_deployment')) {
                $table->boolean('requires_deployment')->default(false)->after('estimated_hours');
            }
            if (! Schema::hasColumn('tickets', 'review_approved_by_id')) {
                $table->foreignId('review_approved_by_id')->nullable()->after('requires_deployment')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('tickets', 'review_approved_at')) {
                $table->timestamp('review_approved_at')->nullable()->after('review_approved_by_id');
            }
            if (! Schema::hasColumn('tickets', 'deployed_by_id')) {
                $table->foreignId('deployed_by_id')->nullable()->after('review_approved_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('tickets', 'deployed_at')) {
                $table->timestamp('deployed_at')->nullable()->after('deployed_by_id');
            }
            if (! Schema::hasColumn('tickets', 'live_url')) {
                $table->string('live_url', 500)->nullable()->after('deployed_at');
            }
            if (! Schema::hasColumn('tickets', 'verified_by_id')) {
                $table->foreignId('verified_by_id')->nullable()->after('live_url')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('tickets', 'verified_at')) {
                $table->timestamp('verified_at')->nullable()->after('verified_by_id');
            }
            if (! Schema::hasColumn('tickets', 'review_round')) {
                $table->unsignedSmallInteger('review_round')->default(0)->after('verified_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tickets')) {
            return;
        }

        // The foreign keys first (MariaDB refuses to drop a column that is still part of a constraint).
        foreach (['review_approved_by_id', 'deployed_by_id', 'verified_by_id'] as $column) {
            if (Schema::hasColumn('tickets', $column)) {
                Schema::table('tickets', function (Blueprint $table) use ($column) {
                    $table->dropForeign([$column]);
                });
            }
        }

        foreach (['review_round', 'verified_at', 'verified_by_id', 'live_url', 'deployed_at', 'deployed_by_id', 'review_approved_at', 'review_approved_by_id', 'requires_deployment'] as $column) {
            if (Schema::hasColumn('tickets', $column)) {
                Schema::table('tickets', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
