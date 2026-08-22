<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Canonical `meeting_reports` table — merges two independently-built report
     * features that collided on the same table name during the EMC-NEW-BACK
     * merge:
     *   - the department "ops report" (department_id/title/meeting_date/
     *     achieved/planned/needs/needs_reason/decisions/notes/created_by),
     *     used by Operations\MeetingReportController + the ratings sub-table
     *     created in 2026_08_21_200003_create_meeting_report_ratings_table;
     *   - the per-meeting quick report (absentees/team_commitment_score/
     *     team_commitment_notes/completed_tasks_summary/kpi_percentage/top_interactive_member/
     *     challenges/executive_requests/next_month_goals/
     *     improvement_suggestions), used by Meeting::report() +
     *     Admin\MeetingController::storeReport().
     * Both feature's columns are nullable so either code path can create a
     * row without touching the other feature's fields.
     */
    public function up(): void
    {
        Schema::create('meeting_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('meeting_id')->nullable()->constrained('meetings')->nullOnDelete();

            // Department ops report
            $table->string('title')->nullable();
            $table->date('meeting_date')->nullable();
            $table->text('achieved')->nullable();       // ماذا أنجزوا
            $table->text('planned')->nullable();        // ماذا سينجزون
            $table->text('needs')->nullable();          // ماذا يحتاجون/يريدون
            $table->text('needs_reason')->nullable();   // ولماذا
            $table->text('decisions')->nullable();      // قرارات المحضر
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // Per-meeting quick report
            $table->json('absentees')->nullable();
            $table->tinyInteger('team_commitment_score')->nullable(); // 1-5 scale
            $table->text('team_commitment_notes')->nullable();
            $table->text('completed_tasks_summary')->nullable();
            $table->tinyInteger('kpi_percentage')->nullable(); // 0-100%
            $table->string('top_interactive_member')->nullable();
            $table->text('challenges')->nullable();
            $table->text('executive_requests')->nullable();
            $table->text('next_month_goals')->nullable();
            $table->text('improvement_suggestions')->nullable();

            $table->timestamps();

            $table->index(['department_id', 'meeting_date']);
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
