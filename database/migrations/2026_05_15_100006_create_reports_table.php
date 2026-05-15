<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            // program_report | course_report | workshop_report | financial_report
            // quality_report | department_report | partnership_report | hr_report
            $table->string('type');
            $table->string('reportable_type')->nullable();
            $table->unsignedBigInteger('reportable_id')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('data');
            $table->text('summary')->nullable();
            // draft | generated | archived
            $table->string('status')->default('generated');
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->index(['reportable_type', 'reportable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
