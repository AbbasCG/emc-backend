<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\Department;
use App\Models\Task;
use App\Services\DepartmentAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        // 1. أعضاء TeamMember (جدول team_members) — النشطون فقط
        $team = $department->teamMembers()
            ->where('status', 'active')
            ->with('user:id,name,role')
            ->get()
            ->filter(fn ($member) => $member->user !== null)
            ->map(fn ($member) => [
                'id'         => $member->user->id,
                'name'       => $member->user->name,
                'role'       => $member->user->role,
                'role_title' => $member->role_title,
                'kind'       => 'team',
            ]);

        // 2. القائد الرئيسي (leader_id)
        $leader = $department->leader()->first(['id', 'name', 'role']);

        // 3. نائب القائد (deputy_leader_id)
        $deputyLeader = $department->deputyLeader()->first(['id', 'name', 'role']);

        // 4. العضو التشغيلي (operational_member_id)
        $operationalMember = $department->operationalMember()->first(['id', 'name', 'role']);

        // 5. أعضاء user_departments (pivot جدول user_departments)
        $usersPivot = $department->users()
            ->select('users.id', 'users.name', 'users.role')
            ->get()
            ->map(fn ($user) => [
                'id'         => $user->id,
                'name'       => $user->name,
                'role'       => $user->role,
                'role_title' => null,
                'kind'       => 'team',
            ]);

        // 6. أعضاء team_profiles (جدول team_profiles) — النشطون المرتبطون بحسابات مستخدمين غير الموقوفين في team_members
        $inactiveUserIds = \App\Models\TeamMember::query()
            ->where('department_id', $department->id)
            ->where('status', '!=', 'active')
            ->pluck('user_id')
            ->filter();

        $teamProfiles = $department->activeTeamProfiles()
            ->whereNotNull('user_id')
            ->whereNotIn('user_id', $inactiveUserIds)
            ->with('user:id,name,role')
            ->get()
            ->filter(fn ($tp) => $tp->user !== null && $tp->user->is_active)
            ->map(fn ($tp) => [
                'id'         => $tp->user->id,
                'name'       => $tp->user->name ?? $tp->name_ar,
                'role'       => $tp->user->role,
                'role_title' => $tp->position_ar,
                'kind'       => 'team',
            ]);

        // 7. المستخدمون المرتبطون مباشرة في جدول users باسم الإدارة أو الـ slug
        $departmentTerms = array_filter([$department->slug, $department->name, $department->name_ar]);
        $directUsers = $departmentTerms !== []
            ? \App\Models\User::query()
                ->where('is_active', true)
                ->whereIn('department', $departmentTerms)
                ->get(['id', 'name', 'role'])
                ->map(fn ($user) => [
                    'id'         => $user->id,
                    'name'       => $user->name,
                    'role'       => $user->role,
                    'role_title' => null,
                    'kind'       => 'team',
                ])
            : collect([]);

        // دمج جميع المصادر وإزالة التكرار بالـ id
        $seedEntries = array_filter([
            $leader         ? ['id' => $leader->id,          'name' => $leader->name,          'role' => $leader->role,          'role_title' => null, 'kind' => 'leader'] : null,
            $deputyLeader   ? ['id' => $deputyLeader->id,    'name' => $deputyLeader->name,    'role' => $deputyLeader->role,    'role_title' => null, 'kind' => 'deputy_leader'] : null,
            $operationalMember ? ['id' => $operationalMember->id, 'name' => $operationalMember->name, 'role' => $operationalMember->role, 'role_title' => null, 'kind' => 'operational'] : null,
        ]);

        $members = collect($seedEntries)
            ->concat($team)
            ->concat($usersPivot)
            ->concat($teamProfiles)
            ->concat($directUsers)
            ->unique('id')
            ->values();

        return response()->json(['success' => true, 'data' => $members]);
    }
}
