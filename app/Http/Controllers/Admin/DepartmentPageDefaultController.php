<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Services\DepartmentPageDefaultService;
use App\Support\PageAccess\DepartmentPageAudience;
use App\Support\PageAccess\PageAccessEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Phase 2A — Department default PAGE ACCESS configuration API.
 *
 * PAGE ACCESS != BUSINESS AUTHORIZATION: nothing here grants a business
 * permission. Configuring these defaults is restricted to super_admin and
 * tech_admin by the route middleware (see routes/api.php). Department leaders
 * deliberately do NOT get to define their own department's default access —
 * that would let a leader widen their own department's exposure, and is a
 * product/security decision left open for a later phase.
 *
 * Eligibility ("which pages may be a department default") is NOT re-derived
 * here — it lives solely in DepartmentPageDefaultService.
 */
class DepartmentPageDefaultController extends Controller
{
    public function __construct(private readonly DepartmentPageDefaultService $service)
    {
    }

    /**
     * GET /api/admin/departments/{department}/page-access
     *
     * Returns the stored defaults plus the eligible catalog entries per
     * audience. The eligible sets are included deliberately: without them the
     * future UI would have to re-implement the eligibility rule client-side,
     * which is exactly the duplication this phase avoids. Entries are sent as
     * light catalog metadata (key/label/category/risk) so the UI can render
     * grouped, searchable lists without a second catalog round-trip.
     */
    public function show(Department $department): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'department' => [
                    'id' => $department->id,
                    'name' => $department->name,
                    'status' => $department->status,
                ],
                'member_defaults' => $this->service->getMemberDefaults($department),
                'leader_defaults' => $this->service->getLeaderDefaults($department),
                'eligible' => [
                    'member' => $this->presentEntries(DepartmentPageAudience::Member),
                    'leader' => $this->presentEntries(DepartmentPageAudience::Leader),
                ],
            ],
        ]);
    }

    /**
     * PUT /api/admin/departments/{department}/page-access
     *
     * Full atomic replace of BOTH audiences. A replace model (rather than
     * per-page POST/DELETE) is what makes the planned "enable all / disable
     * all / reset" bulk actions safe: the whole submitted set is validated
     * before anything is written, and the write runs in one transaction.
     */
    public function update(Request $request, Department $department): JsonResponse
    {
        $data = $request->validate([
            'member_defaults' => 'present|array',
            'member_defaults.*' => 'string',
            'leader_defaults' => 'present|array',
            'leader_defaults.*' => 'string',
        ]);

        $actor = $request->user();

        try {
            // Validate BOTH audiences up-front so a bad leader key cannot leave
            // a half-applied member set behind.
            $this->service->validateKeys($data['member_defaults'], DepartmentPageAudience::Member);
            $this->service->validateKeys($data['leader_defaults'], DepartmentPageAudience::Leader);

            $member = $this->service->replaceDefaults($department, DepartmentPageAudience::Member, $data['member_defaults'], $actor);
            $leader = $this->service->replaceDefaults($department, DepartmentPageAudience::Leader, $data['leader_defaults'], $actor);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث الصفحات الافتراضية للإدارة بنجاح',
            'data' => [
                'member_defaults' => $member,
                'leader_defaults' => $leader,
            ],
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function presentEntries(DepartmentPageAudience $audience): array
    {
        return array_map(static fn (PageAccessEntry $e) => [
            'key' => $e->key,
            'label_ar' => $e->labelAr,
            'category' => $e->category->value,
            'category_label_ar' => $e->category->labelAr(),
            'risk_level' => $e->riskLevel->value,
            'department_scoped' => $e->departmentScoped,
        ], $this->service->eligibleEntries($audience));
    }
}
