<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Draft volunteer opportunities generated from an HR incoming request.
 *
 * Draft storage only: nothing here is published or public. `status` stays 'draft' until a
 * later phase adds publishing, and `published_at` stays null. One draft per incoming request
 * (unique incoming_request_id), so a request cannot be turned into duplicate drafts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('volunteer_opportunities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incoming_request_id')->nullable()->unique()->constrained('hr_requests')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('title', 200);
            $table->text('summary')->nullable();
            $table->json('responsibilities')->nullable();
            $table->json('required_skills')->nullable();
            $table->json('preferred_skills')->nullable();
            $table->unsignedSmallInteger('volunteers_needed')->nullable();
            $table->string('availability', 255)->nullable();
            $table->string('location_mode', 20)->nullable(); // remote | onsite | hybrid
            $table->date('start_date')->nullable();
            $table->text('application_instructions')->nullable();
            $table->string('status', 20)->default('draft');   // draft | published | archived (only draft is used for now)
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('department_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('volunteer_opportunities');
    }
};
