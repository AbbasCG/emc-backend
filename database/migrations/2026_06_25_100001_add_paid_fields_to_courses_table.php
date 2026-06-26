<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            if (! Schema::hasColumn('courses', 'is_paid')) {
                $table->boolean('is_paid')->default(false)->after('is_free');
            }
            if (! Schema::hasColumn('courses', 'currency')) {
                $table->string('currency', 3)->default('EUR')->after('price');
            }
            if (! Schema::hasColumn('courses', 'stripe_product_id')) {
                $table->string('stripe_product_id')->nullable()->after('currency');
            }
            if (! Schema::hasColumn('courses', 'stripe_price_id')) {
                $table->string('stripe_price_id')->nullable()->after('stripe_product_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumnIfExists(['is_paid', 'currency', 'stripe_product_id', 'stripe_price_id']);
        });
    }
};
