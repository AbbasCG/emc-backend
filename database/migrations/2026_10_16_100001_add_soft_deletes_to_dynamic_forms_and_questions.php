<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forms Phase 0 — data safety.
 *
 * Deleting a form or a question used to cascade at the database level and silently destroy every submission and
 * answer that referenced it. Both tables now soft-delete instead, so the application never fires those cascades;
 * the answers stay attached to their (hidden) question and the submissions to their (hidden) form.
 *
 * Purely additive: one nullable column per table, no existing row is read or changed, and the foreign keys are
 * left exactly as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['dynamic_forms', 'form_questions'] as $table) {
            if (! Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->softDeletes();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['dynamic_forms', 'form_questions'] as $table) {
            if (Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropSoftDeletes();
                });
            }
        }
    }
};
