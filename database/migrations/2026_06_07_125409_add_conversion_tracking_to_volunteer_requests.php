<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('volunteer_requests', function (Blueprint $table) {
            $table->timestamp('converted_to_member_at')->nullable()->after('admin_notes');
            // FK to team_profiles; nullable because most requests never get converted
            $table->unsignedBigInteger('converted_member_id')->nullable()->after('converted_to_member_at');

            $table->foreign('converted_member_id')
                ->references('id')
                ->on('team_profiles')
                ->nullOnDelete();

            $table->index('converted_member_id');
        });
    }

    public function down(): void
    {
        Schema::table('volunteer_requests', function (Blueprint $table) {
            $table->dropForeign(['converted_member_id']);
            $table->dropIndex(['converted_member_id']);
            $table->dropColumn(['converted_to_member_at', 'converted_member_id']);
        });
    }
};
