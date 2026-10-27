<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Smart Forms — data retention. How many days a form's responses are kept before `forms:purge-responses` deletes them (with their
 * files). Null, the default for every existing form, means they are kept until someone deletes them: nothing is ever removed
 * without the form's owner having chosen it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('dynamic_forms', 'retention_days')) {
            return;
        }

        Schema::table('dynamic_forms', function (Blueprint $table) {
            $table->unsignedSmallInteger('retention_days')->nullable()->after('automation');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('dynamic_forms', 'retention_days')) {
            return;
        }

        Schema::table('dynamic_forms', function (Blueprint $table) {
            $table->dropColumn('retention_days');
        });
    }
};
