<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\Department;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Models\User;
use App\Services\DepartmentAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * لوحة التشغيل المرئية للجميع: كل أدوار الفريق تقرأ نفس اللوحة —
 * من يعمل على ماذا، حالة كل مهمة، والمُقفَل منها. القراءة للجميع؛
 * الكتابة تبقى عبر مسارات الأدمن المقيَّدة.
 */
class OperationsBoardController extends Controller
{
    public function __construct(private readonly DepartmentAccessService $departmentAccess)
    {
    }

    /** ما نطاق الإدارات المتاح لهذا المستخدم — تُبنى عليه واجهة اختيار الإدارة. */
    public function myDepartmentAccess(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->departmentAccess->manifest($request->user()),
        ]);
    }

    public function board(Request $request): JsonResponse
    {
        $statuses = [
            'idea', 'under_review', 'planning', 'waiting_approval',
            'in_progress', 'completed', 'postponed', 'cancelled', 'needs_evaluation',
        ];

        $query = Task::query()
            ->with(['department:id,name', 'assignedTo:id,name', 'createdBy:id,name'])
            ->when($request->department_id, fn ($q) => $q->where('department_id', $request->department_id));

        $board = [];
        foreach ($statuses as $status) {
            $board[$status] = TaskResource::collection(
                (clone $query)->where('status', $status)->orderByDesc('updated_at')->get(),
            );
        }

        return response()->json(['success' => true, 'data' => $board]);
    }

    /** أعضاء إدارة (القائد + كامل أعضاء الإدارة) — يغذي قائمة التقييم في التقرير الأسبوعي ومحضر الاجتماع. */
    public function departmentMembers(Request $request, Department $department): JsonResponse
    {
        if (! $this->departmentAccess->canAccessDepartment($request->user(), $department->id)) {
            return response()->json(['success' => false, 'message' => 'ليست لديك صلاحية على هذه الإدارة.'], 403);
        }

        // Sources are listed in PRECEDENCE order. The first source that yields
        // a user decides that user's `kind` (and name/role); a later canonical
        // source may only fill a missing `role_title`, never override. So a
        // leader who is also an active team member stays `leader` but still
        // gets their team_members.role_title.
        $sources = [];

        // 1–3. Canonical leadership seats on the department row itself.
        foreach ([
            'leader'        => $department->leader(),
            'deputy_leader' => $department->deputyLeader(),
            'operational'   => $department->operationalMember(),
        ] as $kind => $relation) {
            $seat = $relation->first(['id', 'name', 'role']);
            if ($seat) {
                $sources[] = collect([$this->memberEntry($seat->id, $seat->name, $seat->role, null, $kind)]);
            }
        }

        // 4. Active team_members rows (canonical).
        $sources[] = $department->teamMembers()
            ->where('status', 'active')
            ->with('user:id,name,role')
            ->orderBy('id')
            ->get()
            ->filter(fn ($member) => $member->user !== null)
            ->map(fn ($member) => $this->memberEntry(
                $member->user->id, $member->user->name, $member->user->role, $member->role_title, 'team'
            ));

        // 5. user_departments pivot (canonical).
        $sources[] = $department->users()
            ->select('users.id', 'users.name', 'users.role')
            ->orderBy('users.id')
            ->get()
            ->map(fn ($user) => $this->memberEntry($user->id, $user->name, $user->role, null, 'team'));

        // 6. Active team_profiles linked to an active account (canonical),
        //    excluding anyone explicitly deactivated in this department's
        //    team_members — a deactivation must not be undone by the profile.
        $inactiveUserIds = TeamMember::query()
            ->where('department_id', $department->id)
            ->where('status', '!=', 'active')
            ->pluck('user_id')
            ->filter();

        $sources[] = $department->activeTeamProfiles()
            ->whereNotNull('user_id')
            ->whereNotIn('user_id', $inactiveUserIds)
            ->with('user:id,name,role,is_active')
            ->get()
            ->filter(fn ($tp) => $tp->user !== null && $tp->user->is_active)
            ->map(fn ($tp) => $this->memberEntry(
                $tp->user->id, $tp->user->name ?? $tp->name_ar, $tp->user->role, $tp->position_ar, 'team'
            ));

        // 7. LAST, and only as a compatibility fallback — see legacyTextMembers().
        $sources[] = $this->legacyTextMembers($department);

        $members = [];
        foreach ($sources as $source) {
            foreach ($source as $entry) {
                $id = $entry['id'];
                if (! isset($members[$id])) {
                    $members[$id] = $entry;
                } elseif ($members[$id]['role_title'] === null && $entry['role_title'] !== null) {
                    $members[$id]['role_title'] = $entry['role_title'];
                }
            }
        }

        return response()->json(['success' => true, 'data' => array_values($members)]);
    }

    private function memberEntry(int $id, ?string $name, ?string $role, ?string $roleTitle, string $kind): array
    {
        return [
            'id'         => $id,
            'name'       => $name,
            'role'       => $role,
            'role_title' => $roleTitle,
            'kind'       => $kind,
        ];
    }

    /**
     * COMPATIBILITY FALLBACK ONLY — `users.department` is NOT organizational
     * authority and must never become one.
     *
     * Why it exists: `users.department` is free text typed in the admin user
     * editor and the instructor editor. AdminUserController syncs the
     * canonical `user_departments` pivot ONLY when that value is a numeric
     * department id; typed text (e.g. "التسويق") is stored on the user row
     * and nothing else. Such users belong to no canonical structure at all,
     * so without this fallback they would silently vanish from the evaluation
     * roster. Whether any exist in production cannot be proven from code, so
     * they are recovered here rather than dropped.
     *
     * It is deliberately narrow, so ambiguous or stale text cannot place
     * someone in a department:
     *   - exact match on this department's slug / name / name_ar only;
     *   - the text must identify exactly ONE department — text shared by two
     *     departments (e.g. an archived duplicate) proves nothing and is
     *     ignored;
     *   - the user must have NO canonical department association anywhere
     *     (pivot, any team_members row, team_profiles, or a leader / deputy /
     *     operational seat on any department). Once canonical data knows a
     *     user, canonical data alone decides where they belong, even if their
     *     legacy text still names another department;
     *   - active accounts only; and it is merged after every canonical source
     *     (it can never set `kind` or `role_title` for a known user).
     */
    private function legacyTextMembers(Department $department): Collection
    {
        $terms = array_values(array_unique(array_filter(
            [$department->slug, $department->name, $department->name_ar],
            fn ($term) => is_string($term) && trim($term) !== '',
        )));

        if ($terms === []) {
            return collect();
        }

        $candidates = User::query()
            ->where('is_active', true)
            ->whereIn('department', $terms)
            ->orderBy('id')
            ->get(['id', 'name', 'role', 'department']);

        if ($candidates->isEmpty()) {
            return collect();
        }

        $unambiguousTexts = $candidates->pluck('department')->unique()->filter(function ($text) use ($department) {
            $matches = Department::query()
                ->where(fn ($q) => $q->where('slug', $text)->orWhere('name', $text)->orWhere('name_ar', $text))
                ->pluck('id');

            return $matches->count() === 1 && (int) $matches->first() === (int) $department->id;
        });

        $candidates = $candidates->whereIn('department', $unambiguousTexts->all());
        if ($candidates->isEmpty()) {
            return collect();
        }

        $ids = $candidates->pluck('id');
        $canonicallyKnown = collect()
            ->merge(DB::table('user_departments')->whereIn('user_id', $ids)->pluck('user_id'))
            ->merge(TeamMember::query()->whereIn('user_id', $ids)->pluck('user_id'))
            ->merge(TeamProfile::query()->whereIn('user_id', $ids)->pluck('user_id'))
            ->merge(Department::query()->whereIn('leader_id', $ids)->pluck('leader_id'))
            ->merge(Department::query()->whereIn('deputy_leader_id', $ids)->pluck('deputy_leader_id'))
            ->merge(Department::query()->whereIn('operational_member_id', $ids)->pluck('operational_member_id'))
            ->map(fn ($id) => (int) $id)
            ->unique();

        return $candidates
            ->reject(fn (User $user) => $canonicallyKnown->contains((int) $user->id))
            ->map(fn (User $user) => $this->memberEntry($user->id, $user->name, $user->role, null, 'team'))
            ->values();
    }
}
