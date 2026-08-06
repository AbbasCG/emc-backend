<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ambassador_applications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Workflow
            $table->string('status', 30)->default('new');
            // new | under_review | interview_scheduled | approved | rejected | waitlisted | cancelled
            $table->boolean('is_draft')->default(false);
            $table->string('draft_token', 80)->nullable()->unique();

            // ── Step 1: Personal Information ─────────────────────────────────────
            $table->string('full_name', 100);
            $table->string('email', 255);
            $table->string('mobile_phone', 20);
            $table->string('nationality', 100);
            $table->string('gender', 30);
            $table->date('date_of_birth')->nullable();
            $table->string('country', 100);
            $table->string('city', 100);

            // ── Step 2: University Information ───────────────────────────────────
            $table->string('university_name', 150);
            $table->string('university_type', 50)->nullable(); // public|private|international
            $table->string('faculty', 100)->nullable();
            $table->string('major', 100)->nullable();
            $table->string('study_year', 30)->nullable();
            $table->string('student_number', 60)->nullable();
            $table->string('student_status', 20)->nullable(); // student|graduate
            $table->date('expected_graduation_date')->nullable();

            // ── Step 3: Ambassador Motivation ────────────────────────────────────
            $table->text('motivation_why');
            $table->text('contribution_what');
            $table->text('expected_gain');
            $table->text('biggest_achievement')->nullable();
            $table->text('biggest_challenge')->nullable();
            $table->boolean('has_volunteer_experience')->default(false);
            $table->boolean('has_event_organization_experience')->default(false);
            $table->boolean('has_teaching_experience')->default(false);
            $table->boolean('has_student_club_involvement')->default(false);

            // Social media (validated URLs)
            $table->string('social_instagram', 255)->nullable();
            $table->string('social_linkedin', 255)->nullable();
            $table->string('social_facebook', 255)->nullable();
            $table->string('social_tiktok', 255)->nullable();
            $table->string('social_github', 255)->nullable();
            $table->string('website_url', 255)->nullable();
            $table->unsignedInteger('followers_count')->nullable();

            // ── Step 4 & 5: Interests & Skills ───────────────────────────────────
            $table->json('interests')->nullable();
            $table->json('skills')->nullable();

            // ── Step 6: Leadership Experience ────────────────────────────────────
            $table->boolean('has_led_team')->default(false);
            $table->boolean('has_organized_events')->default(false);
            $table->unsignedInteger('events_attendees_count')->nullable();
            $table->boolean('has_represented_university')->default(false);

            // ── Step 7: Availability ─────────────────────────────────────────────
            $table->unsignedTinyInteger('weekly_hours_available')->nullable();
            $table->boolean('can_attend_university_events')->default(false);
            $table->boolean('can_represent_emc_outside')->default(false);
            $table->boolean('can_travel')->default(false);
            $table->boolean('owns_laptop')->default(false);
            $table->boolean('has_stable_internet')->default(false);
            $table->boolean('can_attend_weekly_meetings')->default(false);

            // ── Step 8: Volunteer Experience Types ───────────────────────────────
            $table->json('volunteer_experience_types')->nullable();

            // ── Step 9: Certifications ───────────────────────────────────────────
            $table->json('certifications')->nullable();

            // ── Step 10: Uploads ─────────────────────────────────────────────────
            $table->string('cv_path', 500)->nullable();
            $table->string('student_id_path', 500)->nullable();
            $table->string('photo_path', 500)->nullable();
            $table->json('certificate_paths')->nullable();

            // ── Admin Workflow ────────────────────────────────────────────────────
            $table->text('admin_notes')->nullable();
            $table->timestamp('interview_scheduled_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('status_updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('status_updated_at')->nullable();

            // ── Audit trail ───────────────────────────────────────────────────────
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // ── Indexes ───────────────────────────────────────────────────────────
            $table->index('status');
            $table->index('email');
            $table->index('country');
            $table->index('university_name');
            $table->index('major');
            $table->index('gender');
            $table->index('is_draft');
            $table->index('created_at');
        });

        Schema::create('ambassador_application_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ambassador_application_id')->constrained('ambassador_applications')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->text('content');
            $table->boolean('is_private')->default(true);
            $table->timestamps();

            $table->index('ambassador_application_id');
        });

        Schema::create('ambassador_application_status_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ambassador_application_id');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->text('reason')->nullable();
            $table->timestamp('changed_at')->useCurrent();

            $table->foreign('ambassador_application_id', 'amb_status_hist_app_fk')
                ->references('id')
                ->on('ambassador_applications')
                ->cascadeOnDelete();

            $table->index('ambassador_application_id', 'amb_status_hist_app_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ambassador_application_status_history');
        Schema::dropIfExists('ambassador_application_notes');
        Schema::dropIfExists('ambassador_applications');
    }
};
