<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Services\ActivityLogger;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin: Role management.
 * Only super_admin can create/update/delete roles.
 * admin + super_admin can view roles.
 */
class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $roles = Role::with('permissions:id,name,display_name,group')
            ->orderBy('name')
            ->get();

        return response()->json(['success' => true, 'data' => $roles]);
    }

    public function show(Role $role): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $role->load('permissions:id,name,display_name,group'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $data = $request->validate([
            'name'         => 'required|string|max:50|unique:roles,name|alpha_dash',
            'display_name' => 'required|string|max:100',
            'description'  => 'nullable|string|max:500',
            'permissions'  => 'sometimes|array',
            'permissions.*'=> 'string|exists:permissions,name',
        ]);

        $role = Role::create([
            'name'         => $data['name'],
            'display_name' => $data['display_name'],
            'description'  => $data['description'] ?? null,
            'is_system'    => false,
        ]);

        if (! empty($data['permissions'])) {
            $role->syncPermissions($data['permissions']);
        }

        app(ActivityLogger::class)->log('role_created', $role);

        return response()->json(['success' => true, 'data' => $role->load('permissions')], 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $data = $request->validate([
            'display_name' => 'sometimes|string|max:100',
            'description'  => 'nullable|string|max:500',
            'permissions'  => 'sometimes|array',
            'permissions.*'=> 'string|exists:permissions,name',
        ]);

        // Protect name of system roles
        if ($role->is_system && isset($data['name'])) {
            return response()->json(['success' => false, 'message' => 'Cannot rename a system role.'], 422);
        }

        $role->update(array_filter([
            'display_name' => $data['display_name'] ?? null,
            'description'  => $data['description'] ?? null,
        ]));

        if (array_key_exists('permissions', $data)) {
            $role->syncPermissions($data['permissions']);
        }

        app(ActivityLogger::class)->log('role_updated', $role);

        return response()->json(['success' => true, 'data' => $role->load('permissions')]);
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        $this->requireSuperAdmin($request);

        if ($role->is_system) {
            return response()->json(['success' => false, 'message' => 'System roles cannot be deleted.'], 422);
        }

        app(ActivityLogger::class)->log('role_deleted', $role);
        $role->delete();

        return response()->json(['success' => true, 'message' => 'Role deleted.']);
    }

    // ── Dedicated permission endpoints ────────────────────────────────────────

    /**
     * GET /api/admin/roles/{role}/permissions
     *
     * Returns:
     *  - role info
     *  - current_permissions: flat list of keys assigned to this role
     *  - modules: all permissions grouped by module, each permission has is_assigned flag
     */
    public function permissions(Role $role): JsonResponse
    {
        $role->load('permissions:id,name,display_name,label_ar,group');

        $assignedKeys = $role->permissions->pluck('name')->toArray();

        // All permissions with is_assigned flag (for checkbox UIs)
        $allPermissions = Permission::orderBy('group')->orderBy('name')
            ->get(['id', 'name', 'display_name', 'label_ar', 'group', 'description_ar']);

        $modules = $allPermissions
            ->groupBy('group')
            ->map(function ($perms, $groupKey) use ($assignedKeys) {
                return [
                    'group'       => $groupKey,
                    'group_label' => $this->groupLabel($groupKey),
                    'permissions' => $perms->map(fn ($p) => [
                        'id'             => $p->id,
                        'key'            => $p->name,
                        'display_name'   => $p->display_name,
                        'label_ar'       => $p->label_ar,
                        'description_ar' => $p->description_ar,
                        'is_assigned'    => in_array($p->name, $assignedKeys, true),
                    ])->values(),
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'data'    => [
                'role'                => [
                    'id'           => $role->id,
                    'name'         => $role->name,
                    'display_name' => $role->display_name,
                    'description'  => $role->description,
                    'is_system'    => $role->is_system,
                ],
                'current_permissions' => $assignedKeys,
                'modules'             => $modules,
                'count'               => count($assignedKeys),
            ],
        ]);
    }

    /**
     * PUT /api/admin/roles/{role}/permissions
     * Replace the role's entire permission set with the provided list.
     * Only super_admin can call this.
     */
    public function setPermissions(Request $request, Role $role): JsonResponse
    {
        $user = $request->user();

        // super_admin can always manage permissions.
        // admin can manage permissions only if they have the manage_roles permission.
        if (! $user->isSuperAdmin()) {
            if (! $user->isAdmin() || ! $user->hasPermission('manage_roles')) {
                abort(403, 'Only super_admin or admin with role management permission can modify permissions.');
            }
        }

        $data = $request->validate([
            'permissions'   => 'required|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        $role->syncPermissions($data['permissions']);

        app(ActivityLogger::class)->log(
            'role_permissions_replaced',
            $role,
            ['permissions_count' => count($data['permissions'])]
        );

        return response()->json([
            'success' => true,
            'message' => 'Permissions updated successfully',
            'data'    => $role->load('permissions:id,name,display_name,label_ar,group'),
        ]);
    }

    // ── Permission assignment shortcuts ───────────────────────────────────────

    public function grantPermission(Request $request, Role $role): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $data = $request->validate(['permission' => 'required|string|exists:permissions,name']);
        $role->grantPermission($data['permission']);

        return response()->json(['success' => true, 'message' => "Permission [{$data['permission']}] granted to [{$role->name}]."]);
    }

    public function revokePermission(Request $request, Role $role): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $data = $request->validate(['permission' => 'required|string|exists:permissions,name']);
        $role->revokePermission($data['permission']);

        return response()->json(['success' => true, 'message' => "Permission [{$data['permission']}] revoked from [{$role->name}]."]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function groupLabel(string $group): string
    {
        return match ($group) {
            'users'             => 'المستخدمون',
            'education'         => 'التعليم والبرامج',
            'lms'               => 'نظام إدارة التعلم',
            'finance'           => 'المالية',
            'certificates'      => 'الشهادات',
            'operations'        => 'العمليات',
            'hr'                => 'الموارد البشرية',
            'partnerships'      => 'الشراكات',
            'marketing'         => 'التسويق والإعلام',
            'quality'           => 'الجودة والحوكمة',
            'support'           => 'الدعم الفني',
            'reports'           => 'التقارير والمؤشرات',
            'knowledge'         => 'قاعدة المعرفة',
            'ai'                => 'الذكاء الاصطناعي',
            'system'            => 'النظام والإعدادات',
            'workshop_requests' => 'طلبات البرامج التدريبية',
            'members'           => 'الأعضاء',
            'departments_g'     => 'الإدارات',
            'courses_g'         => 'الدورات',
            'attendance_g'      => 'الحضور',
            'assignments_g'     => 'الواجبات',
            default             => $group,
        };
    }

    private function requireSuperAdmin(Request $request): void
    {
        abort_unless($request->user()?->isSuperAdmin(), 403, 'Only super_admin can manage roles and permissions.');
    }
}
