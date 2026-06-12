<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workshop_requests', function (Blueprint $table) {
            $table->unsignedTinyInteger('selected_date_option')->nullable()->after('workflow_status')
                ->comment('1, 2, or 3 — set by operations (step 3) or executive_admin (step 7)');
        });
    }

    public function down(): void
    {
        Schema::table('workshop_requests', function (Blueprint $table) {
            $table->dropColumn('selected_date_option');
        });
    }
};
