<?php

namespace App\Console\Commands;

use App\Models\FinanceAccount;
use App\Models\FinanceAccountTransaction;
use App\Models\ManualPayment;
use App\Models\Payment;
use App\Support\PaymentStatuses;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Detects gaps between the payments table and the finance_account_transactions ledger.
 *
 * Safe to run any number of times — read-only unless --fix is passed.
 *
 * php artisan finance:reconcile --dry-run
 * php artisan finance:reconcile --fix
 */
class ReconcileFinanceLedger extends Command
{
    protected $signature = 'finance:reconcile
                            {--fix : Create missing ledger entries for confirmed manual payments (Stripe entries require a configured Stripe account)}
                            {--dry-run : Show what would be created without writing anything}';

    protected $description = 'Detect and optionally repair gaps between payments and the finance ledger';

    public function handle(): int
    {
        $this->line('');
        $this->info('=== EMC Finance Ledger Reconciliation ===');
        $this->line('');

        $isDryRun = $this->option('dry-run');
        $doFix    = $this->option('fix');

        $totalIssues = 0;

        // ── 1. Confirmed manual payments without a ledger entry ────────────────
        $totalIssues += $this->checkManualPaymentLedgerGaps($isDryRun, $doFix);

        // ── 2. Confirmed Stripe payments without a ledger entry ───────────────
        $totalIssues += $this->checkStripeLedgerGaps($isDryRun, $doFix);

        // ── 3. Rejected/cancelled manual payments that have a ledger entry ────
        $totalIssues += $this->checkIllegalLedgerEntries();

        // ── 4. Account balance vs ledger mismatch ─────────────────────────────
        $totalIssues += $this->checkBalanceMismatches();

        // ── 5. Duplicate ledger entries (same payment, multiple entries) ───────
        $totalIssues += $this->checkDuplicateLedgerEntries();

        $this->line('');
        if ($totalIssues === 0) {
            $this->info('✓ No issues found. Ledger is consistent.');
        } else {
            $this->warn("Found {$totalIssues} issue(s) total.");
            if (! $doFix && ! $isDryRun) {
                $this->line('Run with --dry-run to preview fixes, or --fix to apply repairs.');
            }
        }

        return $totalIssues > 0 ? self::FAILURE : self::SUCCESS;
    }

    // ─── Check 1 ──────────────────────────────────────────────────────────────

    private function checkManualPaymentLedgerGaps(bool $isDryRun, bool $doFix): int
    {
        $this->line('[ CHECK 1 ] Confirmed manual payments without ledger entry');

        $gaps = ManualPayment::where('status', 'confirmed')
            ->whereNull('deleted_at')
            ->whereNotExists(function ($q) {
                $q->from('finance_account_transactions as fat')
                  ->where('fat.reference_type', 'manual_payment')
                  ->whereColumn('fat.reference_id', 'manual_payments.id');
            })
            ->with('financeAccount')
            ->get();

        if ($gaps->isEmpty()) {
            $this->line('  ✓ None found.');
            return 0;
        }

        $this->warn("  Found {$gaps->count()} confirmed manual payment(s) missing ledger entries:");

        foreach ($gaps as $mp) {
            $this->line("  MP#{$mp->id} — {$mp->paid_amount} {$mp->currency} — account: " . ($mp->financeAccount?->name ?? 'N/A'));

            if (($doFix || $isDryRun) && $mp->financeAccount) {
                $label = $isDryRun ? '[DRY-RUN] Would create' : 'Creating';
                $this->line("    {$label} ledger entry on account #{$mp->finance_account_id}");

                if ($doFix) {
                    FinanceAccountTransaction::create([
                        'finance_account_id' => $mp->finance_account_id,
                        'type'               => 'revenue',
                        'category'           => 'course_revenue',
                        'amount'             => (float) $mp->paid_amount,
                        'currency'           => $mp->financeAccount->currency,
                        'status'             => 'confirmed',
                        'description'        => "Reconciled: manual payment #{$mp->id}",
                        'payment_method'     => $mp->payment_method,
                        'reference_type'     => 'manual_payment',
                        'reference_id'       => $mp->id,
                        'external_reference' => $mp->external_reference,
                        'transaction_date'   => $mp->payment_date ?? now()->toDateString(),
                        'created_by'         => null,
                    ]);
                }
            }
        }

        return $gaps->count();
    }

    // ─── Check 2 ──────────────────────────────────────────────────────────────

    private function checkStripeLedgerGaps(bool $isDryRun, bool $doFix): int
    {
        $this->line('[ CHECK 2 ] Confirmed Stripe payments without ledger entry');

        $gaps = Payment::where('status', PaymentStatuses::CONFIRMED)
            ->where('provider', 'stripe')
            ->whereNotExists(function ($q) {
                $q->from('finance_account_transactions as fat')
                  ->where('fat.reference_type', 'payment')
                  ->whereColumn('fat.reference_id', 'payments.id');
            })
            ->with('order')
            ->get();

        if ($gaps->isEmpty()) {
            $this->line('  ✓ None found.');
            return 0;
        }

        $this->warn("  Found {$gaps->count()} confirmed Stripe payment(s) missing ledger entries:");

        foreach ($gaps as $payment) {
            $currency = strtoupper($payment->order?->currency ?? 'EUR');
            $account  = FinanceAccount::forProvider('stripe', $currency);
            $accountLabel = $account ? $account->name : '⚠ no Stripe account for ' . $currency;
            $this->line("  Payment#{$payment->id} — {$payment->amount} {$currency} — {$accountLabel}");

            if (($doFix || $isDryRun) && $account) {
                $label = $isDryRun ? '[DRY-RUN] Would create' : 'Creating';
                $this->line("    {$label} ledger entry on Stripe account #{$account->id}");

                if ($doFix) {
                    $alreadyExists = FinanceAccountTransaction::where('reference_type', 'payment')
                        ->where('reference_id', $payment->id)
                        ->exists();

                    if (! $alreadyExists) {
                        FinanceAccountTransaction::create([
                            'finance_account_id' => $account->id,
                            'type'               => 'revenue',
                            'category'           => 'course_revenue',
                            'amount'             => (float) $payment->amount,
                            'currency'           => $account->currency,
                            'status'             => 'confirmed',
                            'description'        => "Reconciled: Stripe payment #{$payment->id}",
                            'payment_method'     => 'stripe',
                            'reference_type'     => 'payment',
                            'reference_id'       => $payment->id,
                            'external_reference' => $payment->provider_payment_id,
                            'transaction_date'   => $payment->confirmed_at?->toDateString() ?? now()->toDateString(),
                            'created_by'         => null,
                        ]);
                        $account->increment('current_balance', (float) $payment->amount);
                    }
                }
            }
        }

        return $gaps->count();
    }

    // ─── Check 3 ──────────────────────────────────────────────────────────────

    private function checkIllegalLedgerEntries(): int
    {
        $this->line('[ CHECK 3 ] Rejected/cancelled manual payments with revenue ledger entries');

        $illegal = ManualPayment::whereIn('status', ['rejected', 'cancelled'])
            ->whereNull('deleted_at')
            ->whereExists(function ($q) {
                $q->from('finance_account_transactions as fat')
                  ->where('fat.reference_type', 'manual_payment')
                  ->where('fat.type', 'revenue')
                  ->whereColumn('fat.reference_id', 'manual_payments.id');
            })
            ->get();

        if ($illegal->isEmpty()) {
            $this->line('  ✓ None found.');
            return 0;
        }

        $this->error("  Found {$illegal->count()} rejected/cancelled payment(s) with income ledger entries — MANUAL REVIEW REQUIRED:");
        foreach ($illegal as $mp) {
            $this->line("  MP#{$mp->id} status={$mp->status} — inspect finance_account_transactions WHERE reference_type='manual_payment' AND reference_id={$mp->id}");
        }

        return $illegal->count();
    }

    // ─── Check 4 ──────────────────────────────────────────────────────────────

    private function checkBalanceMismatches(): int
    {
        $this->line('[ CHECK 4 ] Account balance vs ledger total mismatches');

        $accounts = FinanceAccount::where('is_active', true)->get();
        $issues   = 0;

        foreach ($accounts as $account) {
            $ledgerBalance = $this->computeLedgerBalance($account->id, (float) $account->opening_balance);
            $cachedBalance = round((float) $account->current_balance, 2);
            $diff          = round(abs($ledgerBalance - $cachedBalance), 2);

            if ($diff > 0.01) {
                $issues++;
                $this->warn("  Account #{$account->id} '{$account->name}' ({$account->currency}): cached={$cachedBalance}, ledger={$ledgerBalance}, diff={$diff}");
            }
        }

        if ($issues === 0) {
            $this->line('  ✓ All account balances match their ledger totals.');
        }

        return $issues;
    }

    // ─── Check 5 ──────────────────────────────────────────────────────────────

    private function checkDuplicateLedgerEntries(): int
    {
        $this->line('[ CHECK 5 ] Duplicate ledger entries (same payment, multiple entries)');

        $duplicates = DB::table('finance_account_transactions')
            ->whereNotNull('reference_type')
            ->whereNotNull('reference_id')
            ->select('reference_type', 'reference_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('reference_type', 'reference_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isEmpty()) {
            $this->line('  ✓ None found.');
            return 0;
        }

        $this->warn("  Found {$duplicates->count()} source(s) with duplicate ledger entries:");
        foreach ($duplicates as $d) {
            $this->line("  {$d->reference_type} #{$d->reference_id} — {$d->cnt} entries");
        }

        return $duplicates->count();
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function computeLedgerBalance(int $accountId, float $openingBalance): float
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
