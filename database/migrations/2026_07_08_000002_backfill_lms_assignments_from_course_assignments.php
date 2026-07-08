<?php

use App\Models\CourseAssignment;
use App\Services\CourseAssignmentBridgeService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Ensure every CourseAssignment has a linked Assignment row.
     * Safe: only creates missing rows, never deletes data.
     */
    public function up(): void
    {
        $bridge = app(CourseAssignmentBridgeService::class);

        $synced  = 0;
        $skipped = 0;

        CourseAssignment::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->each(function (CourseAssignment $ca) use ($bridge, &$synced, &$skipped) {
                try {
                    $bridge->ensureLmsAssignment($ca);
                    $synced++;
                } catch (\Throwable $e) {
                    Log::warning("backfill_lms_assignments: skipped course_assignment #{$ca->id} — {$e->getMessage()}");
                    $skipped++;
                }
            });

        if ($skipped > 0) {
            Log::warning("backfill_lms_assignments: {$skipped} record(s) could not be linked — check logs above.");
        }
    }

    public function down(): void
    {
        // Non-destructive; no rollback needed.
    }
};
