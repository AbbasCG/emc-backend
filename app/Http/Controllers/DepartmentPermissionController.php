<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Permission;
use App\Models\PermissionNode;
use App\Models\UserPermissionAssignment;
use App\Services\PermissionDelegationService;

class DepartmentPermissionController extends Controller
{
    /**
     * List all employees in the departments managed by the authenticated user.
     */
    public function getEmployees(Request $request)
    {
        $manager = $request->user();
        
        // Get all department IDs where this user is the leader
        $managedDepartmentIds = \App\Models\Department::where('leader_id', $manager->id)->pluck('id');
        
        if ($managedDepartmentIds->isEmpty()) {
            return response()->json(['success' => true, 'employees' => []]);
        }

        // Get users who belong to any of these departments
        $employees = User::whereHas('departments', function ($query) use ($managedDepartmentIds) {
            $query->whereIn('departments.id', $managedDepartmentIds);
        })->get(['id', 'name', 'email', 'avatar', 'role']);

        return response()->json(['success' => true, 'employees' => $employees]);
    }

    /**
     * Get the permission tree and assignment status for a specific employee.
     */
    public function getEmployeePermissionTree(Request $request, User $employee, PermissionDelegationService $delegationService)
    {
        $manager = $request->user();

        // 1. Verify manager manages this employee
        $managedDepartmentIds = \App\Models\Department::where('leader_id', $manager->id)->pluck('id');
        $employeeDepartmentIds = $employee->departments()->pluck('departments.id');
        
        if ($manager->id !== $employee->id && !$manager->isSuperAdmin() && $managedDepartmentIds->intersect($employeeDepartmentIds)->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized. Employee not in your scope.'], 403);
        }

        // 2. Build Tree
        $nodes = PermissionNode::where('is_active', true)->orderBy('sort_order')->get();
        $employeePermissions = $employee->allPermissions(); // Includes role + direct assignments
        $employeeBasePermissions = $employee->basePermissions();
        
        $employeeAssignments = UserPermissionAssignment::where('user_id', $employee->id)->get()->keyBy('permission_id');

        // Map nodes to include status
        $tree = $nodes->map(function ($node) use ($manager, $employee, $delegationService, $employeePermissions, $employeeBasePermissions, $employeeAssignments) {
            $canDelegate = $node->permission_name 
                ? $delegationService->canDelegate($manager, $employee, $node->permission_name) 
                : false;
            
            $assignmentState = 'none';
            if ($node->permission_name) {
                $hasPerm = in_array($node->permission_name, $employeePermissions);
                $isBase = in_array($node->permission_name, $employeeBasePermissions);
                
                $permission = Permission::where('name', $node->permission_name)->first();
                $assignment = $permission ? $employeeAssignments->get($permission->id) : null;
                
                if ($isBase) {
                    $assignmentState = 'inherited';
                } elseif ($hasPerm && $assignment && $assignment->state === 'allow') {
                    $assignmentState = 'granted';
                } elseif ($assignment && $assignment->state === 'deny') {
                    $assignmentState = 'denied';
                }
            }
            
            return [
                'id' => $node->id,
                'parent_id' => $node->parent_id,
                'node_type' => $node->node_type,
                'key' => $node->key,
                'label_ar' => $node->label_ar,
                'label_en' => $node->label_en,
                'permission_name' => $node->permission_name,
                'has_permission' => $node->permission_name ? in_array($node->permission_name, $employeePermissions) : false,
                'can_delegate' => $canDelegate,
                'assignment_state' => $assignmentState,
                'is_system' => $node->is_system
            ];
        });

        // Filter out sections that are empty because they have no visible/delegatable children
        // Actually, returning the full tree with the statuses is fine, frontend will hide nodes if they are totally irrelevant.
        return response()->json(['success' => true, 'tree' => $tree]);
    }

    /**
     * Grant a permission to an employee.
     */
    public function grantPermission(Request $request, User $employee, PermissionDelegationService $delegationService)
    {
        $request->validate([
            'permission_name' => 'required|string|exists:permissions,name'
        ]);

        $manager = $request->user();
        $permissionName = $request->permission_name;

        if (!$delegationService->canDelegate($manager, $employee, $permissionName)) {
            return response()->json(['success' => false, 'message' => 'You do not have the right to delegate this permission to this user.'], 403);
        }

        $permission = Permission::where('name', $permissionName)->first();

        UserPermissionAssignment::updateOrCreate(
            ['user_id' => $employee->id, 'permission_id' => $permission->id],
            [
                'state' => 'allow',
                'scope_type' => 'department',
                'source_type' => 'delegation',
                'granted_by' => $manager->id,
                'expires_at' => null // could be passed in request
            ]
        );

        $employee->flushPermissionCache();

        return response()->json(['success' => true, 'message' => 'Permission granted successfully.']);
    }

    /**
     * Revoke a permission from an employee.
     */
    public function revokePermission(Request $request, User $employee, PermissionDelegationService $delegationService)
    {
        $request->validate([
            'permission_name' => 'required|string|exists:permissions,name'
        ]);

        $manager = $request->user();
        $permissionName = $request->permission_name;

        // Even to revoke, manager must have delegation rights over this user for this permission
        if (!$delegationService->canDelegate($manager, $employee, $permissionName)) {
            return response()->json(['success' => false, 'message' => 'You do not have the right to revoke this permission from this user.'], 403);
        }

        $permission = Permission::where('name', $permissionName)->first();

        // Either delete the assignment or explicitly deny it. 
        // We will explicitly deny it to handle inherited permissions as well.
        UserPermissionAssignment::updateOrCreate(
            ['user_id' => $employee->id, 'permission_id' => $permission->id],
            [
                'state' => 'deny',
                'scope_type' => 'department',
                'source_type' => 'delegation',
                'granted_by' => $manager->id,
                'expires_at' => null
            ]
        );

        $employee->flushPermissionCache();

        return response()->json(['success' => true, 'message' => 'Permission revoked successfully.']);
    }
}
