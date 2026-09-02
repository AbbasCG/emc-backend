<?php

namespace App\Console\Commands;

use App\Models\FinanceAccount;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('finance:ensure-system-accounts')]
#[Description('Idempotently create the system-managed Stripe Card / iDEAL finance accounts (safe to run on every deploy).')]
class EnsureFinanceSystemAccountsCommand extends Command
{
    protected $signature = 'finance:ensure-system-accounts';
    protected $description = 'Idempotently create the system-managed Stripe Card / iDEAL finance accounts (safe to run on every deploy).';

    public function handle(): int
    {
        FinanceAccount::ensureSystemAccounts();

        $this->info('Finance system accounts are present: STRIPE_CARD, STRIPE_IDEAL.');

        return self::SUCCESS;
    }
}
