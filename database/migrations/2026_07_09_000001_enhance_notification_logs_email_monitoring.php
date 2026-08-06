<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            // Identity & traceability
            $table->uuid('uuid')->nullable()->unique()->after('id');
            $table->string('recipient_name')->nullable()->after('recipient');

            // Delivery metadata
            $table->string('mail_driver', 50)->nullable()->after('status');
            $table->string('job_id', 150)->nullable()->after('mail_driver');
            $table->unsignedTinyInteger('retry_count')->default(0)->after('job_id');
            $table->unsignedInteger('execution_time_ms')->nullable()->after('retry_count');

            // SMTP detail
            $table->text('smtp_response')->nullable()->after('error_message');

            // Timestamps
            $table->timestamp('delivered_at')->nullable()->after('failed_at');

            // Relations
            $table->unsignedBigInteger('triggered_by_user_id')->nullable()->after('user_id');
            $table->unsignedBigInteger('related_course_id')->nullable()->after('triggered_by_user_id');
            $table->unsignedBigInteger('related_session_id')->nullable()->after('related_course_id');
            $table->unsignedBigInteger('related_ticket_id')->nullable()->after('related_session_id');
            $table->unsignedBigInteger('related_assignment_id')->nullable()->after('related_ticket_id');

            // Indexes for dashboard queries
            $table->index('triggered_by_user_id');
            $table->index('related_course_id');
        });
    }

    public function down(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->dropIndex(['triggered_by_user_id']);
            $table->dropIndex(['related_course_id']);
            $table->dropColumn([
                'uuid', 'recipient_name', 'mail_driver', 'job_id', 'retry_count',
                'execution_time_ms', 'smtp_response', 'delivered_at',
                'triggered_by_user_id', 'related_course_id', 'related_session_id',
                'related_ticket_id', 'related_assignment_id',
            ]);
        });
    }
};
