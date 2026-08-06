<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            // Mirrors FinanceAccount's is_system/is_deletable pattern — lets
            // the Chart of Accounts protect system-managed rows (e.g. a
            // future STRIPE_CARD/STRIPE_IDEAL ledger entry) from deletion or
            // identity changes once finance_manager gets write access.
            $table->boolean('is_system')->default(false)->after('is_selectable');
            $table->boolean('is_deletable')->default(true)->after('is_system');
            $table->foreignId('created_by')->nullable()->after('is_deletable')->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropColumn(['is_system', 'is_deletable']);
        });
    }
};
