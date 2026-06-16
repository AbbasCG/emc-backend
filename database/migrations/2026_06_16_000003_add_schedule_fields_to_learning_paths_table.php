<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learning_paths', function (Blueprint $table) {
            $table->unsignedTinyInteger('study_days_per_week')->nullable()->after('level');
            $table->json('study_days')->nullable()->after('study_days_per_week');
            $table->string('study_time')->nullable()->after('study_days');
            $table->string('schedule_note')->nullable()->after('study_time');
        });
    }

    public function down(): void
    {
        Schema::table('learning_paths', function (Blueprint $table) {
            $table->dropColumn(['study_days_per_week', 'study_days', 'study_time', 'schedule_note']);
        });
    }
};
