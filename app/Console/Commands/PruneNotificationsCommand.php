<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneNotificationsCommand extends Command
{
    protected $signature   = 'emc:prune-notifications {--days=90 : Delete read notifications older than this many days}';
    protected $description = 'Delete old read platform notifications to keep the table lean.';

    public function handle(): int
    {
        $days      = (int) $this->option('days');
        $threshold = now()->subDays($days);

        $deleted = DB::table('platform_notifications')
            ->where('is_read', true)
            ->where('created_at', '<', $threshold)
            ->delete();

        $this->info("Pruned {$deleted} read notification(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
