<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Services\ActivityLogger;
use App\Services\RoleMembershipService;
use App\Support\PermissionGovernance;
use App\Support\Permissions;
use App\Support\ProtectedAuthority;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin: Role management.
 * Only super_admin can create/update/delete roles.
 * admin + super_admin can view roles.
 */
class RoleController extends Controller
{
    public function __construct(private readonly RoleMembershipService $membership)
    {
    }

    public function index(): JsonResponse
    {
        $roles = Role::with('permissions:id,name,display_name,group')
            ->orderBy('name')
            ->get();

        // users_count = the SAME membership query the members list uses (RoleMembershipService), never a client-side tally.
        $roles->each(fn (Role $r) => $r->setAttribute('users_count', $this->membership->count($r)));

        return response()->json(['success' => true, 'data' => $roles]);
    }

    public function show(Role $role): JsonResponse
    {
        $role->load('permissions:id,name,display_name,group');
        $role->setAttribute('users_count', $this->membership->count($role));

        return response()->json([
            'success' => true,
            'data'    => $role,
        ]);
    }

    /**
     * GET /api/admin/roles/{role}/users?search=&page=&per_page=
     * Members of the role (primary users.role, plus department leaders acting with a specialised role), de-duplicated,
     * soft-deleted excluded; `total` is exactly the `users_count` of the role. Admin tier only (the route group is wider).
     */
    public function users(Request $request, Role $role): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Only admin-tier accounts can list role members.');

        $data = $request->validate([
            'search' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1|max:10000',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = $this->membership->query($role);
        if (($search = trim((string) ($data['search'] ?? ''))) !== '') {
            $like = '%'.addcslashes($search, '\%_').'%';
            $query->where(fn ($w) => $w->where('users.name', 'like', $like)->orWhere('users.email', 'like', $like));
        }

        $page = $query->paginate((int) ($data['per_page'] ?? 20), ['*'], 'page', (int) ($data['page'] ?? 1));

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $page->getCollection()->map(fn ($u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'is_active' => (bool) $u->is_active,
                    'role_source' => $u->role_source,
                ])->values(),
                'total' => $page->total(),
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'last_page' => $page->lastPage(),
            ],
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
            PermissionGovernance::requireSuperAdmin($request->user(), $data['permissions']);
            $role->syncPermissions($data['permissions']);
            PermissionGovernance::logChange('role_permissions_changed', $role, $data['permissions'], [], ['role' => $role->name], $request);
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
            $before = $role->permissions()->pluck('name')->all();
            PermissionGovernance::requireSuperAdmin($request->user(), array_merge(array_diff($data['permissions'], $before), array_diff($before, $data['permissions'])));
            $role->syncPermissions($data['permissions']);
            $this->logRoleDiff($request, $role, $before, $data['permissions']);
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
     *
     * The super_admin role itself is protected: only an actual super_admin
     * may modify its permissions, regardless of what manage_roles-equivalent
     * permission the actor otherwise holds (tech_admin included — this is
     * the fix for the confirmed gap where tech_admin could previously
     * overwrite super_admin's entire permission set through this exact
     * endpoint). Every other role keeps the existing, unchanged behavior:
     * super_admin always, or an admin-tier actor who holds manage_roles.
     */
    public function setPermissions(Request $request, Role $role): JsonResponse
    {
        $user = $request->user();

        if (ProtectedAuthority::isProtectedRole($role)) {
            ProtectedAuthority::requireSuperAdmin($user, 'Only super_admin may modify the super_admin role\'s permissions.');
        } elseif (! $user->isSuperAdmin()) {
            if (! $user->isAdmin() || ! $user->hasPermission('manage_roles')) {
                abort(403, 'Only super_admin or admin with role management permission can modify permissions.');
            }
        }

        $data = $request->validate([
            'permissions'   => 'required|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        // Diff the role's permission names: adding or removing the participation key is for super_admin only (admin + manage_roles,
        // tech_admin and leaders are refused, the rest of the edit is not applied either).
        $before = $role->permissions()->pluck('name')->all();
        PermissionGovernance::requireSuperAdmin($user, array_merge(array_diff($data['permissions'], $before), array_diff($before, $data['permissions'])));

        $role->syncPermissions($data['permissions']);
        $this->logRoleDiff($request, $role, $before, $data['permissions']);

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
        $data = $request->validate(['permission' => 'required|string|exists:permissions,name']);
        PermissionGovernance::requireSuperAdmin($request->user(), [$data['permission']]);
        $this->requireSuperAdmin($request);

        $had = $role->permissions()->where('name', $data['permission'])->exists();
        $role->grantPermission($data['permission']);
        if (! $had) {
            PermissionGovernance::logChange('role_permissions_changed', $role, [$data['permission']], [], ['role' => $role->name], $request);
        }

        return response()->json(['success' => true, 'message' => "Permission [{$data['permission']}] granted to [{$role->name}]."]);
    }

    public function revokePermission(Request $request, Role $role): JsonResponse
    {
        $data = $request->validate(['permission' => 'required|string|exists:permissions,name']);
        PermissionGovernance::requireSuperAdmin($request->user(), [$data['permission']]);
        $this->requireSuperAdmin($request);

        $had = $role->permissions()->where('name', $data['permission'])->exists();
        $role->revokePermission($data['permission']);
        if ($had) {
            PermissionGovernance::logChange('role_permissions_changed', $role, [], [$data['permission']], ['role' => $role->name], $request);
        }

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
            'tasks'             => 'المهام والعمليات',
            'hr'                => 'الموارد البشرية',
            'partnerships'      => 'الشراكات',
            'marketing'         => 'التسويق والإعلام',
            'quality'           => 'الجودة والحوكمة',
            'support'           => 'الدعم الفني',
            'reports'           => 'التقارير والمؤشرات',
            'knowledge'         => 'قاعدة المعرفة',
            'ai'                => 'الذكاء الاصطناعي',
            'system'            => 'النظام والإعدادات',
            'tickets'           => 'التذاكر',
            'workshop_requests' => 'طلبات البرامج التدريبية',
            'members'           => 'الأعضاء',
            'departments_g'     => 'الإدارات',
            'courses_g'         => 'الدورات',
            'attendance_g'      => 'الحضور',
            'assignments_g'     => 'الواجبات',
            default             => $group,
        };
    }

    /** @param array<int, string> $before @param array<int, string> $after */
    private function logRoleDiff(Request $request, Role $role, array $before, array $after): void
    {
        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));
        if ($added !== [] || $removed !== []) {
            PermissionGovernance::logChange('role_permissions_changed', $role, $added, $removed, ['role' => $role->name], $request);
        }
    }

    private function requireSuperAdmin(Request $request): void
    {
        abort_unless($request->user()?->isSuperAdmin(), 403, 'Only super_admin can manage roles and permissions.');
    }
}
