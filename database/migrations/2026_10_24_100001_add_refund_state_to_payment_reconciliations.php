<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tracking flag for the follow-up of a case that was closed without enrollment: `pending` = finance decided money is owed back and
 * it has to be returned OUTSIDE the platform. It is a to-do marker, never a payment status: nothing here (or anywhere in the
 * platform) marks a payment as refunded, because the platform holds no verified refund evidence to base that on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_reconciliations') && ! Schema::hasColumn('payment_reconciliations', 'refund_status')) {
            Schema::table('payment_reconciliations', function (Blueprint $table) {
                $table->string('refund_status', 20)->nullable()->after('resolution');   // null | pending
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payment_reconciliations', 'refund_status')) {
            Schema::table('payment_reconciliations', function (Blueprint $table) {
                $table->dropColumn('refund_status');
            });
        }
    }
};
