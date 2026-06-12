<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->string('kind', 30)->default('event')->after('user_id');
            $table->string('visibility', 20)->default('private')->after('kind');
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete()->after('visibility');
            $table->json('attendees')->nullable()->after('department_id');
        });
    }

    public function down(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
            $table->dropColumn(['kind', 'visibility', 'department_id', 'attendees']);
        });
    }
};
