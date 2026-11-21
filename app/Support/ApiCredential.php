<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * What kind of credential authenticated an API request. One place, so every gate classifies a request the same way.
 *
 *   NONE           no authenticated user
 *   SESSION        first-party SPA cookie session (Sanctum TransientToken)
 *   FIRST_PARTY    personal access token holding "*" (AuthController login tokens)
 *   IMPERSONATION  impersonation bearer token (exactly ["via-impersonation"], ImpersonationController::start)
 *   SCOPED         any other personal access token: a developer/integration token from ApiTokenController,
 *                  or anything unrecognised (empty abilities included). Fail-closed: unknown means scoped.
 *
 * A SESSION or FIRST_PARTY credential can still be an impersonation (cookie-mode preview): see isImpersonating().
 */
final class ApiCredential
{
    public const NONE = 'none';
    public const SESSION = 'session';
    public const FIRST_PARTY = 'first_party';
    public const IMPERSONATION = 'impersonation';
    public const SCOPED = 'scoped';

    public const IMPERSONATION_ABILITY = 'via-impersonation';

    /** Session keys written by ImpersonationController for cookie-mode impersonation. */
    private const SESSION_ACTOR_KEY = 'emc_impersonator_id';
    private const SESSION_EXPIRES_KEY = 'emc_impersonation_expires_at';

    public static function kind(Request $request): string
    {
        $user = $request->user() ?? Auth::guard('sanctum')->user();

        if (! $user) {
            return self::NONE;
        }

        $token = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;

        if (! $token instanceof PersonalAccessToken) {
            // TransientToken (cookie session) or a non-Sanctum guard: the first-party browser session.
            return self::SESSION;
        }

        if (! $token->exists) {
            // Sanctum's guard only ever attaches tokens it loaded from personal_access_tokens, and the app never calls
            // withAccessToken() itself, so an unsaved token object cannot reach a real request. It is what
            // Sanctum::actingAs() attaches in tests to stand for "this user is signed in"; treat it as exactly that.
            // Consequence for tests: Sanctum::actingAs($user, ['read:courses']) is NOT a scoped token here. A test of
            // scoped-token behaviour must use a real token: $user->createToken('x', [...])->plainTextToken + withToken().
            return self::FIRST_PARTY;
        }

        $abilities = array_values((array) ($token->abilities ?? []));

        if (in_array('*', $abilities, true)) {
            return self::FIRST_PARTY;
        }

        if ($abilities === [self::IMPERSONATION_ABILITY]) {
            return self::IMPERSONATION;
        }

        return self::SCOPED;
    }

    /** The abilities a SCOPED token carries ([] for every other kind). */
    public static function scopes(Request $request): array
    {
        if (self::kind($request) !== self::SCOPED) {
            return [];
        }

        return array_values((array) ($request->user()?->currentAccessToken()?->abilities ?? []));
    }

    /**
     * Is someone acting as another user on this request, by impersonation token OR by cookie-mode preview?
     * Checked directly (not only via ImpersonationMiddleware's request attribute) so the answer does not depend
     * on middleware order. Fail-closed: the cookie-preview session keys count even after their expiry time, because the
     * web session still belongs to the TARGET until ImpersonationMiddleware has restored the administrator (which also
     * removes the keys and stops the request that crossed the expiry).
     */
    public static function isImpersonating(Request $request): bool
    {
        if ($request->attributes->get('is_impersonating', false)) {
            return true;
        }

        $kind = self::kind($request);

        if ($kind === self::IMPERSONATION) {
            return true;
        }

        // A token carrying the impersonation marker together with other abilities is still treated as one.
        if ($kind === self::SCOPED && in_array(self::IMPERSONATION_ABILITY, self::scopes($request), true)) {
            return true;
        }

        return $request->hasSession() && $request->session()->has(self::SESSION_ACTOR_KEY);
    }
}
