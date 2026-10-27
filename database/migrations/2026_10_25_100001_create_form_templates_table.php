<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Smart Forms Phase 6 — templates saved by employees.
 *
 * The built-in library (course evaluation, leave request, ...) lives in code (SystemFormTemplates) and is never stored here, so a
 * deployment can improve it without a data migration. This table holds only the templates people save from their own forms.
 * `definition` is a self-contained copy of a form's structure (title, description, flow and the block list, rules naming blocks
 * by negative refs) — never a response, never a person.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('form_templates')) {
            return;
        }

        Schema::create('form_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('category', 40)->default('general');
            $table->string('form_type', 100)->nullable();
            $table->json('definition');
            // private: the owner only; department: the department's people; organization: everyone who may build forms
            $table->string('visibility', 20)->default('private');
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('source_form_id')->nullable()->constrained('dynamic_forms')->nullOnDelete();
            $table->unsignedInteger('uses_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            // Leads with a non-FK column on purpose: a composite index that starts with an FK column replaces the FK's own index on
            // MariaDB and breaks the rollback.
            $table->index(['visibility', 'category'], 'form_templates_visibility_category_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_templates');
    }
};
