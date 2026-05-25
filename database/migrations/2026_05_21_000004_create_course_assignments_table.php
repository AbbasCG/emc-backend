<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_id')->nullable()->constrained('course_modules')->nullOnDelete();
            $table->foreignId('course_session_id')->nullable()->constrained('course_sessions')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('instructions')->nullable();
            $table->datetime('deadline')->nullable();
            $table->unsignedInteger('max_points')->default(100);
            $table->string('submission_type')->default('any'); // file|text|url|any
            $table->boolean('is_required')->default(true);
            $table->boolean('is_visible')->default(true);
            $table->string('status')->default('draft'); // draft|published
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_assignments');
    }
};
