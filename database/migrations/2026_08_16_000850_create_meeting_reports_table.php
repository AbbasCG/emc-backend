<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('meeting_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            
            // Section 1: Foundational / Attendance extension
            $table->json('absentees')->nullable();
            $table->tinyInteger('team_commitment_score')->nullable(); // 1-5 scale
            $table->text('team_commitment_notes')->nullable();
            
            // Section 2: Performance Evaluation
            $table->text('completed_tasks_summary')->nullable();
            $table->tinyInteger('kpi_percentage')->nullable(); // 0-100%
            $table->string('top_interactive_member')->nullable();
            $table->text('challenges')->nullable();
            
            // Section 4: Future Requirements & Aspirations (Section 3 outcomes are in separate tables)
            $table->text('executive_requests')->nullable();
            $table->text('next_month_goals')->nullable();
            $table->text('improvement_suggestions')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meeting_reports');
    }
};
