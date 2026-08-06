<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('program_finance_approvals', function (Blueprint $table) {
            $table->id();

            // Polymorphic relation — covers Course, LearningPath, any future type
            $table->string('approvable_type');
            $table->unsignedBigInteger('approvable_id');

            // Approval state
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');

            // Submitter
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();

            // Reviewer
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            // Finance notes
            $table->text('review_note')->nullable();
            $table->text('rejection_reason')->nullable();

            // Price snapshot at time of submission (for audit trail)
            $table->decimal('price_snapshot', 10, 2)->nullable();
            $table->string('currency_snapshot', 3)->nullable();

            $table->timestamps();

            // One active approval record per program
            $table->unique(['approvable_type', 'approvable_id']);
            $table->index(['approvable_type', 'approvable_id']);
            $table->index('status');
            $table->index('submitted_by');
            $table->index('reviewed_by');
            $table->index('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_finance_approvals');
    }
};
