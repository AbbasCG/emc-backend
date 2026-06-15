<?php

namespace App\Console\Commands;

use App\Enums\RegistrationStatus;
use App\Models\Registration;
use App\Models\StudentProgress;
use App\Models\User;
use Illuminate\Console\Command;

class RepairEnrollmentsCommand extends Command
{
    protected $signature = 'emc:repair-enrollments
                            {--dry-run : Preview without making changes}
                            {--user= : Repair only for a specific user_id}
                            {--course= : Repair only for a specific course_id}';

    protected $description = 'Link registrations to user accounts and create missing student_progress records';

    private const ACTIVE_STATUSES = [
        RegistrationStatus::REGISTERED->value,
        RegistrationStatus::PAYMENT_CONFIRMED->value,
        RegistrationStatus::ATTENDED->value,
        'confirmed',
        'active',
        'enrolled',
    ];

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('⚠  DRY-RUN — no changes will be saved.');
            $this->newLine();
        }

        $linked          = 0;
        $progressCreated = 0;
        $skipped         = 0;

        // ── Step 1: Link guest registrations to existing user accounts ────────
        $this->info('Step 1: Linking guest registrations to existing user accounts…');

        Registration::whereNull('user_id')
            ->whereNotNull('email')
            ->whereNotIn('status', [RegistrationStatus::CANCELLED->value])
            ->when($this->option('course'), fn ($q) => $q->where('course_id', $this->option('course')))
            ->chunk(200, function ($registrations) use (&$linked, $dryRun) {
                foreach ($registrations as $reg) {
                    $user = User::where('email', strtolower(trim((string) $reg->email)))->first();

                    if (! $user) {
                        continue;
                    }

                    $this->line("  → Reg #{$reg->id} ({$reg->email}) linked to user #{$user->id} ({$user->name})");

                    if (! $dryRun) {
                        $reg->update(['user_id' => $user->id]);
                    }

                    $linked++;
                }
            });

        $this->line("  Linked: {$linked} registrations");
        $this->newLine();

        // ── Step 2: Create missing student_progress records ───────────────────
        $this->info('Step 2: Creating missing student_progress records…');

        Registration::whereNotNull('user_id')
            ->whereNotNull('course_id')
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->when($this->option('user'),   fn ($q) => $q->where('user_id',   $this->option('user')))
            ->when($this->option('course'), fn ($q) => $q->where('course_id', $this->option('course')))
            ->chunk(200, function ($registrations) use (&$progressCreated, &$skipped, $dryRun) {
                foreach ($registrations as $reg) {
                    $exists = StudentProgress::where('user_id',   $reg->user_id)
                                             ->where('course_id', $reg->course_id)
                                             ->exists();

                    if ($exists) {
                        $skipped++;
                        continue;
                    }

                    $this->line("  → Progress for user #{$reg->user_id} → course #{$reg->course_id} (reg #{$reg->id})");

                    if (! $dryRun) {
                        StudentProgress::create([
                            'user_id'                          => $reg->user_id,
                            'course_id'                        => $reg->course_id,
                            'progress_percentage'              => 0,
                            'status'                           => 'in_progress',
                            'total_sessions'                   => 0,
                            'completed_sessions'               => 0,
                            'attendance_percentage'            => 0,
                            'assignment_completion_percentage' => 0,
                        ]);
                    }

                    $progressCreated++;
                }
            });

        $this->line("  Created: {$progressCreated} | Skipped (already exist): {$skipped}");
        $this->newLine();

        // ── Summary ───────────────────────────────────────────────────────────
        $this->table(
            ['Metric', 'Count'],
            [
                ['linked_registrations',     $linked],
                ['created_progress_records', $progressCreated],
                ['skipped_duplicates',        $skipped],
            ]
        );

        if ($dryRun) {
            $this->warn('DRY-RUN complete — no changes were saved. Run without --dry-run to apply.');
        } else {
            $this->info('✅ Repair complete.');
        }

        return self::SUCCESS;
    }
}
