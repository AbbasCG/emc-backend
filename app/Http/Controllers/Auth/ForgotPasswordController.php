<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        // STATUS_RESET_LINK_SENT or STATUS_INVALID_USER — we return 200 for both
        // to prevent email enumeration attacks.
        Password::sendResetLink($request->only('email'));

        return response()->json([
            'success' => true,
            'message' => 'If this email is registered, a password reset link has been sent.',
        ]);
    }
}
