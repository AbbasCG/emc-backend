<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who is assigned to a meeting and who attended it.
 *
 * One row per (meeting, user). `source` records WHY the row exists:
 *   invited - the organizer picked this person explicitly (an explicit audience);
 *   self    - a department member joined/confirmed without being invited;
 *   roster  - created when the official report marked attendance for a
 *             department-derived participant.
 * `status`: invited -> joined (opened the link / confirmed) -> attended | absent
 * (the final call, made in the official report).
 *
 * Purely additive: no existing table or row changes, so it is safe to run on
 * production data. Meetings without rows keep their current department-based
 * visibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('source', 20)->default('invited');
            $table->string('status', 20)->default('invited');
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['meeting_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_participants');
    }
};
