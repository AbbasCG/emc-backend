<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->enum('location_type', ['online', 'offline', 'hybrid', 'unknown'])
                ->default('unknown')
                ->after('location');
            $table->string('meeting_link', 500)->nullable()->after('location_type');
            $table->boolean('registration_open')->default(true)->after('capacity');
            $table->unsignedBigInteger('created_by')->nullable()->after('registration_open');
            $table->unsignedBigInteger('assigned_by')->nullable()->after('created_by');
            $table->unsignedBigInteger('department_id')->nullable()->after('assigned_by');

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('assigned_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropForeign(['assigned_by']);
            $table->dropForeign(['department_id']);
            $table->dropColumn(['location_type', 'meeting_link', 'registration_open', 'created_by', 'assigned_by', 'department_id']);
        });
    }
};
