<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->string('subject')->nullable()->after('type');
            $table->timestamp('queued_at')->nullable()->after('sent_at');
            $table->timestamp('failed_at')->nullable()->after('queued_at');
        });
    }

    public function down(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->dropColumn(['subject', 'queued_at', 'failed_at']);
        });
    }
};
