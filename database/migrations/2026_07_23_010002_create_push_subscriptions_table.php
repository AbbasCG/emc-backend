<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 8 completion pass — Web Push (VAPID) subscription storage (Step 6).
 * One row per browser/device subscription; a user may have several (multiple
 * browsers/devices). Endpoint URLs can exceed MySQL's ~767-byte index limit,
 * so uniqueness is enforced on a SHA-256 hash of the endpoint, not the raw
 * (unindexed, unbounded-length-safe `text`) endpoint column itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();
            $table->string('p256dh_key');
            $table->string('auth_token');
            $table->string('user_agent')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
