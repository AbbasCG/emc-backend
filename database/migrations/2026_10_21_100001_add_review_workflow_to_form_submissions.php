<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Forms Phase 4: reviewing, assigning and correcting responses.
 *
 *  form_submissions.assigned_to / assigned_at   the employee a response is assigned to (kept NULL when they are deleted)
 *  form_submissions.corrected_at                set once any answer of the response has been corrected
 *  form_submissions.status                      new | in_review | needs_followup | completed | archived. The old `reviewed`
 *                                               becomes `completed`, which is what it meant.
 *  form_answers.original_value / corrected_*    the answer as the respondent submitted it, kept for good, and the latest correction
 *  form_collaborators.can_correct_responses     an explicit, separate grant: seeing responses never lets anyone change them
 *
 * Nothing existing is dropped or rewritten except the one status alias above.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_submissions', function (Blueprint $table) {
            if (! Schema::hasColumn('form_submissions', 'assigned_to')) {
                $table->foreignId('assigned_to')->nullable()->after('status')->constrained('users')->nullOnDelete();
                $table->timestamp('assigned_at')->nullable()->after('assigned_to');
                $table->timestamp('corrected_at')->nullable()->after('assigned_at');
                // Leads with `status`, not `form_id`: an index that starts with a foreign-key column can take over that key's own
                // index on MySQL/MariaDB and then cannot be dropped on rollback (found by rolling this back on a real MariaDB).
                // The assignee filter is served by the index the foreign key itself creates.
                $table->index(['status', 'form_id'], 'form_submissions_status_form_idx');
            }
        });

        Schema::table('form_answers', function (Blueprint $table) {
            if (! Schema::hasColumn('form_answers', 'original_value')) {
                $table->text('original_value')->nullable()->after('file_path');
                $table->timestamp('corrected_at')->nullable()->after('original_value');
                $table->foreignId('corrected_by')->nullable()->after('corrected_at')->constrained('users')->nullOnDelete();
            }
        });

        Schema::table('form_collaborators', function (Blueprint $table) {
            if (! Schema::hasColumn('form_collaborators', 'can_correct_responses')) {
                $table->boolean('can_correct_responses')->default(false)->after('can_view_responses');
            }
        });

        DB::table('form_submissions')->where('status', 'reviewed')->update(['status' => 'completed']);
    }

    public function down(): void
    {
        // The new statuses have no old equivalent: `completed` goes back to `reviewed`, the rest are simply "not reviewed yet".
        DB::table('form_submissions')->where('status', 'completed')->update(['status' => 'reviewed']);
        DB::table('form_submissions')->whereIn('status', ['in_review', 'needs_followup'])->update(['status' => 'new']);

        Schema::table('form_collaborators', function (Blueprint $table) {
            if (Schema::hasColumn('form_collaborators', 'can_correct_responses')) {
                $table->dropColumn('can_correct_responses');
            }
        });

        Schema::table('form_answers', function (Blueprint $table) {
            if (Schema::hasColumn('form_answers', 'corrected_by')) {
                $table->dropConstrainedForeignId('corrected_by');
                $table->dropColumn(['original_value', 'corrected_at']);
            }
        });

        Schema::table('form_submissions', function (Blueprint $table) {
            if (Schema::hasColumn('form_submissions', 'assigned_to')) {
                $table->dropIndex('form_submissions_status_form_idx');
                $table->dropConstrainedForeignId('assigned_to');
                $table->dropColumn(['assigned_at', 'corrected_at']);
            }
        });
    }
};
