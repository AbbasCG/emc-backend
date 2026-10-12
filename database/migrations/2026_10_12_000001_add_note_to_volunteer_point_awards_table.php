<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Impact Points — optional INTERNAL note on a ledger row.
 *
 * Adjustments made by HR/authorized staff carry a required public `reason`
 * (shown to the recipient in their own history) and an optional `note` that is
 * visible only to staff who can view other people's history. It is never
 * serialized on the recipient's own endpoints (see VolunteerPointAward::$hidden).
 *
 * Purely additive: one nullable column on an existing table, no backfill, no
 * index, nothing renamed or dropped. Existing rows simply have NULL. Safe to
 * run against production data and fully reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('volunteer_point_awards', function (Blueprint $table) {
            $table->text('note')->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('volunteer_point_awards', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
