<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // تقييم أعضاء الإدارة ضمن تقرير الإثنين الأسبوعي — نفس معايير الاجتماعات
        // العشرة (scores = {criterion: 1..10}) حتى يتوحد قياس الأداء أسبوعيًا.
        Schema::create('weekly_report_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('weekly_department_report_id')
                ->constrained('weekly_department_reports')
                ->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->json('scores')->nullable();
            $table->decimal('avg_score', 4, 1)->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['weekly_department_report_id', 'user_id'], 'weekly_rating_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_report_ratings');
    }
};
