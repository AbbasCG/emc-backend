<?php

namespace App\Http\Controllers\Mobile;

use App\Enums\RegistrationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CourseResource;
use App\Http\Resources\RegistrationResource;
use App\Models\CalendarEvent;
use App\Models\Certificate;
use App\Models\LmsSession;
use App\Models\PlatformNotification;
use App\Models\Registration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile-first API endpoints.
 *
 * Lightweight, student-focused responses.
 * No admin data is ever included here.
 */
class MobileController extends Controller
{
    /**
     * GET /api/mobile/home
     *
     * Quick summary for the home screen widget.
     */
    public function home(Request $request): JsonResponse
    {
        $user = $request->user();

        $enrolledCount = Registration::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [RegistrationStatus::REGISTERED->value, RegistrationStatus::PENDING_PAYMENT->value])
            ->count();

        $unreadCount = $user->unreadNotifications()->count();

        $nextSession = CalendarEvent::query()
            ->where('user_id', $user->id)
            ->where('start_at', '>', now())
            ->orderBy('start_at')
            ->first();

        return response()->json([
            'success' => true,
            'data'    => [
                'greeting'          => "Welcome back, {$user->name}",
                'enrolled_courses'  => $enrolledCount,
                'unread_notifications' => $unreadCount,
                'next_event'        => $nextSession ? [
                    'id'       => $nextSession->id,
                    'title'    => $nextSession->title,
                    'start_at' => $nextSession->start_at->toIso8601String(),
                    'location' => $nextSession->location,
                ] : null,
            ],
        ]);
    }

    /**
     * GET /api/mobile/dashboard
     *
     * Richer dashboard for the main screen.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();

        $registrations = Registration::query()
            ->where('user_id', $user->id)
            ->with('course:id,title,slug')
            ->latest()
            ->take(5)
            ->get();

        $certificates = Certificate::query()
            ->where('user_id', $user->id)
            ->where('status', 'issued')
            ->latest('issued_at')
            ->take(3)
            ->get(['id', 'title', 'verification_code', 'issued_at']);

        $upcomingSessions = CalendarEvent::query()
            ->where('user_id', $user->id)
            ->where('start_at', '>', now())
            ->orderBy('start_at')
            ->take(5)
            ->get(['id', 'title', 'start_at', 'end_at', 'location', 'meeting_url']);

        return response()->json([
            'success' => true,
            'data'    => [
                'user' => [
                    'id'     => $user->id,
                    'name'   => $user->name,
                    'email'  => $user->email,
                    'role'   => $user->role,
                    'avatar' => $user->avatar_url ?? null,
                ],
                'recent_registrations' => RegistrationResource::collection($registrations),
                'certificates'         => $certificates,
                'upcoming_sessions'    => $upcomingSessions,
                'unread_notifications' => $user->unreadNotifications()->count(),
            ],
        ]);
    }

    /**
     * GET /api/mobile/profile
     */
    public function profile(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data'    => [
                'id'         => $user->id,
                'name'       => $user->name,
                'email'      => $user->email,
                'phone'      => $user->phone ?? null,
                'role'       => $user->role,
                'avatar_url' => $user->avatar_url ?? null,
                'created_at' => $user->created_at->toDateString(),
            ],
        ]);
    }

    /**
     * PUT /api/mobile/profile
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name'  => 'sometimes|string|max:255',
            'phone' => 'sometimes|nullable|string|max:20',
        ]);

        $user->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Profile updated.',
            'data'    => [
                'id'    => $user->id,
                'name'  => $user->name,
                'phone' => $user->phone ?? null,
            ],
        ]);
    }

    /**
     * GET /api/mobile/my-learning
     *
     * Active registrations + progress for enrolled courses.
     */
    public function myLearning(Request $request): JsonResponse
    {
        $registrations = Registration::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('status', RegistrationStatus::activeEnrollment())
            ->with('course:id,title,slug,description,price,is_paid')
            ->latest()
            ->paginate(10);

        return response()->json([
            'success' => true,
            'data'    => RegistrationResource::collection($registrations)->response()->getData(true),
        ]);
    }

    /**
     * GET /api/mobile/notifications
     *
     * Ticket 8 canonical-architecture pass: previously read from Laravel's
     * built-in `notifications` table, which — per the notification
     * call-graph audit — had zero live writers (the only two producers,
     * `SendNotificationJob` and `NotificationDispatcher::dispatchInApp`,
     * were themselves unreachable dead code), so this endpoint always
     * returned an empty list in practice. Now reads the canonical
     * `platform_notifications` store — the same rows the web Notification
     * Center shows — through a response-shape adapter that keeps the
     * pre-existing `{id, type, notifiable_type, notifiable_id, data:
     * {type, title, message, url}, read_at, created_at}` item contract, so
     * an existing mobile client parsing this shape keeps working.
     */
    public function notifications(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $notifications = PlatformNotification::query()
            ->where('user_id', $userId)
            ->whereNull('archived_at')
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => collect($notifications->items())->map(fn (PlatformNotification $n) => $this->notificationToMobileShape($n))->all(),
            'meta'    => [
                'current_page' => $notifications->currentPage(),
                'last_page'    => $notifications->lastPage(),
                'total'        => $notifications->total(),
                'unread'       => PlatformNotification::where('user_id', $userId)->where('is_read', false)->count(),
            ],
        ]);
    }

    /**
     * PUT /api/mobile/notifications/{id}/read
     *
     * `{id}` is now the numeric `platform_notifications.id` (previously a
     * UUID from the built-in table) — ownership-scoped via `user_id`, so a
     * forged/foreign id returns 404 rather than mutating another user's row.
     */
    public function markNotificationRead(Request $request, string $id): JsonResponse
    {
        $notification = PlatformNotification::query()
            ->where('user_id', $request->user()->id)
            ->where('id', $id)
            ->first();

        if (! $notification) {
            return response()->json(['success' => false, 'message' => 'Notification not found.'], 404);
        }

        $notification->update(['is_read' => true, 'read_at' => $notification->read_at ?? now()]);

        return response()->json(['success' => true, 'message' => 'Marked as read.']);
    }

    private function notificationToMobileShape(PlatformNotification $n): array
    {
        return [
            'id'              => (string) $n->id,
            'type'            => $n->type,
            'notifiable_type' => \App\Models\User::class,
            'notifiable_id'   => $n->user_id,
            'data'            => [
                'type'    => $n->type,
                'title'   => $n->title,
                'message' => $n->message,
                'url'     => $n->meta_url ?? $n->action_url,
            ],
            'read_at'    => optional($n->read_at)->toJSON(),
            'created_at' => optional($n->created_at)->toJSON(),
        ];
    }

    /**
     * GET /api/mobile/upcoming-sessions
     */
    public function upcomingSessions(Request $request): JsonResponse
    {
        $events = CalendarEvent::query()
            ->where('user_id', $request->user()->id)
            ->where('start_at', '>', now())
            ->orderBy('start_at')
            ->paginate(10);

        return response()->json([
            'success' => true,
            'data'    => $events->items(),
            'meta'    => [
                'current_page' => $events->currentPage(),
                'last_page'    => $events->lastPage(),
                'total'        => $events->total(),
            ],
        ]);
    }

    /**
     * GET /api/mobile/certificates
     */
    public function certificates(Request $request): JsonResponse
    {
        $certificates = Certificate::query()
            ->where('user_id', $request->user()->id)
            ->where('status', 'issued')
            ->with('course:id,title')
            ->latest('issued_at')
            ->paginate(10);

        return response()->json([
            'success' => true,
            'data'    => $certificates->items(),
            'meta'    => [
                'current_page' => $certificates->currentPage(),
                'last_page'    => $certificates->lastPage(),
                'total'        => $certificates->total(),
            ],
        ]);
    }
}
