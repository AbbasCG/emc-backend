<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a volunteer application to the published opportunity it was submitted for.
 *
 * The opportunity already points to its originating incoming request, so that chain
 * (incoming request -> opportunity -> application) is the authoritative trace; nothing is
 * inferred from text. `opportunity_id` is nullable, so every existing application and every
 * general (non-opportunity) application is untouched. The unique pair (opportunity_id, email)
 * is the database-level guard against a second application to the same opportunity, including
 * under concurrent submissions; rows without an opportunity are exempt (NULLs never collide).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('volunteer_requests', function (Blueprint $table) {
            $table->foreignId('opportunity_id')->nullable()->after('id')->constrained('volunteer_opportunities')->nullOnDelete();
            $table->unique(['opportunity_id', 'email'], 'volunteer_requests_opportunity_email_unique');
        });
    }

    public function down(): void
    {
        Schema::table('volunteer_requests', function (Blueprint $table) {
            // MySQL/MariaDB serve the foreign key with the composite unique index (opportunity_id is its
            // first column), so the index cannot be dropped while the constraint exists: constraint first.
            $table->dropForeign(['opportunity_id']);
            $table->dropUnique('volunteer_requests_opportunity_email_unique');
            $table->dropColumn('opportunity_id');
        });
    }
};
