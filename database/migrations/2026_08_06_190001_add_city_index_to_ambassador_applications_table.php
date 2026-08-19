<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ambassador_applications', function (Blueprint $table) {
            // status, country, university_name, major, created_at already indexed.
            $table->index('city');
        });
    }

    public function down(): void
    {
        Schema::table('ambassador_applications', function (Blueprint $table) {
            $table->dropIndex(['city']);
        });
    }
};
