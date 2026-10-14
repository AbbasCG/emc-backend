<?php

namespace App\Services;

use App\Models\User;
use App\Models\VolunteerOpportunity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Publish, unpublish and close volunteer opportunities.
 *
 * The only code that sets `status`, `published_at`, `closed_at` and `published_by`.
 *
 *   draft      --publish-->   published   (mandatory fields validated first)
 *   published  --close-->     closed      (new applications stop; existing ones stay)
 *   published  --unpublish--> draft       (back off the public site, editable again)
 *   closed     --unpublish--> draft
 *   closed     --publish-->   published   (re-open; the deadline must still be valid)
 *
 * Publishing sends nothing: no email, no notification. Authorization is the caller's
 * (HrRequestAccessService::canManage); this class enforces the content and the transitions.
 */
class VolunteerOpportunityPublishingService
{
    /**
     * Mandatory content before anything may be public.
     *
     * @return array<string, string> field => Arabic message (empty when ready)
     */
    public function missing(VolunteerOpportunity $o): array
    {
        $m = [];
        if (mb_strlen(trim((string) $o->title)) < 3) {
            $m['title'] = 'عنوان الفرصة مطلوب.';
        }
        if (mb_strlen(trim((string) $o->summary)) < 20) {
            $m['summary'] = 'الوصف المختصر مطلوب (20 حرفًا على الأقل).';
        }
        if (count(array_filter((array) $o->responsibilities)) < 1) {
            $m['responsibilities'] = 'أضف مهمة واحدة على الأقل.';
        }
        if (count(array_filter((array) $o->required_skills)) < 1) {
            $m['required_skills'] = 'أضف مهارة مطلوبة واحدة على الأقل.';
        }
        if (! in_array($o->location_mode, VolunteerOpportunity::LOCATION_MODES, true)) {
            $m['location_mode'] = 'حدّد نمط العمل (عن بُعد أو حضوري أو مختلط).';
        }
        if ($o->closes_at !== null && $o->closes_at->endOfDay()->isPast()) {
            $m['closes_at'] = 'آخر موعد للتقديم يجب أن يكون اليوم أو بعده.';
        }

        return $m;
    }

    public function publish(VolunteerOpportunity $o, User $by): VolunteerOpportunity
    {
        if (! in_array($o->status, [VolunteerOpportunity::STATUS_DRAFT, VolunteerOpportunity::STATUS_CLOSED], true)) {
            throw ValidationException::withMessages(['status' => ['لا يمكن نشر فرصة بهذه الحالة.']]);
        }
        $this->assertReady($o);

        return $this->transition($o, $by, 'opportunity_published', function (VolunteerOpportunity $o) use ($by) {
            $o->status = VolunteerOpportunity::STATUS_PUBLISHED;
            $o->published_at = now();
            $o->published_by = $by->id;
            $o->closed_at = null;
        });
    }

    public function unpublish(VolunteerOpportunity $o, User $by): VolunteerOpportunity
    {
        if (! in_array($o->status, [VolunteerOpportunity::STATUS_PUBLISHED, VolunteerOpportunity::STATUS_CLOSED], true)) {
            throw ValidationException::withMessages(['status' => ['هذه الفرصة غير منشورة أصلًا.']]);
        }

        return $this->transition($o, $by, 'opportunity_unpublished', function (VolunteerOpportunity $o) {
            $o->status = VolunteerOpportunity::STATUS_DRAFT;
            $o->published_at = null;
            $o->closed_at = null;
        });
    }

    public function close(VolunteerOpportunity $o, User $by): VolunteerOpportunity
    {
        if ($o->status !== VolunteerOpportunity::STATUS_PUBLISHED) {
            throw ValidationException::withMessages(['status' => ['يمكن إغلاق الفرص المنشورة فقط.']]);
        }

        return $this->transition($o, $by, 'opportunity_closed', function (VolunteerOpportunity $o) {
            $o->status = VolunteerOpportunity::STATUS_CLOSED;
            $o->closed_at = now();
        });
    }

    /** A published opportunity must always keep its mandatory content (used after an edit). */
    public function assertReady(VolunteerOpportunity $o): void
    {
        $missing = $this->missing($o);
        if ($missing !== []) {
            throw ValidationException::withMessages(array_map(fn ($msg) => [$msg], $missing));
        }
    }

    private function transition(VolunteerOpportunity $o, User $by, string $action, callable $apply): VolunteerOpportunity
    {
        return DB::transaction(function () use ($o, $by, $action, $apply) {
            // Re-read under lock so two admins cannot race a transition.
            $locked = VolunteerOpportunity::query()->lockForUpdate()->findOrFail($o->id);
            $apply($locked);
            $locked->updated_by = $by->id;
            $locked->save();

            AuditLogService::log(
                action: $action,
                entityType: 'VolunteerOpportunity',
                entityId: $locked->id,
                entityName: $locked->title,
                description: $action.' #'.$locked->id,
            );

            return $locked->fresh();
        });
    }
}
