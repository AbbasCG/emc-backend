<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where a saved email preference came from, so the preference page can tell «you chose this» from «saved before this page existed».
 *
 * The email preference is now three-state per type: follow the central setting (NO row), enabled (row true), disabled (row false).
 * Rows that existed BEFORE this change cannot be told apart by their value: the old save-everything preferences page wrote an
 * explicit true/false row for every toggle it showed, so a `false` may be the user's own choice or just the page's default-off.
 * They are therefore marked source = 'legacy' and kept exactly as saved, never replaced in bulk:
 *
 *   legacy false  stays DISABLED until the user picks «اتباع الإعداد المركزي» or «مفعّل»
 *   legacy true   stays ENABLED  (what they receive today), shown as a saved legacy choice they can switch to «اتباع الإعداد المركزي»
 *
 * Exception: the two ticket-assignment emails. Their own page only ever wrote the rows a user touched (partial saves), so a saved
 * row there IS a known personal choice and is NOT marked legacy.
 * Rows written by the new endpoint carry source = NULL (a deliberate choice).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notification_preferences')) {
            return;
        }

        Schema::table('notification_preferences', function (Blueprint $table) {
            if (! Schema::hasColumn('notification_preferences', 'source')) {
                $table->string('source', 20)->nullable()->after('enabled');
            }
        });

        DB::table('notification_preferences')
            ->where('channel', 'email')
            ->whereNotIn('type', ['operations_ticket_assigned', 'ticket_assigned'])
            ->whereNull('source')
            ->update(['source' => 'legacy']);
    }

    public function down(): void
    {
        if (Schema::hasTable('notification_preferences') && Schema::hasColumn('notification_preferences', 'source')) {
            Schema::table('notification_preferences', function (Blueprint $table) {
                $table->dropColumn('source');
            });
        }
    }
};
