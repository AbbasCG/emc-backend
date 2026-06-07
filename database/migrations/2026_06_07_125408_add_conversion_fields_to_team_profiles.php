<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_profiles', function (Blueprint $table) {
            // Contact / identity fields not present in the original EMC org-chart migration
            $table->string('phone')->nullable()->after('country');
            $table->string('email')->nullable()->after('phone');
            $table->string('city')->nullable()->after('email');
            $table->string('gender')->nullable()->after('city');
            $table->text('skills')->nullable()->after('gender');
            $table->string('cv_file_path')->nullable()->after('skills');

            // Volunteer-conversion provenance fields
            $table->string('member_type')->nullable()->after('cv_file_path');   // e.g. 'volunteer', 'staff'
            $table->string('source')->nullable()->after('member_type');          // e.g. 'volunteer_request', 'manual'
            $table->unsignedBigInteger('source_id')->nullable()->after('source'); // volunteer_requests.id
            $table->boolean('is_new_member')->default(false)->after('source_id');
            $table->timestamp('joined_at')->nullable()->after('is_new_member');

            $table->index('source_id');
        });
    }

    public function down(): void
    {
        Schema::table('team_profiles', function (Blueprint $table) {
            $table->dropIndex(['source_id']);
            $table->dropColumn([
                'phone', 'email', 'city', 'gender', 'skills', 'cv_file_path',
                'member_type', 'source', 'source_id', 'is_new_member', 'joined_at',
            ]);
        });
    }
};
