<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\TicketAssignmentNotifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Administrator switches for the two "ticket assigned to you" notifications, per channel.
 *
 * Email reuses the existing admin email switch (EmailSetting, same key as the email settings list);
 * in-app is NotificationChannelSetting. Routed exactly like email-settings: readable by the admin
 * group, writable only by super_admin / executive_admin. Turning a channel off never changes any
 * user's saved preference.
 */
class AssignmentNotificationSettingsController extends Controller
{
    /** GET /api/admin/assignment-notification-settings */
    public function index(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['types' => TicketAssignmentNotifications::adminSettings()]]);
    }

    /**
     * PUT /api/admin/assignment-notification-settings
     * Body: { "settings": { "ticket_assigned": { "email": true }, "operations_ticket_assigned": { "in_app": false } } }
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'settings' => 'required|array|min:1',
            'settings.*' => 'required|array|min:1',
            'settings.*.*' => 'required|boolean',
        ]);

        foreach ($data['settings'] as $type => $byChannel) {
            if (! TicketAssignmentNotifications::isType((string) $type)) {
                return response()->json(['success' => false, 'message' => 'نوع إشعار غير معروف.', 'errors' => ["settings.{$type}" => ['نوع إشعار غير معروف.']]], 422);
            }
            foreach (array_keys($byChannel) as $channel) {
                if (! in_array($channel, TicketAssignmentNotifications::CHANNELS, true)) {
                    return response()->json(['success' => false, 'message' => 'قناة غير معروفة.', 'errors' => ["settings.{$type}.{$channel}" => ['قناة غير معروفة.']]], 422);
                }
            }
        }

        foreach ($data['settings'] as $type => $byChannel) {
            foreach ($byChannel as $channel => $enabled) {
                TicketAssignmentNotifications::setAdmin($type, $channel, (bool) $enabled);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'تم حفظ إعدادات إشعارات الإسناد.',
            'data' => ['types' => TicketAssignmentNotifications::adminSettings()],
        ]);
    }
}
