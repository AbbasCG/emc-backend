<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical HR intake profile for people who are already volunteering, or are
 * about to be officially added as active volunteers — distinct from the
 * public `volunteer_requests` application form. Approval here creates/links
 * a `team_profiles` row (the single source of truth already shared by both
 * the Super Admin Team page and the Members page), so no separate sync table
 * is needed for either surface.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('volunteer_hr_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();

            // ── Personal information ──
            $table->string('full_name');
            $table->string('email');
            $table->string('normalized_email')->index();
            $table->string('phone', 30);
            $table->string('country', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('nationality', 100)->nullable();

            // ── Volunteer role ──
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('job_title');
            $table->string('employment_type', 50)->nullable(); // e.g. volunteer / part_time / project_based
            $table->date('join_date');
            $table->string('availability', 100)->nullable();
            $table->unsignedSmallInteger('weekly_hours')->nullable();

            // ── Skills and experience ──
            $table->text('skills')->nullable();
            $table->text('languages')->nullable();
            $table->text('education')->nullable();
            $table->text('experience')->nullable();
            $table->text('motivation')->nullable();

            // ── Emergency contact ──
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone', 30)->nullable();

            // ── Documents ──
            $table->string('cv_path');
            $table->string('profile_photo_path')->nullable();
            $table->string('linkedin_url')->nullable();
            $table->string('portfolio_url')->nullable();

            // ── Status workflow ──
            $table->string('status', 20)->default('submitted'); // draft, submitted, under_review, approved, rejected, archived
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            // ── Synchronization provenance ──
            $table->foreignId('team_profile_id')->nullable()->constrained('team_profiles')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('join_date');
            $table->index('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('volunteer_hr_profiles');
    }
};
