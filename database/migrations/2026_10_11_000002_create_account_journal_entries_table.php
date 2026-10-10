<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('voucher_no', 50)->index();
            $table->string('transaction_type', 50)->default('expense'); // expense, manual_payment, journal_entry
            $table->foreignId('manual_expense_id')->nullable()->constrained('manual_expenses')->onDelete('cascade');
            $table->foreignId('account_id')->constrained('accounts')->onDelete('restrict');
            $table->enum('entry_type', ['debit', 'credit']);
            $table->decimal('debit_amount', 15, 2)->default(0.00);
            $table->decimal('credit_amount', 15, 2)->default(0.00);
            $table->string('currency', 10)->default('USD');
            $table->date('date');
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            $table->index(['account_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_journal_entries');
    }
};
