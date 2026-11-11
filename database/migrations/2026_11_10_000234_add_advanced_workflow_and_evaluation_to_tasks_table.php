<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // Task Scope / Size: simple (25-50), medium (75-150), large (200-300), strategic (300-500 or unlimited)
            $table->string('task_scope')->nullable()->after('priority');
            $table->integer('points')->nullable()->after('task_scope');
            $table->decimal('estimated_hours', 8, 2)->nullable()->after('points');
            $table->dateTime('due_datetime')->nullable()->after('due_date');
            $table->text('notes')->nullable()->after('description');

            // Member Acceptance Workflow
            $table->string('acceptance_status')->default('accepted')->after('status'); // pending, accepted, rejected
            $table->text('rejection_reason')->nullable()->after('acceptance_status');
            $table->dateTime('accepted_at')->nullable()->after('rejection_reason');
            $table->dateTime('rejected_at')->nullable()->after('accepted_at');

            // Dual-tier Approvals: Dept Manager & HR
            $table->dateTime('manager_approved_at')->nullable()->after('rejected_at');
            $table->foreignId('manager_approved_by')->nullable()->after('manager_approved_at')->constrained('users')->nullOnDelete();
            $table->dateTime('hr_approved_at')->nullable()->after('manager_approved_by');
            $table->foreignId('hr_approved_by')->nullable()->after('hr_approved_at')->constrained('users')->nullOnDelete();

            // Multi-member Collective Task Link
            $table->foreignId('parent_task_id')->nullable()->after('id')->constrained('tasks')->nullOnDelete();

            $table->index('task_scope');
            $table->index('acceptance_status');
        });

        Schema::create('task_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('file_path');
            $table->string('file_name');
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('mime_type')->nullable();
            $table->timestamps();
        });

        Schema::create('task_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('evaluator_id')->nullable()->constrained('users')->nullOnDelete();
            // 5 Likert criteria (1-5)
            $table->unsignedTinyInteger('delivered_on_time');      // سلمت في الوقت المحدد
            $table->unsignedTinyInteger('quality_of_outputs');     // جودة المخرجات المطلوبة
            $table->unsignedTinyInteger('autonomy');               // استقلالية العضو في أداء المهمة
            $table->unsignedTinyInteger('responsiveness');          // سرعة الاستجابة
            $table->unsignedTinyInteger('collaboration')->nullable(); // التعاون مع الآخرين
            $table->boolean('collaboration_na')->default(false);    // خيار "لا ينطبق"
            $table->decimal('final_score', 4, 2)->nullable();
            $table->text('feedback')->nullable();
            $table->timestamps();

            $table->index('task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_evaluations');
        Schema::dropIfExists('task_attachments');

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['parent_task_id']);
            $table->dropForeign(['manager_approved_by']);
            $table->dropForeign(['hr_approved_by']);

            $table->dropColumn([
                'parent_task_id',
                'task_scope',
                'points',
                'estimated_hours',
                'due_datetime',
                'notes',
                'acceptance_status',
                'rejection_reason',
                'accepted_at',
                'rejected_at',
                'manager_approved_at',
                'manager_approved_by',
                'hr_approved_at',
                'hr_approved_by',
            ]);
        });
    }
};
