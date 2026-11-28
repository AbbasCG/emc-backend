<?php

namespace App\Services;

use App\Models\EmailSetting;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Support\EmailNotificationTypes;

/**
 * THE one decision «does this email reach this person?» — used by the sender (at dispatch time AND when the queued job runs) and by
 * the preference page that shows the same answer, so the screen can never promise something the sender will not do.
 *
 * Email channel only; in-app notifications never go through here. In this order:
 *
 *   1. ELIGIBLE      a registered recipient of a type with an explicit audience (staff fan-out) must be someone that type is
 *                    for. The sender has already established the relationship to the event (their task, their ticket, their
 *                    certificate); this re-checks the role side, so a recipient who lost the role while the mail waited in the
 *                    queue is skipped.
 *   2. PAUSED        «إيقاف الإرسال للجميع مؤقتًا»: nobody receives it, whatever they chose. Lifting it restores each person's state;
 *                    nothing is edited and nothing missed is replayed.
 *   3. MANDATORY     security / credentials mail and guests (no account, nothing to choose): sent.
 *   4. PERSONAL      the person chose «مفعّل» or «متوقف»: that applies. (No saved row = they follow the central setting.)
 *   5. CENTRAL       «التفعيل افتراضيًا» applies to everybody who follows the central setting, guests included.
 */
class EmailDeliveryPolicy
{
    public const SOURCE_NOT_ELIGIBLE = 'not_eligible';

    public const SOURCE_PAUSED = 'central_paused';

    public const SOURCE_MANDATORY = 'mandatory';

    public const SOURCE_GUEST = 'guest';

    public const SOURCE_USER_ENABLED = 'user_enabled';

    public const SOURCE_USER_DISABLED = 'user_disabled';

    public const SOURCE_DEFAULT_ON = 'central_default_on';

    public const SOURCE_DEFAULT_OFF = 'central_default_off';

    /** The Arabic line shown next to the effective state. */
    public const SOURCE_LABELS = [
        self::SOURCE_NOT_ELIGIBLE => 'هذا النوع غير متاح لحسابك',
        self::SOURCE_PAUSED => 'موقوف مؤقتًا من الإدارة',
        self::SOURCE_MANDATORY => 'رسالة إلزامية',
        self::SOURCE_GUEST => 'يتبع الإعداد المركزي',
        self::SOURCE_USER_ENABLED => 'بحسب اختيارك: مفعّل',
        self::SOURCE_USER_DISABLED => 'بحسب اختيارك: متوقف',
        self::SOURCE_DEFAULT_ON => 'يتبع الإعداد المركزي: مفعّل',
        self::SOURCE_DEFAULT_OFF => 'يتبع الإعداد المركزي: متوقف',
    ];

    /**
     * @param  bool  $ignorePersonalChoice  an administrator's deliberate test message to themselves: steps 4-5 are skipped
     *                                      (the pause and eligibility still apply, as the test always honoured the admin switch)
     * @return array{send: bool, source: string, log_status: string|null}
     *         log_status: the Email Logs status of a skipped message ('skipped_disabled' = the central setting: pause or default off,
     *         'skipped_user_preference' = the person's own choice or their eligibility), null when it is sent
     */
    public static function decide(string $key, ?User $user, bool $ignorePersonalChoice = false): array
    {
        // 1. eligibility (only where the type names an audience, and only for a registered recipient)
        if ($user !== null
            && ! EmailNotificationTypes::isMandatory($key)
            && EmailNotificationTypes::hasRestrictedAudience($key)
            && ! EmailNotificationTypes::isEligible($user, $key)) {
            return self::skip(self::SOURCE_NOT_ELIGIBLE, 'skipped_user_preference');
        }

        // 2. the central pause (for mandatory mail this is the administrator's old stop, unchanged)
        if (EmailSetting::isPaused($key)) {
            return self::skip(self::SOURCE_PAUSED, 'skipped_disabled');
        }

        // 3. mandatory mail, and recipients with no account
        if (EmailNotificationTypes::isMandatory($key)) {
            return self::send(self::SOURCE_MANDATORY);
        }

        $configurable = EmailNotificationTypes::isUserConfigurable($key);

        // 4. the person's own choice (never for a type nobody can choose for)
        if ($user !== null && $configurable && ! $ignorePersonalChoice) {
            $pref = NotificationPreference::query()
                ->where('user_id', $user->id)->where('channel', 'email')->where('type', $key)->first();
            if ($pref !== null) {
                return $pref->enabled
                    ? self::send(self::SOURCE_USER_ENABLED)
                    : self::skip(self::SOURCE_USER_DISABLED, 'skipped_user_preference');
            }
        }

        // 5. the central default
        if (EmailSetting::defaultEnabled($key) || $ignorePersonalChoice) {
            return self::send($user === null ? self::SOURCE_GUEST : self::SOURCE_DEFAULT_ON);
        }

        return self::skip(self::SOURCE_DEFAULT_OFF, 'skipped_disabled');
    }

    /** @return array{send: bool, source: string, log_status: string|null} */
    private static function send(string $source): array
    {
        return ['send' => true, 'source' => $source, 'log_status' => null];
    }

    /** @return array{send: bool, source: string, log_status: string|null} */
    private static function skip(string $source, string $logStatus): array
    {
        return ['send' => false, 'source' => $source, 'log_status' => $logStatus];
    }

    /**
     * The person a mail is addressed to. A personal choice belongs to the owner of the ADDRESS: a caller that passes the signed-in
     * user's id with an address somebody else typed (a contact form, a form's email question) must not apply that user's choice to
     * somebody else's mail. So the id is honoured only when it is the account of that address; otherwise the address decides
     * (an account with that email, or nobody: a visitor who follows the central setting).
     */
    public static function resolveUser(?int $userId, string $email): ?User
    {
        if ($userId !== null && $userId > 0) {
            $user = User::query()->find($userId);
            if ($user !== null && strcasecmp((string) $user->email, trim($email)) === 0) {
                return $user;
            }
        }

        return User::query()->where('email', trim($email))->first();
    }
}
