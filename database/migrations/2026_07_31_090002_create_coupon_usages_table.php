<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->foreignId('registration_id')->nullable()->constrained('registrations')->nullOnDelete();
            $table->string('stripe_checkout_session_id')->nullable()->index();

            $table->decimal('original_amount', 10, 2);
            $table->decimal('discount_amount', 10, 2);
            $table->decimal('final_amount', 10, 2);
            $table->string('currency', 3)->default('EUR');

            // reserved: checkout session created, not yet paid — does not
            // count toward permanent usage limits.
            // used: payment confirmed by webhook — the only status that
            // counts toward usage_limit_total/usage_limit_per_user.
            // cancelled: session abandoned/failed — reservation released.
            // reversed: refunded, per refund policy.
            // expired: reserved usage that was never completed and aged out.
            $table->string('status', 20)->default('reserved');

            $table->timestamp('used_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['coupon_id', 'status']);
            $table->index(['coupon_id', 'user_id', 'status']);
            // A given payment can finalize at most one coupon usage row —
            // the idempotency guard the webhook relies on.
            $table->unique('payment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_usages');
    }
};
