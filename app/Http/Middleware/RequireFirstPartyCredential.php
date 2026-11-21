<?php

namespace App\Http\Middleware;

use App\Support\ApiCredential;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Credential-management and identity-switching routes (API tokens, starting an impersonation) accept ONLY the real
 * user's own first-party credential: the SPA cookie session or a "*" login token, and never while impersonating.
 *
 * Refused with 403:
 *   - scoped API tokens, whatever scopes they hold (a token must never mint or manage tokens, or switch identity);
 *   - impersonation tokens and cookie-mode impersonation previews (the impersonator must not leave a durable token
 *     behind on the target account, revoke the target's tokens, or chain into another impersonation).
 *
 * Alias: `credential.first_party`. It is an authorization gate, so it runs before route-model binding.
 */
class RequireFirstPartyCredential
{
    public const ALIAS = 'credential.first_party';

    public function handle(Request $request, Closure $next): Response
    {
        $kind = ApiCredential::kind($request);

        if ($kind === ApiCredential::NONE) {
            // The route's auth middleware answers guests; never let this gate pass one through on its own.
            return response()->json([
                'success' => false,
                'code' => 'unauthenticated',
                'message' => 'يجب تسجيل الدخول أولاً.',
                'message_en' => 'Unauthenticated.',
            ], 401);
        }

        if (ApiCredential::isImpersonating($request)) {
            return response()->json([
                'success' => false,
                'code' => 'impersonation_forbidden',
                'message' => 'لا يمكن تنفيذ هذا الإجراء أثناء الدخول بحساب مستخدم آخر.',
                'message_en' => 'This action is not allowed while impersonating another user.',
            ], 403);
        }

        if (! in_array($kind, [ApiCredential::SESSION, ApiCredential::FIRST_PARTY], true)) {
            return response()->json([
                'success' => false,
                'code' => 'first_party_credential_required',
                'message' => 'هذا الإجراء يتطلب تسجيل الدخول المباشر، ولا يُقبل فيه رمز برمجي محدود الصلاحيات.',
                'message_en' => 'This action requires a first-party sign-in; scoped API tokens are not accepted.',
            ], 403);
        }

        return $next($request);
    }
}
