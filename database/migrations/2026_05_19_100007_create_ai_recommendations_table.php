<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_recommendations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('type');              // suggested_course, suggested_track, at_risk_program…
            $table->string('title');
            $table->text('description');
            $table->string('priority')->default('medium'); // low, medium, high
            $table->string('related_type')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->string('status')->default('active');  // active, dismissed, acted_on
            $table->timestamp('generated_at')->useCurrent();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['type', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_recommendations');
    }
};
