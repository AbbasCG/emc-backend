<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * User role and permission management.
 *
 * Security rules enforced here:
 * - Users cannot promote themselves
 * - admin cannot edit super_admin users
 * - Only super_admin can assign super_admin or executive_admin roles
 */
class UserRoleController extends Controller
{
    private const SUPER_ADMIN_ONLY_ROLES = ['super_admin', 'executive_admin'];

    /** PUT /api/admin/users/{user}/role */
    public function assignRole(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();

        // Cannot change own role
        if ($actor->id === $user->id) {
            return response()->json(['success' => false, 'message' => 'You cannot change your own role.'], 403);
        }

        // admin cannot touch super_admin users
        if (! $actor->isSuperAdmin() && $user->isSuperAdmin()) {
            return response()->json(['success' => false, 'message' => 'You cannot modify a super admin.'], 403);
        }

        $data = $request->validate([
            'role' => ['required', 'string', Role::assignableRule()],
        ]);

        $newRole = $data['role'];

        // Only super_admin can assign privileged roles
        if (in_array($newRole, self::SUPER_ADMIN_ONLY_ROLES, true) && ! $actor->isSuperAdmin()) {
            return response()->json(['success' => false, 'message' => 'Only super_admin can assign this role.'], 403);
        }

        $oldRole = $user->role;
        $user->update(['role' => $newRole]);
        $user->flushPermissionCache();

        app(ActivityLogger::class)->log(
            'user_role_changed',
            $user,
            oldValues: ['role' => $oldRole],
            newValues: ['role' => $newRole],
        );

        return response()->json([
            'success' => true,
            'message' => "Role updated to [{$newRole}].",
            'data'    => ['user_id' => $user->id, 'role' => $user->role, 'permissions' => $user->allPermissions()],
        ]);
    }

    /** GET /api/admin/users/{user}/permissions */
    public function getUserPermissions(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();

        // Only admin+ can view other users' permissions
        if (! $actor->isAdmin() && $actor->id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'user_id'     => $user->id,
                'name'        => $user->name,
                'role'        => $user->role,
                'permissions' => $user->allPermissions(),
            ],
        ]);
    }

    /** GET /api/admin/roles/options — simplified list for frontend dropdowns */
    public function roleOptions(): JsonResponse
    {
        return response()->json([
            'success' => true,
            // Sourced from the roles table itself — 'teacher' (a legacy,
            // deliberately unseeded RoleMiddleware alias for 'instructor')
            // is never a row here, so it never needs excluding by name.
            'data'    => Role::query()
                ->orderBy('name')
                ->get(['name', 'display_name'])
                ->map(fn (Role $r) => ['value' => $r->name, 'label' => $r->display_name])
                ->values(),
        ]);
    }
}
