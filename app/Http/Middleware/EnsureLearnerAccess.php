<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Entry to the learner experience (My Courses, lessons, progress, attendance, assignments, certificates, checkout).
 *
 * Replaces `role:student`. Learning is not a role: every authenticated, active account may learn, whatever its organizational role,
 * and a role added later gets the same access without anyone touching this file. Suspended accounts are stopped by
 * EnsureUserIsActive, which runs on the whole api group; this middleware only insists on a signed-in user.
 *
 * It grants ENTRY only. Every endpoint behind it still reads the caller's own data (their registrations, progress and
 * certificates), and management features keep their own role and permission middleware.
 */
class EnsureLearnerAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        if (! $user->is_active) {
            return response()->json(['success' => false, 'message' => 'Account is suspended.'], 403);
        }

        return $next($request);
    }
}
