<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Departmental Units table (e.g., 8 specialized units for Tech Admin)
        if (!Schema::hasTable('departmental_units')) {
            Schema::create('departmental_units', function (Blueprint $table) {
                $table->id();
                $table->foreignId('department_id')->constrained('departments')->onDelete('cascade');
                $table->string('code', 50)->unique();
                $table->string('name_ar', 150);
                $table->string('name_en', 150)->nullable();
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        // 2. Main Tickets table
        if (!Schema::hasTable('tickets')) {
            Schema::create('tickets', function (Blueprint $table) {
                $table->id();
                $table->string('ticket_number', 50)->unique(); // e.g., EMC-TCK-2026-001
                $table->string('title', 255);
                $table->text('description');
                $table->string('ticket_category', 50)->default('OLD_ISSUE'); // 'OLD_ISSUE', 'NEW_SUGGESTION'
                $table->string('priority', 20)->default('MEDIUM'); // 'LOW', 'MEDIUM', 'HIGH', 'CRITICAL'
                
                // Routing path
                $table->foreignId('department_id')->constrained('departments');
                $table->foreignId('unit_id')->nullable()->constrained('departmental_units')->nullOnDelete();
                
                // Stakeholders
                $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('created_by_name', 150);
                $table->string('created_by_email', 150)->nullable();
                $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('assigned_to_id')->nullable()->constrained('users')->nullOnDelete();
                
                // Status lifecycle
                $table->string('status', 50)->default('PENDING_APPROVAL');
                // PENDING_APPROVAL, ASSIGNED, IN_PROGRESS, RESOLVED, UNRESOLVED, REJECTED_BY_ADMIN, REJECTED_BY_ASSIGNEE
                
                // Timing & SLA
                $table->timestamp('expected_resolution_time')->nullable();
                $table->timestamp('accepted_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                
                // Mandatory validation justification fields
                $table->text('admin_rejection_reason')->nullable();
                $table->text('assignee_rejection_reason')->nullable();
                $table->text('resolution_summary')->nullable();
                $table->text('unresolved_reason')->nullable();
                $table->text('internal_notes')->nullable();
                
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 3. Ticket Attachments table
        if (!Schema::hasTable('ticket_attachments')) {
            Schema::create('ticket_attachments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ticket_id')->constrained('tickets')->onDelete('cascade');
                $table->text('file_url');
                $table->string('file_name', 255)->nullable();
                $table->string('file_type', 50)->default('IMAGE'); // IMAGE, VIDEO, DOCUMENT
                $table->string('attachment_context', 50)->default('INITIAL_SUBMISSION'); // INITIAL_SUBMISSION, RESOLUTION_PROOF
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        // 4. Activity Audit Logs table
        if (!Schema::hasTable('ticket_activity_logs')) {
            Schema::create('ticket_activity_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ticket_id')->constrained('tickets')->onDelete('cascade');
                $table->string('action', 100); // STATUS_CHANGE, ASSIGNED, REJECTED, RESOLVED, SLA_UPDATED
                $table->string('previous_state', 50)->nullable();
                $table->string('new_state', 50)->nullable();
                $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('performed_by_name', 150)->nullable();
                $table->text('details')->nullable();
                $table->timestamp('logged_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_activity_logs');
        Schema::dropIfExists('ticket_attachments');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('departmental_units');
    }
};
