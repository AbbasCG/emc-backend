<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forms Phase 3 — share links and invitations.
 *
 * form_share_links: a form-specific link with a label for the people who manage it, an optional expiry and an optional maximum
 * number of submissions. Only a SHA-256 hash of the token is stored (the token itself is shown once, when the link is made), so a
 * database leak cannot reconstruct a working link. A link is never deleted: revoking sets `revoked_at`, so the history of who made
 * and who revoked it stays, and the submissions that came through it keep pointing at it.
 *
 * form_invitations: the registered EMC users a restricted form is open to. An invitation carries no secret and no token: the
 * respondent must be signed in as that very user, so a forwarded link can never stand in for the invited person.
 *
 * Purely additive: two new tables, nothing existing is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('form_share_links')) {
            Schema::create('form_share_links', function (Blueprint $table) {
                $table->id();
                $table->foreignId('form_id')->constrained('dynamic_forms')->cascadeOnDelete();
                $table->string('label', 100);
                $table->char('token_hash', 64)->unique();
                // The last characters of the token, so people can tell two links apart. Not enough to use the link.
                $table->string('token_hint', 8);
                $table->timestamp('expires_at')->nullable();
                // The most submissions that may be made THROUGH this link (views are not counted). Null means no limit.
                $table->unsignedInteger('max_uses')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('revoked_at')->nullable();
                $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('form_invitations')) {
            Schema::create('form_invitations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('form_id')->constrained('dynamic_forms')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('revoked_at')->nullable();
                $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['form_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('form_invitations');
        Schema::dropIfExists('form_share_links');
    }
};
