<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_journal_entries', function (Blueprint $table) {
            $table->foreignId('manual_revenue_id')->nullable()->after('manual_expense_id')->constrained('manual_revenues')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('account_journal_entries', function (Blueprint $table) {
            $table->dropForeign(['manual_revenue_id']);
            $table->dropColumn('manual_revenue_id');
        });
    }
};
