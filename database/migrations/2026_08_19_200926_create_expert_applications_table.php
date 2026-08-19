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
        Schema::create('expert_applications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('status')->default('new'); // new, under_review, approved, rejected
            
            // Section 1: Basic Info
            $table->string('full_name');
            $table->string('country');
            $table->string('city');
            $table->string('whatsapp_number');
            $table->string('email');
            $table->string('linkedin_url')->nullable();
            
            // Section 2: Professional Background
            $table->string('primary_specialty');
            $table->string('academic_qualification');
            $table->string('current_employer')->nullable();
            $table->string('job_title');
            $table->string('years_of_experience');
            
            // Section 3: Expertise Areas
            $table->json('expertise_fields');
            $table->string('expertise_level');
            $table->text('tools_technologies');
            
            // Section 4: Contribution Roles
            $table->json('contribution_roles');
            
            // Section 5: Instructors (Nullable)
            $table->json('trainer_fields')->nullable();
            $table->json('trainer_program_types')->nullable();
            $table->json('trainer_target_audiences')->nullable();
            $table->json('trainer_delivery_mode')->nullable();
            $table->boolean('trainer_has_experience')->nullable();
            $table->string('trainer_years_experience')->nullable();
            $table->text('trainer_previous_courses')->nullable();
            $table->string('trainer_content_readiness')->nullable();
            
            // Section 6: Experts (Nullable)
            $table->text('expert_specialty_areas')->nullable();
            $table->json('expert_contribution_types')->nullable();
            $table->text('expert_achievements')->nullable();
            $table->boolean('expert_has_certifications')->nullable();
            $table->text('expert_certifications_details')->nullable();
            
            // Section 7: Consultants (Nullable)
            $table->json('consultant_fields')->nullable();
            $table->json('consultant_types')->nullable();
            $table->text('consultant_previous_clients')->nullable();
            $table->json('consultant_target_clients')->nullable();
            
            // Section 8: Events Specialists (Nullable)
            $table->json('events_types')->nullable();
            $table->json('events_contribution_areas')->nullable();
            $table->text('events_previous_events')->nullable();
            
            // Section 9: Mentors (Nullable)
            $table->json('mentor_fields')->nullable();
            $table->json('mentor_target_audiences')->nullable();
            $table->json('mentor_session_types')->nullable();
            
            // Section 10: Evaluators (Nullable)
            $table->json('evaluator_fields')->nullable();
            $table->json('evaluator_types')->nullable();
            
            // Section 11: Initiatives/Projects (Nullable)
            $table->json('participant_project_types')->nullable();
            $table->text('participant_contribution_method')->nullable();
            
            // Section 12: Availability
            $table->json('availability_times');
            $table->string('availability_level');
            $table->json('collaboration_preferences');
            
            // Section 13: About
            $table->text('bio');
            $table->text('unique_value');
            
            // Section 14: Consent
            $table->boolean('agree_to_contact');
            $table->boolean('agree_to_store_data');
            
            // Auditing & Review
            $table->text('admin_notes')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expert_applications');
    }
};
