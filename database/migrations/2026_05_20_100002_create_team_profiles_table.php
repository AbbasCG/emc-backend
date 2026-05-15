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
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
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
            $table->timestamps();

            $table->index(['department_id', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_profiles');
    }
};
