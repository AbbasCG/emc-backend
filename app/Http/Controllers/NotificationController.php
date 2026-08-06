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

        $query = PlatformNotification::where('user_id', $user->id)
            ->where('type', '!=', 'product_update');

        // Archived items are hidden by default — same convention as email trash/archive UIs.
        // ?archived=1 shows only archived; ?archived=all shows everything.
        $archivedParam = $request->query('archived');
        if ($archivedParam === '1' || $archivedParam === 'true') {
            $query->whereNotNull('archived_at');
        } elseif ($archivedParam !== 'all') {
            $query->whereNull('archived_at');
        }

        if ($request->filled('unread_only') && $request->boolean('unread_only')) {
            $query->where('is_read', false);
        }

        if ($request->filled('pinned_only') && $request->boolean('pinned_only')) {
            $query->where('pinned', true);
        }

        if ($request->filled('search')) {
            $term = $request->string('search')->trim();
            $query->where(fn ($q) => $q->where('title', 'like', "%{$term}%")->orWhere('message', 'like', "%{$term}%"));
        }

        $notifications = $query->orderByDesc('pinned')->latest()->paginate(30);

        $unreadCount = PlatformNotification::where('user_id', $user->id)
            ->where('type', '!=', 'product_update')
            ->whereNull('archived_at')
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

    public function pin(Request $request, string $id): JsonResponse
    {
        PlatformNotification::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->firstOrFail()
            ->update(['pinned' => true]);

        return response()->json(['success' => true, 'message' => 'Notification pinned.']);
    }

    public function unpin(Request $request, string $id): JsonResponse
    {
        PlatformNotification::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->firstOrFail()
            ->update(['pinned' => false]);

        return response()->json(['success' => true, 'message' => 'Notification unpinned.']);
    }

    public function archive(Request $request, string $id): JsonResponse
    {
        PlatformNotification::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->firstOrFail()
            ->update(['archived_at' => now()]);

        return response()->json(['success' => true, 'message' => 'Notification archived.']);
    }

    public function unarchive(Request $request, string $id): JsonResponse
    {
        PlatformNotification::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->firstOrFail()
            ->update(['archived_at' => null]);

        return response()->json(['success' => true, 'message' => 'Notification restored.']);
    }

    /**
     * POST /notifications/bulk
     * Body: { "ids": [1,2,3], "action": "read"|"unread"|"archive"|"unarchive"|"pin"|"unpin"|"delete" }
     *
     * Ownership-scoped exactly like every single-item endpoint above — the
     * whereIn is still constrained to user_id = auth user, so a forged ID
     * belonging to another user is silently excluded, not a 403 (matches
     * the existing firstOrFail-404 convention for a single forged ID).
     */
    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids'    => ['required', 'array', 'min:1', 'max:200'],
            'ids.*'  => ['integer'],
            'action' => ['required', 'string', 'in:read,unread,archive,unarchive,pin,unpin,delete'],
        ]);

        $query = PlatformNotification::where('user_id', $request->user()->id)
            ->whereIn('id', $data['ids']);

        $affected = match ($data['action']) {
            'read'      => $query->update(['is_read' => true, 'read_at' => now()]),
            'unread'    => $query->update(['is_read' => false, 'read_at' => null]),
            'archive'   => $query->update(['archived_at' => now()]),
            'unarchive' => $query->update(['archived_at' => null]),
            'pin'       => $query->update(['pinned' => true]),
            'unpin'     => $query->update(['pinned' => false]),
            'delete'    => $query->delete(),
        };

        return response()->json(['success' => true, 'message' => 'تم تحديث الإشعارات المحددة.', 'affected' => $affected]);
    }
}
