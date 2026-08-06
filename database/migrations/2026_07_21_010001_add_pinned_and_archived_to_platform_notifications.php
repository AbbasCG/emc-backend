<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_notifications', function (Blueprint $table) {
            $table->boolean('pinned')->default(false)->after('is_read');
            $table->timestamp('archived_at')->nullable()->after('read_at');
            $table->index(['user_id', 'pinned']);
            $table->index(['user_id', 'archived_at']);
        });
    }

    public function down(): void
    {
        Schema::table('platform_notifications', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'pinned']);
            $table->dropIndex(['user_id', 'archived_at']);
            $table->dropColumn(['pinned', 'archived_at']);
        });
    }
};
