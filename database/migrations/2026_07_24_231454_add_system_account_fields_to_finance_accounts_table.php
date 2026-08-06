<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('finance_accounts', function (Blueprint $table) {
            // Stable technical identity, distinct from the human-editable `name`
            // (e.g. "Stripe – iDEAL / Wero Payments" can be renamed; STRIPE_IDEAL
            // must not, since code — not name — is how the webhook resolves it).
            $table->string('code', 50)->nullable()->unique()->after('id');
            // Which specific payment method this gateway account represents —
            // distinguishes Stripe Card from Stripe iDEAL, both provider=stripe.
            $table->string('payment_method', 50)->nullable()->after('provider');
            // System-provisioned accounts (Stripe gateway accounts, etc.) that
            // the webhook/checkout flow depends on — must not be deletable or
            // have their technical identity (code/provider/payment_method)
            // changed. Enforced in the model, not only hidden in the UI.
            $table->boolean('is_system')->default(false)->after('is_default_for_provider');
            $table->boolean('is_deletable')->default(true)->after('is_system');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('finance_accounts', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn(['code', 'payment_method', 'is_system', 'is_deletable']);
        });
    }
};
