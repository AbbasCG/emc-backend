<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::table('attendances', function (Blueprint $table) {
            // Make session_id nullable so CourseSession-only attendance rows
            // can leave it NULL while setting course_session_id instead.
            if (Schema::hasColumn('attendances', 'session_id')) {
                $table->unsignedBigInteger('session_id')->nullable()->change();
            }

            // New: nullable FK to course_sessions for CourseSession attendance
            if (! Schema::hasColumn('attendances', 'course_session_id')) {
                $table->foreignId('course_session_id')
                    ->nullable()
                    ->after('session_id')
                    ->constrained('course_sessions')
                    ->cascadeOnDelete();
            }
        });

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::table('attendances', function (Blueprint $table) {
            if (Schema::hasColumn('attendances', 'course_session_id')) {
                $table->dropForeign(['course_session_id']);
                $table->dropColumn('course_session_id');
            }

            if (Schema::hasColumn('attendances', 'session_id')) {
                $table->unsignedBigInteger('session_id')->nullable(false)->change();
            }
        });

        Schema::enableForeignKeyConstraints();
    }
};
