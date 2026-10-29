<?php

namespace App\Console\Commands;

use App\Services\Forms\FormRetentionService;
use Illuminate\Console\Command;

class PurgeFormResponsesCommand extends Command
{
    protected $signature = 'forms:purge-responses {--dry-run : List what would be deleted without deleting anything}';

    protected $description = 'Delete the responses (and their files) of forms whose owner set a retention period, once they are older than it.';

    public function handle(FormRetentionService $retention): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $report = $retention->purge($dryRun);

        foreach ($report as $row) {
            $this->line(sprintf('form #%d: %d response(s) %s', $row['form_id'], $row['responses'], $dryRun ? 'would be deleted' : 'deleted'));
        }
        $this->info(($dryRun ? 'Dry run — responses that would be deleted: ' : 'Responses deleted: ').array_sum(array_column($report, 'responses')));

        return self::SUCCESS;
    }
}
