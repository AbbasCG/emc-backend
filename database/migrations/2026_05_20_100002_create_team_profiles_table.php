<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public-facing team profiles for the EMC V3 website.
 *
 * Separate from the operational `team_members` table (which links user accounts
 * to departments). This table stores standalone biographical profiles for people
 * who appear on the public website — they do not need a user account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_profiles', function (Blueprint $table) {
            $table->id();
            // Nullable: volunteer-converted members may not have a matching dept yet
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            // Optional link to a platform user account (set after "create account" step)
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('position_ar');
            $table->string('position_en')->nullable();
            $table->string('role_key')->nullable()->index();
            $table->string('country')->nullable();
            $table->string('image')->nullable();
            $table->text('bio_ar')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_leader')->default(false);
            $table->boolean('is_executive')->default(false);
            $table->boolean('is_active')->default(true);

            // Contact / identity fields (added for volunteer conversion flow)
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('city')->nullable();
            $table->string('gender')->nullable();
            $table->text('skills')->nullable();
            $table->string('cv_file_path')->nullable();

            // Volunteer-conversion provenance
            $table->string('member_type')->nullable();   // e.g. 'volunteer', 'staff'
            $table->string('source')->nullable();         // e.g. 'volunteer_request', 'manual'
            $table->unsignedBigInteger('source_id')->nullable(); // volunteer_requests.id
            $table->boolean('is_new_member')->default(false);
            $table->timestamp('joined_at')->nullable();

            $table->timestamps();

            $table->index(['department_id', 'is_active', 'sort_order']);
            $table->index('source_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_profiles');
    }
};
