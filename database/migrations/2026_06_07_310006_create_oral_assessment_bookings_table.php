<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oral_assessment_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('instructor_id')->constrained('instructors')->cascadeOnDelete();
            $table->foreignId('placement_test_attempt_id')
                  ->constrained('placement_test_attempts')
                  ->cascadeOnDelete();
            $table->foreignId('slot_id')->constrained('oral_assessment_slots')->cascadeOnDelete();
            $table->string('status')->default('booked'); // booked | completed | cancelled
            $table->unsignedTinyInteger('oral_score')->nullable();
            $table->string('final_level')->nullable();
            $table->text('instructor_notes')->nullable();
            $table->timestamps();

            // One booking per student per course
            $table->unique(['user_id', 'course_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oral_assessment_bookings');
    }
};
