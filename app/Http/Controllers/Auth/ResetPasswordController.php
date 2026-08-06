<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class ResetPasswordController extends Controller
{
    /**
     * POST /api/auth/reset-password
     *
     * Resets the user's password using a valid reset token.
     * Revokes all existing Sanctum tokens on success (force re-login).
     */
    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'token'    => 'required|string',
            'email'    => 'required|email|max:255',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password'       => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                // Revoke all tokens — forces re-login on all devices
                $user->tokens()->delete();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'success' => true,
                'message' => 'تم تغيير كلمة المرور بنجاح. يمكنك الآن تسجيل الدخول بكلمة المرور الجديدة.',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => match ($status) {
                Password::INVALID_TOKEN => 'رابط إعادة التعيين غير صالح أو منتهي الصلاحية. يرجى طلب رابط جديد.',
                Password::INVALID_USER  => 'لا يوجد حساب مرتبط بهذا البريد الإلكتروني.',
                default                 => 'تعذّر تغيير كلمة المرور. يرجى طلب رابط جديد.',
            },
        ], 422);
    }
}
