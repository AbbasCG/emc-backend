<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_account_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('destination_account_id')->nullable()->after('finance_account_id');
            $table->string('external_reference')->nullable()->after('receipt_path');
            $table->unsignedBigInteger('reviewed_by')->nullable()->after('updated_by');
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');

            $table->foreign('destination_account_id')
                  ->references('id')
                  ->on('finance_accounts')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('finance_account_transactions', function (Blueprint $table) {
            $table->dropForeign(['destination_account_id']);
            $table->dropColumn(['destination_account_id', 'external_reference', 'reviewed_by', 'reviewed_at']);
        });
    }
};
