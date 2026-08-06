<?php

namespace App\Http\Controllers;

use App\Models\NotificationPreference;
use App\Models\NotificationQuietHours;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lets authenticated users manage their notification channel preferences.
 */
class NotificationPreferenceController extends Controller
{
    /** GET /api/notification-preferences */
    public function index(Request $request): JsonResponse
    {
        $prefs = NotificationPreference::query()
            ->where('user_id', $request->user()->id)
            ->get();

        return response()->json(['success' => true, 'data' => $prefs]);
    }

    /**
     * PUT /api/notification-preferences
     *
     * Upserts a batch of preferences in one request.
     *
     * Body:
     * {
     *   "preferences": [
     *     { "channel": "email", "type": "certificate_issued", "enabled": true },
     *     { "channel": "whatsapp", "type": "session_reminder", "enabled": false }
     *   ]
     * }
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'preferences'           => 'required|array|min:1',
            'preferences.*.channel' => 'required|in:email,whatsapp,in_app,sms,push',
            'preferences.*.type'    => 'required|string|max:100',
            'preferences.*.enabled' => 'required|boolean',
        ]);

        foreach ($data['preferences'] as $pref) {
            NotificationPreference::updateOrCreate(
                [
                    'user_id' => $request->user()->id,
                    'channel' => $pref['channel'],
                    'type'    => $pref['type'],
                ],
                ['enabled' => $pref['enabled']],
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Notification preferences saved.',
        ]);
    }

    /** POST /api/notifications/preferences/reset */
    public function reset(Request $request): JsonResponse
    {
        NotificationPreference::where('user_id', $request->user()->id)->delete();
        return response()->json(['success' => true, 'message' => 'Preferences reset to defaults.']);
    }

    /** GET /api/notifications/quiet-hours */
    public function quietHours(Request $request): JsonResponse
    {
        $settings = NotificationQuietHours::where('user_id', $request->user()->id)->first();

        return response()->json([
            'success' => true,
            'data' => [
                'enabled'    => $settings?->enabled ?? false,
                'start_time' => $settings?->start_time,
                'end_time'   => $settings?->end_time,
                'timezone'   => $settings?->timezone ?? 'UTC',
            ],
        ]);
    }

    /**
     * PUT /api/notifications/quiet-hours
     *
     * Body: { "enabled": true, "start_time": "22:00", "end_time": "07:00", "timezone": "Asia/Riyadh" }
     * Overnight ranges (start > end) are valid and supported — see QuietHoursService.
     */
    public function updateQuietHours(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled'    => 'required|boolean',
            'start_time' => 'required_if:enabled,true|nullable|date_format:H:i',
            'end_time'   => 'required_if:enabled,true|nullable|date_format:H:i',
            'timezone'   => 'required_if:enabled,true|nullable|timezone',
        ]);

        NotificationQuietHours::updateOrCreate(
            ['user_id' => $request->user()->id],
            [
                'enabled'    => $data['enabled'],
                'start_time' => $data['start_time'] ?? null,
                'end_time'   => $data['end_time'] ?? null,
                'timezone'   => $data['timezone'] ?? 'UTC',
            ],
        );

        return response()->json(['success' => true, 'message' => 'Quiet hours updated.']);
    }
}
