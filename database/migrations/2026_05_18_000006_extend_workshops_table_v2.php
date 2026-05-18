<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workshops', function (Blueprint $table) {
            $table->foreignId('instructor_id')
                ->nullable()
                ->constrained('instructors')
                ->nullOnDelete()
                ->after('course_id');
            $table->unsignedBigInteger('assigned_by')->nullable()->after('instructor_id');
            $table->time('start_time')->nullable()->after('start_date');
            $table->time('end_time')->nullable()->after('end_date');
            $table->enum('location_type', ['online', 'offline', 'hybrid', 'unknown'])
                ->default('unknown')
                ->after('end_time');
            $table->string('meeting_link', 500)->nullable()->after('location_type');
            $table->boolean('registration_open')->default(false)->after('meeting_link');

            $table->foreign('assigned_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('workshops', function (Blueprint $table) {
            $table->dropForeign(['instructor_id']);
            $table->dropForeign(['assigned_by']);
            $table->dropColumn(['instructor_id', 'assigned_by', 'start_time', 'end_time', 'location_type', 'meeting_link', 'registration_open']);
        });
    }
};
