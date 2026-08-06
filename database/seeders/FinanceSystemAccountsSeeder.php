<?php

namespace Database\Seeders;

use App\Models\FinanceAccount;
use Illuminate\Database\Seeder;

/**
 * Idempotent — reuses the same firstOrCreate-by-code logic as
 * `finance:ensure-system-accounts`, so running the full seeder suite
 * repeatedly (local dev, CI) never creates duplicate system accounts.
 */
class FinanceSystemAccountsSeeder extends Seeder
{
    public function run(): void
    {
        FinanceAccount::ensureSystemAccounts();
    }
}
