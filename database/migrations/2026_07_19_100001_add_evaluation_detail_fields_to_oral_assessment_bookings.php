<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oral_assessment_bookings', function (Blueprint $table) {
            // Structured teacher feedback — split out of the single
            // instructor_notes field so the full-assessment modal can show
            // strengths/weaknesses/recommendations as distinct sections.
            // instructor_notes itself is kept as the "reason for level" note.
            $table->text('strengths')->nullable()->after('instructor_notes');
            $table->text('weaknesses')->nullable()->after('strengths');
            $table->text('recommendations')->nullable()->after('weaknesses');

            // When the evaluation was first recorded (distinct from created_at,
            // which is booking time, not evaluation time).
            $table->timestamp('evaluated_at')->nullable()->after('recommendations');

            // Edit history — only populated when an already-completed
            // evaluation is edited again, so the modal can show "original vs
            // updated" without a separate history table for this narrow case.
            $table->unsignedInteger('previous_oral_score')->nullable()->after('evaluated_at');
            $table->string('previous_final_level', 20)->nullable()->after('previous_oral_score');
            $table->unsignedBigInteger('edited_by')->nullable()->after('previous_final_level');
            $table->timestamp('edited_at')->nullable()->after('edited_by');

            $table->foreign('edited_by', 'oral_bookings_edited_by_fk')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('oral_assessment_bookings', function (Blueprint $table) {
            $table->dropForeign('oral_bookings_edited_by_fk');
            $table->dropColumn([
                'strengths', 'weaknesses', 'recommendations', 'evaluated_at',
                'previous_oral_score', 'previous_final_level', 'edited_by', 'edited_at',
            ]);
        });
    }
};
