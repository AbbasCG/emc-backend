<?php

namespace App\Services;

use App\Models\VolunteerRequest;
use Illuminate\Support\Facades\DB;

/**
 * Applicants who applied directly to a published opportunity (volunteer_requests.opportunity_id).
 *
 * The trace is incoming request -> opportunity -> application: callers pass opportunity ids that
 * were resolved from the request, never from a title. Output is limited to what an HR reviewer
 * needs to triage; contact details, CV, notes and the motivation text stay on the application
 * page, which has its own authorization.
 */
class VolunteerApplicantService
{
    private const LIMIT = 200;

    /**
     * @param  array<int, int>  $opportunityIds
     * @return array{items: array<int, array<string, mixed>>, counts: array<string, int>, total: int}
     */
    public function forOpportunities(array $opportunityIds): array
    {
        if ($opportunityIds === []) {
            return ['items' => [], 'counts' => $this->emptyCounts(), 'total' => 0];
        }

        $counts = VolunteerRequest::query()->whereIn('opportunity_id', $opportunityIds)
            ->select('status', DB::raw('COUNT(*) as c'))->groupBy('status')->pluck('c', 'status');

        $rows = VolunteerRequest::query()->whereIn('opportunity_id', $opportunityIds)
            ->with('opportunity:id,title')
            ->orderByDesc('created_at')->orderByDesc('id')->limit(self::LIMIT)
            ->get(['id', 'opportunity_id', 'full_name', 'status', 'experience_level', 'skills', 'availability', 'created_at', 'reviewed_at']);

        $merged = $this->emptyCounts();
        foreach ($counts as $status => $c) {
            $merged[$status] = (int) $c;
        }

        return [
            'items' => $rows->map(fn (VolunteerRequest $v) => $this->present($v))->all(),
            'counts' => $merged,
            'total' => (int) $counts->sum(),
        ];
    }

    /** @return array<string, mixed> */
    public function present(VolunteerRequest $v): array
    {
        return [
            'id' => $v->id,
            'name' => $v->full_name,
            'status' => $v->status,
            'status_label' => VolunteerMatchingService::STATUS_LABELS[$v->status] ?? 'غير محدد',
            'source' => 'direct_application',
            'source_label' => 'تقدّم مباشرة',
            'applied_at' => $v->created_at?->toIso8601String(),
            'reviewed_at' => $v->reviewed_at?->toIso8601String(),
            'experience_level' => $v->experience_level,
            'availability' => $v->availability,
            'skills' => $v->skills ? mb_substr(trim((string) $v->skills), 0, 200) : null,
            'opportunity' => $v->opportunity ? ['id' => $v->opportunity->id, 'title' => $v->opportunity->title] : null,
            'url' => '/dashboard/hr/volunteer-requests/'.$v->id,
        ];
    }

    /** @return array<string, int> */
    private function emptyCounts(): array
    {
        return array_fill_keys(array_keys(VolunteerMatchingService::STATUS_LABELS), 0);
    }
}
