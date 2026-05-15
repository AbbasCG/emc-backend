<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_reviews', function (Blueprint $table) {
            $table->id();
            // polymorphic: workshop, course, track, instructor
            $table->string('reviewable_type');
            $table->unsignedBigInteger('reviewable_id');
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('overall_score', 4, 2);
            $table->decimal('objective_clarity_score', 4, 2)->nullable();
            $table->decimal('content_quality_score', 4, 2)->nullable();
            $table->decimal('instructor_score', 4, 2)->nullable();
            $table->decimal('organization_score', 4, 2)->nullable();
            $table->decimal('time_commitment_score', 4, 2)->nullable();
            $table->decimal('completion_score', 4, 2)->nullable();
            $table->decimal('output_quality_score', 4, 2)->nullable();
            $table->decimal('repeatability_score', 4, 2)->nullable();
            $table->text('notes')->nullable();
            $table->text('recommendations')->nullable();
            // draft | submitted | approved
            $table->string('status')->default('draft');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['reviewable_type', 'reviewable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_reviews');
    }
};
