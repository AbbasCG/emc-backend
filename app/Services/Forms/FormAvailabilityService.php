<?php

namespace App\Services\Forms;

use App\Models\DynamicForm;
use App\Models\FormInvitation;
use App\Models\FormSubmission;
use App\Models\User;

/**
 * Is this form accepting answers from this respondent right now?
 *
 * Decided on the server from the form's own settings, the clock and the stored submissions; nothing the browser sends can
 * change the outcome. Used twice by the public endpoints: to tell the page what to show, and again inside the locked
 * transaction that stores a submission, so a limit cannot be exceeded by requests that arrive together.
 *
 * LIMITS AND ANONYMOUS RESPONDENTS: an anonymous respondent has no identity the server can trust (an address is shared by
 * a whole office and changes by the minute), so a per-respondent limit cannot be applied to them. Instead, a form with a
 * per-respondent limit requires a signed-in respondent. The overall `max_submissions` limit counts everyone and needs no
 * identity. The per-address rate limit on the submit route (Phase 0) remains the only protection against floods from
 * anonymous visitors.
 *
 * ACCESS (Phase 3): beyond the dates and limits, a form can need a valid share link (`requires_share_link`), a signed-in
 * respondent (`allow_anonymous` off) or a signed-in respondent who has been invited (`invitation_only`). A share link only ever
 * ADDS conditions (valid, unexpired, not used up); it never replaces signing in, so forwarding an invitation link cannot let
 * anyone in as somebody else.
 */
class FormAvailabilityService
{
    public const OPEN = 'open';

    public const CLOSED = 'closed';

    public const NOT_OPEN_YET = 'not_open_yet';

    public const ENDED = 'ended';

    public const LOGIN_REQUIRED = 'login_required';

    public const LIMIT_REACHED = 'limit_reached';

    public const RESPONDENT_LIMIT = 'respondent_limit';

    public const SHARE_REQUIRED = 'share_link_required';

    public const SHARE_INVALID = 'share_link_invalid';

    public const SHARE_REVOKED = 'share_link_revoked';

    public const SHARE_EXPIRED = 'share_link_expired';

    public const SHARE_EXHAUSTED = 'share_link_exhausted';

    public const NOT_INVITED = 'not_invited';

    /** States that say nothing about the form itself (not even its title): the visitor has not shown they may see it. */
    public const HIDES_FORM = [
        self::SHARE_REQUIRED, self::SHARE_INVALID, self::SHARE_REVOKED, self::SHARE_EXPIRED, self::SHARE_EXHAUSTED, self::NOT_INVITED,
    ];

    /** Machine states that are a refusal, and the HTTP status each is answered with. */
    public const HTTP_STATUS = [
        self::CLOSED           => 403,
        self::NOT_OPEN_YET     => 403,
        self::ENDED            => 403,
        self::LOGIN_REQUIRED   => 401,
        self::LIMIT_REACHED    => 403,
        self::RESPONDENT_LIMIT => 403,
        self::SHARE_REQUIRED   => 403,
        self::SHARE_INVALID    => 403,
        self::SHARE_REVOKED    => 403,
        self::SHARE_EXPIRED    => 403,
        self::SHARE_EXHAUSTED  => 403,
        self::NOT_INVITED      => 403,
    ];

    /** @return array{state: string, message: string|null, opens_at: string|null, closes_at: string|null, requires_login: bool, access_mode: string} */
    public function evaluate(DynamicForm $form, ?User $user, ?ShareAccess $share = null): array
    {
        $requiresLogin = self::requiresLogin($form);
        $base = [
            'opens_at'       => $form->opens_at?->toIso8601String(),
            'closes_at'      => $form->closes_at?->toIso8601String(),
            'requires_login' => $requiresLogin,
            'access_mode'    => $form->accessMode(),
        ];

        $state = $this->state($form, $user, $requiresLogin, $share ?? ShareAccess::none());

        return ['state' => $state, 'message' => $this->message($state, $form)] + $base;
    }

    public static function requiresLogin(DynamicForm $form): bool
    {
        return ! $form->allow_anonymous || $form->invitation_only || $form->per_respondent_limit !== null;
    }

    private function state(DynamicForm $form, ?User $user, bool $requiresLogin, ShareAccess $share): string
    {
        if ($form->status !== 'active') {
            return self::CLOSED;
        }
        // A token that was presented must be good, whatever the form's own mode; and a form that needs a link refuses everyone without one.
        if ($share->presented && $share->problem !== null) {
            return $share->problem;
        }
        if ($form->requires_share_link && $share->link === null) {
            return self::SHARE_REQUIRED;
        }
        if ($form->opens_at !== null && $form->opens_at->isFuture()) {
            return self::NOT_OPEN_YET;
        }
        if ($form->closes_at !== null && $form->closes_at->isPast()) {
            return self::ENDED;
        }
        if ($requiresLogin && $user === null) {
            return self::LOGIN_REQUIRED;
        }
        if ($form->invitation_only && ! $this->isInvited($form, $user)) {
            return self::NOT_INVITED;
        }
        if ($form->max_submissions !== null && FormSubmission::query()->where('form_id', $form->id)->count() >= $form->max_submissions) {
            return self::LIMIT_REACHED;
        }
        if ($form->per_respondent_limit !== null && $user !== null
            && FormSubmission::query()->where('form_id', $form->id)->where('user_id', $user->id)->count() >= $form->per_respondent_limit) {
            return self::RESPONDENT_LIMIT;
        }

        return self::OPEN;
    }

    /** Invited means: signed in as a user with a standing (not revoked) invitation to this very form. */
    private function isInvited(DynamicForm $form, ?User $user): bool
    {
        return $user !== null && FormInvitation::query()
            ->where('form_id', $form->id)->where('user_id', $user->id)->whereNull('revoked_at')->exists();
    }

    private function message(string $state, DynamicForm $form): ?string
    {
        return match ($state) {
            self::CLOSED           => 'هذا النموذج مغلق ولا يستقبل إجابات حالياً.',
            self::NOT_OPEN_YET     => 'لم يبدأ استقبال الإجابات على هذا النموذج بعد.',
            self::ENDED            => 'انتهى موعد استقبال الإجابات على هذا النموذج.',
            self::LOGIN_REQUIRED   => $form->invitation_only ? 'هذا النموذج مخصص لمدعوّين. سجّل الدخول بحسابك لتعبئته.' : 'يجب تسجيل الدخول لتعبئة هذا النموذج.',
            self::NOT_INVITED      => 'هذا النموذج مخصص لمدعوّين فقط، وحسابك ليس ضمنهم.',
            self::SHARE_REQUIRED   => 'هذا النموذج متاح عبر رابط مشاركة خاص فقط. اطلب الرابط من الجهة المسؤولة عنه.',
            self::SHARE_INVALID    => 'رابط المشاركة غير صحيح.',
            self::SHARE_REVOKED    => 'أُلغي رابط المشاركة هذا ولم يعد صالحاً.',
            self::SHARE_EXPIRED    => 'انتهت صلاحية رابط المشاركة هذا.',
            self::SHARE_EXHAUSTED  => 'اكتمل العدد المسموح به من الإجابات عبر رابط المشاركة هذا.',
            self::LIMIT_REACHED    => 'اكتمل العدد المسموح به من الإجابات على هذا النموذج.',
            self::RESPONDENT_LIMIT => 'لقد وصلت إلى الحد الأقصى المسموح لك من الإجابات على هذا النموذج.',
            default                => null,
        };
    }
}
