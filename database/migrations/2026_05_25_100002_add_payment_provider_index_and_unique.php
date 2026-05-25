<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Safety check: abort if duplicates exist so we don't silently lose data.
        $duplicates = DB::table('payments')
            ->select('provider', 'provider_payment_id', DB::raw('COUNT(*) as cnt'))
            ->whereNotNull('provider')
            ->whereNotNull('provider_payment_id')
            ->groupBy('provider', 'provider_payment_id')
            ->having('cnt', '>', 1)
            ->get();

        if ($duplicates->isNotEmpty()) {
            $details = $duplicates->map(fn ($r) => "{$r->provider}:{$r->provider_payment_id} ({$r->cnt}x)")->implode(', ');
            throw new \RuntimeException(
                "Cannot add unique constraint — duplicate (provider, provider_payment_id) records found: {$details}. "
                . "Deduplicate these rows manually, then re-run the migration."
            );
        }

        Schema::table('payments', function (Blueprint $table) {
            // Lookup index for webhook reconciliation (WHERE provider = ? AND provider_payment_id = ?)
            if (! $this->hasIndex('payments', 'payments_provider_pid_index')) {
                $table->index(['provider', 'provider_payment_id'], 'payments_provider_pid_index');
            }

            // Unique constraint prevents double-processing the same webhook event
            if (! $this->hasIndex('payments', 'payments_provider_pid_unique')) {
                $table->unique(['provider', 'provider_payment_id'], 'payments_provider_pid_unique');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('payments_provider_pid_unique');
            $table->dropIndex('payments_provider_pid_index');
        });
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        try {
            $indexes = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);
            return count($indexes) > 0;
        } catch (\Throwable) {
            return false;
        }
    }
};
