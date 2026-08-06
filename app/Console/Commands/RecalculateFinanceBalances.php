<?php

namespace App\Console\Commands;

use App\Models\FinanceAccount;
use App\Models\FinanceAccountTransaction;
use App\Services\AuditLogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes every FinanceAccount.current_balance from the ledger.
 *
 * Read-only by default. Pass --apply to write corrections.
 *
 * php artisan finance:recalculate-balances --dry-run
 * php artisan finance:recalculate-balances --apply
 */
class RecalculateFinanceBalances extends Command
{
    protected $signature = 'finance:recalculate-balances
                            {--dry-run : Show differences without writing}
                            {--apply  : Write corrected balances to the database}';

    protected $description = 'Recompute finance account balances from the ledger and report or fix discrepancies';

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $doApply  = $this->option('apply');

        if (! $isDryRun && ! $doApply) {
            $this->line('Pass --dry-run to preview, or --apply to write corrections.');
            return self::FAILURE;
        }

        $accounts = FinanceAccount::withTrashed()->get();
        $changed  = 0;
        $ok       = 0;

        $rows = [];
        foreach ($accounts as $account) {
            $ledger = $this->ledgerBalance($account->id, (float) $account->opening_balance);
            $cached = round((float) $account->current_balance, 2);
            $diff   = round($ledger - $cached, 2);

            if (abs($diff) <= 0.01) {
                $ok++;
                continue;
            }

            $changed++;
            $rows[] = [
                $account->id,
                $account->name,
                $account->currency,
                $cached,
                $ledger,
                $diff > 0 ? "+{$diff}" : (string) $diff,
                $account->trashed() ? 'deleted' : 'active',
            ];

            if ($doApply && ! $account->trashed()) {
                DB::transaction(function () use ($account, $ledger) {
                    $account->update(['current_balance' => $ledger]);
                    AuditLogService::log(
                        'finance_account.balance_recalculated',
                        'FinanceAccount',
                        $account->id,
                        $account->name,
                        ['current_balance' => $account->getOriginal('current_balance')],
                        ['current_balance' => $ledger],
                    );
                });
            }
        }

        $this->table(
            ['ID', 'Account', 'Currency', 'Cached', 'Ledger', 'Diff', 'Status'],
            $rows
        );

        $action = $doApply ? 'Corrected' : ($isDryRun ? 'Would correct' : 'Found');
        $this->line('');
        $this->info("{$ok} account(s) are correct. {$action} {$changed} account(s).");

        if ($isDryRun && $changed > 0) {
            $this->line('Run with --apply to write corrections.');
        }

        return $changed > 0 && ! $doApply ? self::FAILURE : self::SUCCESS;
    }

    private function ledgerBalance(int $accountId, float $openingBalance): float
    {
        $income = (float) FinanceAccountTransaction::where('finance_account_id', $accountId)
            ->where('status', 'confirmed')
            ->whereIn('type', ['revenue', 'transfer_in', 'balance_adjustment'])
            ->sum('amount');

        $outflow = (float) FinanceAccountTransaction::where('finance_account_id', $accountId)
            ->where('status', 'confirmed')
            ->whereIn('type', ['expense', 'transfer'])
            ->sum('amount');

        return round($openingBalance + $income - $outflow, 2);
    }
}
