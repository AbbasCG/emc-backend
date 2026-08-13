<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('volunteer_hr_profiles', function (Blueprint $table) {
            $table->string('university_specialization', 255)->nullable()->after('education');
            $table->string('professional_bio', 500)->nullable()->after('motivation');

            // Nullable on purpose: null = not yet answered, true = consent given,
            // false = explicitly declined. New submissions require an explicit
            // choice at the FormRequest layer; null only ever applies to rows
            // created before this migration.
            $table->boolean('photo_publication_consent')->nullable()->after('portfolio_url');
            $table->dateTime('photo_consent_at')->nullable()->after('photo_publication_consent');
            $table->boolean('professional_profile_consent')->nullable()->after('photo_consent_at');
            $table->dateTime('professional_profile_consent_at')->nullable()->after('professional_profile_consent');
        });

        // Propagated onto the approved TeamProfile row so the public /api/team
        // endpoint can gate photo/bio exposure without re-querying the private
        // volunteer_hr_profiles table. Only ever set for source='volunteer_hr_profile'
        // rows (see VolunteerApprovalService::approve()); directly-added team
        // members (admin CRUD) keep these null and are treated as pre-existing,
        // unconditionally-displayable entries — never gated by this migration.
        Schema::table('team_profiles', function (Blueprint $table) {
            $table->boolean('photo_publication_consent')->nullable()->after('source_id');
            $table->boolean('professional_profile_consent')->nullable()->after('photo_publication_consent');
        });
    }

    public function down(): void
    {
        Schema::table('team_profiles', function (Blueprint $table) {
            $table->dropColumn(['photo_publication_consent', 'professional_profile_consent']);
        });

        Schema::table('volunteer_hr_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'university_specialization', 'professional_bio',
                'photo_publication_consent', 'photo_consent_at',
                'professional_profile_consent', 'professional_profile_consent_at',
            ]);
        });
    }
};
