<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\PermissionDelegationService;
use App\Models\PermissionNode;

class AccessManifestController extends Controller
{
    public function __invoke(Request $request, PermissionDelegationService $service)
    {
        $user = $request->user();
        
        $effectivePermissions = $user->allPermissions(); // Uses the new service cached logic

        // We can also return the tree nodes if needed for the UI menu building
        $nodes = PermissionNode::where('is_active', true)->orderBy('sort_order')->get();

        return response()->json([
            'success' => true,
            'manifest' => [
                'role' => $user->role,
                'is_super_admin' => $user->isSuperAdmin(),
                'is_tech_admin' => $user->isTechAdmin(),
                'permissions' => $effectivePermissions,
                // UI can use these nodes to determine what sections/screens are available based on permissions
                'nodes' => $nodes
            ]
        ]);
    }
}
