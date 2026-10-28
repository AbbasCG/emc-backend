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
        // Re-runnable: on MariaDB/MySQL DDL is not transactional, so a previous failed attempt may have added the column while
        // the migration was never recorded.
        if (! Schema::hasColumn('accounts', 'default_for_currency')) {
            Schema::table('accounts', function (Blueprint $table) {
                // e.g. 'USD', 'YER', 'SAR', 'EUR' — or NULL (not a default)
                $table->string('default_for_currency', 10)
                    ->nullable()
                    ->after('is_selectable')
                    ->comment('If set, this account is the system default deposit/payment account for this currency.');
            });
        }

        // "Two accounts can never be the default of the same currency". MySQL/MariaDB have no PARTIAL unique index
        // (`... WHERE col IS NOT NULL` is a syntax error there), but a plain unique index gives the same guarantee on every
        // supported database: MySQL, MariaDB, SQLite and PostgreSQL all allow any number of NULLs in a unique index.
        if (! Schema::hasIndex('accounts', 'accounts_default_for_currency_unique')) {
            Schema::table('accounts', function (Blueprint $table) {
                $table->unique('default_for_currency', 'accounts_default_for_currency_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('accounts', 'accounts_default_for_currency_unique')) {
            Schema::table('accounts', function (Blueprint $table) {
                $table->dropUnique('accounts_default_for_currency_unique');
            });
        }
        if (Schema::hasColumn('accounts', 'default_for_currency')) {
            Schema::table('accounts', function (Blueprint $table) {
                $table->dropColumn('default_for_currency');
            });
        }
    }
};
