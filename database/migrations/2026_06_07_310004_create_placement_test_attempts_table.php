<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('placement_test_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('placement_test_id')->constrained('placement_tests')->cascadeOnDelete();
            $table->unsignedSmallInteger('score')->default(0);
            $table->unsignedTinyInteger('total_questions')->default(70);
            $table->decimal('percentage', 5, 2)->default(0.00);
            $table->string('estimated_level')->nullable();
            $table->datetime('started_at')->nullable();
            $table->datetime('submitted_at')->nullable();
            $table->json('answers')->nullable(); // {"question_id": "a|b|c|d", ...}
            $table->string('status')->default('in_progress'); // in_progress | completed
            $table->timestamps();

            // One attempt per student per course — enforced here + in controller
            $table->unique(['user_id', 'course_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('placement_test_attempts');
    }
};
