<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Services\RolePageDefaultService;
use App\Support\PageAccess\PageAccessEntry;
use App\Support\ProtectedAuthority;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Phase 2B — Role default PAGE ACCESS configuration API.
 *
 * PAGE ACCESS != BUSINESS AUTHORIZATION: nothing here grants a business
 * permission.
 *
 * AUTHORIZATION deliberately MIRRORS RoleController::setPermissions() — the
 * closest existing analogue — rather than inventing a second, divergent policy
 * for role configuration:
 *
 *   - the super_admin role itself may only be modified by an actual
 *     super_admin (ProtectedAuthority), so tech_admin can never reach into
 *     protected root authority through this endpoint;
 *   - any other role may be modified by super_admin, or by an admin-tier actor
 *     (admin/super_admin/tech_admin per User::isAdmin()) who also holds
 *     manage_roles.
 *
 * tech_admin therefore may manage ordinary role defaults, exactly as it may
 * already manage ordinary role permissions. It cannot escalate through this
 * API because SYSTEM_PROTECTED pages are ineligible for ANY role default
 * (enforced in RolePageDefaultService) — so there is no page here capable of
 * conferring root-equivalent access to assign in the first place.
 */
class RolePageDefaultController extends Controller
{
    public function __construct(private readonly RolePageDefaultService $service)
    {
    }

    /**
     * GET /api/admin/roles/{role}/page-access
     *
     * Returns the role's stored defaults plus the eligible catalog entries.
     * The eligible set is included deliberately: without it a future Role
     * Management UI would have to re-implement the eligibility rule
     * client-side, duplicating the very rule this phase centralizes. Entries
     * carry light presentational metadata (key/label/category/risk) so the UI
     * can render grouped, searchable checkbox lists — and support "enable all
     * / disable all / reset" per category — without a second catalog call.
     */
    public function show(Request $request, Role $role): JsonResponse
    {
        $this->authorizeRoleConfiguration($request, $role);

        return response()->json([
            'success' => true,
            'data' => [
                'role' => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'display_name' => $role->display_name,
                    'is_system' => (bool) $role->is_system,
                ],
                'page_defaults' => $this->service->getDefaultsForRole($role),
                'eligible' => array_map(static fn (PageAccessEntry $e) => [
                    'key' => $e->key,
                    'label_ar' => $e->labelAr,
                    'category' => $e->category->value,
                    'category_label_ar' => $e->category->labelAr(),
                    'risk_level' => $e->riskLevel->value,
                    'department_scoped' => $e->departmentScoped,
                ], $this->service->eligibleEntries()),
            ],
        ]);
    }

    /**
     * PUT /api/admin/roles/{role}/page-access
     *
     * Atomic full replace, matching the shape of
     * PUT /api/admin/roles/{role}/permissions. Replace semantics are what make
     * the planned bulk checkbox UI safe: the whole submitted set is validated
     * before anything is written, and the write runs in one transaction.
     */
    public function update(Request $request, Role $role): JsonResponse
    {
        $this->authorizeRoleConfiguration($request, $role);

        $data = $request->validate([
            'page_defaults' => 'present|array',
            'page_defaults.*' => 'string',
        ]);

        try {
            $stored = $this->service->replaceDefaults($role, $data['page_defaults'], $request->user());
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث الصفحات الافتراضية للدور بنجاح',
            'data' => ['page_defaults' => $stored],
        ]);
    }

    /**
     * Identical policy to RoleController::setPermissions(). Kept in one private
     * helper so both endpoints here can never drift apart from each other.
     */
    private function authorizeRoleConfiguration(Request $request, Role $role): void
    {
        $user = $request->user();

        if (ProtectedAuthority::isProtectedRole($role)) {
            ProtectedAuthority::requireSuperAdmin($user, 'Only super_admin may modify the super_admin role\'s page access.');

            return;
        }

        if (! $user->isSuperAdmin()) {
            if (! $user->isAdmin() || ! $user->hasPermission('manage_roles')) {
                abort(403, 'Only super_admin or admin with role management permission can modify role page access.');
            }
        }
    }
}
