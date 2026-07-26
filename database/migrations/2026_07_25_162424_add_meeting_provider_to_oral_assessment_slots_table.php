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
        Schema::table('oral_assessment_slots', function (Blueprint $table) {
            // Tags which conferencing provider `meeting_link` belongs to — the
            // link itself stays a plain URL regardless of provider (no live
            // Zoom/Meet/Teams API call is made), so future providers can
            // populate the same column behind their own integration without
            // a schema change. See App\Support\MeetingProvider.
            $table->string('meeting_provider', 30)->nullable()->default('custom_url')->after('meeting_link');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('oral_assessment_slots', function (Blueprint $table) {
            $table->dropColumn('meeting_provider');
        });
    }
};
