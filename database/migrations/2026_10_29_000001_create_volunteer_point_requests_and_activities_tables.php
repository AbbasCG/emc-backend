<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. الأنشطة والتصنيفات القابلة للإدارة
        Schema::create('volunteer_point_activities', function (Blueprint $table) {
            $table->id();
            $table->string('category_key');
            $table->string('category_name_ar');
            $table->string('name_ar');
            $table->enum('type', ['fixed', 'range'])->default('fixed');
            $table->integer('points_min');
            $table->integer('points_max');
            $table->text('description_ar')->nullable();
            $table->boolean('requires_attachment')->default(false);
            $table->integer('monthly_cap')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('category_key');
            $table->index('is_active');
        });

        // 2. طلبات منح النقاط ومسار الاعتماد في الموارد البشرية
        Schema::create('volunteer_point_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('activity_id')->nullable()->constrained('volunteer_point_activities')->nullOnDelete();
            $table->string('category_key');
            $table->string('activity_name');
            $table->integer('points_requested');
            $table->integer('points_approved')->nullable();
            $table->text('reason');
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('status')->default('pending'); // pending, approved, rejected, revision
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('award_id')->nullable()->constrained('volunteer_point_awards')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['department_id', 'status']);
            $table->index(['requester_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('volunteer_point_requests');
        Schema::dropIfExists('volunteer_point_activities');
    }
};
