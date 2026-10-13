<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\EmailVerificationService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Email verification.
 *
 *   GET  /api/email/verify/{id}/{hash}   public; honoured ONLY with a valid, unexpired
 *                                        Laravel signature; then redirects to the SPA
 *   POST /api/auth/email/resend          the signed-in user asks for a new link for themself
 *
 * The public endpoint never reveals whether an id exists: every failure (bad signature,
 * wrong hash, unknown user) is the same "invalid", so it cannot be used to probe accounts.
 */
class EmailVerificationController extends Controller
{
    public const MESSAGES = [
        'success' => 'تم توثيق بريدك الإلكتروني بنجاح.',
        'already' => 'بريدك الإلكتروني موثّق مسبقًا.',
        'expired' => 'رابط التوثيق غير صالح أو انتهت صلاحيته.',
        'invalid' => 'رابط التوثيق غير صالح أو انتهت صلاحيته.',
    ];

    public function __construct(private readonly EmailVerificationService $verification)
    {
    }

    public function verify(Request $request, int $id, string $hash): RedirectResponse|JsonResponse
    {
        $status = $this->outcome($request, $id, $hash);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => in_array($status, ['success', 'already'], true),
                'status'  => $status,
                'message' => self::MESSAGES[$status],
            ], in_array($status, ['success', 'already'], true) ? 200 : 403);
        }

        $frontend = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        // Only the outcome travels in the URL: no id, no email, no token.
        return redirect()->away($frontend.'/verify-email?status='.$status);
    }

    private function outcome(Request $request, int $id, string $hash): string
    {
        if (! URL::hasCorrectSignature($request)) {
            return 'invalid';
        }
        if (! URL::signatureHasNotExpired($request)) {
            return 'expired';
        }

        $user = User::query()->find($id);
        if (! $user || ! hash_equals(EmailVerificationService::hashFor($user), $hash)) {
            return 'invalid';
        }
        if ($user->hasVerifiedEmail()) {
            return 'already';
        }

        $user->markEmailAsVerified();
        event(new Verified($user));
        AuditLogService::log('email_verified', 'User', $user->id, $user->name, null, null, ['via' => 'signed_link'], $request);

        return 'success';
    }

    /** POST /api/auth/email/resend */
    public function resendMine(Request $request): JsonResponse
    {
        return self::respond($this->verification->resend($request->user(), $request->user()));
    }

    /**
     * The one mapping from a resend result to an HTTP answer, shared with the admin endpoint.
     *
     * @param  array{status: string, retry_after: int|null}  $result
     */
    public static function respond(array $result): JsonResponse
    {
        return match ($result['status']) {
            EmailVerificationService::SEND_OK => response()->json([
                'success' => true,
                'message' => 'تم إرسال رسالة توثيق البريد الإلكتروني.',
            ]),
            EmailVerificationService::SEND_ALREADY_VERIFIED => response()->json([
                'success' => false,
                'message' => 'البريد الإلكتروني موثّق مسبقًا.',
            ], 409),
            EmailVerificationService::SEND_THROTTLED => response()->json([
                'success'     => false,
                'message'     => 'تم إرسال رسالة توثيق قبل قليل. يمكنك المحاولة مجددًا بعد '.max(1, (int) ceil(($result['retry_after'] ?? 60) / 60)).' دقيقة.',
                'retry_after' => $result['retry_after'],
            ], 429),
            EmailVerificationService::SEND_DISABLED => response()->json([
                'success' => false,
                'message' => 'إرسال رسائل توثيق البريد الإلكتروني متوقف من إعدادات البريد.',
            ], 409),
            default => response()->json([
                'success' => false,
                'message' => 'لا يوجد بريد إلكتروني مسجَّل لهذا الحساب.',
            ], 422),
        };
    }
}
