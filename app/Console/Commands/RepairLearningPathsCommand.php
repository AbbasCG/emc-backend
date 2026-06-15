<?php

namespace App\Console\Commands;

use App\Models\LearningPath;
use App\Models\LearningPathEnrollment;
use App\Models\Registration;
use App\Models\StudentProgress;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairLearningPathsCommand extends Command
{
    protected $signature   = 'emc:repair-learning-paths {--dry-run : Preview changes without writing}';
    protected $description = 'Ensure student_progress rows exist for all courses in enrolled learning paths';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('[DRY RUN] No changes will be written to the database.');
        }

        $stats = [
            'enrollments_checked'  => 0,
            'registrations_created' => 0,
            'progress_created'     => 0,
            'skipped_existing'     => 0,
            'errors'               => 0,
        ];

        $enrollments = LearningPathEnrollment::with(['learningPath.items', 'user'])->get();

        $this->info("Checking {$enrollments->count()} learning path enrollments…");

        foreach ($enrollments as $enrollment) {
            $stats['enrollments_checked']++;
            $lp       = $enrollment->learningPath;
            $user     = $enrollment->user;

            if (! $lp || ! $user) {
                $stats['errors']++;
                $this->warn("  Skipping enrollment #{$enrollment->id}: missing path or user.");
                continue;
            }

            $courseIds = $lp->items->pluck('course_id')->all();

            foreach ($courseIds as $courseId) {
                try {
                    // Ensure registration exists (some LMS flows require it)
                    $regExists = Registration::where('user_id', $user->id)
                        ->where('course_id', $courseId)
                        ->exists();

                    if (! $regExists) {
                        if (! $dryRun) {
                            Registration::create([
                                'user_id'   => $user->id,
                                'course_id' => $courseId,
                                'status'    => 'registered',
                            ]);
                        }
                        $stats['registrations_created']++;
                        $this->line("  + Created registration: user {$user->id} → course {$courseId}");
                    }

                    // Ensure StudentProgress exists
                    $progressExists = StudentProgress::where('user_id', $user->id)
                        ->where('course_id', $courseId)
                        ->exists();

                    if (! $progressExists) {
                        if (! $dryRun) {
                            StudentProgress::create([
                                'user_id'                          => $user->id,
                                'course_id'                        => $courseId,
                                'progress_percentage'              => 0,
                                'status'                           => 'in_progress',
                                'total_sessions'                   => 0,
                                'completed_sessions'               => 0,
                                'attendance_percentage'            => 0,
                                'assignment_completion_percentage' => 0,
                            ]);
                        }
                        $stats['progress_created']++;
                        $this->line("  + Created progress: user {$user->id} → course {$courseId}");
                    } else {
                        $stats['skipped_existing']++;
                    }
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    $this->error("  ERROR user {$user->id} course {$courseId}: {$e->getMessage()}");
                }
            }
        }

        $this->newLine();
        $this->info('── Repair Summary ──');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Enrollments checked',   $stats['enrollments_checked']],
                ['Registrations created', $stats['registrations_created']],
                ['Progress rows created', $stats['progress_created']],
                ['Skipped (existing)',    $stats['skipped_existing']],
                ['Errors',               $stats['errors']],
            ]
        );

        if ($dryRun) {
            $this->warn('[DRY RUN] Nothing was written. Remove --dry-run to apply.');
        }

        return $stats['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
