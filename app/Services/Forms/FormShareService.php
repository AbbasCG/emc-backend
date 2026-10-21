<?php

namespace App\Services\Forms;

use App\Models\DynamicForm;
use App\Models\FormInvitation;
use App\Models\FormShareLink;
use App\Models\FormSubmission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Who a form is shared with and how: share links, the access mode and invitations.
 *
 * SHARE LINKS. A link is `<site>/forms/<slug>/s/<token>`. The token is random, shown once when the link is made, and only its
 * SHA-256 hash is stored. A request presents the token in the `X-Form-Share` header (never in a query string, so it does not
 * end up in server logs); the form is found by its slug and the token must belong to THAT form. A link is revoked, never deleted.
 *
 * INVITATIONS. A restricted form is open to a list of registered EMC users. Identity is the respondent's own signed-in
 * account, checked on the server on every view and every submission; the invitation carries no secret, so there is nothing to
 * forward or leak. A share link never substitutes for being the invited person.
 *
 * MANAGEMENT is separate from answering: only people who may manage access (the owner and platform admins) change any of this.
 */
class FormShareService
{
    public const MAX_ACTIVE_LINKS = 50;

    public const MAX_INVITATIONS = 500;

    public const LABEL_MAX = 100;

    public function __construct(private readonly FormAvailabilityService $availability) {}

    // ── Reading a presented token ───────────────────────────────────────────

    /** What a presented token means for this form. No token, no opinion. */
    public function resolve(DynamicForm $form, ?string $token): ShareAccess
    {
        if ($token === null || trim($token) === '') {
            return ShareAccess::none();
        }
        // Tokens are 48 characters; anything wildly longer is not one and is not worth hashing.
        if (strlen($token) > 200) {
            return new ShareAccess(true, null, FormAvailabilityService::SHARE_INVALID);
        }

        $link = FormShareLink::query()->where('form_id', $form->id)->where('token_hash', FormShareLink::hashToken(trim($token)))->first();
        if ($link === null) {
            return new ShareAccess(true, null, FormAvailabilityService::SHARE_INVALID);
        }

        $status = $link->statusFor($this->uses($link));
        $problem = match ($status) {
            FormShareLink::REVOKED   => FormAvailabilityService::SHARE_REVOKED,
            FormShareLink::EXPIRED   => FormAvailabilityService::SHARE_EXPIRED,
            FormShareLink::EXHAUSTED => FormAvailabilityService::SHARE_EXHAUSTED,
            default                  => null,
        };

        return new ShareAccess(true, $problem === null ? $link : null, $problem);
    }

    /** Submissions made through the link: the number its `max_uses` limits. */
    public function uses(FormShareLink $link): int
    {
        return FormSubmission::query()->where('share_link_id', $link->id)->count();
    }

    // ── Links ───────────────────────────────────────────────────────────────

    /**
     * Makes a link and returns it with its token, which exists only in this return value.
     *
     * @return array{0: FormShareLink, 1: string}
     */
    public function createLink(DynamicForm $form, User $actor, string $label, ?\DateTimeInterface $expiresAt, ?int $maxUses, ?Request $request = null): array
    {
        $label = trim($label);
        if ($label === '') {
            throw ValidationException::withMessages(['label' => 'اكتب اسماً للرابط يساعدك على تمييزه.']);
        }
        if ($form->status === 'archived') {
            throw ValidationException::withMessages(['form' => 'هذا النموذج مؤرشف: لا يمكن إنشاء روابط جديدة له. استعده من الأرشيف أولاً.']);
        }
        if ($expiresAt !== null && $expiresAt <= now()) {
            throw ValidationException::withMessages(['expires_at' => 'يجب أن يكون موعد انتهاء الرابط في المستقبل.']);
        }

        return DB::transaction(function () use ($form, $actor, $label, $expiresAt, $maxUses, $request) {
            DynamicForm::query()->whereKey($form->id)->lockForUpdate()->firstOrFail();

            $active = FormShareLink::query()->where('form_id', $form->id)->whereNull('revoked_at')->count();
            if ($active >= self::MAX_ACTIVE_LINKS) {
                throw ValidationException::withMessages(['form' => 'وصلت إلى الحد الأقصى من الروابط النشطة ('.self::MAX_ACTIVE_LINKS.'). ألغِ رابطاً لا تحتاجه ثم أعد المحاولة.']);
            }

            $token = FormShareLink::newToken();
            $link = FormShareLink::create([
                'form_id'    => $form->id,
                'label'      => mb_substr($label, 0, self::LABEL_MAX),
                'token_hash' => FormShareLink::hashToken($token),
                'token_hint' => substr($token, -4),
                'expires_at' => $expiresAt,
                'max_uses'   => $maxUses,
                'created_by' => $actor->id,
            ]);

            $changes = [];
            if ($expiresAt !== null) {
                $changes[] = 'ينتهي: '.$expiresAt->format('Y-m-d H:i');
            }
            if ($maxUses !== null) {
                $changes[] = 'أقصى عدد للإجابات: '.$maxUses;
            }
            FormActivityService::record($form, $actor, 'share_link_created', ['note' => $link->label] + ($changes !== [] ? ['changes' => $changes] : []), $request);

            return [$link, $token];
        });
    }

    /** Stops a link from working. Idempotent; the link, its history and every submission that came through it stay. */
    public function revoke(FormShareLink $link, User $actor, ?Request $request = null): FormShareLink
    {
        return DB::transaction(function () use ($link, $actor, $request) {
            $locked = FormShareLink::query()->whereKey($link->id)->lockForUpdate()->firstOrFail();
            if ($locked->revoked_at === null) {
                $locked->forceFill(['revoked_at' => now(), 'revoked_by' => $actor->id])->save();
                FormActivityService::record($locked->form, $actor, 'share_link_revoked', ['note' => $locked->label], $request);
            }

            return $locked;
        });
    }

    // ── Access mode ─────────────────────────────────────────────────────────

    /** Sets who may answer and whether a link is needed. Both live in the form row; the mode is derived from the flags. */
    public function setAccess(DynamicForm $form, User $actor, string $mode, bool $requiresLink, ?Request $request = null): DynamicForm
    {
        return DB::transaction(function () use ($form, $actor, $mode, $requiresLink, $request) {
            $locked = DynamicForm::query()->whereKey($form->id)->lockForUpdate()->firstOrFail();

            if ($requiresLink && ! $locked->requires_share_link
                && ! FormShareLink::query()->where('form_id', $locked->id)->whereNull('revoked_at')->exists()) {
                throw ValidationException::withMessages(['requires_share_link' => 'أنشئ رابط مشاركة واحداً على الأقل قبل جعل النموذج متاحاً عبر الرابط فقط، وإلا لن يتمكن أحد من فتحه.']);
            }

            $beforeMode = $locked->accessMode();
            $beforeLink = (bool) $locked->requires_share_link;

            $locked->fill([
                'allow_anonymous'     => $mode === DynamicForm::ACCESS_PUBLIC,
                'invitation_only'     => $mode === DynamicForm::ACCESS_INVITED,
                'requires_share_link' => $requiresLink,
            ]);
            if (! $locked->isDirty()) {
                return $locked;
            }

            $locked->last_edited_by = $actor->id;
            $locked->save();

            $changes = [];
            if ($beforeMode !== $locked->accessMode()) {
                $changes[] = 'نمط الوصول: '.self::modeLabel($beforeMode).' ← '.self::modeLabel($locked->accessMode());
            }
            if ($beforeLink !== $requiresLink) {
                $changes[] = $requiresLink ? 'أصبح فتح النموذج يتطلب رابط مشاركة' : 'لم يعد فتح النموذج يتطلب رابط مشاركة';
            }
            FormActivityService::record($locked, $actor, 'access_changed', ['changes' => $changes], $request);

            return $locked->refresh();
        });
    }

    public static function modeLabel(string $mode): string
    {
        return [
            DynamicForm::ACCESS_PUBLIC        => 'عام',
            DynamicForm::ACCESS_AUTHENTICATED => 'مستخدمو EMC المسجّلون',
            DynamicForm::ACCESS_INVITED       => 'مدعوّون فقط',
        ][$mode] ?? $mode;
    }

    // ── Invitations ─────────────────────────────────────────────────────────

    /**
     * Registered, active users whose name matches, who do not already have a standing invitation. Name only: no e-mail address
     * and no role, so searching cannot be used to browse the platform's directory.
     *
     * @return Collection<int, User>
     */
    public function candidates(DynamicForm $form, string $query): Collection
    {
        $query = trim($query);
        if (mb_strlen($query) < 3) {
            return collect();
        }
        $taken = FormInvitation::query()->where('form_id', $form->id)->whereNull('revoked_at')->pluck('user_id')->all();

        return User::query()
            ->where('is_active', true)
            ->where('name', 'like', '%'.addcslashes($query, '%_\\').'%')
            ->whereNotIn('id', $taken ?: [0])
            ->orderBy('name')
            ->limit(15)
            ->get(['id', 'name']);
    }

    public function invite(DynamicForm $form, User $actor, int $userId, ?Request $request = null): FormInvitation
    {
        $target = User::query()->where('is_active', true)->find($userId);
        if ($target === null) {
            throw ValidationException::withMessages(['user_id' => 'هذا المستخدم غير موجود أو حسابه غير نشط.']);
        }

        return DB::transaction(function () use ($form, $actor, $target, $request) {
            DynamicForm::query()->whereKey($form->id)->lockForUpdate()->firstOrFail();

            $existing = FormInvitation::query()->where('form_id', $form->id)->where('user_id', $target->id)->first();
            if ($existing !== null && $existing->revoked_at === null) {
                throw ValidationException::withMessages(['user_id' => 'هذا المستخدم مدعوّ بالفعل.']);
            }
            if (FormInvitation::query()->where('form_id', $form->id)->whereNull('revoked_at')->count() >= self::MAX_INVITATIONS) {
                throw ValidationException::withMessages(['user_id' => 'وصلت إلى الحد الأقصى من المدعوّين ('.self::MAX_INVITATIONS.').']);
            }

            // A revoked invitation is brought back rather than duplicated: one row per (form, user).
            $invitation = $existing ?? new FormInvitation(['form_id' => $form->id, 'user_id' => $target->id]);
            $invitation->forceFill(['invited_by' => $actor->id, 'revoked_at' => null, 'revoked_by' => null])->save();

            FormActivityService::record($form, $actor, 'invitation_added', ['user' => $target->name], $request);

            return $invitation;
        });
    }

    public function revokeInvitation(FormInvitation $invitation, User $actor, ?Request $request = null): FormInvitation
    {
        return DB::transaction(function () use ($invitation, $actor, $request) {
            $locked = FormInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            if ($locked->revoked_at === null) {
                $locked->forceFill(['revoked_at' => now(), 'revoked_by' => $actor->id])->save();
                FormActivityService::record($locked->form, $actor, 'invitation_revoked', ['user' => $locked->user?->name], $request);
            }

            return $locked;
        });
    }

    // ── What the public sees ────────────────────────────────────────────────

    /**
     * Can a stranger reach this form, and is it taking answers? Answered for the Share screen from the same rules the public
     * endpoints use (an anonymous visitor with no link), so the screen can never promise what the server would refuse.
     *
     * @return array{state: string, reachable: bool, accepting: bool, message: string}
     */
    public function publicStatus(DynamicForm $form): array
    {
        if ($form->status === 'draft') {
            return ['state' => 'draft', 'reachable' => false, 'accepting' => false, 'message' => 'مسودة: لا يراها العامة. الرابط لا يعمل قبل نشر النموذج.'];
        }
        if ($form->status === 'archived') {
            return ['state' => 'archived', 'reachable' => false, 'accepting' => false, 'message' => 'مؤرشف: توقف الرابط العام. استعد النموذج من الأرشيف لإتاحته مجدداً.'];
        }

        $evaluated = $this->availability->evaluate($form, null);
        $state = $evaluated['state'];

        return [
            'state'     => $state,
            'reachable' => ! in_array($state, FormAvailabilityService::HIDES_FORM, true),
            'accepting' => $state === FormAvailabilityService::OPEN,
            'message'   => $state === FormAvailabilityService::OPEN ? 'منشور ويستقبل الإجابات.' : (string) $evaluated['message'],
        ];
    }
}
