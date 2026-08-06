<?php

namespace App\Console\Commands;

use App\Models\ManualPayment;
use App\Models\Registration;
use Illuminate\Console\Command;

class RepairManualPaymentRegistrations extends Command
{
    protected $signature = 'finance:repair-manual-payment-registrations
                            {--dry-run : Preview changes without writing anything}';

    protected $description = 'Create missing pending registrations for manual payments that have none';

    public function handle(): int
    {
        $typeMap = [
            'App\Models\Course'       => ['column' => 'course_id',        'label' => 'Course'],
            'App\Models\Workshop'     => ['column' => 'workshop_id',       'label' => 'Workshop'],
            'App\Models\LearningPath' => ['column' => 'learning_path_id',  'label' => 'LearningPath'],
        ];

        $pending = ManualPayment::with(['student'])
            ->whereIn('status', ['pending_review'])
            ->whereNull('deleted_at')
            ->get();

        if ($pending->isEmpty()) {
            $this->info('No pending_review manual payments found.');
            return self::SUCCESS;
        }

        $isDryRun = $this->option('dry-run');
        $created  = 0;
        $skipped  = 0;
        $errors   = 0;

        foreach ($pending as $mp) {
            $meta = $typeMap[$mp->purchasable_type] ?? null;

            if (! $meta) {
                $this->warn("MP#{$mp->id}: unknown purchasable_type '{$mp->purchasable_type}' — skipping");
                $errors++;
                continue;
            }

            if (! $mp->student) {
                $this->warn("MP#{$mp->id}: student #{$mp->student_id} not found — skipping");
                $errors++;
                continue;
            }

            $existing = Registration::where('user_id', $mp->student_id)
                ->where($meta['column'], $mp->purchasable_id)
                ->first();

            if ($existing) {
                $skipped++;
                continue;
            }

            $this->line("MP#{$mp->id}: {$mp->student->name} → {$meta['label']} #{$mp->purchasable_id} — registration missing");

            if (! $isDryRun) {
                Registration::create([
                    'user_id'         => $mp->student_id,
                    'full_name'       => $mp->student->name,
                    'email'           => $mp->student->email,
                    'phone'           => $mp->student->phone ?? null,
                    $meta['column']   => $mp->purchasable_id,
                    'status'          => 'pending',
                ]);
                $created++;
            } else {
                $created++;
            }
        }

        $action = $isDryRun ? 'Would create' : 'Created';
        $this->info("{$action} {$created} registration(s). Skipped {$skipped} (already exist). Errors: {$errors}.");

        if ($isDryRun && $created > 0) {
            $this->line('Run without --dry-run to apply changes.');
        }

        return self::SUCCESS;
    }
}
