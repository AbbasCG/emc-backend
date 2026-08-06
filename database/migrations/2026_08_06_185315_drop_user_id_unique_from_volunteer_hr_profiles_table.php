<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `volunteer_hr_profiles.user_id` was unique at the DB level, but the table
 * uses SoftDeletes — MySQL has no filtered/partial unique index, so a
 * soft-deleted row (deleted_at IS NOT NULL) still permanently blocks any
 * future INSERT for that user_id, crashing submission with a raw 500
 * (UniqueConstraintViolationException) instead of the controlled
 * "already submitted" response VolunteerApprovalService::findDuplicate()
 * is meant to produce. findDuplicate() already correctly scopes to
 * non-deleted rows via Eloquent's default soft-delete scope, so duplicate
 * prevention among *active* profiles is already enforced at the app level —
 * this migration only removes the DB constraint that fought against it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('volunteer_hr_profiles', function (Blueprint $table) {
            // The unique index backs the user_id -> users FK; MySQL refuses to
            // drop it outright ("needed in a foreign key constraint"). Add a
            // plain index first so the FK has something to use, then drop the
            // unique one.
            $table->index('user_id', 'volunteer_hr_profiles_user_id_index');
            $table->dropUnique('volunteer_hr_profiles_user_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('volunteer_hr_profiles', function (Blueprint $table) {
            $table->dropIndex('volunteer_hr_profiles_user_id_index');
            $table->unique('user_id', 'volunteer_hr_profiles_user_id_unique');
        });
    }
};
