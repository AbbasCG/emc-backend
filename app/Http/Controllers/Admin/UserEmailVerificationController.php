<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\EmailVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/admin/users/{user}/resend-verification
 *
 * Reachable only through the same role gate as the admin users module
 * (admin / super_admin / tech_admin). On top of that: only for a user who is still
 * unverified, active and not deleted, never against a super_admin unless the caller is
 * one (the same protection as editing that account), and rate limited per user by the
 * service (1 per minute, 5 per hour).
 */
class UserEmailVerificationController extends Controller
{
    public function __construct(private readonly EmailVerificationService $verification)
    {
    }

    public function resend(Request $request, User $user): JsonResponse
    {
        if ($user->isSuperAdmin() && ! $request->user()->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكنك إرسال رسالة توثيق لحساب مدير النظام.',
            ], 403);
        }

        if ($user->hasVerifiedEmail()) {
            return EmailVerificationController::respond(['status' => EmailVerificationService::SEND_ALREADY_VERIFIED, 'retry_after' => null]);
        }

        if (! $user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن إرسال رسالة توثيق لحساب موقوف. فعّل الحساب أولًا.',
            ], 422);
        }

        $result = $this->verification->resend($user, $request->user());

        if ($result['status'] === EmailVerificationService::SEND_OK) {
            AuditLogService::log('verification_resent', 'User', $user->id, $user->name, null, null, ['by' => $request->user()->id], $request);
        }

        return EmailVerificationController::respond($result);
    }
}
