<?php

namespace App\Http\Middleware;

use App\Models\SecurityLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Detects active impersonation sessions and annotates the request.
 *
 * Runs globally on all api/* routes (registered in bootstrap/app.php).
 * It resolves the Sanctum user directly so it works before or after
 * auth:sanctum route middleware.
 *
 * When an impersonation token is detected it sets two request attributes:
 *   - is_impersonating  (bool)  true
 *   - impersonator_id   (int)   the super_admin's user id
 *
 * These attributes are read by AuditLogService and AuthController::me().
 * No action is taken for normal (non-impersonation) tokens.
 */
class ImpersonationMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // Resolve user from the Sanctum guard. This is cached after the first
        // call, so auth:sanctum route middleware later is a no-op.
        $user = Auth::guard('sanctum')->user();

        if (! $user) {
            return $next($request);
        }

        if ($request->hasSession() && $request->session()->has('emc_impersonator_id')) {
            $actorId = (int) $request->session()->get('emc_impersonator_id');
            $expiresAt = (int) $request->session()->get('emc_impersonation_expires_at', 0);

            if ($expiresAt > 0 && now()->timestamp > $expiresAt) {
                // The preview has expired. This request was authenticated as the TARGET (the sanctum guard has already
                // cached that user), so it must never run: once the markers below are gone nothing would know it is an
                // impersonation, and a gate such as credential.first_party would let the target's account mint a token
                // or start another impersonation. Restore the administrator for the NEXT request and stop this one.
                $request->session()->forget([
                    'emc_impersonator_id',
                    'emc_impersonation_target_id',
                    'emc_impersonation_expires_at',
                ]);

                $actor = \App\Models\User::find($actorId);
                if ($actor && $actor->is_active && ! $actor->trashed()) {
                    Auth::guard('web')->login($actor);
                    $request->session()->regenerate();

                    return response()->json([
                        'success' => false,
                        'code' => 'impersonation_expired',
                        'message' => 'انتهت مدة المعاينة بحساب المستخدم وأُعيدت جلستك. أعد تحميل الصفحة.',
                        'message_en' => 'The impersonation preview has expired and your own session was restored. Reload the page.',
                    ], 409);
                }

                // The administrator is gone or suspended: end the session entirely rather than leave it on the target.
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return response()->json([
                    'success' => false,
                    'code' => 'impersonation_expired',
                    'message' => 'انتهت الجلسة. سجّل الدخول من جديد.',
                    'message_en' => 'Unauthenticated.',
                ], 401);
            }

            $request->attributes->set('is_impersonating', true);
            $request->attributes->set('impersonator_id', $actorId);

            return $next($request);
        }

        $token = $user->currentAccessToken();

        if (! $token || ! in_array('via-impersonation', $token->abilities ?? [], true)) {
            return $next($request);
        }

        // This is an impersonation token — find the originating security log
        $securityLog = SecurityLog::where('sanctum_token_id', $token->id)
            ->where('event', 'impersonation_started')
            ->first();

        if ($securityLog) {
            $request->attributes->set('is_impersonating', true);
            $request->attributes->set('impersonator_id', $securityLog->actor_id);
        }

        return $next($request);
    }
}
