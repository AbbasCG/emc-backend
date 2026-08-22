<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PermissionNode;
use App\Models\Role;
use App\Models\Permission;
use App\Models\DelegatablePermission;

class AdminPermissionController extends Controller
{
    /**
     * Get the permission tree and what the given role can delegate.
     */
    public function getDelegatableConfig(Request $request, $roleName)
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $nodes = PermissionNode::where('is_active', true)->orderBy('sort_order')->get();
        
        $delegatablePermissionIds = DelegatablePermission::where('role_id', $role->id)
            ->pluck('permission_id');
            
        $delegatablePermissions = Permission::whereIn('id', $delegatablePermissionIds)
            ->pluck('name')
            ->toArray();

        // Build the tree with is_delegatable flag
        $tree = $nodes->map(function ($node) use ($delegatablePermissions) {
            return [
                'id' => $node->id,
                'parent_id' => $node->parent_id,
                'node_type' => $node->node_type,
                'key' => $node->key,
                'label_ar' => $node->label_ar,
                'label_en' => $node->label_en,
                'permission_name' => $node->permission_name,
                'is_delegatable' => $node->permission_name ? in_array($node->permission_name, $delegatablePermissions) : false,
                'is_system' => $node->is_system
            ];
        });

        return response()->json(['success' => true, 'tree' => $tree]);
    }

    /**
     * Toggle whether a role can delegate a specific permission.
     */
    public function toggleDelegatable(Request $request, $roleName)
    {
        $request->validate([
            'permission_name' => 'required|string|exists:permissions,name',
            'is_delegatable' => 'required|boolean'
        ]);

        $role = Role::where('name', $roleName)->firstOrFail();
        $permission = Permission::where('name', $request->permission_name)->firstOrFail();

        if ($request->is_delegatable) {
            DelegatablePermission::firstOrCreate([
                'role_id' => $role->id,
                'permission_id' => $permission->id,
            ]);
        } else {
            DelegatablePermission::where('role_id', $role->id)
                ->where('permission_id', $permission->id)
                ->delete();
        }

        return response()->json(['success' => true, 'message' => 'Delegatable permission updated successfully.']);
    }

    /**
     * Get available roles for delegation management.
     */
    public function getRoles()
    {
        // Typically, we only allow managers to delegate
        $roles = Role::whereIn('name', [
            'department_manager', 
            'programs_manager', 
            'operations_manager', 
            'partnerships_manager', 
            'community_manager',
            'admin'
        ])->get(['id', 'name', 'display_name']);
        
        return response()->json(['success' => true, 'roles' => $roles]);
    }
}
