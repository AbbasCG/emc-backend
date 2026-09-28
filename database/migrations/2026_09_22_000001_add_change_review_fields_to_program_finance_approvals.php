<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Finance previously only ever reviewed a program's FIRST submission, so a
     * single price_snapshot was enough context. Now that edits to an already
     * approved program come back for review, a reviewer needs to know which
     * kind of request they are looking at and exactly what changed — and the
     * system needs to be able to tell whether the thing being approved is
     * still the thing that was submitted.
     *
     * Additive and nullable only: the existing unique(approvable_type,
     * approvable_id) index and every existing row keep working untouched.
     */
    public function up(): void
    {
        Schema::table('program_finance_approvals', function (Blueprint $table) {
            // 'create' = first submission of a new paid program.
            // 'update' = a change to an already-approved program.
            // Existing rows are all first submissions, hence the default.
            $table->string('request_type', 10)->default('create')->after('status');

            // {field: {from: <last approved value>, to: <proposed value>}} —
            // captured at submit time from the real before/after values so the
            // reviewer sees the actual diff rather than re-deriving it from
            // mutable current columns (which drift as the program is edited).
            $table->json('changes_snapshot')->nullable()->after('currency_snapshot');

            // sha256 of the finance-relevant fingerprint at submit time.
            // Re-computed at approve/reject time; a mismatch means the program
            // changed again after submission, so the request is stale.
            $table->string('proposed_hash', 64)->nullable()->after('changes_snapshot');

            $table->index('request_type');
        });
    }

    public function down(): void
    {
        Schema::table('program_finance_approvals', function (Blueprint $table) {
            $table->dropIndex(['request_type']);
            $table->dropColumn(['request_type', 'changes_snapshot', 'proposed_hash']);
        });
    }
};
