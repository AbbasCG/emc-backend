<?php

namespace App\Http\Controllers\TechAdmin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Services\TechDepartmentDashboardService;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only department monitoring for Technology Management.
 *
 * Two GET endpoints, no writes. The route group already requires the
 * `tech.department_dashboards.view` permission; the Gate check below repeats it so a
 * future route change cannot silently expose the data. Page access (the sidebar entry
 * and the SPA route guard) is a separate layer and grants nothing here.
 */
class TechDepartmentDashboardController extends Controller
{
    public function __construct(private readonly TechDepartmentDashboardService $service) {}

    // GET /api/tech/department-dashboards
    public function index(Request $request): JsonResponse
    {
        $this->authorizeView($request);

        return response()->json([
            'success' => true,
            'data' => [
                'departments' => $this->service->departments(),
                'selected_department' => null,
                'capabilities' => TechDepartmentDashboardService::capabilities(),
            ],
        ]);
    }

    // GET /api/tech/department-dashboards/{department}
    public function show(Request $request, int $department): JsonResponse
    {
        $this->authorizeView($request);

        // Active departments only. An archived or unknown id is a plain 404, the same
        // answer for both, so the endpoint does not reveal which ids exist.
        $model = Department::query()->active()->find($department);
        if (! $model) {
            return response()->json(['success' => false, 'message' => 'الإدارة غير موجودة أو غير نشطة.'], 404);
        }

        $payload = $this->service->dashboard($model);

        return response()->json([
            'success' => true,
            'data' => array_merge(
                ['departments' => $this->service->departments(), 'selected_department' => $payload['department']],
                array_diff_key($payload, ['department' => true]),
            ),
        ]);
    }

    private function authorizeView(Request $request): void
    {
        abort_unless($request->user()?->can(Permissions::TECH_DEPARTMENT_DASHBOARDS_VIEW), 403, 'Forbidden. Missing permission: '.Permissions::TECH_DEPARTMENT_DASHBOARDS_VIEW.'.');
    }
}
