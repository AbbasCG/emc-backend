<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forms Phase 2 — what the visual builder needs to store.
 *
 * form_questions gains the properties a question can now carry (a description under the label, a placeholder, a short
 * help text, and a `config` bag for type-specific settings: scale, validation limits, default value). All nullable, so every
 * existing question reads exactly as before: no value is read, rewritten or defaulted here.
 *
 * dynamic_forms gains `draft_revision`, a counter that goes up each time the DRAFT really changes. The builder sends the
 * revision it started from, and the server refuses a save that would silently overwrite a newer one (two editors).
 *
 * Purely additive; down() removes only what up() added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_questions', function (Blueprint $table) {
            if (! Schema::hasColumn('form_questions', 'description')) {
                $table->text('description')->nullable();
            }
            if (! Schema::hasColumn('form_questions', 'placeholder')) {
                $table->string('placeholder', 255)->nullable();
            }
            if (! Schema::hasColumn('form_questions', 'help_text')) {
                $table->string('help_text', 500)->nullable();
            }
            if (! Schema::hasColumn('form_questions', 'config')) {
                $table->json('config')->nullable();
            }
        });

        Schema::table('dynamic_forms', function (Blueprint $table) {
            if (! Schema::hasColumn('dynamic_forms', 'draft_revision')) {
                $table->unsignedInteger('draft_revision')->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('dynamic_forms', function (Blueprint $table) {
            if (Schema::hasColumn('dynamic_forms', 'draft_revision')) {
                $table->dropColumn('draft_revision');
            }
        });

        Schema::table('form_questions', function (Blueprint $table) {
            $drop = array_filter(['description', 'placeholder', 'help_text', 'config'], fn ($c) => Schema::hasColumn('form_questions', $c));
            if ($drop !== []) {
                $table->dropColumn(array_values($drop));
            }
        });
    }
};
