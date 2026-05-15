<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend the existing departments table with bilingual + is_active columns
 * needed for the public-facing EMC V3 team API.
 *
 * Non-destructive: only adds columns, does not remove or rename existing ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            if (! Schema::hasColumn('departments', 'name_ar')) {
                $table->string('name_ar')->after('id');
            }
            if (! Schema::hasColumn('departments', 'name_en')) {
                $table->string('name_en')->nullable()->after('name_ar');
            }
            if (! Schema::hasColumn('departments', 'description_ar')) {
                $table->text('description_ar')->nullable()->after('description');
            }
            if (! Schema::hasColumn('departments', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('sort_order');
            }
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn(array_filter([
                Schema::hasColumn('departments', 'name_ar')       ? 'name_ar' : null,
                Schema::hasColumn('departments', 'name_en')       ? 'name_en' : null,
                Schema::hasColumn('departments', 'description_ar')? 'description_ar' : null,
                Schema::hasColumn('departments', 'is_active')     ? 'is_active' : null,
            ]));
        });
    }
};
