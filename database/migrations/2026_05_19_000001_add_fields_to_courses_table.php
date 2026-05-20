<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            // Scheduling (times — dates already exist)
            $table->time('start_time')->nullable()->after('start_date');
            $table->time('end_time')->nullable()->after('end_date');

            // Payment / access
            $table->boolean('is_free')->default(false)->after('price');

            // Program type for public display
            $table->string('program_type', 50)->nullable()->after('type');
            // values: course | workshop | one_session | full_program

            // Certificate detail
            $table->boolean('certificate_available')->default(false)->after('certificate');
            $table->string('certificate_type', 100)->nullable()->after('certificate_available');

            // Learning content (JSON arrays)
            $table->json('requirements')->nullable()->after('learning_outcomes');
            $table->json('curriculum_topics')->nullable()->after('requirements');
            $table->json('keywords')->nullable()->after('curriculum_topics');

            // Registration state
            $table->string('registration_status', 20)->default('open')->after('registration_open');
            // values: open | closed | coming_soon

            // Internal admin notes
            $table->text('notes')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn([
                'start_time', 'end_time',
                'is_free', 'program_type',
                'certificate_available', 'certificate_type',
                'requirements', 'curriculum_topics', 'keywords',
                'registration_status', 'notes',
            ]);
        });
    }
};
