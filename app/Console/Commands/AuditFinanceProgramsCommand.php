<?php

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\LearningPath;
use App\Models\ProgramFinanceApproval;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditFinanceProgramsCommand extends Command
{
    protected $signature   = 'programs:audit-finance {--fix : Apply fixes to bad records}';
    protected $description = 'Audit paid programs published without Finance approval. Pass --fix to correct them.';

    public function handle(): int
    {
        $dryRun = ! $this->option('fix');

        if ($dryRun) {
            $this->warn('DRY RUN — no changes will be made. Pass --fix to apply corrections.');
        } else {
            $this->error('LIVE RUN — records will be modified!');
            if (! $this->confirm('Proceed?')) {
                return self::FAILURE;
            }
        }

        $this->line('');
        $this->info('=== Paid Courses Published Without Finance Approval ===');

        $badCourses = Course::where('status', 'published')
            ->where(fn ($q) => $q->where('type', 'paid')->orWhere('price', '>', 0))
            ->where(fn ($q) => $q->where('finance_approval_status', '!=', 'approved')
                ->orWhereNull('finance_approval_status')
            )
            ->get();

        if ($badCourses->isEmpty()) {
            $this->info('None found.');
        } else {
            $this->warn("Found {$badCourses->count()} course(s):");
            $this->table(
                ['ID', 'Title', 'Price', 'Finance Status', 'Status'],
                $badCourses->map(fn ($c) => [$c->id, mb_substr($c->title, 0, 40), $c->price, $c->finance_approval_status ?? 'NULL', $c->status])
            );

            if (! $dryRun) {
                DB::transaction(function () use ($badCourses) {
                    foreach ($badCourses as $course) {
                        ProgramFinanceApproval::updateOrCreate(
                            ['approvable_type' => get_class($course), 'approvable_id' => $course->id],
                            [
                                'status'            => 'approved',
                                'submitted_by'      => $course->created_by,
                                'submitted_at'      => $course->created_at,
                                'reviewed_at'       => $course->created_at,
                                'review_note'       => 'Backdated — existing published paid course',
                                'price_snapshot'    => $course->price,
                                'currency_snapshot' => 'EUR',
                            ]
                        );
                        $course->update(['finance_approval_status' => 'approved']);
                    }
                });
                $this->info("Fixed {$badCourses->count()} course(s).");
            }
        }

        $this->line('');
        $this->info('=== Paid Learning Paths Published Without Finance Approval ===');

        $badPaths = LearningPath::where('status', 'published')
            ->where('price', '>', 0)
            ->where(fn ($q) => $q->where('finance_approval_status', '!=', 'approved')
                ->orWhereNull('finance_approval_status')
            )
            ->get();

        if ($badPaths->isEmpty()) {
            $this->info('None found.');
        } else {
            $this->warn("Found {$badPaths->count()} learning path(s):");
            $this->table(
                ['ID', 'Title', 'Price', 'Finance Status', 'Status'],
                $badPaths->map(fn ($p) => [$p->id, mb_substr($p->title, 0, 40), $p->price, $p->finance_approval_status ?? 'NULL', $p->status])
            );

            if (! $dryRun) {
                DB::transaction(function () use ($badPaths) {
                    foreach ($badPaths as $path) {
                        ProgramFinanceApproval::updateOrCreate(
                            ['approvable_type' => get_class($path), 'approvable_id' => $path->id],
                            [
                                'status'            => 'approved',
                                'submitted_by'      => $path->created_by,
                                'submitted_at'      => $path->created_at,
                                'reviewed_at'       => $path->created_at,
                                'review_note'       => 'Backdated — existing published paid learning path',
                                'price_snapshot'    => $path->price,
                                'currency_snapshot' => strtoupper($path->currency ?? 'EUR'),
                            ]
                        );
                        $path->update(['finance_approval_status' => 'approved']);
                    }
                });
                $this->info("Fixed {$badPaths->count()} learning path(s).");
            }
        }

        // ── Paid courses with NO approval row at all (any status) ────────────
        $this->line('');
        $this->info('=== Paid Courses With No Approval Record ===');

        $missingCourseIds = Course::where(fn ($q) => $q->where('type', 'paid')->orWhere('price', '>', 0))
            ->whereNotIn('id', ProgramFinanceApproval::where('approvable_type', 'App\\Models\\Course')->pluck('approvable_id'))
            ->get();

        if ($missingCourseIds->isEmpty()) {
            $this->info('None found.');
        } else {
            $this->warn("Found {$missingCourseIds->count()} course(s) with no approval record:");
            $this->table(
                ['ID', 'Title', 'Price', 'Finance Status', 'Status'],
                $missingCourseIds->map(fn ($c) => [$c->id, mb_substr($c->title, 0, 40), $c->price, $c->finance_approval_status ?? 'NULL', $c->status])
            );

            if (! $dryRun) {
                DB::transaction(function () use ($missingCourseIds) {
                    foreach ($missingCourseIds as $course) {
                        $isPublished = $course->status === 'published';
                        ProgramFinanceApproval::updateOrCreate(
                            ['approvable_type' => get_class($course), 'approvable_id' => $course->id],
                            [
                                'status'            => $isPublished ? 'approved' : 'pending',
                                'submitted_by'      => $course->created_by,
                                'submitted_at'      => $course->created_at,
                                'reviewed_at'       => $isPublished ? $course->updated_at : null,
                                'review_note'       => 'Backdated — created before finance workflow',
                                'price_snapshot'    => $course->price,
                                'currency_snapshot' => strtoupper($course->currency ?? 'EUR'),
                            ]
                        );
                        $course->update([
                            'finance_approval_status' => $isPublished ? 'approved' : 'pending',
                        ]);
                    }
                });
                $this->info("Fixed {$missingCourseIds->count()} course(s).");
            }
        }

        // ── Paid learning paths with NO approval row at all (any status) ──────
        $this->line('');
        $this->info('=== Paid Learning Paths With No Approval Record ===');

        $missingPathIds = LearningPath::where('price', '>', 0)
            ->whereNotIn('id', ProgramFinanceApproval::where('approvable_type', 'App\\Models\\LearningPath')->pluck('approvable_id'))
            ->get();

        if ($missingPathIds->isEmpty()) {
            $this->info('None found.');
        } else {
            $this->warn("Found {$missingPathIds->count()} learning path(s) with no approval record:");
            $this->table(
                ['ID', 'Title', 'Price', 'Finance Status', 'Status'],
                $missingPathIds->map(fn ($p) => [$p->id, mb_substr($p->title, 0, 40), $p->price, $p->finance_approval_status ?? 'NULL', $p->status])
            );

            if (! $dryRun) {
                DB::transaction(function () use ($missingPathIds) {
                    foreach ($missingPathIds as $path) {
                        $isPublished = $path->status === 'published';
                        ProgramFinanceApproval::updateOrCreate(
                            ['approvable_type' => get_class($path), 'approvable_id' => $path->id],
                            [
                                'status'            => $isPublished ? 'approved' : 'pending',
                                'submitted_by'      => $path->created_by,
                                'submitted_at'      => $path->created_at,
                                'reviewed_at'       => $isPublished ? $path->updated_at : null,
                                'review_note'       => 'Backdated — created before finance workflow',
                                'price_snapshot'    => $path->price,
                                'currency_snapshot' => strtoupper($path->currency ?? 'EUR'),
                            ]
                        );
                        $path->update([
                            'finance_approval_status' => $isPublished ? 'approved' : 'pending',
                        ]);
                    }
                });
                $this->info("Fixed {$missingPathIds->count()} learning path(s).");
            }
        }

        $this->line('');
        $this->info('Audit complete.');
        return self::SUCCESS;
    }
}
