<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            // Renamed concept from the existing single-target
            // applies_to_type/applies_to_id (morph, unused by any real
            // checkout flow today) to an explicit enum + normalized pivot —
            // supports "all paid courses" and "selected courses" without
            // storing course IDs as JSON.
            $table->string('eligibility_type', 30)->default('all_paid_courses')->after('applies_to_id');

            $table->decimal('maximum_discount_amount', 10, 2)->nullable()->after('discount_value');
            $table->decimal('minimum_order_amount', 10, 2)->nullable()->after('maximum_discount_amount');
            $table->string('currency', 3)->nullable()->after('minimum_order_amount');

            $table->unsignedInteger('usage_limit_per_user')->nullable()->after('max_uses');
            $table->boolean('first_order_only')->default(false)->after('usage_limit_per_user');
            $table->boolean('individual_use_only')->default(false)->after('first_order_only');

            $table->foreignId('created_by')->nullable()->after('individual_use_only')->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();

            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropColumn([
                'eligibility_type', 'maximum_discount_amount', 'minimum_order_amount', 'currency',
                'usage_limit_per_user', 'first_order_only', 'individual_use_only', 'deleted_at',
            ]);
        });
    }
};
