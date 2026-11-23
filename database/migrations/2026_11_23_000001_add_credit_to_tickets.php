<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credit of a ticket after the FINAL approval of its completion (additive; no existing row is touched, nothing is
 * backfilled: tickets already RESOLVED / UNRESOLVED / closed before this deploy are never credited).
 *
 *   tickets.credited_points / credited_hours / credited_at / credited_by_id
 *       the snapshot of what was credited, written once at approval (all NULL = never credited)
 *   tickets.submitted_by_id / submitted_at
 *       who submitted the work for review and when (the CURRENT review round), so the reviewer can be required to
 *       differ from the submitter without scanning the activity log; overwritten on a resubmission
 *   ticket_credits
 *       exactly one row per credited ticket (UNIQUE ticket_id): the database-level backstop against a second
 *       credit. It is a NEW table on purpose: volunteer_point_awards is a production ledger that may already hold
 *       duplicates for other sources, so no unique index is added to it.
 *
 * Status needs no change (tickets.status is a plain string(50): PENDING_REVIEW fits).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tickets')) {
            Schema::table('tickets', function (Blueprint $table) {
                if (! Schema::hasColumn('tickets', 'submitted_by_id')) {
                    $table->foreignId('submitted_by_id')->nullable()->after('estimated_hours')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('tickets', 'submitted_at')) {
                    $table->timestamp('submitted_at')->nullable()->after('submitted_by_id');
                }
                if (! Schema::hasColumn('tickets', 'credited_points')) {
                    $table->unsignedInteger('credited_points')->nullable()->after('estimated_hours');
                }
                if (! Schema::hasColumn('tickets', 'credited_hours')) {
                    $table->decimal('credited_hours', 6, 2)->nullable()->after('credited_points');
                }
                if (! Schema::hasColumn('tickets', 'credited_at')) {
                    $table->timestamp('credited_at')->nullable()->after('credited_hours');
                }
                if (! Schema::hasColumn('tickets', 'credited_by_id')) {
                    $table->foreignId('credited_by_id')->nullable()->after('credited_at')->constrained('users')->nullOnDelete();
                }
            });
        }

        if (! Schema::hasTable('ticket_credits')) {
            Schema::create('ticket_credits', function (Blueprint $table) {
                $table->id();
                // RESTRICT: a credited ticket is accounting evidence; hard-deleting it must fail loudly, not
                // silently drop the proof that points were credited.
                $table->foreignId('ticket_id')->constrained('tickets')->restrictOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->unsignedInteger('points');
                $table->decimal('hours', 6, 2)->default(0);
                $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
                // dateTime, not timestamp: on MariaDB a first NOT NULL timestamp silently gets ON UPDATE CURRENT_TIMESTAMP,
                // which would rewrite the credit time on any later update of the row.
                $table->dateTime('credited_at');
                $table->timestamps();

                // Named explicitly so down() and any later DBA work can refer to it. The FK on ticket_id uses this
                // unique index; it is dropped together with the table, after the FK.
                $table->unique('ticket_id', 'ticket_credits_ticket_id_unique');
                $table->index('user_id', 'ticket_credits_user_id_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ticket_credits')) {
            // Dropping the table drops its own FKs and indexes with it.
            Schema::drop('ticket_credits');
        }

        if (Schema::hasTable('tickets')) {
            if (Schema::hasColumn('tickets', 'credited_by_id')) {
                // The FK first (MariaDB refuses to drop a column that is still part of a constraint).
                Schema::table('tickets', function (Blueprint $table) {
                    $table->dropForeign(['credited_by_id']);
                });
                Schema::table('tickets', function (Blueprint $table) {
                    $table->dropColumn('credited_by_id');
                });
            }

            if (Schema::hasColumn('tickets', 'submitted_by_id')) {
                Schema::table('tickets', function (Blueprint $table) {
                    $table->dropForeign(['submitted_by_id']);
                });
                Schema::table('tickets', function (Blueprint $table) {
                    $table->dropColumn('submitted_by_id');
                });
            }

            foreach (['submitted_at', 'credited_at', 'credited_hours', 'credited_points'] as $column) {
                if (Schema::hasColumn('tickets', $column)) {
                    Schema::table('tickets', function (Blueprint $table) use ($column) {
                        $table->dropColumn($column);
                    });
                }
            }
        }
    }
};
