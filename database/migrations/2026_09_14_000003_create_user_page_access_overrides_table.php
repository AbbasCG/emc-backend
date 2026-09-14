<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2C — USER PAGE ACCESS OVERRIDES.
 *
 * Per-user explicit ALLOW / DENY for a PageAccessCatalog page.
 *
 * DEFAULT IS THE ABSENCE OF A ROW. There is no 'default' state value — reset
 * deletes the row. This keeps exactly one representation per meaning and makes
 * the future resolver unambiguous.
 *
 * PAGE ACCESS != BUSINESS AUTHORIZATION. A row here never grants a business
 * permission. An ALLOW may expose a page the user's role/department defaults
 * do not include, but performing anything on that page still runs through
 * Permissions/policies/Gates/DepartmentAccessService untouched.
 *
 * `page_key` intentionally has NO foreign key — PageAccessCatalog is a
 * code-backed registry, not a table. Validity, page eligibility and actor
 * authority are all enforced by UserPageAccessOverrideService, the single
 * place those rules live.
 *
 * Purely additive: creates one new table, touches no existing table, column,
 * index or constraint. No back-fill, no destructive operation, fully
 * reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_page_access_overrides', function (Blueprint $table) {
            $table->id();

            // The override dies with the user it belongs to.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Canonical PageAccessCatalog stable key, e.g. 'quality.dashboard'.
            $table->string('page_key', 191);

            // 'allow' | 'deny' (App\Support\PageAccess\UserPageAccessState).
            // No 'default' — that is the absence of a row.
            $table->string('state', 16);

            // Accountability metadata on the row itself. This does NOT replace
            // AuditLogService/Auditable history; it records provenance.
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();

            // Set when a DEPARTMENT LEADER created the override (the department
            // they actually lead, via departments.leader_id). NULL when a global
            // admin (super_admin/tech_admin) created it.
            //
            // cascadeOnDelete is deliberate and fail-closed: a department-scoped
            // grant must never outlive its department. nullOnDelete was rejected
            // because it would silently promote a department-scoped override into
            // an indistinguishable global one. (Departments are archived, not
            // deleted, in normal operation — see Phase 2A.)
            $table->foreignId('source_department_id')->nullable()->constrained('departments')->cascadeOnDelete();

            $table->timestamps();

            // At most ONE override per user per page — enforced at the DB level,
            // not only in application validation. ALLOW -> DENY updates this row.
            $table->unique(['user_id', 'page_key'], 'user_page_overrides_unique');

            // Primary read path: "all overrides for this user".
            $table->index('user_id', 'user_page_overrides_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_page_access_overrides');
    }
};
