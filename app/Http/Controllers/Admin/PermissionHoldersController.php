<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionAssignment;
use App\Services\RoleMembershipService;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/admin/permissions/{key}/holders — who holds a permission and through what. Read-only, super_admin + tech_admin.
 *
 * Mirrors User::allPermissions() without evaluating every user one by one:
 *   candidates = users whose role grants it (system roles via Permissions::all(), other roles via permission_role, a department
 *                leader through the specialised role) UNION users with an active direct allow
 *   minus      users with an active direct deny (a deny beats role and system, as in PermissionDelegationService)
 * One row per user, soft-deleted users excluded. Inactive accounts are listed (is_active is returned).
 */
class PermissionHoldersController extends Controller
{
    private const DIRECT_LIST_CAP = 200;

    public function __construct(private readonly RoleMembershipService $membership)
    {
    }

    public function show(Request $request, string $key): JsonResponse
    {
        $permission = Permission::where('name', $key)->firstOrFail();
        $data = $request->validate([
            'search' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1|max:10000',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        // ── Roles that grant it ──
        $systemRoles = in_array($key, Permissions::all(), true) ? ['super_admin', 'tech_admin'] : [];
        $dbRoleNames = $permission->roles()->pluck('roles.name')->all();
        $granting = array_values(array_unique(array_merge($systemRoles, $dbRoleNames)));
        $roles = Role::query()->whereIn('name', $granting)->orderBy('name')->get()->map(fn (Role $r) => [
            'id' => $r->id,
            'name' => $r->name,
            'display_name' => $r->display_name,
            'users_count' => $this->membership->count($r),
            'source' => in_array($r->name, $systemRoles, true) ? 'system' : 'role',
        ])->values();

        // ── Direct assignments (active) ── the list is capped at DIRECT_LIST_CAP (name order); `total` is the full count.
        $active = fn () => UserPermissionAssignment::query()
            ->join('users', 'users.id', '=', 'user_permission_assignments.user_id')
            ->whereNull('users.deleted_at')
            ->where('user_permission_assignments.permission_id', $permission->id)
            ->where(fn ($q) => $q->whereNull('user_permission_assignments.expires_at')->orWhere('user_permission_assignments.expires_at', '>', now()));
        $assignments = $active()->get(['user_permission_assignments.user_id', 'user_permission_assignments.state']);
        $directTotal = $assignments->count();
        $capped = $active()->orderBy('users.name')->orderBy('user_permission_assignments.id')->limit(self::DIRECT_LIST_CAP)
            ->get(['user_permission_assignments.*', 'users.name as holder_name', 'users.email as holder_email', 'users.role as holder_role']);
        $grantors = User::withTrashed()->whereIn('id', $capped->pluck('granted_by')->filter()->unique())->pluck('name', 'id');
        $direct = $capped->map(fn ($a) => [
            'id' => $a->user_id,
            'name' => $a->holder_name,
            'email' => $a->holder_email,
            'role' => $a->holder_role,
            'state' => $a->state,
            'expires_at' => $a->expires_at?->toIso8601String(),
            'granted_by' => $a->granted_by ? ['id' => (int) $a->granted_by, 'name' => $grantors[$a->granted_by] ?? null] : null,
        ])->values();

        // ── Effective users ──
        $allowIds = $assignments->where('state', 'allow')->pluck('user_id')->all();
        $denyIds = $assignments->where('state', 'deny')->pluck('user_id')->all();
        $primaryRoleNames = [];
        foreach ($granting as $name) {
            array_push($primaryRoleNames, $name);
            if ($name === 'instructor') {
                $primaryRoleNames[] = 'teacher';
            }
        }
        $leaderSlugs = [];
        foreach ($granting as $name) {
            $leaderSlugs = array_merge($leaderSlugs, $this->membership->leaderSlugsFor($name));
        }
        $leaderSlugs = array_values(array_unique($leaderSlugs));

        $query = User::query()
            ->select(['users.id', 'users.name', 'users.email', 'users.is_active', 'users.role'])
            ->where(function (Builder $w) use ($primaryRoleNames, $leaderSlugs, $allowIds) {
                $w->whereIn('users.role', $primaryRoleNames)->orWhereIn('users.id', $allowIds);
                if ($leaderSlugs !== []) {
                    $w->orWhere(fn (Builder $s) => $this->membership->constrainSpecialized($s, $leaderSlugs));
                }
            })
            ->whereNotIn('users.id', $denyIds)
            ->orderBy('users.name')->orderBy('users.id');
        if (($search = trim((string) ($data['search'] ?? ''))) !== '') {
            $like = '%'.addcslashes($search, '\%_').'%';
            $query->where(fn ($w) => $w->where('users.name', 'like', $like)->orWhere('users.email', 'like', $like));
        }
        $page = $query->paginate((int) ($data['per_page'] ?? 20), ['*'], 'page', (int) ($data['page'] ?? 1));

        $allowSet = array_flip($allowIds);
        $roleLabels = Role::query()->whereIn('name', $granting)->pluck('display_name', 'name');
        $items = $page->getCollection()->map(function (User $u) use ($systemRoles, $primaryRoleNames, $roleLabels, $allowSet, $granting) {
            $sources = [];
            $ownRole = $u->role;
            if (in_array($ownRole, $systemRoles, true)) {
                $sources[] = ['type' => 'system', 'label_ar' => 'صلاحية ضمنية لدور «'.$ownRole.'»'];
            } elseif (in_array($ownRole, $primaryRoleNames, true)) {
                $sources[] = ['type' => 'role', 'label_ar' => 'من الدور: '.($roleLabels[$ownRole === 'teacher' ? 'instructor' : $ownRole] ?? $ownRole)];
            }
            // The same function that decides the user's specialised role everywhere else (User::getDepartmentSpecializedRole).
            if ($ownRole === 'department_manager' && ($spec = $u->getDepartmentSpecializedRole()) !== null && in_array($spec, $granting, true)) {
                $sources[] = ['type' => 'role', 'label_ar' => 'من الدور المتخصص لقيادة الإدارة: '.($roleLabels[$spec] ?? $spec)];
            }
            if (isset($allowSet[$u->id])) {
                $sources[] = ['type' => 'direct_allow', 'label_ar' => 'منح مباشر للمستخدم'];
            }

            return ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'is_active' => (bool) $u->is_active, 'role' => $u->role, 'sources' => $sources];
        })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'permission' => [
                    'key' => $permission->name,
                    'label_ar' => $permission->label_ar ?: $permission->display_name ?: $permission->name,
                    'description_ar' => $permission->description_ar,
                    'group' => $permission->group,
                    'explicit_only' => ! in_array($key, Permissions::all(), true),
                ],
                'roles' => $roles,
                'direct_users' => ['items' => $direct, 'total' => $directTotal, 'capped' => $directTotal > $direct->count()],
                'effective_users' => [
                    'items' => $items,
                    'total' => $page->total(),
                    'page' => $page->currentPage(),
                    'per_page' => $page->perPage(),
                    'last_page' => $page->lastPage(),
                ],
            ],
        ]);
    }
}
