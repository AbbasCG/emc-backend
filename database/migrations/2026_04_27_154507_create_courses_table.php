<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            // track_id FK added later by 2026_05_12_000002 (tracks table created after this timestamp)
            $table->unsignedBigInteger('track_id')->nullable();
            $table->foreignId('instructor_id')->nullable()->constrained('instructors')->nullOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->text('short_description')->nullable();
            $table->enum('type', ['free', 'paid'])->default('free');
            $table->string('program_type', 50)->nullable();
            $table->string('session_format', 50)->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->boolean('is_free')->default(false);
            $table->string('location')->nullable();
            $table->enum('location_type', ['online', 'offline', 'hybrid', 'unknown'])->default('unknown');
            $table->string('meeting_link', 500)->nullable();
            $table->boolean('is_online')->default(false);
            $table->dateTime('start_date')->nullable();
            $table->time('start_time')->nullable();
            $table->dateTime('end_date')->nullable();
            $table->time('end_time')->nullable();
            $table->integer('capacity')->nullable();
            $table->boolean('registration_open')->default(true);
            $table->string('registration_status', 20)->default('open');
            $table->string('status')->default('published');
            $table->text('notes')->nullable();
            $table->string('duration')->nullable();
            $table->integer('training_hours')->nullable();
            $table->string('target_audience')->nullable();
            $table->string('language')->nullable();
            $table->string('level')->nullable();
            $table->string('study_days')->nullable();
            $table->string('study_time')->nullable();
            $table->string('certificate')->nullable();
            $table->boolean('certificate_available')->default(false);
            $table->string('certificate_type', 100)->nullable();
            $table->json('learning_outcomes')->nullable();
            $table->json('requirements')->nullable();
            $table->json('curriculum_topics')->nullable();
            $table->json('keywords')->nullable();
            $table->string('course_image')->nullable();
            $table->string('course_thumbnail')->nullable();
            $table->boolean('requires_placement_test')->default(false);
            $table->string('placement_test_type')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('assigned_by')->nullable();
            // department_id FK added later by 2026_05_18_000005 (departments table created after this timestamp)
            $table->unsignedBigInteger('department_id')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('track_id');
            $table->index('status');
            $table->index('instructor_id');

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('assigned_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
