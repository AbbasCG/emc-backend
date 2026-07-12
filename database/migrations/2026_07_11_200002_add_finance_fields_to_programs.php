<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── courses ──────────────────────────────────────────────────────────────
        Schema::table('courses', function (Blueprint $table) {
            if (! Schema::hasColumn('courses', 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->after('created_by')
                    ->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('courses', 'finance_approval_status')) {
                $table->enum('finance_approval_status', ['not_required', 'pending', 'approved', 'rejected'])
                    ->default('not_required')
                    ->after('updated_by');
            }
            if (! Schema::hasColumn('courses', 'submitted_for_finance_at')) {
                $table->timestamp('submitted_for_finance_at')->nullable()->after('finance_approval_status');
            }
        });

        // Indexes (idempotent)
        $this->addIndexIfMissing('courses', 'courses_finance_approval_status_index', 'finance_approval_status');
        $this->addIndexIfMissing('courses', 'courses_created_by_index', 'created_by');

        // Backfill: paid courses that are already published → approved (existing data)
        // Paid courses that are not published → pending approval (new workflow)
        DB::statement("
            UPDATE courses
            SET finance_approval_status = CASE
                WHEN (is_paid = 1 OR type = 'paid' OR price > 0)
                     AND status = 'published' THEN 'approved'
                WHEN (is_paid = 1 OR type = 'paid' OR price > 0)
                     AND status != 'published' THEN 'pending'
                ELSE 'not_required'
            END
            WHERE finance_approval_status = 'not_required'
        ");

        // ── learning_paths ────────────────────────────────────────────────────────
        Schema::table('learning_paths', function (Blueprint $table) {
            if (! Schema::hasColumn('learning_paths', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('instructor_id')
                    ->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('learning_paths', 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->after('created_by')
                    ->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('learning_paths', 'currency')) {
                $table->string('currency', 3)->default('EUR')->after('discount_price');
            }
            if (! Schema::hasColumn('learning_paths', 'finance_approval_status')) {
                $table->enum('finance_approval_status', ['not_required', 'pending', 'approved', 'rejected'])
                    ->default('not_required')
                    ->after('currency');
            }
            if (! Schema::hasColumn('learning_paths', 'submitted_for_finance_at')) {
                $table->timestamp('submitted_for_finance_at')->nullable()->after('finance_approval_status');
            }
        });

        $this->addIndexIfMissing('learning_paths', 'lp_finance_approval_status_index', 'finance_approval_status');
        $this->addIndexIfMissing('learning_paths', 'lp_created_by_index', 'created_by');

        // Backfill learning_paths same logic
        DB::statement("
            UPDATE learning_paths
            SET finance_approval_status = CASE
                WHEN price > 0 AND status = 'published' THEN 'approved'
                WHEN price > 0 AND status != 'published' THEN 'pending'
                ELSE 'not_required'
            END
            WHERE finance_approval_status = 'not_required'
        ");
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumnIfExists(['updated_by', 'finance_approval_status', 'submitted_for_finance_at']);
        });
        Schema::table('learning_paths', function (Blueprint $table) {
            $table->dropColumnIfExists(['created_by', 'updated_by', 'currency', 'finance_approval_status', 'submitted_for_finance_at']);
        });
    }

    private function addIndexIfMissing(string $table, string $indexName, string $column): void
    {
        $indexes = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);
        if (empty($indexes)) {
            Schema::table($table, fn (Blueprint $t) => $t->index([$column], $indexName));
        }
    }
};
