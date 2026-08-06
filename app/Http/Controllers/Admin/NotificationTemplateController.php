<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationTemplateController extends Controller
{
    /** GET /api/admin/notification-templates */
    public function index(Request $request): JsonResponse
    {
        $templates = NotificationTemplate::query()
            ->when($request->channel, fn ($q) => $q->where('channel', $request->channel))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $templates->items(),
            'meta'    => [
                'current_page' => $templates->currentPage(),
                'last_page'    => $templates->lastPage(),
                'total'        => $templates->total(),
            ],
        ]);
    }

    /** POST /api/admin/notification-templates */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key'      => 'required|string|max:100',
            'channel'  => 'required|in:email,whatsapp,in_app,sms',
            'language' => 'sometimes|string|max:10',
            'subject'  => 'nullable|string|max:255',
            'content'  => 'required|string',
            'status'   => 'sometimes|in:active,inactive',
        ]);

        $template = NotificationTemplate::create($data);

        return response()->json(['success' => true, 'data' => $template], 201);
    }

    /** PUT /api/admin/notification-templates/{id} */
    public function update(Request $request, NotificationTemplate $notificationTemplate): JsonResponse
    {
        $data = $request->validate([
            'subject' => 'nullable|string|max:255',
            'content' => 'sometimes|string',
            'status'  => 'sometimes|in:active,inactive',
        ]);

        $notificationTemplate->update($data);

        return response()->json(['success' => true, 'data' => $notificationTemplate->fresh()]);
    }

    /** DELETE /api/admin/notification-templates/{id} */
    public function destroy(NotificationTemplate $notificationTemplate): JsonResponse
    {
        $notificationTemplate->delete();

        return response()->json(['success' => true, 'message' => 'Template deleted.']);
    }

    /** GET /api/admin/notification-logs */
    public function logs(Request $request): JsonResponse
    {
        $logs = NotificationLog::query()
            ->when($request->channel, fn ($q) => $q->where('channel', $request->channel))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->user_id, fn ($q) => $q->where('user_id', $request->user_id))
            ->latest()
            ->paginate(25);

        return response()->json([
            'success' => true,
            'data'    => $logs->items(),
            'meta'    => [
                'current_page' => $logs->currentPage(),
                'last_page'    => $logs->lastPage(),
                'total'        => $logs->total(),
            ],
        ]);
    }
}
