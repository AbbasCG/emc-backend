<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_department_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->date('week_start');                 // إثنين الأسبوع المُقرَّر عنه
            $table->text('achievements');               // ماذا أنجزنا هذا الأسبوع
            $table->text('planned');                    // ماذا سننجز الأسبوع القادم
            $table->text('blockers')->nullable();       // العوائق
            $table->text('needs')->nullable();          // الاحتياجات
            $table->text('notes')->nullable();
            $table->foreignId('submitted_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->unique(['department_id', 'week_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_department_reports');
    }
};
