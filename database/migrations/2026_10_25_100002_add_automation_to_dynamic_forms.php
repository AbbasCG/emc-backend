<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Smart Forms Phase 8 — what happens automatically when a form is answered (who is notified, a confirmation to the respondent,
 * automatic assignment, reminders, an outbound webhook). Null means the defaults of FormAutomationSettings: existing forms keep
 * behaving exactly as before until their owner switches something on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('dynamic_forms', 'automation')) {
            return;
        }

        Schema::table('dynamic_forms', function (Blueprint $table) {
            $table->json('automation')->nullable()->after('flow');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('dynamic_forms', 'automation')) {
            return;
        }

        Schema::table('dynamic_forms', function (Blueprint $table) {
            $table->dropColumn('automation');
        });
    }
};
