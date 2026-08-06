<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_automation_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('trigger');           // meeting_created, overdue_assignment, failed_payment…
            $table->json('conditions')->nullable();
            $table->string('ai_action');         // summarize_meeting, create_task, generate_report…
            $table->json('action_config')->nullable();
            $table->string('status')->default('active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['trigger', 'status']);
        });

        Schema::create('ai_automation_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->constrained('ai_automation_rules')->cascadeOnDelete();
            $table->string('status');             // running, completed, failed
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['rule_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_automation_executions');
        Schema::dropIfExists('ai_automation_rules');
    }
};
