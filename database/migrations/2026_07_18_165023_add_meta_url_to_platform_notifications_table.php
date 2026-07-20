<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('platform_notifications', function (Blueprint $table) {
            // External action link (e.g. a meeting URL), distinct from
            // `action_url` which is an internal SPA route. Never rendered as
            // plain text — always validated to http(s) before storage.
            $table->string('meta_url')->nullable()->after('action_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('platform_notifications', function (Blueprint $table) {
            $table->dropColumn('meta_url');
        });
    }
};
