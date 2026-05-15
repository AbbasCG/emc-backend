<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('course_id')->constrained('users')->nullOnDelete();
            $table->foreignId('track_id')->nullable()->after('user_id')->constrained('tracks')->nullOnDelete();
            $table->foreignId('workshop_id')->nullable()->after('track_id')->constrained('workshops')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropConstrainedForeignId('track_id');
            $table->dropConstrainedForeignId('workshop_id');
        });
    }
};
