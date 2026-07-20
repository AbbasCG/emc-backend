<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 5: attempt-history table. Additive, new table only — does not touch
 * `assignment_submissions` (which remains the "current/latest" row per the
 * existing unique(assignment_id,user_id) constraint, unchanged). Every time
 * a submission is about to be overwritten by a resubmission, its prior state
 * is snapshotted into a row here first, so no attempt is ever lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignment_submission_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_submission_id')->constrained('assignment_submissions')->cascadeOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->string('file_path')->nullable();
            $table->text('text_answer')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('score', 8, 2)->nullable();
            $table->text('feedback')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['assignment_submission_id', 'attempt_number'], 'asa_submission_attempt_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_submission_attempts');
    }
};
