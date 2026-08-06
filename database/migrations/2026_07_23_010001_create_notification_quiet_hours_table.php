<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 8 completion pass — quiet hours (Step 8). One row per user (global,
 * not per-channel/type — the smallest architecture that meets the
 * requirement; see ADR). Disabled by default so no existing delivery
 * behavior changes until a user explicitly opts in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_quiet_hours', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->boolean('enabled')->default(false);
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_quiet_hours');
    }
};
