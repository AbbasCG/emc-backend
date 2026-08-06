<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a nullable class_group_id to every course-scoped learning-content table.
 * Nullable and additive by design: existing rows stay NULL (= visible to the
 * whole course, exactly as today), so this cannot break any existing data or
 * query. Content is only class-isolated once a row explicitly sets this FK.
 */
return new class extends Migration
{
    // table => [fk constraint name, column to insert after]
    private const TARGETS = [
        'lms_sessions'       => ['lms_sessions_class_group_fk', 'course_id'],
        'course_sessions'    => ['course_sessions_class_group_fk', 'course_id'],
        'course_materials'   => ['course_materials_class_group_fk', 'course_id'],
        'assignments'        => ['assignments_class_group_fk', 'course_id'],
        'course_assignments' => ['course_assignments_class_group_fk', 'course_id'],
        // attendances has no course_id column — it's scoped via session_id only.
        'attendances'        => ['attendances_class_group_fk', 'session_id'],
    ];

    public function up(): void
    {
        foreach (self::TARGETS as $table => [$fkName, $afterColumn]) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'class_group_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($fkName, $afterColumn, $table) {
                if (Schema::hasColumn($table, $afterColumn)) {
                    $t->unsignedBigInteger('class_group_id')->nullable()->after($afterColumn);
                } else {
                    $t->unsignedBigInteger('class_group_id')->nullable();
                }
                $t->foreign('class_group_id', $fkName)
                    ->references('id')->on('class_groups')
                    ->nullOnDelete();
                $t->index('class_group_id', $fkName . '_idx');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TARGETS as $table => [$fkName, $afterColumn]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'class_group_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($fkName) {
                $t->dropForeign($fkName);
                $t->dropColumn('class_group_id');
            });
        }
    }
};
