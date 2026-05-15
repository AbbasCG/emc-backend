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

        app(ActivityLogger::class)->log('role_created', "Role [{$role->name}] created", $request->user()->id);

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

        app(ActivityLogger::class)->log('role_updated', "Role [{$role->name}] permissions updated", $request->user()->id);

        return response()->json(['success' => true, 'data' => $role->load('permissions')]);
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        $this->requireSuperAdmin($request);

        if ($role->is_system) {
            return response()->json(['success' => false, 'message' => 'System roles cannot be deleted.'], 422);
        }

        $role->delete();

        app(ActivityLogger::class)->log('role_deleted', "Role [{$role->name}] deleted", $request->user()->id);

        return response()->json(['success' => true, 'message' => 'Role deleted.']);
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

    private function requireSuperAdmin(Request $request): void
    {
        abort_unless($request->user()?->isSuperAdmin(), 403, 'Only super_admin can manage roles and permissions.');
    }
}
