<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->string('purchasable_type');
            $table->unsignedBigInteger('purchasable_id');
            $table->unsignedBigInteger('finance_account_id');
            $table->decimal('expected_amount', 14, 2);
            $table->decimal('paid_amount', 14, 2);
            $table->decimal('difference_amount', 14, 2)->default(0);
            $table->string('currency', 10)->default('EUR');
            $table->string('payment_method')->nullable();
            $table->string('external_reference')->nullable();
            $table->string('proof_path')->nullable();
            $table->string('status')->default('pending_review');
            $table->date('payment_date');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->unsignedBigInteger('finance_transaction_id')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('student_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('finance_account_id')->references('id')->on('finance_accounts')->restrictOnDelete();
            $table->foreign('finance_transaction_id')->references('id')->on('finance_account_transactions')->nullOnDelete();

            $table->index('student_id');
            $table->index('finance_account_id');
            $table->index('status');
            $table->index('payment_date');
            $table->index(['purchasable_type', 'purchasable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_payments');
    }
};
