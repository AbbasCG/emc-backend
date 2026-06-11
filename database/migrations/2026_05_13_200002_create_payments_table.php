<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registration_id')->constrained('registrations')->cascadeOnDelete();
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('provider')->nullable();
            $table->string('provider_payment_id')->nullable();
            $table->text('checkout_url')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('receipt_url')->nullable();
            $table->string('status')->default('pending_payment');
            $table->timestamp('confirmed_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('status');
            $table->index('provider');
            $table->index(['provider', 'status'], 'payments_provider_status_index');
            $table->index(['provider', 'provider_payment_id'], 'payments_provider_pid_index');
            $table->unique(['provider', 'provider_payment_id'], 'payments_provider_pid_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
