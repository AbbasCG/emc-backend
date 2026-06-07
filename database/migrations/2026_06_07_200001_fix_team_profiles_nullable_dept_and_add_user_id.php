<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two changes in one migration (same batch, same concern):
 * 1. Make department_id nullable — volunteer conversions may not match a dept.
 * 2. Add user_id (nullable FK) — links a team_profile to a platform user account
 *    once "create user account" is triggered after conversion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_profiles', function (Blueprint $table) {
            // Drop the FK + NOT NULL constraint, re-add as nullable FK
            $table->dropForeign(['department_id']);
            $table->unsignedBigInteger('department_id')->nullable()->change();
            $table->foreign('department_id')
                ->references('id')->on('departments')
                ->nullOnDelete();

            // Optional link to a platform user account
            $table->foreignId('user_id')
                ->nullable()
                ->after('department_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('team_profiles', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');

            $table->dropForeign(['department_id']);
            $table->unsignedBigInteger('department_id')->nullable(false)->change();
            $table->foreign('department_id')
                ->references('id')->on('departments')
                ->cascadeOnDelete();
        });
    }
};
