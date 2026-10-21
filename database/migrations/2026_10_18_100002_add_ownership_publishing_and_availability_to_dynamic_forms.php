<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forms Phase 1 — ownership, publication metadata, visibility, availability, and the version a submission used.
 *
 * Every default preserves how an existing form behaved before this change: visibility `organization` and
 * responses `form_viewers` are exactly the Phase 0 access rules, anonymous submission stays allowed, and there is
 * no schedule or limit. New forms get stricter values from the application, never from the column defaults.
 *
 * Additive only: nullable or defaulted columns, no existing row is read or rewritten here (the next migration
 * backfills ownership and versions).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dynamic_forms', function (Blueprint $table) {
            if (! Schema::hasColumn('dynamic_forms', 'owner_id')) {
                $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('dynamic_forms', 'last_edited_by')) {
                $table->foreignId('last_edited_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('dynamic_forms', 'published_by')) {
                $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('dynamic_forms', 'published_at')) {
                $table->timestamp('published_at')->nullable();
            }
            if (! Schema::hasColumn('dynamic_forms', 'published_version_id')) {
                $table->foreignId('published_version_id')->nullable()->constrained('form_versions')->nullOnDelete();
            }
            // private | organization
            if (! Schema::hasColumn('dynamic_forms', 'visibility')) {
                $table->string('visibility', 20)->default('organization');
            }
            // restricted | form_viewers
            if (! Schema::hasColumn('dynamic_forms', 'responses_visibility')) {
                $table->string('responses_visibility', 20)->default('form_viewers');
            }
            if (! Schema::hasColumn('dynamic_forms', 'opens_at')) {
                $table->timestamp('opens_at')->nullable();
            }
            if (! Schema::hasColumn('dynamic_forms', 'closes_at')) {
                $table->timestamp('closes_at')->nullable();
            }
            if (! Schema::hasColumn('dynamic_forms', 'max_submissions')) {
                $table->unsignedInteger('max_submissions')->nullable();
            }
            if (! Schema::hasColumn('dynamic_forms', 'per_respondent_limit')) {
                $table->unsignedSmallInteger('per_respondent_limit')->nullable();
            }
            if (! Schema::hasColumn('dynamic_forms', 'allow_anonymous')) {
                $table->boolean('allow_anonymous')->default(true);
            }
        });

        Schema::table('form_submissions', function (Blueprint $table) {
            if (! Schema::hasColumn('form_submissions', 'form_version_id')) {
                $table->foreignId('form_version_id')->nullable()->constrained('form_versions')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('form_submissions', function (Blueprint $table) {
            if (Schema::hasColumn('form_submissions', 'form_version_id')) {
                $table->dropConstrainedForeignId('form_version_id');
            }
        });

        Schema::table('dynamic_forms', function (Blueprint $table) {
            // the foreign key first, then the column (the index it created goes with it)
            foreach (['published_version_id', 'published_by', 'last_edited_by', 'owner_id'] as $column) {
                if (Schema::hasColumn('dynamic_forms', $column)) {
                    $table->dropConstrainedForeignId($column);
                }
            }
            $plain = array_filter(
                ['published_at', 'visibility', 'responses_visibility', 'opens_at', 'closes_at', 'max_submissions', 'per_respondent_limit', 'allow_anonymous'],
                fn ($c) => Schema::hasColumn('dynamic_forms', $c),
            );
            if ($plain !== []) {
                $table->dropColumn(array_values($plain));
            }
        });
    }
};
