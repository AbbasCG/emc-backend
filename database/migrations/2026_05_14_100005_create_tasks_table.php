<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('department_sections')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users');
            // low, medium, high, urgent
            $table->string('priority')->default('medium');
            // idea, under_review, planning, waiting_approval, in_progress, completed, postponed, cancelled, needs_evaluation
            $table->string('status')->default('idea');
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->string('related_type')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->json('tags')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('assigned_to');
            $table->index('due_date');
            $table->index('department_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
