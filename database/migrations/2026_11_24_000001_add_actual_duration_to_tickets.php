<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ACTUAL approved time of a ticket, in whole minutes (additive, nullable, no backfill).
 *
 *   tickets.actual_minutes    the duration the reviewer enters inside the final approval (the only writer is
 *                             TicketCreditService; never mass assigned)
 *   tickets.credited_minutes  snapshot of the minutes that were credited, written once at approval
 *   ticket_credits.minutes    the same, on the credit row
 *
 * `estimated_hours` stays a planning-only field and is never credited. Tickets credited BEFORE this migration keep
 * their `credited_hours` / `ticket_credits.hours` untouched and have NULL minutes: they are legacy, not errors.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tickets')) {
            Schema::table('tickets', function (Blueprint $table) {
                if (! Schema::hasColumn('tickets', 'actual_minutes')) {
                    $table->unsignedInteger('actual_minutes')->nullable()->after('estimated_hours');
                }
                if (! Schema::hasColumn('tickets', 'credited_minutes')) {
                    $table->unsignedInteger('credited_minutes')->nullable()->after('credited_hours');
                }
            });
        }

        if (Schema::hasTable('ticket_credits') && ! Schema::hasColumn('ticket_credits', 'minutes')) {
            Schema::table('ticket_credits', function (Blueprint $table) {
                $table->unsignedInteger('minutes')->nullable()->after('hours');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ticket_credits') && Schema::hasColumn('ticket_credits', 'minutes')) {
            Schema::table('ticket_credits', function (Blueprint $table) {
                $table->dropColumn('minutes');
            });
        }

        if (Schema::hasTable('tickets')) {
            foreach (['credited_minutes', 'actual_minutes'] as $column) {
                if (Schema::hasColumn('tickets', $column)) {
                    Schema::table('tickets', function (Blueprint $table) use ($column) {
                        $table->dropColumn($column);
                    });
                }
            }
        }
    }
};
