<?php

namespace App\Http\Resources;

use App\Models\HrRequest;
use App\Services\VolunteerOpportunityDraftService;
use App\Support\SkillText;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe, consistent shape of an HR incoming (staffing) request for the HR workspace.
 *
 * Never returns the model: only the fields the page shows, the requester's NAME (no email or
 * phone), a derived display title, the parsed skill list, and a pointer to the draft
 * opportunity when one exists. Legacy rows with missing fields yield null/empty values, not errors.
 *
 * @mixin HrRequest
 */
class HrIncomingRequestResource extends JsonResource
{
    public const STATUS_LABELS = [
        'pending' => 'جديد',
        'in_progress' => 'قيد المراجعة',
        'approved' => 'مقبول',
        'rejected' => 'مرفوض',
    ];

    public const AUDIENCE_LABELS = ['hr' => 'الموارد البشرية', 'top_management' => 'الإدارة العليا'];

    public function toArray(Request $request): array
    {
        /** @var HrRequest $r */
        $r = $this->resource;
        $dept = $r->department;
        $deptName = $dept ? ($dept->name_ar ?: $dept->name) : null;
        $type = trim((string) $r->request_type);
        $generic = $type === '' || str_contains($type, 'احتياج وظيفي');
        $skills = SkillText::phrases($r->skills_and_specs, 12);
        $draft = $r->relationLoaded('opportunityDraft') ? $r->opportunityDraft : null;

        return [
            'id' => $r->id,
            'title' => ($generic ? 'طلب احتياج متطوعين' : $type).($deptName ? " — {$deptName}" : ''),
            'request_type' => $type !== '' ? $type : VolunteerOpportunityDraftService::DEFAULT_REQUEST_TYPE,
            'department' => $dept ? ['id' => $dept->id, 'name' => $deptName] : null,
            'requester' => $r->creator ? ['id' => $r->creator->id, 'name' => $r->creator->name] : null,
            'required_count' => (int) ($r->required_count ?? 1),
            'skills_and_specs' => (string) $r->skills_and_specs,
            'skills' => $skills,
            'notes' => $r->notes,
            'status' => $r->status,
            'status_label' => self::STATUS_LABELS[$r->status] ?? 'غير محدد',
            'target_audience' => $r->target_audience,
            'target_audience_label' => self::AUDIENCE_LABELS[$r->target_audience] ?? null,
            'hr_feedback' => $r->hr_feedback,
            'created_at' => $r->created_at?->toIso8601String(),
            'updated_at' => $r->updated_at?->toIso8601String(),
            'draft' => $draft ? [
                'id' => $draft->id,
                'title' => $draft->title,
                'status' => $draft->status,
                'status_label' => \App\Models\VolunteerOpportunity::STATUS_LABELS[$draft->status] ?? 'غير محدد',
                'applicants_count' => (int) ($draft->applications_count ?? 0),
            ] : null,
        ];
    }
}
