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
        Schema::table('team_profiles', function (Blueprint $table) {
            // Volunteer-converted members may not have a matching dept yet.
            $table->foreignId('department_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('team_profiles', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable(false)->change();
        });
    }
};
