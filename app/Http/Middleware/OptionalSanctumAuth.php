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
        if ($user) {
            Auth::guard('sanctum')->setUser($user);
            Auth::shouldUse('sanctum');
        }

        return $next($request);
    }
}
