<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forms Phase 3 — who may reach a form, and through which link a response arrived.
 *
 *  dynamic_forms.invitation_only      the form is open only to invited, signed-in users. Together with the existing
 *                                     `allow_anonymous` it gives the three access modes: public (anonymous allowed),
 *                                     authenticated (any signed-in user) and invited. No new "mode" column is added, so the two
 *                                     cannot disagree.
 *  dynamic_forms.requires_share_link  the form opens only through one of its valid share links; the plain /forms/{slug} address
 *                                     then answers that a link is needed. Off by default, so every existing address keeps working.
 *  form_submissions.share_link_id     the share link a response came through, if any. Kept when the link is revoked.
 *
 * Defaults reproduce today's behaviour exactly; nothing existing is rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dynamic_forms', function (Blueprint $table) {
            if (! Schema::hasColumn('dynamic_forms', 'invitation_only')) {
                $table->boolean('invitation_only')->default(false);
            }
            if (! Schema::hasColumn('dynamic_forms', 'requires_share_link')) {
                $table->boolean('requires_share_link')->default(false);
            }
        });

        Schema::table('form_submissions', function (Blueprint $table) {
            if (! Schema::hasColumn('form_submissions', 'share_link_id')) {
                $table->foreignId('share_link_id')->nullable()->constrained('form_share_links')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('form_submissions', function (Blueprint $table) {
            if (Schema::hasColumn('form_submissions', 'share_link_id')) {
                // the foreign key first, then the column (the index it created goes with it)
                $table->dropConstrainedForeignId('share_link_id');
            }
        });

        Schema::table('dynamic_forms', function (Blueprint $table) {
            $columns = array_filter(['invitation_only', 'requires_share_link'], fn ($c) => Schema::hasColumn('dynamic_forms', $c));
            if ($columns !== []) {
                $table->dropColumn(array_values($columns));
            }
        });
    }
};
