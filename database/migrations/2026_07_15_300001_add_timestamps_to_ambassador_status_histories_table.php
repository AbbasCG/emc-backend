<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The original table was created without timestamps(); the later rename
        // migration only renamed it, so created_at/updated_at were never added.
        // The model has $timestamps = true, so every insert/update fails with
        // "Unknown column 'updated_at'" until these columns exist.
        Schema::table('ambassador_application_status_histories', function (Blueprint $table) {
            if (! Schema::hasColumn('ambassador_application_status_histories', 'created_at')) {
                $table->timestamps();
            }
        });
    }

    public function down(): void
    {
        Schema::table('ambassador_application_status_histories', function (Blueprint $table) {
            $table->dropColumn(['created_at', 'updated_at']);
        });
    }
};
