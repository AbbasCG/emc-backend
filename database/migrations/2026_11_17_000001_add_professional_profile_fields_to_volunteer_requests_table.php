<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Four optional questions of the old HR volunteer profile that the public apply form lacked: languages, LinkedIn,
 * a short professional bio and the consent to show professional data.
 *
 * Every column is nullable and has NO default: a question the applicant did not answer stays NULL (no answer), never an
 * invented one. In particular `professional_profile_consent` is tri-state (NULL = not answered, true = agreed, false = declined).
 *
 * Existing rows are not touched. The migration is additive, so it is safe to run before the code that uses it is deployed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('volunteer_requests')) {
            return;
        }

        Schema::table('volunteer_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('volunteer_requests', 'languages')) {
                $table->json('languages')->nullable();
            }
            if (! Schema::hasColumn('volunteer_requests', 'linkedin_url')) {
                $table->string('linkedin_url', 255)->nullable();
            }
            if (! Schema::hasColumn('volunteer_requests', 'professional_bio')) {
                $table->text('professional_bio')->nullable();
            }
            if (! Schema::hasColumn('volunteer_requests', 'professional_profile_consent')) {
                $table->boolean('professional_profile_consent')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('volunteer_requests')) {
            return;
        }

        $columns = array_values(array_filter(
            ['languages', 'linkedin_url', 'professional_bio', 'professional_profile_consent'],
            fn (string $column) => Schema::hasColumn('volunteer_requests', $column),
        ));

        if ($columns !== []) {
            Schema::table('volunteer_requests', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }
};
