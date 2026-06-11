<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('volunteer_requests', function (Blueprint $table) {
            $table->id();

            // Applicant personal info
            $table->string('full_name');
            $table->string('email');
            $table->string('phone', 30);
            $table->string('country', 100);
            $table->string('city', 100);
            $table->string('gender', 30);

            // Volunteer intent
            $table->string('desired_department', 150);
            $table->string('experience_level', 50);
            $table->text('skills')->nullable();
            $table->string('availability', 100);
            $table->text('motivation');
            $table->text('previous_experience')->nullable();

            // Attachments / links
            $table->string('cv_file_path')->nullable();
            $table->string('portfolio_url', 255)->nullable();

            // Applicant notes
            $table->text('notes')->nullable();

            // Admin workflow
            $table->string('status', 30)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('admin_notes')->nullable();

            // Volunteer-to-member conversion tracking
            $table->timestamp('converted_to_member_at')->nullable();
            $table->unsignedBigInteger('converted_member_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('desired_department');
            $table->index('email');
            $table->index('converted_member_id');

            $table->foreign('converted_member_id')
                ->references('id')
                ->on('team_profiles')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('volunteer_requests');
    }
};
