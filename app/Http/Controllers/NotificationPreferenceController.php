<?php

namespace App\Http\Controllers;

use App\Models\NotificationPreference;
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
            'preferences.*.channel' => 'required|in:email,whatsapp,in_app,sms',
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
}
