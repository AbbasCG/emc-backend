<?php

namespace App\Http\Middleware;

use App\Models\Department;
use App\Services\DepartmentAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ROUTE ENTRY for department-leadership surfaces.
 *
 * Usage on routes:
 *   role.or.leader:admin,super_admin,department_manager
 *
 * Semantics: pass when the user matches one of the listed role slugs OR is the
 * canonical leader of at least one ACTIVE department.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * WHY THIS EXISTS
 * ─────────────────────────────────────────────────────────────────────────
 * The department route group previously listed every role "capable of
 * legitimately holding leader_id" and excluded the rest, which encoded an
 * assumption the data does not guarantee: departments.leader_id is a plain
 * foreign key to users, so ANY role can be assigned as a department's canonical
 * leader, and real rows do exactly that. Such a leader was refused at the route
 * layer even though every controller behind it would have authorised them via
 * DepartmentAccessService. This middleware closes that contradiction at the
 * narrowest possible point.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * WHAT THIS DOES NOT DO — PAGE/ROUTE ENTRY != BUSINESS AUTHORIZATION
 * ─────────────────────────────────────────────────────────────────────────
 * Passing here grants NO privilege whatsoever. It only lets the request reach
 * a controller that then performs the real, department-scoped check of its own:
 *
 *   - DepartmentStructureController re-checks getLedDepartmentIds() /
 *     canManageDepartment() and 403s a non-leader, so a leader of department A
 *     still cannot read or edit department B.
 *   - DepartmentPermissionController scopes employees to led departments and
 *     routes every grant/revoke through PermissionDelegationService, which
 *     independently blocks self-delegation, requires the actor to already hold
 *     the permission, and forbids cross-department delegation.
 *
 * A leader therefore gains contextual entry to their OWN department's leader
 * surfaces and nothing else. No global admin authority, no /api/admin access,
 * no ProtectedAuthority change, no permission.
 *
 * ARCHIVED/INACTIVE departments confer no entry: leadership of a department
 * that is no longer active must not keep a door open. This matches
 * EffectivePageAccessService::departmentLayers(), which filters the identical
 * way, so route entry and page access cannot disagree.
 */
class DepartmentLeaderOrRoleMiddleware
{
    /** Legacy slug aliases, mirroring RoleMiddleware. */
    private const ALIASES = [
        'instructor' => ['teacher', 'instructor'],
        'teacher' => ['teacher', 'instructor'],
    ];

    public function __construct(private readonly DepartmentAccessService $departmentAccess)
    {
    }

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return $this->deny();
        }

        // Root passes every route check, exactly as RoleMiddleware defines it.
        if ($user->isSuperAdmin() || $user->isTechAdmin()) {
            return $next($request);
        }

        $specializedRole = method_exists($user, 'getDepartmentSpecializedRole') ? $user->getDepartmentSpecializedRole() : null;

        foreach ($roles as $role) {
            $accepted = self::ALIASES[$role] ?? [$role];

            if (in_array($user->role, $accepted, true) || ($specializedRole && in_array($specializedRole, $accepted, true))) {
                return $next($request);
            }
            if (in_array('department_manager', $accepted, true) && method_exists($user, 'isDepartmentManager') && $user->isDepartmentManager()) {
                return $next($request);
            }
        }

        if ($this->leadsAnActiveDepartment($user)) {
            return $next($request);
        }

        return $this->deny();
    }

    /**
     * Canonical leadership only: departments.leader_id, via the one service
     * that owns the question — never a role slug, never team_profiles.is_leader.
     */
    private function leadsAnActiveDepartment(\App\Models\User $user): bool
    {
        $ledIds = $this->departmentAccess->getLedDepartmentIds($user);

        if ($ledIds->isEmpty()) {
            return false;
        }

        return Department::query()
            ->whereIn('id', $ledIds->all())
            ->active()
            ->exists();
    }

    private function deny(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Forbidden. You do not have permission to perform this action.',
        ], 403);
    }
}
