<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            if (!Schema::hasColumn('departments', 'deputy_leader_id')) {
                $table->foreignId('deputy_leader_id')->nullable()->after('leader_id')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('departments', 'operational_member_id')) {
                $table->foreignId('operational_member_id')->nullable()->after('deputy_leader_id')->constrained('users')->nullOnDelete();
            }
        });

        Schema::table('user_departments', function (Blueprint $table) {
            if (!Schema::hasColumn('user_departments', 'unit_id')) {
                $table->foreignId('unit_id')->nullable()->after('department_id')->constrained('departmental_units')->nullOnDelete();
            }
            if (!Schema::hasColumn('user_departments', 'job_title')) {
                $table->string('job_title')->nullable()->after('unit_id');
            }
            if (!Schema::hasColumn('user_departments', 'role_type')) {
                $table->string('role_type')->default('member')->after('job_title');
            }
        });

        Schema::table('volunteer_profiles', function (Blueprint $table) {
            if (!Schema::hasColumn('volunteer_profiles', 'unit_id')) {
                $table->foreignId('unit_id')->nullable()->after('department_id')->constrained('departmental_units')->nullOnDelete();
            }
            if (!Schema::hasColumn('volunteer_profiles', 'volunteer_title')) {
                $table->string('volunteer_title')->nullable()->after('unit_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropForeign(['deputy_leader_id']);
            $table->dropForeign(['operational_member_id']);
            $table->dropColumn(['deputy_leader_id', 'operational_member_id']);
        });

        Schema::table('user_departments', function (Blueprint $table) {
            $table->dropForeign(['unit_id']);
            $table->dropColumn(['unit_id', 'job_title', 'role_type']);
        });

        Schema::table('volunteer_profiles', function (Blueprint $table) {
            $table->dropForeign(['unit_id']);
            $table->dropColumn(['unit_id', 'volunteer_title']);
        });
    }
};
