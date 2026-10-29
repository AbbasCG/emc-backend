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
        // Re-runnable: an earlier attempt may have created the table before failing on something else.
        if (Schema::hasTable('trainer_applications')) {
            return;
        }

        Schema::create('trainer_applications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('status')->default('new'); // new, under_review, approved, rejected

            // أولاً: البيانات الشخصية
            $table->string('full_name');
            $table->string('phone_or_whatsapp');
            $table->string('email');
            $table->string('linkedin_url')->nullable();

            // ثانياً: التخصص والخبرة
            $table->string('primary_specialty');
            $table->string('years_of_experience');
            $table->boolean('has_training_experience')->default(false);

            // ثالثاً: مجالات التدريب
            $table->json('ai_training_fields'); // قائمة مجالات الذكاء الاصطناعي
            $table->string('other_ai_field')->nullable();
            $table->json('training_program_types'); // دورات تدريبية، محاضرات، برامج متكاملة
            $table->json('target_audiences'); // الفئات المستهدفة

            // رابعاً: آلية التعاون
            $table->string('delivery_mode'); // حضوريًا، عن بُعد، كلاهما
            $table->decimal('hourly_rate', 10, 2)->nullable(); // المقابل المالي للساعة
            $table->string('currency')->default('USD'); // USD, SAR, YER, EUR, OTHER
            $table->string('currency_other')->nullable();
            $table->text('additional_notes')->nullable();

            // مراجعة الإدارة والملاحظات
            $table->text('admin_notes')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();
            
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trainer_applications');
    }
};
