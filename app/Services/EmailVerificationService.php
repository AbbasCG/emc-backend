<?php

namespace App\Services;

use App\Mail\EmailVerificationMail;
use App\Models\EmailSetting;
use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;

/**
 * Email verification for platform users.
 *
 * The link is a Laravel SIGNED, EXPIRING URL to the API (`email.verify`), so it
 * proves nothing else and can be neither forged nor stretched: it carries the user
 * id and `sha1(email)` (so a link for an old address stops working the moment the
 * email changes), a signature and an expiry. It carries no token, no name and no
 * email. The mail itself goes through the platform mail pipeline (EmailService ->
 * notification_logs -> queued SendLoggedEmailJob), type `email_verification`.
 *
 * Two entry points, deliberately different:
 *  - sendInitial(): once, right after an interactive account is created. Never for an
 *    already-verified user, and never twice for the same user even if a flow calls it
 *    twice (a prior email_verification log row blocks it). Existing users are never
 *    mailed by a deploy or a migration: nothing here runs unless an account is created
 *    or someone explicitly asks for a resend.
 *  - resend(): explicit (an admin, or the user for themself), unverified only, and
 *    rate limited per user: 1 per minute and 5 per hour.
 */
class EmailVerificationService
{
    public const TYPE = 'email_verification';

    public const SEND_OK = 'sent';
    public const SEND_ALREADY_VERIFIED = 'already_verified';
    public const SEND_DISABLED = 'disabled';
    public const SEND_THROTTLED = 'throttled';
    public const SEND_NO_EMAIL = 'no_email';

    private const PER_MINUTE = 1;
    private const PER_HOUR = 5;

    /** Minutes a link stays valid. Config: auth.verification.expire (env EMAIL_VERIFICATION_EXPIRE_MINUTES). */
    public function expireMinutes(): int
    {
        return max(5, (int) config('auth.verification.expire', 1440));
    }

    /** The hash a link must carry for this user's CURRENT email. */
    public static function hashFor(User $user): string
    {
        return sha1((string) $user->getEmailForVerification());
    }

    public function verificationUrl(User $user): string
    {
        return URL::temporarySignedRoute(
            'email.verify',
            now()->addMinutes($this->expireMinutes()),
            ['id' => $user->getKey(), 'hash' => self::hashFor($user)],
        );
    }

    /** "24 ساعة" / "60 دقيقة" / "يومان" for the mail copy. */
    public function expiresText(): string
    {
        $m = $this->expireMinutes();
        if ($m % 1440 === 0) {
            $d = intdiv($m, 1440);

            return match (true) {
                $d === 1 => 'يوم واحد (24 ساعة)',
                $d === 2 => 'يومين',
                default => "{$d} أيام",
            };
        }
        if ($m % 60 === 0) {
            $h = intdiv($m, 60);

            return match (true) {
                $h === 1 => 'ساعة واحدة',
                $h === 2 => 'ساعتين',
                $h <= 10 => "{$h} ساعات",
                default => "{$h} ساعة",
            };
        }

        return "{$m} دقيقة";
    }

    private function alreadySentOnce(User $user): bool
    {
        return NotificationLog::query()
            ->where('channel', 'email')
            ->where('type', self::TYPE)
            ->where('user_id', $user->id)
            ->exists();
    }

    /**
     * The automatic email after an account is created. Best effort: a mail problem
     * must never fail the account creation, so this never throws.
     */
    public function sendInitial(User $user, ?User $by = null): void
    {
        try {
            if (! $user->email || $user->hasVerifiedEmail() || $this->alreadySentOnce($user)) {
                return;
            }

            $this->dispatch($user, $by);
        } catch (\Throwable $e) {
            Log::error('EmailVerificationService: initial send failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * An explicit (re)send. Returns one of the SEND_* constants, plus the seconds to
     * wait when throttled.
     *
     * @return array{status: string, retry_after: int|null}
     */
    public function resend(User $user, ?User $by = null): array
    {
        if (! $user->email) {
            return ['status' => self::SEND_NO_EMAIL, 'retry_after' => null];
        }
        if ($user->hasVerifiedEmail()) {
            return ['status' => self::SEND_ALREADY_VERIFIED, 'retry_after' => null];
        }
        if (! EmailSetting::isEnabled(self::TYPE)) {
            return ['status' => self::SEND_DISABLED, 'retry_after' => null];
        }

        $minuteKey = "email-verification:{$user->id}:minute";
        $hourKey = "email-verification:{$user->id}:hour";
        foreach ([[$minuteKey, self::PER_MINUTE], [$hourKey, self::PER_HOUR]] as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return ['status' => self::SEND_THROTTLED, 'retry_after' => RateLimiter::availableIn($key)];
            }
        }
        RateLimiter::hit($minuteKey, 60);
        RateLimiter::hit($hourKey, 3600);

        $this->dispatch($user, $by);

        return ['status' => self::SEND_OK, 'retry_after' => null];
    }

    private function dispatch(User $user, ?User $by): void
    {
        EmailService::dispatch(
            self::TYPE,
            $user->email,
            $user->name,
            $user->id,
            new EmailVerificationMail($user->name, $this->verificationUrl($user), $this->expiresText()),
            'توثيق البريد الإلكتروني لحسابك في EMC',
            // The log keeps who and what, never the link.
            ['user_id' => $user->id],
            ['triggered_by_user_id' => $by?->id],
        );
    }
}
