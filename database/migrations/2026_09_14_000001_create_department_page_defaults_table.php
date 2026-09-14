<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2A — DEPARTMENT DEFAULT PAGE ACCESS.
 *
 * Stores, per department, which PageAccessCatalog pages are granted BY DEFAULT
 * to that department's members and (additionally) to its canonical leader.
 *
 * PAGE ACCESS != BUSINESS AUTHORIZATION. A row here never grants a business
 * permission; it only expresses "this dashboard page is exposed by default to
 * this audience in this department". Business actions continue to run through
 * Permissions/policies/DepartmentAccessService/controller checks, unchanged.
 *
 * `page_key` intentionally has NO foreign key: PageAccessCatalog is a
 * code-backed registry (app/Support/PageAccess/PageAccessCatalog.php), not a
 * table. Validity AND eligibility are enforced in the application layer by
 * DepartmentPageDefaultService, which is the single place that rule lives.
 *
 * This migration is purely additive: it creates one new table and touches no
 * existing table, column, index or constraint. Nothing is dropped, renamed or
 * back-filled, so it is safe to run against existing production data and is
 * fully reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_page_defaults', function (Blueprint $table) {
            $table->id();

            // Deleting a department removes its defaults — they are meaningless
            // without it. NOTE: archiving is NOT deletion; an archived
            // department (status='archived') keeps every row here untouched.
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();

            // Canonical PageAccessCatalog stable key, e.g. 'operations.weekly_reports'.
            $table->string('page_key', 191);

            // 'member' | 'leader' (App\Support\PageAccess\DepartmentPageAudience)
            $table->string('audience', 16);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One definition per department + page + audience — enforced at the
            // DB level, not only in application validation.
            $table->unique(['department_id', 'page_key', 'audience'], 'dept_page_defaults_unique');

            // Primary read path: "all defaults for this department (+audience)".
            $table->index(['department_id', 'audience'], 'dept_page_defaults_dept_audience_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_page_defaults');
    }
};
