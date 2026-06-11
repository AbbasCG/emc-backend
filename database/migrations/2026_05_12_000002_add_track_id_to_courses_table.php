<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Column consolidated into 2026_04_27_154507_create_courses_table.php.
// This migration only adds the FK constraint, which requires tracks to exist first.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->foreign('track_id')->references('id')->on('tracks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropForeign(['track_id']);
        });
    }
};
