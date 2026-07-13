<?php

namespace App\Http\Controllers;

use App\Http\Resources\PlatformNotificationResource;
use App\Models\PlatformNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $notifications = PlatformNotification::where('user_id', $user->id)
            ->where('type', '!=', 'product_update')
            ->latest()
            ->paginate(30);

        $unreadCount = PlatformNotification::where('user_id', $user->id)
            ->where('type', '!=', 'product_update')
            ->where('is_read', false)
            ->count();

        return response()->json([
            'success'      => true,
            'unread_count' => $unreadCount,
            'data'         => PlatformNotificationResource::collection($notifications),
            'meta'         => [
                'total'        => $notifications->total(),
                'current_page' => $notifications->currentPage(),
                'last_page'    => $notifications->lastPage(),
                'per_page'     => $notifications->perPage(),
            ],
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = PlatformNotification::where('user_id', $request->user()->id)
            ->where('type', '!=', 'product_update')
            ->where('is_read', false)
            ->count();

        return response()->json(['success' => true, 'unread_count' => $count]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        PlatformNotification::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->firstOrFail()
            ->update(['is_read' => true, 'read_at' => now()]);

        return response()->json(['success' => true, 'message' => 'Notification marked as read.']);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        PlatformNotification::where('user_id', $request->user()->id)
            ->where('type', '!=', 'product_update')
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        return response()->json(['success' => true, 'message' => 'All notifications marked as read.']);
    }

    public function delete(Request $request, string $id): JsonResponse
    {
        PlatformNotification::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->firstOrFail()
            ->delete();

        return response()->json(['success' => true, 'message' => 'Notification deleted.']);
    }
}
