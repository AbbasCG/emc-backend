<?php

namespace App\Console\Commands;

use App\Models\Course;
use Illuminate\Console\Command;

/**
 * courses:mark-ended
 *
 * Preferred approach: computed status (is_ended / computed_status) in CourseResource
 * means no DB mutation is needed for display. This command is a utility for logging,
 * alerting, or optionally closing registration_open on clearly expired courses.
 *
 * Run manually or schedule daily in Kernel.php.
 */
class MarkEndedCoursesCommand extends Command
{
    protected $signature = 'courses:mark-ended
                            {--close-registration : Also set registration_open=false on ended courses}
                            {--dry-run : Report what would happen without writing to DB}';

    protected $description = 'Report (and optionally update) courses whose end_date has passed.';

    public function handle(): int
    {
        $dryRun        = $this->option('dry-run');
        $closeReg      = $this->option('close-registration');

        $ended = Course::query()
            ->whereIn('status', ['published', 'active'])
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<', now())
            ->get();

        if ($ended->isEmpty()) {
            $this->info('No ended courses found.');
            return 0;
        }

        $this->info("Found {$ended->count()} ended course(s):");

        $rows = [];
        foreach ($ended as $course) {
            $rows[] = [
                $course->id,
                mb_strimwidth($course->title ?? '—', 0, 50, '…'),
                $course->end_date?->format('Y-m-d'),
                $course->registration_open ? 'open' : 'closed',
            ];

            if ($closeReg && !$dryRun && $course->registration_open) {
                $course->update(['registration_open' => false]);
            }
        }

        $this->table(['ID', 'Title', 'End Date', 'Reg Open'], $rows);

        if ($dryRun) {
            $this->warn('DRY RUN — no database changes were made.');
        } elseif ($closeReg) {
            $this->info('registration_open set to false for courses that were still open.');
        } else {
            $this->line('No DB changes made (computed status is used for display). Use --close-registration to update registration_open.');
        }

        return 0;
    }
}
