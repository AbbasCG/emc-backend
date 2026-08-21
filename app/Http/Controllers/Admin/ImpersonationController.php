<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SecurityLog;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ImpersonationController extends Controller
{
    private const SESSION_ACTOR_KEY = 'emc_impersonator_id';
    private const SESSION_TARGET_KEY = 'emc_impersonation_target_id';
    private const SESSION_EXPIRES_KEY = 'emc_impersonation_expires_at';

    /**
     * POST /api/admin/impersonate/{user}
     *
     * Super admin starts impersonating another user.
     * Returns a short-lived Sanctum token scoped to 'via-impersonation'.
     * The frontend stores the original token, switches to this one, then
     * reverts on stop. All actions under this token are attributed to the
     * super_admin in audit_logs (via ImpersonationMiddleware + AuditLogService).
     */
    public function start(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();

        if (! $actor->hasPermission(Permissions::IMPERSONATE_USERS)) {
            return response()->json([
                'success'    => false,
                'message'    => 'You do not have permission to impersonate users.',
                'message_ar' => 'لا تملك صلاحية تسجيل الدخول كمستخدم آخر.',
            ], 403);
        }

        if ($user->id === $actor->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot impersonate yourself.',
            ], 422);
        }

        if ($user->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Super admins cannot be impersonated.',
            ], 403);
        }

        if (! $user->is_active || $user->trashed()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot impersonate an inactive or deleted user.',
            ], 422);
        }

        // First-party browser sessions never receive an impersonation token.
        // The encrypted server session keeps the original actor and expires the
        // preview after 30 minutes; API clients continue through the token path.
        if (! $request->bearerToken() && $request->hasSession()) {
            $request->session()->put([
                self::SESSION_ACTOR_KEY => $actor->id,
                self::SESSION_TARGET_KEY => $user->id,
                self::SESSION_EXPIRES_KEY => now()->addMinutes(30)->timestamp,
            ]);

            Auth::guard('web')->login($user);
            $request->session()->regenerate();

            SecurityLog::create([
                'actor_id' => $actor->id,
                'event' => 'impersonation_started',
                'target_user_id' => $user->id,
                'sanctum_token_id' => null,
                'metadata' => [
                    'session_impersonation' => true,
                    'impersonator_name' => $actor->name,
                    'impersonator_role' => $actor->role,
                    'target_name' => $user->name,
                    'target_role' => $user->role,
                ],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'success' => true,
                'message' => "Now impersonating {$user->name}.",
                'token' => null,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                ],
                'impersonator' => [
                    'id' => $actor->id,
                    'name' => $actor->name,
                    'role' => $actor->role,
                ],
            ]);
        }

        // Revoke any stale impersonation tokens previously issued for this target
        $user->tokens()->where('name', 'like', 'impersonation-by-%')->delete();

        $tokenResult = $user->createToken(
            'impersonation-by-' . $actor->id,
            ['via-impersonation'],
            now()->addMinutes(30)
        );

        SecurityLog::create([
            'actor_id'         => $actor->id,
            'event'            => 'impersonation_started',
            'target_user_id'   => $user->id,
            'sanctum_token_id' => $tokenResult->accessToken->id,
            'metadata'         => [
                'impersonator_name' => $actor->name,
                'impersonator_role' => $actor->role,
                'target_name'       => $user->name,
                'target_role'       => $user->role,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'success'      => true,
            'message'      => "Now impersonating {$user->name}.",
            'token'        => $tokenResult->plainTextToken,
            'user'         => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'role'  => $user->role,
            ],
            'impersonator' => [
                'id'   => $actor->id,
                'name' => $actor->name,
                'role' => $actor->role,
            ],
        ]);
    }

    /**
     * POST /api/admin/impersonate/stop
     *
     * Must be called with the impersonation token (not the original super_admin token).
     * Revokes the impersonation token and logs the stop event.
     * Frontend should then revert to the stored original super_admin token.
     */
    public function stop(Request $request): JsonResponse
    {
        if ($request->hasSession() && $request->session()->has(self::SESSION_ACTOR_KEY)) {
            $target = $request->user();
            $actor = User::find($request->session()->get(self::SESSION_ACTOR_KEY));

            if (! $actor || ! $actor->is_active || $actor->trashed()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();

                return response()->json([
                    'success' => false,
                    'message' => 'The original administrator session is no longer available.',
                ], 401);
            }

            SecurityLog::create([
                'actor_id' => $actor->id,
                'event' => 'impersonation_stopped',
                'target_user_id' => $target?->id,
                'sanctum_token_id' => null,
                'metadata' => [
                    'session_impersonation' => true,
                    'stopped_at' => now()->toISOString(),
                ],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            Auth::guard('web')->login($actor);
            $request->session()->forget([
                self::SESSION_ACTOR_KEY,
                self::SESSION_TARGET_KEY,
                self::SESSION_EXPIRES_KEY,
            ]);
            $request->session()->regenerate();

            return response()->json([
                'success' => true,
                'message' => 'Impersonation stopped.',
                'data' => [
                    'token' => null,
                    'user' => [
                        'id' => $actor->id,
                        'name' => $actor->name,
                        'email' => $actor->email,
                        'role' => $actor->role,
                    ],
                    'role' => $actor->effectiveRole(),
                    'permissions' => $actor->allPermissions(),
                ],
            ]);
        }

        $token = $request->user()->currentAccessToken();

        if (! $token || ! in_array('via-impersonation', $token->abilities ?? [], true)) {
            return response()->json([
                'success' => false,
                'message' => 'No active impersonation session on this token.',
            ], 422);
        }

        $startLog = SecurityLog::where('sanctum_token_id', $token->id)
            ->where('event', 'impersonation_started')
            ->first();

        if ($startLog) {
            SecurityLog::create([
                'actor_id'         => $startLog->actor_id,
                'event'            => 'impersonation_stopped',
                'target_user_id'   => $request->user()->id,
                'sanctum_token_id' => $token->id,
                'metadata'         => array_merge($startLog->metadata ?? [], [
                    'stopped_at' => now()->toISOString(),
                ]),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        }

        // Revoke the impersonation token — session is over
        $token->delete();

        return response()->json([
            'success' => true,
            'message' => 'Impersonation stopped. Revert to your original token.',
        ]);
    }
}
