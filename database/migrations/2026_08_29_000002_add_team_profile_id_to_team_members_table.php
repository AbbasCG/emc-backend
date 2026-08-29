<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 1 of the TeamProfile/TeamMember identity correction (see the
 * architecture audit): an explicit link from an operational team_members row
 * to the SPECIFIC canonical team_profiles row it operationalizes, instead of
 * inferring one via `TeamProfile::where('user_id', ...)->first()` (ambiguous
 * whenever a user has more than one profile — which the business rule says
 * should not happen going forward, but may already exist historically).
 *
 * Deliberately NOT unique yet, and department_id/role_title are NOT dropped
 * from team_members yet — per the audit's non-destructive staging plan,
 * uniqueness/cleanup only happen after existing data is reconciled
 * (see App\Console\Commands\AuditDuplicateTeamProfiles).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_members', function (Blueprint $table) {
            $table->foreignId('team_profile_id')->nullable()->after('user_id')
                ->constrained('team_profiles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('team_members', function (Blueprint $table) {
            $table->dropForeign(['team_profile_id']);
            $table->dropColumn('team_profile_id');
        });
    }
};
