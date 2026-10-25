<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `default_for_currency` to the `accounts` table.
 *
 * This allows the system to automatically resolve the correct
 * cash/bank account for a given currency when creating revenue
 * or expense vouchers, without requiring the user to manually
 * select a deposit/payment account in the UI.
 *
 * Rules enforced by a unique partial index:
 *  - At most ONE account can be the default for each currency.
 *  - NULL means "not a default" and is never included in the index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            // e.g. 'USD', 'YER', 'SAR', 'EUR' — or NULL (not a default)
            $table->string('default_for_currency', 10)
                  ->nullable()
                  ->after('is_selectable')
                  ->comment('If set, this account is the system default deposit/payment account for this currency.');
        });

        // SQLite does not support partial unique indexes via Blueprint,
        // so we use a raw statement that works on both SQLite and MySQL/Postgres.
        // The unique constraint prevents two accounts from being the default
        // for the same currency at the same time.
        DB::statement(
            'CREATE UNIQUE INDEX accounts_default_for_currency_unique
             ON accounts (default_for_currency)
             WHERE default_for_currency IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('default_for_currency');
        });
    }
};
