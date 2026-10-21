<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forms Phase 5: conditional logic and multi-step flow.
 *
 *  form_questions.logic   the rule that decides whether a block is shown (see Services\Forms\Logic\FormLogic). NULL for every
 *                         existing block, which therefore behaves exactly as before.
 *  dynamic_forms.flow     form-level behaviour of the respondent's flow (today: `review_step`). NULL for every existing form.
 *
 * Steps need no column: a step is a `page_break` block, and its title and description are that block's label and description.
 * Both columns travel in the published snapshot (only when set, so no existing version's hash changes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_questions', function (Blueprint $table) {
            if (! Schema::hasColumn('form_questions', 'logic')) {
                $table->json('logic')->nullable()->after('config');
            }
        });

        Schema::table('dynamic_forms', function (Blueprint $table) {
            if (! Schema::hasColumn('dynamic_forms', 'flow')) {
                $table->json('flow')->nullable()->after('draft_revision');
            }
        });
    }

    public function down(): void
    {
        Schema::table('dynamic_forms', function (Blueprint $table) {
            if (Schema::hasColumn('dynamic_forms', 'flow')) {
                $table->dropColumn('flow');
            }
        });

        Schema::table('form_questions', function (Blueprint $table) {
            if (Schema::hasColumn('form_questions', 'logic')) {
                $table->dropColumn('logic');
            }
        });
    }
};
