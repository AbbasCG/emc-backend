<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_accounts', function (Blueprint $table) {
            // Identifies which payment provider routes to this account.
            // Examples: stripe, paypal, cash, bank, wallet, manual
            $table->string('provider', 50)->nullable()->after('type');

            // Marks the default account for this provider+currency combination.
            // When Stripe confirms a EUR payment, the system uses the account
            // where provider='stripe', currency='EUR', is_default_for_provider=true.
            $table->boolean('is_default_for_provider')->default(false)->after('provider');
        });
    }

    public function down(): void
    {
        Schema::table('finance_accounts', function (Blueprint $table) {
            $table->dropColumn(['provider', 'is_default_for_provider']);
        });
    }
};
