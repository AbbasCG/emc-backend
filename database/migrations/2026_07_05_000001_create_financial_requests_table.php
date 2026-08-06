<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requester_id')->constrained('users');
            $table->foreignId('department_id')->constrained('departments');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('request_type'); // budget|purchase|invoice_payment|reimbursement|operational_expense|transfer|other
            $table->decimal('amount', 15, 2);
            $table->string('currency', 10)->default('SAR');
            $table->date('needed_by_date')->nullable();
            $table->string('priority')->default('normal'); // low|normal|high|urgent
            $table->string('status')->default('draft');
            // draft|submitted|finance_review|finance_approved|finance_rejected
            // executive_review|approved|rejected|returned|cancelled
            $table->text('finance_note')->nullable();
            $table->text('executive_note')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('returned_reason')->nullable();
            $table->foreignId('finance_reviewer_id')->nullable()->constrained('users');
            $table->foreignId('executive_reviewer_id')->nullable()->constrained('users');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('finance_reviewed_at')->nullable();
            $table->timestamp('executive_reviewed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_requests');
    }
};
