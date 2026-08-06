<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;

class ForgotPasswordController extends Controller
{
    /**
     * POST /api/auth/forgot-password
     *
     * Sends a password reset link to the given email address.
     * Always returns 200 — never reveals whether the email exists.
     */
    public function send(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email|max:255',
        ]);

        // sendResetLink() handles the lookup, token generation, and mail internally.
        // Wrapped in try/catch: if the mail transport itself fails (bad SMTP creds,
        // network issue), the request must NOT 500 — that would leak which emails
        // are registered (only real accounts reach the mail-send step) and would
        // break the UX for every legitimate reset attempt. Log and still return the
        // same generic response as an unregistered email (STATUS_INVALID_USER),
        // preserving the anti-enumeration guarantee either way.
        try {
            Password::sendResetLink($request->only('email'));
        } catch (\Throwable $e) {
            Log::error('ForgotPasswordController: sendResetLink failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'إذا كان هذا البريد الإلكتروني مسجلاً لدينا، فسيصلك رابط إعادة تعيين كلمة المرور خلال دقائق.',
        ]);
    }
}
