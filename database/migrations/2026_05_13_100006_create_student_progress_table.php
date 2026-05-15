<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->foreignId('track_id')->nullable()->constrained('tracks')->nullOnDelete();
            $table->unsignedTinyInteger('progress_percentage')->default(0);
            $table->unsignedInteger('completed_sessions')->default(0);
            $table->unsignedInteger('total_sessions')->default(0);
            $table->unsignedTinyInteger('attendance_percentage')->default(0);
            $table->unsignedTinyInteger('assignment_completion_percentage')->default(0);
            $table->string('status')->default('in_progress');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'course_id']);
            $table->index(['user_id', 'track_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_progress');
    }
};
