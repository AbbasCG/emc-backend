<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 8 canonical-architecture pass — additive, reversible.
 *
 * idempotency_key: optional deterministic dedup key for high-risk callers
 * (Step 11). Nullable so every pre-existing row and every caller that has
 * not opted in remains valid; the legacy time-window+lock dedup in
 * SendPlatformNotificationJob still applies whenever it is absent.
 *
 * legacy_source_id: marks a row created by the one-time
 * `notifications:migrate-legacy` command from a Laravel built-in
 * `notifications` table row (its UUID), so reruns are idempotent and never
 * duplicate an already-migrated notification.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_notifications', function (Blueprint $table) {
            $table->string('idempotency_key', 191)->nullable()->after('entity_id');
            $table->string('legacy_source_id', 36)->nullable()->after('idempotency_key');

            $table->index(['user_id', 'type', 'idempotency_key']);
            $table->unique('legacy_source_id');
        });
    }

    public function down(): void
    {
        Schema::table('platform_notifications', function (Blueprint $table) {
            $table->dropUnique(['legacy_source_id']);
            $table->dropIndex(['user_id', 'type', 'idempotency_key']);
            $table->dropColumn(['idempotency_key', 'legacy_source_id']);
        });
    }
};
