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
        Schema::table('lms_sessions', function (Blueprint $table) {
            // Fix: StudentRegistrationsController selects 'location' from lms_sessions
            // but the column was missing from the original create migration.
            $table->string('location')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('lms_sessions', function (Blueprint $table) {
            $table->dropColumn('location');
        });
    }
};
