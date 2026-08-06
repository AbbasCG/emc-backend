<?php

namespace App\Console\Commands;

use App\Models\FinanceAccount;
use App\Models\ManualPayment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditManualPaymentCurrencies extends Command
{
    protected $signature = 'finance:audit-currency-mismatches
                            {--fix : Auto-correct currency on pending_review records to match their account}
                            {--dry-run : Print what --fix would change without writing anything}';

    protected $description = 'Audit manual_payments for currency mismatches with their finance account';

    public function handle(): int
    {
        $mismatches = DB::table('manual_payments as mp')
            ->join('finance_accounts as fa', 'fa.id', '=', 'mp.finance_account_id')
            ->whereColumn('mp.currency', '!=', 'fa.currency')
            ->whereNull('mp.deleted_at')
            ->select([
                'mp.id',
                'mp.status',
                'mp.paid_amount',
                'mp.currency as payment_currency',
                'fa.id as account_id',
                'fa.name as account_name',
                'fa.currency as account_currency',
            ])
            ->orderBy('mp.id')
            ->get();

        if ($mismatches->isEmpty()) {
            $this->info('No currency mismatches found. All manual payments are consistent.');
            return self::SUCCESS;
        }

        $this->warn("Found {$mismatches->count()} currency mismatch(es):");
        $this->table(
            ['ID', 'Status', 'Paid Amount', 'Payment Currency', 'Account', 'Account Currency'],
            $mismatches->map(fn ($r) => [
                $r->id,
                $r->status,
                $r->paid_amount,
                $r->payment_currency,
                "{$r->account_name} (#{$r->account_id})",
                $r->account_currency,
            ])->all()
        );

        $fixable = $mismatches->where('status', 'pending_review');
        $blocked = $mismatches->where('status', '!=', 'pending_review');

        if ($blocked->isNotEmpty()) {
            $this->error(
                "{$blocked->count()} record(s) are already confirmed/rejected/cancelled and CANNOT be auto-corrected. " .
                "These must be reviewed manually — the paid_amount may be in the wrong unit."
            );
        }

        if ($fixable->isEmpty()) {
            $this->line('No pending_review records eligible for auto-correction.');
            return self::FAILURE;
        }

        $isDryRun = $this->option('dry-run');
        $doFix    = $this->option('fix');

        if (! $doFix && ! $isDryRun) {
            $this->line('Run with --fix to correct pending_review records, or --dry-run to preview changes.');
            return self::FAILURE;
        }

        $this->line('');
        $this->line($isDryRun ? '[DRY RUN] Would fix the following pending_review records:' : 'Fixing pending_review records...');

        foreach ($fixable as $r) {
            $this->line("  MP#{$r->id}: {$r->payment_currency} → {$r->account_currency} (account: {$r->account_name})");
            if (! $isDryRun) {
                ManualPayment::where('id', $r->id)->update(['currency' => $r->account_currency]);
            }
        }

        if (! $isDryRun) {
            $this->info("{$fixable->count()} pending_review record(s) corrected.");
        }

        return self::SUCCESS;
    }
}
