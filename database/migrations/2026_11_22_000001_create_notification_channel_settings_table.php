<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Administrator switches for non-email channels per notification type (see
 * App\Models\NotificationChannelSetting). Starts empty: no row = channel on, so
 * nothing that is sent today stops being sent by this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_channel_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type', 100);
            $table->string('channel', 20);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['type', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_channel_settings');
    }
};
