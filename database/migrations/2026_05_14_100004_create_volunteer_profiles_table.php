<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('volunteer_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('department_sections')->nullOnDelete();
            // applicant, under_review, accepted, active, partially_available, inactive, withdrawn
            $table->string('status')->default('applicant');
            $table->json('skills')->nullable();
            $table->string('availability')->nullable();
            $table->decimal('hours_logged', 8, 2)->default(0);
            $table->string('onboarding_status')->default('pending'); // pending, in_progress, completed
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('volunteer_profiles');
    }
};
