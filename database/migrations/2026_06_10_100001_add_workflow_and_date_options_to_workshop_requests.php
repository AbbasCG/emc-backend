<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workshop_requests', function (Blueprint $table) {
            // Three proposed date/time options
            $table->date('proposed_date_1')->nullable()->after('target_audience');
            $table->string('proposed_time_1', 20)->nullable()->after('proposed_date_1');
            $table->date('proposed_date_2')->nullable()->after('proposed_time_1');
            $table->string('proposed_time_2', 20)->nullable()->after('proposed_date_2');
            $table->date('proposed_date_3')->nullable()->after('proposed_time_2');
            $table->string('proposed_time_3', 20)->nullable()->after('proposed_date_3');

            // Workflow tracking
            $table->unsignedTinyInteger('current_step')->default(1)->after('status');
            $table->string('current_department', 50)->default('quality_governance')->after('current_step');
            $table->string('workflow_status', 30)->default('pending')->after('current_department');
        });

        // Make legacy date fields nullable for backward compat
        Schema::table('workshop_requests', function (Blueprint $table) {
            $table->date('proposed_date')->nullable()->change();
            $table->string('proposed_time')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('workshop_requests', function (Blueprint $table) {
            $table->dropColumn([
                'proposed_date_1', 'proposed_time_1',
                'proposed_date_2', 'proposed_time_2',
                'proposed_date_3', 'proposed_time_3',
                'current_step', 'current_department', 'workflow_status',
            ]);
        });
    }
};
