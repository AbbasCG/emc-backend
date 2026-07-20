<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ticket 5: mirrors the same additive session_id/max_attempts columns added to `assignments`, so CourseAssignmentBridgeService can propagate them onto the bridge row. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_assignments', function (Blueprint $table) {
            $table->foreignId('session_id')->nullable()->after('class_group_id')
                ->constrained('lms_sessions')->nullOnDelete();
            $table->unsignedInteger('max_attempts')->nullable()->after('resubmission_allowed');
        });
    }

    public function down(): void
    {
        Schema::table('course_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('session_id');
            $table->dropColumn('max_attempts');
        });
    }
};
