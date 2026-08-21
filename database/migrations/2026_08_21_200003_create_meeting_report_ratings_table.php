<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_report_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_report_id')->constrained('meeting_reports')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('attended')->default(true);
            $table->unsignedTinyInteger('rating')->nullable(); // 1..5 — تقييم مساهمة الحاضر
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['meeting_report_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_report_ratings');
    }
};
