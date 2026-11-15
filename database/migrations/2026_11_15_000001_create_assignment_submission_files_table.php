<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-file assignment submissions.
 *
 * Additive: a NEW table; `assignment_submissions.file_path` (and its attempt-history copy) is untouched so old
 * submissions keep working exactly as before.
 *
 * attempt_number: 0 = the submission's CURRENT attachment set; N > 0 = the set that belonged to history attempt N
 * (copied here by AssignmentSubmissionHistoryService::snapshot() when a resubmission supersedes it). Physical
 * files are never deleted, so a history row can share `stored_path` with a current row.
 *
 * The unique key makes retries idempotent: the same content (sha256) cannot appear twice in one attachment set.
 * It starts with submission_id, which is also the FK column; down() drops the whole table, so the usual
 * "index needed in a foreign key constraint" rollback trap does not apply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignment_submission_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained('assignment_submissions')->cascadeOnDelete();
            $table->unsignedInteger('attempt_number')->default(0);
            $table->string('original_name', 255);
            $table->string('stored_path', 500);
            $table->string('mime', 150);
            $table->string('extension', 10);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->timestamps();

            $table->unique(['submission_id', 'attempt_number', 'sha256'], 'asf_submission_attempt_sha_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_submission_files');
    }
};
