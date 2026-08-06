<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_accounts', function (Blueprint $table) {
            $table->string('bank_name')->nullable()->after('notes');
            $table->string('account_holder')->nullable()->after('bank_name');
            $table->string('iban', 34)->nullable()->after('account_holder');
            $table->string('account_number')->nullable()->after('iban');
        });
    }

    public function down(): void
    {
        Schema::table('finance_accounts', function (Blueprint $table) {
            $table->dropColumn(['bank_name', 'account_holder', 'iban', 'account_number']);
        });
    }
};
