<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermissionAssignment;
use App\Services\UserActionPermissionService;
use App\Support\PermissionGovernance;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

/**
 * Per-user ACTION permissions ("what may this person DO"), as opposed to page access. Route role gate: super_admin + tech_admin.
 *
 * GET   view (both roles).
 * POST  grant / revoke the DIRECT assignment only. Authority = UserActionPermissionService::mayMutate():
 *       super_admin: any key; tech_admin: any ordinary key (PermissionDelegationService::canDelegate), but NEVER
 *       tickets.review_participated (PermissionGovernance: actual super_admin only); nobody edits their own direct grants; the
 *       super_admin account is editable by a super_admin only. Every change is audited with PermissionGovernance::logChange().
 */
class UserActionPermissionController extends Controller
{
    public function __construct(private readonly UserActionPermissionService $service)
    {
    }

    /** GET /api/admin/access/users/{user}/action-permissions?search=&group= */
    public function index(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['search' => 'nullable|string|max:100', 'group' => 'nullable|string|max:50']);

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $this->userBlock($user),
                'groups' => $this->service->groups($user, $request->user(), $data['search'] ?? null, $data['group'] ?? null),
            ],
        ]);
    }

    /** POST /api/admin/access/users/{user}/action-permissions/{key}/grant  (body: optional expires_at) */
    public function grant(Request $request, User $user, string $key): JsonResponse
    {
        $permission = $this->permissionOr404($key);
        $actor = $request->user();
        $data = $request->validate(['expires_at' => 'nullable|date|after:now']);

        $this->authorize403($actor, $user, $key);

        // Every row of the (user, permission) pair is replaced by ONE global direct_admin allow, atomically. A department-scoped delegation
        // (or any non direct_admin row) is not silently overwritten: 409 unless the actor is a super_admin.
        $previous = DB::transaction(function () use ($user, $permission, $actor, $data) {
            $rows = UserPermissionAssignment::where('user_id', $user->id)->where('permission_id', $permission->id)->orderBy('id')->lockForUpdate()->get();
            if (! $actor->isSuperAdmin() && UserActionPermissionService::hasForeignRow($rows)) {
                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'message' => 'لهذا المستخدم تفويض أو منح مرتبط بنطاق إدارة لهذه الصلاحية؛ لا يمكن استبداله إلا من المدير العام.',
                    'code' => 'DELEGATION_CONFLICT',
                ], 409));
            }
            UserPermissionAssignment::whereIn('id', $rows->pluck('id'))->delete();
            UserPermissionAssignment::create([
                'user_id' => $user->id,
                'permission_id' => $permission->id,
                'state' => 'allow',
                'scope_type' => 'global',
                'scope_id' => null,
                'source_type' => 'direct_admin',
                'granted_by' => $actor->id,
                'expires_at' => $data['expires_at'] ?? null,
                'reason' => null,
            ]);

            return UserActionPermissionService::snapshot($rows);
        });
        $user->flushPermissionCache();

        PermissionGovernance::logChange('user_permission_granted', $user, [$key], [], [
            'target_user_id' => $user->id, 'state' => 'allow', 'previous_state' => $previous[0]['state'] ?? null, 'previous_rows' => $previous,
            'expires_at' => $data['expires_at'] ?? null, 'scope_type' => 'global', 'source_type' => 'direct_admin', 'granted_by' => $actor->id, 'via' => 'access_control',
        ], $request);

        return $this->permissionResponse($user, $key, $actor, 'تم منح الصلاحية مباشرةً للمستخدم.');
    }

    /** POST /api/admin/access/users/{user}/action-permissions/{key}/revoke — removes the DIRECT assignment (allow or deny); role / system grants are untouched */
    public function revoke(Request $request, User $user, string $key): JsonResponse
    {
        $permission = $this->permissionOr404($key);
        $actor = $request->user();

        $this->authorize403($actor, $user, $key);

        // All rows of the pair go, atomically. A deny row or a delegation / non direct_admin row is for a super_admin only.
        $removed = DB::transaction(function () use ($user, $permission, $actor) {
            $rows = UserPermissionAssignment::where('user_id', $user->id)->where('permission_id', $permission->id)->orderBy('id')->lockForUpdate()->get();
            abort_if(
                ! $actor->isSuperAdmin() && UserActionPermissionService::needsSuperToRevoke($rows),
                403,
                'سحب منع صريح أو تفويض مرتبط بنطاق إدارة متاح للمدير العام فقط.',
            );
            UserPermissionAssignment::whereIn('id', $rows->pluck('id'))->delete();

            return UserActionPermissionService::snapshot($rows);
        });

        if ($removed !== []) {
            $user->flushPermissionCache();
            PermissionGovernance::logChange('user_permission_revoked', $user, [], [$key], [
                'target_user_id' => $user->id, 'removed_state' => $removed[0]['state'], 'previous_state' => $removed[0]['state'], 'previous_rows' => $removed, 'via' => 'access_control',
            ], $request);
        }
        $existing = $removed !== [];

        return $this->permissionResponse($user, $key, $actor, $existing ? 'تم سحب المنح المباشر للصلاحية.' : 'لا يوجد منح مباشر لهذه الصلاحية.');
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function permissionOr404(string $key): Permission
    {
        // ModelNotFoundException -> the API's JSON 404 ({success:false, message:"Permission not found."}).
        return Permission::where('name', $key)->firstOrFail();
    }

    private function authorize403(User $actor, User $target, string $key): void
    {
        abort_unless($this->service->mayMutate($actor, $target, $key), 403, $this->service->refusalMessage($actor, $target, $key));
    }

    private function permissionResponse(User $user, string $key, User $actor, string $message): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            // The permission row itself at the top level (same shape as the list rows), plus the user and the row again under `permission`.
            'data' => ($row = $this->service->row($user, $key, $actor)) + [
                'user' => $this->userBlock($user),
                'permission' => $row,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function userBlock(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role, 'is_active' => (bool) $user->is_active];
    }
}
