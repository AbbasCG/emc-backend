<?php

namespace App\Http\Controllers\Executive;

use App\Http\Controllers\Controller;
use App\Services\ExecutiveCommandCenterService;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GET /api/executive/command-center — the private executive follow-up feed.
 *
 * Authorized solely by the explicit `executive.command_center.view` permission, checked
 * with hasPermission() (not the role bypass): super_admin holds it only because the
 * opt-in seeder granted it, and tech_admin does not hold it at all until an owner grants it.
 * Page access (the sidebar entry and the SPA route guard) is a separate layer and returns
 * no data on its own.
 */
class ExecutiveCommandCenterController extends Controller
{
    public function __construct(private readonly ExecutiveCommandCenterService $service) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()?->hasPermission(Permissions::EXECUTIVE_COMMAND_CENTER_VIEW),
            403,
            'Forbidden. Missing permission: '.Permissions::EXECUTIVE_COMMAND_CENTER_VIEW.'.',
        );

        $v = $request->validate([
            'range' => ['sometimes', Rule::in(ExecutiveCommandCenterService::RANGES)],
            'source' => ['sometimes', Rule::in(['all', ...array_keys(ExecutiveCommandCenterService::SOURCES)])],
            'priority' => ['sometimes', Rule::in(['all', ...array_keys(ExecutiveCommandCenterService::PRIORITIES)])],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->service->build(
                $request->user(),
                $v['range'] ?? 'today',
                $v['source'] ?? 'all',
                $v['priority'] ?? 'all',
                (int) ($v['limit'] ?? 25),
            ),
        ]);
    }
}
