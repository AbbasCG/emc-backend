<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workshops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('instructor_id')->nullable()->constrained('instructors')->nullOnDelete();
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->string('title');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('status')->default('draft');
            $table->dateTime('start_date')->nullable();
            $table->time('start_time')->nullable();
            $table->dateTime('end_date')->nullable();
            $table->time('end_time')->nullable();
            $table->enum('location_type', ['online', 'offline', 'hybrid', 'unknown'])->default('unknown');
            $table->string('meeting_link', 500)->nullable();
            $table->boolean('registration_open')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['course_id', 'slug']);
            $table->foreign('assigned_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workshops');
    }
};
