<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            if (! Schema::hasColumn('assignments', 'course_assignment_id')) {
                $table->foreignId('course_assignment_id')
                    ->nullable()
                    ->after('workshop_id')
                    ->constrained('course_assignments')
                    ->nullOnDelete();
                $table->unique('course_assignment_id');
            }
            if (! Schema::hasColumn('assignments', 'resubmission_allowed')) {
                $table->boolean('resubmission_allowed')->default(false)->after('status');
            }
        });

        Schema::table('course_assignments', function (Blueprint $table) {
            if (! Schema::hasColumn('course_assignments', 'resubmission_allowed')) {
                $table->boolean('resubmission_allowed')->default(false)->after('is_required');
            }
        });
    }

    public function down(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            if (Schema::hasColumn('assignments', 'course_assignment_id')) {
                $table->dropConstrainedForeignId('course_assignment_id');
            }
            if (Schema::hasColumn('assignments', 'resubmission_allowed')) {
                $table->dropColumn('resubmission_allowed');
            }
        });

        Schema::table('course_assignments', function (Blueprint $table) {
            if (Schema::hasColumn('course_assignments', 'resubmission_allowed')) {
                $table->dropColumn('resubmission_allowed');
            }
        });
    }
};
