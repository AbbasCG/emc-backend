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
        Schema::create('finance_account_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('finance_account_id');
            $table->string('type'); // revenue|expense|transfer|balance_adjustment
            $table->string('category')->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 10)->default('EUR');
            $table->string('status')->default('confirmed'); // confirmed|pending|cancelled
            $table->text('description')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('receipt_path')->nullable();
            $table->date('transaction_date');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('finance_account_id')->references('id')->on('finance_accounts')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('finance_account_transactions');
    }
};
