<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('volunteer_requests', function (Blueprint $table) {
            // المستوى الأول: تفاصيل إضافية للبيانات الشخصية
            $table->date('birth_date')->nullable()->after('gender');
            $table->string('nationality', 100)->nullable()->after('birth_date');

            // المستوى الثاني: المؤهلات والتفرغ
            $table->string('education_level', 100)->nullable()->after('nationality');
            $table->string('current_status', 100)->nullable()->after('education_level');
            $table->string('job_title', 150)->nullable()->after('current_status');
            $table->string('specialization', 150)->nullable()->after('job_title');

            $table->string('volunteer_days_availability', 100)->nullable()->after('specialization');
            $table->json('volunteer_days')->nullable()->after('volunteer_days_availability');
            $table->string('preferred_time_slot', 100)->nullable()->after('volunteer_days');
            $table->string('daily_hours', 100)->nullable()->after('preferred_time_slot');
            $table->string('in_person_capable', 50)->nullable()->after('daily_hours');
            $table->string('remote_capable', 50)->nullable()->after('in_person_capable');

            // المستوى الثالث: الخبرات والقدرات
            $table->boolean('has_previous_volunteering')->nullable()->after('remote_capable');
            $table->text('volunteering_organizations')->nullable()->after('has_previous_volunteering');
            $table->string('volunteering_duration', 100)->nullable()->after('volunteering_organizations');
            $table->json('past_volunteer_tasks')->nullable()->after('volunteering_duration');
            $table->json('personal_skills')->nullable()->after('past_volunteer_tasks');
            $table->unsignedTinyInteger('skill_level_rating')->nullable()->after('personal_skills');
            $table->json('technical_tools')->nullable()->after('skill_level_rating');
            $table->text('value_add_contributions')->nullable()->after('technical_tools');
            $table->string('portfolio_link_status', 100)->nullable()->after('value_add_contributions');

            // المستوى الرابع: الدافعية والجدية
            $table->json('motivation_ratings')->nullable()->after('portfolio_link_status');
            $table->json('commitment_ratings')->nullable()->after('motivation_ratings');
            $table->json('situational_responses')->nullable()->after('commitment_ratings');
            $table->string('expected_duration', 100)->nullable()->after('situational_responses');
            $table->string('commitment_three_months', 50)->nullable()->after('expected_duration');

            // المستوى الخامس: التوقعات والإدارات
            $table->json('reasons_for_applying')->nullable()->after('commitment_three_months');
            $table->json('expected_gains')->nullable()->after('reasons_for_applying');
            $table->json('secondary_departments')->nullable()->after('expected_gains');
            $table->string('primary_value_department', 150)->nullable()->after('secondary_departments');
            $table->json('department_skills')->nullable()->after('primary_value_department');
            $table->string('preferred_work_environment', 100)->nullable()->after('department_skills');
            $table->json('quit_reasons')->nullable()->after('preferred_work_environment');
            $table->text('suggestions')->nullable()->after('quit_reasons');
            $table->json('assessment_data')->nullable()->after('suggestions');
        });
    }

    public function down(): void
    {
        Schema::table('volunteer_requests', function (Blueprint $table) {
            $table->dropColumn([
                'birth_date',
                'nationality',
                'education_level',
                'current_status',
                'job_title',
                'specialization',
                'volunteer_days_availability',
                'volunteer_days',
                'preferred_time_slot',
                'daily_hours',
                'in_person_capable',
                'remote_capable',
                'has_previous_volunteering',
                'volunteering_organizations',
                'volunteering_duration',
                'past_volunteer_tasks',
                'personal_skills',
                'skill_level_rating',
                'technical_tools',
                'value_add_contributions',
                'portfolio_link_status',
                'motivation_ratings',
                'commitment_ratings',
                'situational_responses',
                'expected_duration',
                'commitment_three_months',
                'reasons_for_applying',
                'expected_gains',
                'secondary_departments',
                'primary_value_department',
                'department_skills',
                'preferred_work_environment',
                'quit_reasons',
                'suggestions',
                'assessment_data',
            ]);
        });
    }
};
