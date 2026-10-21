<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forms Phase 1 — immutable published versions and per-form collaborators.
 *
 * form_versions: one row per publication of a form: the exact questions, order, labels, options and settings
 * respondents saw. Rows are written once and never updated or deleted by the application (the model refuses).
 *
 * form_collaborators: employees a form is explicitly shared with, as viewer or editor, with a separate flag for
 * reading the responses. One row per (form, user).
 *
 * Purely additive: two new tables, nothing existing is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('form_versions')) {
            Schema::create('form_versions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('form_id')->constrained('dynamic_forms')->cascadeOnDelete();
                $table->unsignedInteger('version_number');
                $table->json('schema');
                $table->char('schema_hash', 40);
                $table->unsignedSmallInteger('question_count')->default(0);
                // publish: made by publishing the draft; restore: published from a draft rebuilt from an older version;
                // legacy: the single version created for a form that was already published before versions existed
                $table->string('origin', 20)->default('publish');
                $table->string('note', 255)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedBigInteger('restored_from_version_id')->nullable();
                $table->timestamp('published_at')->nullable();
                $table->timestamps();

                $table->unique(['form_id', 'version_number']);
            });
        }

        if (! Schema::hasTable('form_collaborators')) {
            Schema::create('form_collaborators', function (Blueprint $table) {
                $table->id();
                $table->foreignId('form_id')->constrained('dynamic_forms')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                // viewer | editor
                $table->string('access_level', 10)->default('viewer');
                // reading the responses is granted separately from editing
                $table->boolean('can_view_responses')->default(false);
                $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['form_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('form_collaborators');
        Schema::dropIfExists('form_versions');
    }
};
