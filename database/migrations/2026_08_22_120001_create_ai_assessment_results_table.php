<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // نتائج «اختبر مستواك في AI» — الأداة التسويقية التعليمية:
        // المستوى والدرجات الخمس والهدف والخلفية + مكافأة 7% (كوبون حقيقي).
        Schema::create('ai_assessment_results', function (Blueprint $table) {
            $table->id();
            $table->string('visitor_id', 64)->nullable()->index();
            $table->string('level_id', 40);              // explorer|power_user|automation_builder|solution_builder|systems_engineer
            $table->string('level_title');
            $table->unsignedTinyInteger('overall');      // 0..100
            $table->json('scores');                      // {usage, prompting, automation, development, engineering}
            $table->string('goal')->nullable();
            $table->string('field')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('whatsapp')->nullable();
            $table->string('reward_code', 40)->nullable()->unique();
            $table->timestamp('reward_expires_at')->nullable();
            $table->string('ref', 64)->nullable();       // وصل من رابط مشاركة
            $table->timestamp('created_at');

            $table->index(['level_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_assessment_results');
    }
};
