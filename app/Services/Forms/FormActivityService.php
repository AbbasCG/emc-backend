<?php

namespace App\Services\Forms;

use App\Models\ActivityLog;
use App\Models\DynamicForm;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The form's history, written to the platform's existing activity log (activity_logs) with entity_type = DynamicForm.
 *
 * What goes in is deliberately small: who did it, when, which action, and a short summary of WHAT changed (field names,
 * question labels, version numbers, a collaborator's display name). Never a token, never an answer, never a response value,
 * never an e-mail address — the history of a form must be readable by everyone who can open the form without leaking
 * the responses it collects.
 */
class FormActivityService
{
    /** action => Arabic label shown in the history tab */
    public const LABELS = [
        'created'                => 'إنشاء النموذج',
        'updated'                => 'تعديل بيانات النموذج',
        'questions_changed'      => 'تعديل الأسئلة',
        'questions_reordered'    => 'إعادة ترتيب الأسئلة',
        'settings_changed'       => 'تغيير إعدادات النشر والإتاحة',
        'visibility_changed'     => 'تغيير ظهور النموذج',
        'ownership_changed'      => 'نقل ملكية النموذج',
        'collaborator_added'     => 'إضافة متعاون',
        'collaborator_updated'   => 'تعديل صلاحيات متعاون',
        'collaborator_removed'   => 'إزالة متعاون',
        'published'              => 'نشر النموذج',
        'unpublished'            => 'إلغاء نشر النموذج',
        'closed'                 => 'إغلاق النموذج',
        'reopened'               => 'إعادة فتح النموذج',
        'archived'               => 'أرشفة النموذج',
        'unarchived'             => 'استعادة النموذج من الأرشيف',
        'version_restored'       => 'استعادة إصدار سابق إلى المسودة',
        'deleted'                => 'حذف النموذج',
        'share_link_created'     => 'إنشاء رابط مشاركة',
        'share_link_revoked'     => 'إلغاء رابط مشاركة',
        'access_changed'         => 'تغيير نمط الوصول إلى النموذج',
        'invitation_added'       => 'دعوة مستخدم إلى النموذج',
        'invitation_revoked'     => 'إلغاء دعوة مستخدم',
        'response_corrected'     => 'تصحيح إجابات في أحد الردود',
        'responses_exported'     => 'تصدير ردود النموذج',
    ];

    /**
     * Saving the draft repeatedly (autosave) must not bury the history in near-identical lines. Consecutive entries of one of
     * these actions by the same person on the same form, within the window and with nothing else in between, are folded into
     * one entry that lists everything that changed and how many saves it covers.
     */
    private const COALESCED = ['updated', 'questions_changed', 'questions_reordered'];

    private const COALESCE_WINDOW_MINUTES = 10;

    private const LIST_KEYS = ['fields', 'added', 'removed', 'edited'];

    /** Keys a details block may carry; anything else is dropped before it is stored or shown. */
    private const ALLOWED_DETAIL_KEYS = [
        'fields', 'added', 'removed', 'edited', 'reordered', 'version', 'from_version', 'question_count', 'note',
        'from', 'to', 'user', 'access_level', 'can_view_responses', 'previous_owner_access', 'status_from', 'status_to', 'changes',
        'saves', 'forced', 'count', 'submission', 'format', 'rows',
    ];

    public static function record(DynamicForm $form, User $actor, string $action, array $details = [], ?Request $request = null): void
    {
        $details = array_intersect_key($details, array_flip(self::ALLOWED_DETAIL_KEYS));

        if (in_array($action, self::COALESCED, true) && self::foldIntoPrevious($form, $actor, $action, $details)) {
            return;
        }

        ActivityLog::create([
            'user_id'     => $actor->id,
            'action'      => 'form.'.$action,
            'model_type'  => DynamicForm::class,
            'model_id'    => $form->id,
            'entity_type' => DynamicForm::class,
            'entity_id'   => $form->id,
            'changes'     => $details ?: null,
            'new_values'  => $details ?: null,
            'ip_address'  => $request?->ip(),
            'user_agent'  => $request ? mb_substr((string) $request->userAgent(), 0, 250) : null,
        ]);
    }

    /** True when the entry was merged into the form's latest log line (same person, same action, recent, nothing between). */
    private static function foldIntoPrevious(DynamicForm $form, User $actor, string $action, array $details): bool
    {
        $last = ActivityLog::query()
            ->where('entity_type', DynamicForm::class)->where('entity_id', $form->id)
            ->latest('id')->first();

        if ($last === null
            || (int) $last->user_id !== (int) $actor->id
            || $last->action !== 'form.'.$action
            || $last->created_at === null
            || $last->created_at->lt(now()->subMinutes(self::COALESCE_WINDOW_MINUTES))) {
            return false;
        }

        $merged = (array) ($last->new_values ?? []);
        foreach (self::LIST_KEYS as $key) {
            if (isset($details[$key]) || isset($merged[$key])) {
                $merged[$key] = array_slice(array_values(array_unique(array_merge((array) ($merged[$key] ?? []), (array) ($details[$key] ?? [])))), 0, 10);
            }
        }
        if (! empty($details['forced'])) {
            $merged['forced'] = true;
        }
        $merged['saves'] = (int) ($merged['saves'] ?? 1) + 1;

        $last->forceFill(['changes' => $merged, 'new_values' => $merged])->save();

        return true;
    }
}
