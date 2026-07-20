<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Ticket 5: explicit assignment scope, session-level linkage (to the
 * Ticket-4-canonical LmsSession, not CourseSession), and a max-attempts cap.
 * Additive and reversible; backfills `scope` from the FK that was already
 * implicitly determining it, so no existing behavior changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->string('scope')->nullable()->after('class_group_id');
            $table->foreignId('session_id')->nullable()->after('class_group_id')
                ->constrained('lms_sessions')->nullOnDelete();
            $table->unsignedInteger('max_attempts')->nullable()->after('resubmission_allowed');
        });

        // Backfill: session_id is always null at this point (column just
        // added), so scope is exactly what class_group_id already implied.
        DB::table('assignments')->whereNotNull('class_group_id')->update(['scope' => 'class_group']);
        DB::table('assignments')->whereNull('class_group_id')->update(['scope' => 'course']);
    }

    public function down(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('session_id');
            $table->dropColumn(['scope', 'max_attempts']);
        });
    }
};
