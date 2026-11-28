<?php

namespace App\Http\Controllers;

use App\Models\NotificationPreference;
use App\Models\NotificationQuietHours;
use App\Services\EmailPreferenceService;
use App\Support\EmailNotificationTypes;
use App\Support\TicketAssignmentNotifications;
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
            // An EMAIL choice for a real email type belongs to the three-state email preferences (follow / enabled / disabled,
            // notifications/email-preferences). This save-everything endpoint must not overwrite it with a plain true/false.
            if ($pref['channel'] === 'email' && EmailNotificationTypes::exists($pref['type'])) {
                continue;
            }

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

    /**
     * GET /api/notifications/assignment-preferences
     *
     * The signed-in user's choices for the two "ticket assigned to you" notifications, per channel,
     * with whether each choice is saved or the default and whether the administrator has the channel
     * available right now (App\Support\TicketAssignmentNotifications).
     */
    public function assignmentPreferences(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['types' => TicketAssignmentNotifications::preferencesFor($request->user()->id)],
        ]);
    }

    /**
     * PUT /api/notifications/assignment-preferences
     *
     * Body: { "preferences": { "operations_ticket_assigned": { "email": false }, "ticket_assigned": { "in_app": true } } }
     * Partial: only the type/channel pairs sent are saved. Always for the signed-in user — there is no
     * user id in the request, so nobody can change another account's preferences. A choice is kept
     * even while the administrator has that channel off, and applies again once it is turned back on.
     */
    public function updateAssignmentPreferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'preferences' => 'required|array|min:1',
            'preferences.*' => 'required|array|min:1',
            'preferences.*.*' => 'required|boolean',
        ], [
            'preferences.required' => 'لا توجد تغييرات لحفظها.',
            'preferences.*.*.boolean' => 'قيمة غير صالحة.',
        ]);

        $errors = [];
        foreach ($data['preferences'] as $type => $byChannel) {
            if (! TicketAssignmentNotifications::isType((string) $type)) {
                $errors["preferences.{$type}"] = ['نوع إشعار غير معروف.'];

                continue;
            }
            foreach (array_keys($byChannel) as $channel) {
                if (! in_array($channel, TicketAssignmentNotifications::CHANNELS, true)) {
                    $errors["preferences.{$type}.{$channel}"] = ['قناة غير معروفة.'];
                }
            }
        }
        if ($errors !== []) {
            return response()->json(['success' => false, 'message' => 'تعذّر حفظ التفضيلات: بيانات غير صالحة.', 'errors' => $errors], 422);
        }

        $userId = $request->user()->id;
        foreach ($data['preferences'] as $type => $byChannel) {
            foreach ($byChannel as $channel => $enabled) {
                NotificationPreference::updateOrCreate(
                    ['user_id' => $userId, 'channel' => $channel, 'type' => $type],
                    ['enabled' => (bool) $enabled],
                );
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'تم حفظ تفضيلات إشعارات الإسناد.',
            'data' => ['types' => TicketAssignmentNotifications::preferencesFor($userId)],
        ]);
    }

    /**
     * GET /api/notifications/email-preferences
     *
     * Only the email types THIS account can receive (by role / permission / targeting rule, computed on the server and de-duplicated
     * when several roles qualify), each with the person's choice (follow / enabled / disabled), the central setting, and the effective
     * state with its source: the same decision the sender makes (App\Services\EmailDeliveryPolicy).
     */
    public function emailPreferences(Request $request, EmailPreferenceService $service): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['types' => $service->typesFor($request->user())]]);
    }

    /**
     * PUT /api/notifications/email-preferences
     *
     * Body: { "preferences": { "course_enrollment": "follow", "certificate_ready": "enabled", "session_reminder": "disabled" } }
     * Partial, always for the signed-in user. Rejected whole (422, nothing saved) when a type is unknown, mandatory, not available to
     * this account's roles, or the choice is not one of follow / enabled / disabled. A saved choice is kept while a type is paused or
     * hidden by a role change.
     */
    public function updateEmailPreferences(Request $request, EmailPreferenceService $service): JsonResponse
    {
        $data = $request->validate([
            'preferences' => 'required|array|min:1|max:100',
            'preferences.*' => 'required|string|max:20',
        ], [
            'preferences.required' => 'لا توجد تغييرات لحفظها.',
            'preferences.*.required' => 'اختيار غير صالح.',
        ]);

        $errors = $service->save($request->user(), $data['preferences']);
        if ($errors !== []) {
            return response()->json(['success' => false, 'message' => 'تعذّر حفظ التفضيلات: بعض الأنواع غير متاحة لحسابك.', 'errors' => $errors], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم حفظ تفضيلات البريد الإلكتروني.',
            'data' => ['types' => $service->typesFor($request->user())],
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
