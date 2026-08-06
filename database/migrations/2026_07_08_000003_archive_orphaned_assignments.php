<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Archive Assignment rows whose linked CourseAssignment has been soft-deleted.
     *
     * This is a safety-net for data created before the cascade-delete fix existed.
     * After this migration, the runtime orphan check in the dashboard/assignment
     * controllers provides live protection; this covers historical stale rows.
     */
    public function up(): void
    {
        // Mark is_visible = false and status = 'archived' for every Assignment row
        // whose course_assignment_id points to a soft-deleted CourseAssignment.
        $affected = DB::table('assignments')
            ->whereNotNull('course_assignment_id')
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('course_assignments')
                    ->whereColumn('course_assignments.id', 'assignments.course_assignment_id')
                    ->whereNotNull('course_assignments.deleted_at');
            })
            ->where(function ($q) {
                $q->where('is_visible', true)
                  ->orWhere('status', '!=', 'archived');
            })
            ->update([
                'is_visible' => false,
                'status'     => 'archived',
                'updated_at' => now(),
            ]);

        \Illuminate\Support\Facades\Log::info("archive_orphaned_assignments: archived {$affected} stale Assignment row(s).");
    }

    public function down(): void
    {
        // Non-destructive rollback is not possible without a snapshot.
        // Re-run the bridge backfill if needed.
    }
};
