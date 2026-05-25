<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_materials', function (Blueprint $table) {
            $table->foreignId('module_id')->nullable()->after('session_id')
                  ->constrained('course_modules')->nullOnDelete();
            $table->foreignId('course_session_id')->nullable()->after('module_id')
                  ->constrained('course_sessions')->nullOnDelete();
            $table->boolean('is_visible')->default(true)->after('sort_order');
            $table->string('file_url')->nullable()->after('file_path');
        });
    }

    public function down(): void
    {
        Schema::table('course_materials', function (Blueprint $table) {
            $table->dropConstrainedForeignId('module_id');
            $table->dropConstrainedForeignId('course_session_id');
            $table->dropColumn(['is_visible', 'file_url']);
        });
    }
};
