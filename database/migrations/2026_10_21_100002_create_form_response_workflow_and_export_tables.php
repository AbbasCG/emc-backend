<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forms Phase 4 tables. All of them belong to a response (or a form) and go with it; none is ever read by a respondent.
 *
 *  form_submission_notes       internal notes staff leave on a response. Append-only: a note is never edited or removed.
 *  form_submission_events      the response's own history: status changes, assignment, corrections (names are kept as they
 *                              were, so the history still reads right after someone is renamed or leaves).
 *  form_answer_corrections     every correction of an answer: previous and new value, who, when, why. Protected history.
 *  form_exports                one export request: what was asked for, who asked, where the private file is, when it expires.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('form_submission_notes')) {
            Schema::create('form_submission_notes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('submission_id')->constrained('form_submissions')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->text('body');
                $table->timestamps();
                $table->index(['submission_id', 'id']);
            });
        }

        if (! Schema::hasTable('form_submission_events')) {
            Schema::create('form_submission_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('submission_id')->constrained('form_submissions')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('type', 30);
                $table->string('from_value', 120)->nullable();
                $table->string('to_value', 120)->nullable();
                $table->json('meta')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['submission_id', 'id']);
            });
        }

        if (! Schema::hasTable('form_answer_corrections')) {
            Schema::create('form_answer_corrections', function (Blueprint $table) {
                $table->id();
                $table->foreignId('submission_id')->constrained('form_submissions')->cascadeOnDelete();
                $table->foreignId('answer_id')->constrained('form_answers')->cascadeOnDelete();
                $table->foreignId('event_id')->nullable()->constrained('form_submission_events')->cascadeOnDelete();
                $table->unsignedBigInteger('question_id');
                $table->text('previous_value')->nullable();
                $table->text('new_value')->nullable();
                $table->foreignId('corrected_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('reason', 500)->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['submission_id', 'id']);
            });
        }

        if (! Schema::hasTable('form_exports')) {
            Schema::create('form_exports', function (Blueprint $table) {
                $table->id();
                $table->foreignId('form_id')->constrained('dynamic_forms')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('format', 8);
                $table->string('status', 12)->default('queued');
                $table->json('filters')->nullable();
                $table->json('columns')->nullable();
                $table->unsignedInteger('row_count')->nullable();
                $table->string('file_name', 200)->nullable();
                $table->string('file_path', 300)->nullable();
                $table->unsignedBigInteger('file_size')->nullable();
                $table->string('error', 300)->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
                $table->index(['form_id', 'user_id']);
                $table->index(['status', 'expires_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('form_exports');
        Schema::dropIfExists('form_answer_corrections');
        Schema::dropIfExists('form_submission_events');
        Schema::dropIfExists('form_submission_notes');
    }
};
