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
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instructor_id')->nullable()->constrained('instructors')->nullOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->text('short_description')->nullable();
            $table->enum('type', ['free', 'paid'])->default('free');
            $table->decimal('price', 10, 2)->default(0);
            $table->string('location')->nullable();
            $table->boolean('is_online')->default(false);
            $table->dateTime('start_date')->nullable();
            $table->dateTime('end_date')->nullable();
            $table->integer('capacity')->nullable();
            $table->string('status')->default('active');
            $table->string('duration')->nullable();
            $table->integer('training_hours')->nullable();
            $table->string('target_audience')->nullable();
            $table->string('language')->nullable();
            $table->string('level')->nullable();
            $table->string('study_days')->nullable();
            $table->string('study_time')->nullable();
            $table->string('certificate')->nullable();

            $table->json('learning_outcomes')->nullable();

            

            $table->string('course_image')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
