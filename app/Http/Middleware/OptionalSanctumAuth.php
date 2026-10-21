<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * If a valid Sanctum personal access token is sent, authenticate the user
 * without requiring auth for the route (guests can still hit the endpoint).
 */
class OptionalSanctumAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if (! $token) {
            return $next($request);
        }

        $accessToken = PersonalAccessToken::findToken($token);
        $user = $accessToken?->tokenable;

        // EnsureUserIsActive ran BEFORE this middleware (it is appended to the whole api group), when no user was resolved yet, so a
        // suspended account's token would otherwise act as a signed-in user on every optional-auth route (enrolling, submitting a
        // form). A suspended token browses as a guest on safe requests and is refused on anything that changes data.
        if ($user && ! $user->is_active) {
            if (! $request->isMethodSafe()) {
                return response()->json(['success' => false, 'message' => 'Account is suspended.'], 403);
            }

            return $next($request);
        }

        if ($user) {
            Auth::guard('sanctum')->setUser($user);
            Auth::shouldUse('sanctum');
        }

        return $next($request);
    }
}
