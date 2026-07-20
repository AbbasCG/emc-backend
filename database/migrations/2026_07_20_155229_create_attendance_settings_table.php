<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 6: single-row platform-wide attendance configuration. Additive,
 * new table only. No row existing (fresh install / before first admin save)
 * must behave identically to today's hardcoded 70%/3-in-a-row defaults —
 * AttendanceSettingsService falls back to those exact values when the table
 * is empty, so this migration alone changes nothing observable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('late_threshold_minutes')->default(15);
            $table->unsignedInteger('auto_absent_after_minutes')->default(30);
            $table->unsignedInteger('minimum_attendance_percentage')->default(70);
            $table->unsignedInteger('at_risk_percentage')->default(70);
            $table->unsignedInteger('repeated_absence_threshold')->default(3);
            $table->unsignedInteger('low_attendance_notification_threshold')->default(70);
            $table->unsignedInteger('certificate_attendance_percentage')->default(70);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_settings');
    }
};
