<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('coupon_id')->nullable()->after('course_id')->constrained('coupons')->nullOnDelete();
            $table->decimal('discount_amount', 10, 2)->default(0)->after('subtotal');
            // Snapshots — historical invoices must stay correct even if the
            // coupon is edited/deleted later. `subtotal` already holds the
            // original (pre-discount) amount and `total` the final amount,
            // so only the coupon's own identity/config needs snapshotting.
            $table->string('coupon_code_snapshot', 50)->nullable()->after('discount_amount');
            $table->string('discount_type_snapshot', 20)->nullable()->after('coupon_code_snapshot');
            $table->decimal('discount_value_snapshot', 10, 2)->nullable()->after('discount_type_snapshot');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('coupon_id')->nullable()->after('registration_id')->constrained('coupons')->nullOnDelete();
            $table->decimal('discount_amount', 10, 2)->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coupon_id');
            $table->dropColumn(['discount_amount', 'coupon_code_snapshot', 'discount_type_snapshot', 'discount_value_snapshot']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coupon_id');
            $table->dropColumn('discount_amount');
        });
    }
};
