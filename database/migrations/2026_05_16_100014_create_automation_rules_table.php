<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // registration_created|payment_confirmed|course_completed|assignment_due_soon
            // task_overdue|meeting_created|certificate_issued
            $table->string('trigger_type');
            $table->json('conditions')->nullable();
            $table->json('actions');
            // active|inactive
            $table->string('status')->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_rules');
    }
};
