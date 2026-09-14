<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2B — ROLE DEFAULT PAGE ACCESS.
 *
 * Stores, per role, which PageAccessCatalog pages are part of that role's
 * DEFAULT page access.
 *
 * WHY A DEDICATED TABLE RATHER THAN REUSING permission_role:
 *   1. 41 of the 129 catalog entries (32%) have NO required_permission at all
 *      (department workspaces, manager dashboards, personal pages, role-home
 *      overviews). Permissions physically cannot express those pages.
 *   2. The mapping that does exist is many-to-one, not one-to-one: 50 distinct
 *      permissions back 88 pages, and 19 of those permissions cover more than
 *      one page (e.g. view_reports backs 4 different pages). Page access is a
 *      strictly finer granularity than permission access.
 *   3. Overloading permission_role would make one row mean two different
 *      things, so revoking a business permission for a genuine authorization
 *      reason would silently change unrelated page exposure — exactly the
 *      "two conflicting systems with no clear precedence" outcome to avoid.
 *
 * PAGE ACCESS != BUSINESS AUTHORIZATION. A row here never grants a business
 * permission; Permissions/policies/Gates never read this table.
 *
 * `page_key` intentionally has NO foreign key — PageAccessCatalog is a
 * code-backed registry, not a table. Validity and eligibility are enforced by
 * RolePageDefaultService, the single place that rule lives.
 *
 * Purely additive: creates one new table, touches no existing table, column,
 * index or constraint. No back-fill, no destructive operation, fully
 * reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_page_defaults', function (Blueprint $table) {
            $table->id();

            // Deleting a role removes its page defaults — they are meaningless
            // without it. (System roles are protected from deletion separately
            // by RoleController::destroy()'s is_system guard.)
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();

            // Canonical PageAccessCatalog stable key, e.g. 'finance.transactions'.
            $table->string('page_key', 191);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One definition per role + page — enforced at the DB level, not
            // only in application validation. Phase 2B is positive-defaults
            // only, so no audience/state column is needed.
            $table->unique(['role_id', 'page_key'], 'role_page_defaults_unique');

            // Primary read path: "all defaults for this role".
            $table->index('role_id', 'role_page_defaults_role_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_page_defaults');
    }
};
