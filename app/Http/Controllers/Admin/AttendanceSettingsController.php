<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AttendanceSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ticket 6: admin-only read/write for platform-wide attendance thresholds.
 * Route middleware (role:admin,super_admin — see routes/api.php) is the
 * write-protection boundary; this controller does not re-derive authorization.
 */
class AttendanceSettingsController extends Controller
{
    public function show(AttendanceSettingsService $settings): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $settings->current()]);
    }

    public function update(Request $request, AttendanceSettingsService $settings): JsonResponse
    {
        $validated = $request->validate([
            'late_threshold_minutes' => ['required', 'integer', 'min:0', 'max:120'],
            'auto_absent_after_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'minimum_attendance_percentage' => ['required', 'integer', 'min:0', 'max:100'],
            'at_risk_percentage' => ['required', 'integer', 'min:0', 'max:100'],
            'repeated_absence_threshold' => ['required', 'integer', 'min:1', 'max:20'],
            'low_attendance_notification_threshold' => ['required', 'integer', 'min:0', 'max:100'],
            'certificate_attendance_percentage' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم حفظ إعدادات الحضور.',
            'data' => $settings->update($validated),
        ]);
    }
}
