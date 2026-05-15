<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_insights', function (Blueprint $table) {
            $table->id();
            $table->string('type');              // attendance_risk, dropout_risk, engagement_alert…
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('title');
            $table->text('summary');
            $table->decimal('score', 5, 2)->nullable(); // 0.00–100.00
            $table->string('severity')->nullable();      // low, medium, high, critical
            $table->json('metadata')->nullable();
            $table->timestamp('generated_at')->useCurrent();
            $table->timestamps();

            $table->index(['type', 'severity']);
            $table->index(['entity_type', 'entity_id']);
            $table->index('generated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_insights');
    }
};
