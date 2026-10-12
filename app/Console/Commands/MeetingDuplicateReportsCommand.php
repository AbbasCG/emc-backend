<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only finder for meetings that already have more than one report, plus an
 * opt-in step that adds the unique index once none remain.
 *
 * It NEVER deletes, merges or edits a report: which duplicate is the official
 * one is the owner's decision.
 */
class MeetingDuplicateReportsCommand extends Command
{
    protected $signature = 'meetings:duplicate-reports {--create-index : Add the unique index on meeting_reports.meeting_id if no duplicates remain}';

    protected $description = 'List meetings that have more than one report (read-only), and optionally add the one-report-per-meeting unique index';

    public function handle(): int
    {
        $groups = DB::table('meeting_reports')
            ->select('meeting_id', DB::raw('count(*) as reports'))
            ->whereNotNull('meeting_id')
            ->groupBy('meeting_id')
            ->havingRaw('count(*) > 1')
            ->orderByDesc('reports')
            ->get();

        if ($groups->isEmpty()) {
            $this->info('No meeting has more than one report.');
        } else {
            $this->warn($groups->count().' meeting(s) have more than one report. Nothing was changed.');

            $rows = [];
            foreach ($groups as $g) {
                $meeting = DB::table('meetings')->where('id', $g->meeting_id)->first(['title']);
                foreach (DB::table('meeting_reports')->where('meeting_id', $g->meeting_id)->orderBy('id')->get(['id', 'created_by', 'created_at']) as $r) {
                    $rows[] = [$g->meeting_id, $meeting->title ?? '(deleted)', $r->id, $r->created_by ?? '-', $r->created_at];
                }
            }
            $this->table(['meeting_id', 'meeting', 'report_id', 'created_by', 'created_at'], $rows);
            $this->line('Decide which report is official for each meeting, remove or re-link the others by hand, then run this command again with --create-index.');
        }

        if (! $this->option('create-index')) {
            return $groups->isEmpty() ? self::SUCCESS : self::FAILURE;
        }

        if ($groups->isNotEmpty()) {
            $this->error('Index NOT created: duplicates remain.');

            return self::FAILURE;
        }

        try {
            Schema::table('meeting_reports', fn (Blueprint $t) => $t->unique('meeting_id', 'meeting_reports_meeting_id_unique'));
            $this->info('Unique index meeting_reports_meeting_id_unique created.');
        } catch (\Throwable $e) {
            $this->line('Index not created (it may already exist): '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
