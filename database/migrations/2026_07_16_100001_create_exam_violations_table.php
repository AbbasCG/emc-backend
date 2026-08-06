<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_violations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('placement_test_attempt_id')
                ->nullable()
                ->constrained('placement_test_attempts')
                ->cascadeOnDelete();
            $table->foreignId('question_id')
                ->nullable()
                ->constrained('placement_test_questions')
                ->nullOnDelete();

            // copy | paste | cut | right_click | keyboard_shortcut | tab_switch |
            // fullscreen_exit | window_blur | selection_attempt
            $table->string('violation_type', 40);
            $table->string('severity', 10)->default('low'); // low | medium | high
            $table->json('meta')->nullable(); // e.g. {"key": "Ctrl+C"} or {"exit_count": 2}

            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 500)->nullable();

            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['placement_test_attempt_id', 'occurred_at'], 'exam_violations_attempt_time_idx');
            $table->index('violation_type', 'exam_violations_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_violations');
    }
};
