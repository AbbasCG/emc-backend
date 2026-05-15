<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dynamic_forms', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            // student_registration, volunteer_application, partnership_request, course_evaluation,
            // workshop_evaluation, trainer_evaluation, program_request, meeting_report,
            // activity_report, quality_report, complaint, suggestion, scholarship_request, attendance
            $table->string('type')->default('general');
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('related_type')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            // draft, active, closed, archived
            $table->string('status')->default('active');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dynamic_forms');
    }
};
