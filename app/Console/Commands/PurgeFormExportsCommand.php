<?php

namespace App\Console\Commands;

use App\Services\Forms\FormExportService;
use Illuminate\Console\Command;

class PurgeFormExportsCommand extends Command
{
    protected $signature = 'forms:purge-exports';

    protected $description = 'Delete the private files of response exports that have expired (they are kept for 24 hours).';

    public function handle(FormExportService $exports): int
    {
        $this->info('Expired form exports removed: '.$exports->purgeExpired());

        return self::SUCCESS;
    }
}
