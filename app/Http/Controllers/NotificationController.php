<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $notifications = $request->user()
            ->notifications()
            ->latest()
            ->paginate(30);

        return response()->json([
            'success'       => true,
            'unread_count'  => $request->user()->unreadNotifications()->count(),
            'data'          => $notifications->through(fn ($n) => [
                'id'         => $n->id,
                'type'       => class_basename($n->type),
                'data'       => $n->data,
                'read_at'    => $n->read_at,
                'created_at' => $n->created_at,
            ]),
        ]);
    }

    public function markRead(Request $request, string $id): \Illuminate\Http\JsonResponse
    {
        $notification = $request->user()->notifications()->where('id', $id)->firstOrFail();
        $notification->markAsRead();

        return response()->json(['success' => true, 'message' => 'Notification marked as read.']);
    }

    public function markAllRead(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['success' => true, 'message' => 'All notifications marked as read.']);
    }
}
