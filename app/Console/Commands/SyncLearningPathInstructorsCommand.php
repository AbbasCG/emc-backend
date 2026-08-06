<?php

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\LearningPath;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncLearningPathInstructorsCommand extends Command
{
    protected $signature = 'emc:sync-learning-path-instructors
                            {--dry-run : Preview changes without writing to the database}';

    protected $description = 'Sync Learning Path instructor to linked courses that have no instructor or still carry the old LP instructor.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN — no changes will be written.');
        }

        $lpChecked      = 0;
        $coursesChecked = 0;
        $coursesUpdated = 0;
        $skipped        = 0;
        $errors         = 0;

        $paths = LearningPath::whereNotNull('instructor_id')
            ->with(['instructor.user', 'items'])
            ->get();

        foreach ($paths as $lp) {
            $lpChecked++;
            $courseIds = $lp->items->pluck('course_id')->all();

            if (empty($courseIds)) {
                continue;
            }

            $courses = Course::whereIn('id', $courseIds)->get();

            foreach ($courses as $course) {
                $coursesChecked++;

                if ($course->instructor_id === null) {
                    // No instructor — inherit from LP
                    $instructorName = $lp->instructor->user?->name ?? $lp->instructor->name ?? "#{$lp->instructor_id}";
                    $this->line("  ↳ [{$lp->title}] Course «{$course->title}» — null → {$instructorName}");

                    if (! $dryRun) {
                        try {
                            $course->update(['instructor_id' => $lp->instructor_id]);
                            $coursesUpdated++;
                        } catch (\Throwable $e) {
                            $this->error("    ERROR: {$e->getMessage()}");
                            $errors++;
                        }
                    } else {
                        $coursesUpdated++;
                    }
                } else {
                    $skipped++;
                }
            }
        }

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['learning_paths_checked',   $lpChecked],
                ['courses_checked',           $coursesChecked],
                ['courses_updated',           $coursesUpdated],
                ['skipped_custom_instructor', $skipped],
                ['errors',                    $errors],
            ]
        );

        if ($dryRun) {
            $this->warn('DRY RUN complete — no data was changed. Re-run without --dry-run to apply.');
        } else {
            $this->info('Sync complete.');
        }

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
