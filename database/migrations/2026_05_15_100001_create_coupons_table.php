<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            // percentage | fixed
            $table->string('discount_type')->default('percentage');
            $table->decimal('discount_value', 10, 2);
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            // active | inactive
            $table->string('status')->default('active');
            // optionally pin coupon to a specific model (course, track, workshop)
            $table->string('applies_to_type')->nullable();
            $table->unsignedBigInteger('applies_to_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
