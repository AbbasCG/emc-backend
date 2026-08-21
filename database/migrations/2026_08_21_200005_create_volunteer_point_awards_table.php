<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // نقاط أثر EMC — سياسة التقدير والمكافآت v1.0 (2026).
        // كل صف = منحة نقاط موثقة بسبب وفئة ومانح. Lifetime = مجموع الموجب،
        // والمتاح = Lifetime - المستبدل (جدول redemptions).
        Schema::create('volunteer_point_awards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('category');                 // meetings|task|quality_bonus|initiative|workshop|camp|community|mentoring|knowledge|institutional|best_of_department|best_of_center|exceptional_impact|continuity|other
            $table->integer('points');                  // موجبة دائمًا؛ الإلغاء بصف عكسي موثق
            $table->string('reason');
            $table->foreignId('awarded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_type')->nullable();  // ربط اختياري بمصدر (task, meeting_report, camp...)
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamp('awarded_at');
            $table->timestamps();

            $table->index(['user_id', 'awarded_at']);
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('volunteer_point_awards');
    }
};
