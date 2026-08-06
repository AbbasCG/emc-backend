<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceSettings extends Model
{
    protected $fillable = [
        'late_threshold_minutes',
        'auto_absent_after_minutes',
        'minimum_attendance_percentage',
        'at_risk_percentage',
        'repeated_absence_threshold',
        'low_attendance_notification_threshold',
        'certificate_attendance_percentage',
    ];

    protected $casts = [
        'late_threshold_minutes' => 'integer',
        'auto_absent_after_minutes' => 'integer',
        'minimum_attendance_percentage' => 'integer',
        'at_risk_percentage' => 'integer',
        'repeated_absence_threshold' => 'integer',
        'low_attendance_notification_threshold' => 'integer',
        'certificate_attendance_percentage' => 'integer',
    ];
}
