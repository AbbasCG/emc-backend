<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Block API access for suspended accounts (is_active = false).
 *
 * Must run AFTER auth:sanctum so $request->user() is already populated.
 * Applied to all authenticated API route groups.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Account is suspended.',
            ], 403);
        }

        return $next($request);
    }
}
