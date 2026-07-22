<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ticket 8 completion pass — Web Push subscription registration (Step 6).
 * All endpoints ownership-scoped to the authenticated user; a subscription
 * always belongs to exactly one user_id, never resolvable/removable by id
 * alone.
 */
class PushSubscriptionController extends Controller
{
    /** GET /api/push-subscriptions — whether this user has any active subscription, for preference UI state. */
    public function index(Request $request): JsonResponse
    {
        $subscribed = PushSubscription::where('user_id', $request->user()->id)->exists();

        return response()->json(['success' => true, 'subscribed' => $subscribed]);
    }

    /** POST /api/push-subscriptions */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint'        => 'required|string|max:2000|url',
            'keys.p256dh'     => 'required|string',
            'keys.auth'       => 'required|string',
        ]);

        // A browser push endpoint is 1:1 with a browser installation, not
        // shared across users — if it's already registered to someone else
        // (e.g. a shared/public machine where a prior user never
        // unsubscribed), re-registering it must reassign it to the new
        // user rather than silently letting the old owner keep receiving
        // pushes meant for a different account.
        $subscription = PushSubscription::updateOrCreate(
            ['endpoint_hash' => hash('sha256', $data['endpoint'])],
            [
                'user_id'      => $request->user()->id,
                'endpoint'     => $data['endpoint'],
                'p256dh_key'   => $data['keys']['p256dh'],
                'auth_token'   => $data['keys']['auth'],
                'user_agent'   => substr((string) $request->userAgent(), 0, 255),
                'last_used_at' => now(),
            ],
        );

        return response()->json(['success' => true, 'id' => $subscription->id], 201);
    }

    /** DELETE /api/push-subscriptions */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => 'required|string']);

        $deleted = PushSubscription::where('user_id', $request->user()->id)
            ->where('endpoint_hash', hash('sha256', $data['endpoint']))
            ->delete();

        return response()->json(['success' => true, 'deleted' => $deleted > 0]);
    }
}
