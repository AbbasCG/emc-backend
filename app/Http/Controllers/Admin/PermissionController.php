<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PermissionController extends Controller
{
    /** GET /api/admin/permissions — list all permissions grouped */
    public function index(Request $request): JsonResponse
    {
        $permissions = Permission::query()
            ->when($request->group, fn ($q) => $q->where('group', $request->group))
            ->orderBy('group')
            ->orderBy('name')
            ->get(['id', 'name', 'display_name', 'group', 'description']);

        $grouped = $permissions->groupBy('group');

        return response()->json([
            'success' => true,
            'data'    => [
                'permissions' => $permissions,
                'grouped'     => $grouped,
                'total'       => $permissions->count(),
            ],
        ]);
    }

    /** GET /api/admin/me/permissions — current admin's own permission list */
    public function myPermissions(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data'    => [
                'user'        => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
                'role'        => $user->role,
                'permissions' => $user->allPermissions(),
            ],
        ]);
    }
}
