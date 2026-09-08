<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\DepartmentSection;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Models\User;
use App\Services\DepartmentAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Department Structure & Org Chart — ported and adapted from
 * feature/dept-structure-and-delegation. Every method (including reads)
 * independently verifies authorization via DepartmentAccessService; the
 * route-level middleware is only a coarse pre-filter (see routes/api.php),
 * never the authority.
 *
 * Adapted from the source branch:
 *   - getStructure() now returns 403 for a genuine non-leader (an
 *     authenticated user who leads zero departments), instead of a silent
 *     200 with empty arrays — matching "non-leader gets 403" for every
 *     operation on this controller, not just writes.
 *   - Every write (store/update/reorder/destroy) additionally blocks
 *     non-global users from modifying an archived department's structure —
 *     canManageDepartment() alone doesn't consider status, so this
 *     controller enforces it directly. Reads remain allowed against
 *     archived departments (historical structure stays viewable).
 *   - A new reorderSections() endpoint replaces one-at-a-time sort_order
 *     edits via updateSection() for a full reorder operation, with its own
 *     department-membership validation on every section id supplied.
 */
class DepartmentStructureController extends Controller
{
    public function __construct(private readonly DepartmentAccessService $departmentAccess)
    {
    }

    /**
     * Get the full organizational structure for the manager's led department(s).
     */
    public function getStructure(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($this->departmentAccess->isGlobal($user)) {
            $departmentId = $request->input('department_id');
            if (! $departmentId) {
                $department = Department::active()->orderBy('sort_order')->first();
                $departmentId = $department ? $department->id : null;
            }
            $departments = Department::active()->orderBy('sort_order')->get(['id', 'name']);
        } else {
            $ledIds = $this->departmentAccess->getLedDepartmentIds($user);

            if ($ledIds->isEmpty()) {
                return response()->json(['success' => false, 'message' => 'غير مصرح لك بعرض هيكلة أي إدارة.'], 403);
            }

            $departmentId = $request->input('department_id') ?: $ledIds->first();

            if (! $ledIds->contains((int) $departmentId)) {
                return response()->json(['success' => false, 'message' => 'غير مصرح لك بعرض هيكلة هذه الإدارة.'], 403);
            }

            $departments = Department::whereIn('id', $ledIds)->get(['id', 'name']);
        }

        if (! $departmentId) {
            return response()->json([
                'success' => true,
                'department' => null,
                'sections' => [],
                'unassigned_members' => [],
                'all_members' => [],
                'departments' => $departments,
            ]);
        }

        $department = Department::with('leader:id,name,email,avatar,role')->find($departmentId);
        if (! $department) {
            return response()->json(['success' => false, 'message' => 'Department not found'], 404);
        }

        $sections = DepartmentSection::where('department_id', $departmentId)
            ->with(['leader:id,name,email,avatar,role', 'teamMembers.user:id,name,email,avatar,role'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        // Membership resolved via the user_departments pivot and team_members —
        // `users.department_id` does not exist as a real column (fixed vs. the
        // source branch), matching DepartmentAccessService::getMemberDepartmentIds().
        $memberUsers = User::where(function ($q) use ($departmentId) {
            $q->whereHas('departments', fn ($sq) => $sq->where('departments.id', $departmentId))
                ->orWhereHas('teamMemberships', fn ($sq) => $sq->where('department_id', $departmentId));
        })->get(['id', 'name', 'email', 'avatar', 'role']);

        $assignedUserIds = [];
        $formattedSections = $sections->map(function ($section) use (&$assignedUserIds) {
            $members = $section->teamMembers->map(function ($tm) use (&$assignedUserIds) {
                if ($tm->user) {
                    $assignedUserIds[] = $tm->user_id;

                    return [
                        'team_member_id' => $tm->id,
                        'user_id' => $tm->user_id,
                        'name' => $tm->user->name,
                        'email' => $tm->user->email,
                        'avatar' => $tm->user->avatar,
                        'role' => $tm->user->role,
                        'role_title' => $tm->role_title ?: 'عضو قسم',
                        'status' => $tm->status,
                    ];
                }

                return null;
            })->filter()->values();

            return [
                'id' => $section->id,
                'name' => $section->name,
                'description' => $section->description,
                'status' => $section->status,
                'sort_order' => $section->sort_order,
                'leader' => $section->leader ? [
                    'id' => $section->leader->id,
                    'name' => $section->leader->name,
                    'email' => $section->leader->email,
                    'avatar' => $section->leader->avatar,
                ] : null,
                'members' => $members,
                'member_count' => $members->count(),
            ];
        });

        $unassigned = $memberUsers->reject(fn ($u) => in_array($u->id, $assignedUserIds))->map(function ($u) {
            $tm = TeamMember::where('user_id', $u->id)->first();

            return [
                'user_id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'avatar' => $u->avatar,
                'role' => $u->role,
                'role_title' => $tm ? ($tm->role_title ?: 'غير مسكّن') : 'غير مسكّن',
            ];
        })->values();

        $allMembersFormatted = $memberUsers->map(function ($u) use ($departmentId) {
            $tm = TeamMember::where('user_id', $u->id)->where('department_id', $departmentId)->first();

            return [
                'user_id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'avatar' => $u->avatar,
                'role' => $u->role,
                'section_id' => $tm ? $tm->section_id : null,
                'role_title' => $tm ? $tm->role_title : null,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'department' => [
                'id' => $department->id,
                'name' => $department->name,
                'description' => $department->description ?? '',
                'status' => $department->status,
                'leader' => $department->leader ? [
                    'id' => $department->leader->id,
                    'name' => $department->leader->name,
                    'email' => $department->leader->email,
                ] : null,
            ],
            'sections' => $formattedSections,
            'unassigned_members' => $unassigned,
            'all_members' => $allMembersFormatted,
            'departments' => $departments,
        ]);
    }

    /** Non-global users cannot write to an archived department's structure. */
    private function assertWritable(User $user, Department $department): ?JsonResponse
    {
        if (! $this->departmentAccess->isGlobal($user) && $department->status !== 'active') {
            return response()->json(['success' => false, 'message' => 'لا يمكن تعديل هيكلة إدارة مؤرشفة.'], 403);
        }

        return null;
    }

    /**
     * team_members.team_profile_id is NOT NULL and unique (one canonical
     * profile per operational membership). A section/member assignment must
     * never fabricate a TeamProfile to satisfy that — per the canonical rule,
     * role/action-triggered auto-creation of a TeamProfile is forbidden. The
     * user must already have a TeamProfile pointed at this exact department
     * (added via the normal Team Management flow); otherwise this returns an
     * error response to display, and the caller must not proceed.
     */
    private function resolveTeamProfileForAssignment(int $userId, int $departmentId): TeamProfile|JsonResponse
    {
        $profile = TeamProfile::where('user_id', $userId)->first();

        if (! $profile) {
            return response()->json(['success' => false, 'message' => 'يجب إنشاء ملف تعريف للعضو في إدارة الفريق أولاً قبل تسكينه.'], 422);
        }

        if ((int) $profile->department_id !== $departmentId) {
            return response()->json(['success' => false, 'message' => 'ملف تعريف العضو يتبع إدارة مختلفة — حدّث إدارة الفريق أولاً.'], 422);
        }

        return $profile;
    }

    /**
     * Create a new sub-section in a department.
     */
    public function storeSection(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'department_id' => 'required|exists:departments,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'leader_id' => 'nullable|exists:users,id',
            'sort_order' => 'nullable|integer',
        ]);

        if (! $this->departmentAccess->canManageDepartment($user, (int) $data['department_id'])) {
            return response()->json(['success' => false, 'message' => 'غير مصرح لك بإدارة هذا القسم'], 403);
        }

        $department = Department::findOrFail($data['department_id']);
        if ($deny = $this->assertWritable($user, $department)) {
            return $deny;
        }

        // Validate the leader's TeamProfile BEFORE writing anything, so a
        // missing/mismatched profile aborts with zero writes, never a
        // half-created section.
        $leaderProfile = null;
        if (! empty($data['leader_id'])) {
            $leaderProfile = $this->resolveTeamProfileForAssignment((int) $data['leader_id'], (int) $data['department_id']);
            if ($leaderProfile instanceof JsonResponse) {
                return $leaderProfile;
            }
        }

        $data['status'] = 'active';
        $data['sort_order'] = $data['sort_order'] ?? 0;

        $section = DepartmentSection::create($data);

        if ($leaderProfile) {
            TeamMember::updateOrCreate(
                ['user_id' => $section->leader_id, 'department_id' => $section->department_id],
                ['team_profile_id' => $leaderProfile->id, 'section_id' => $section->id, 'role_title' => 'مشرف التقسيم', 'status' => 'active'],
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء التقسيم الإداري بنجاح',
            'section' => $section->load('leader:id,name,email'),
        ], 201);
    }

    /**
     * Update an existing section.
     */
    public function updateSection(Request $request, DepartmentSection $section): JsonResponse
    {
        $user = $request->user();

        if (! $this->departmentAccess->canManageDepartment($user, (int) $section->department_id)) {
            return response()->json(['success' => false, 'message' => 'غير مصرح لك بإدارة هذا القسم'], 403);
        }

        if ($deny = $this->assertWritable($user, $section->department)) {
            return $deny;
        }

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'leader_id' => 'nullable|exists:users,id',
            'status' => 'sometimes|in:active,inactive',
            'sort_order' => 'nullable|integer',
        ]);

        $leaderProfile = null;
        if (array_key_exists('leader_id', $data) && $data['leader_id']) {
            $leaderProfile = $this->resolveTeamProfileForAssignment((int) $data['leader_id'], (int) $section->department_id);
            if ($leaderProfile instanceof JsonResponse) {
                return $leaderProfile;
            }
        }

        $section->update($data);

        if ($leaderProfile) {
            TeamMember::updateOrCreate(
                ['user_id' => $data['leader_id'], 'department_id' => $section->department_id],
                ['team_profile_id' => $leaderProfile->id, 'section_id' => $section->id, 'role_title' => 'مشرف التقسيم', 'status' => 'active'],
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث التقسيم الإداري بنجاح',
            'section' => $section->load('leader:id,name,email'),
        ]);
    }

    /**
     * Reorder every section within a department in one call.
     */
    public function reorderSections(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'department_id' => 'required|exists:departments,id',
            'section_ids' => 'required|array|min:1',
            'section_ids.*' => 'integer|exists:department_sections,id',
        ]);

        $departmentId = (int) $data['department_id'];

        if (! $this->departmentAccess->canManageDepartment($user, $departmentId)) {
            return response()->json(['success' => false, 'message' => 'غير مصرح لك بإدارة هذا القسم'], 403);
        }

        $department = Department::findOrFail($departmentId);
        if ($deny = $this->assertWritable($user, $department)) {
            return $deny;
        }

        // Every supplied section id must actually belong to this department —
        // never trust the client to only submit ids from the right department.
        $sectionIds = collect($data['section_ids'])->map(fn ($id) => (int) $id);
        $ownedCount = DepartmentSection::where('department_id', $departmentId)
            ->whereIn('id', $sectionIds)
            ->count();

        if ($ownedCount !== $sectionIds->count()) {
            return response()->json(['success' => false, 'message' => 'بعض التقسيمات لا تنتمي إلى هذه الإدارة'], 422);
        }

        foreach ($sectionIds->values() as $index => $sectionId) {
            DepartmentSection::where('id', $sectionId)->update(['sort_order' => $index]);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم إعادة ترتيب التقسيمات بنجاح',
            'sections' => DepartmentSection::where('department_id', $departmentId)->orderBy('sort_order')->get(),
        ]);
    }

    /**
     * Delete a section (unassigning members).
     */
    public function destroySection(Request $request, DepartmentSection $section): JsonResponse
    {
        $user = $request->user();

        if (! $this->departmentAccess->canManageDepartment($user, (int) $section->department_id)) {
            return response()->json(['success' => false, 'message' => 'غير مصرح لك بإدارة هذا القسم'], 403);
        }

        if ($deny = $this->assertWritable($user, $section->department)) {
            return $deny;
        }

        TeamMember::where('section_id', $section->id)->update(['section_id' => null]);
        $section->delete();

        return response()->json(['success' => true, 'message' => 'تم حذف التقسيم الإداري بنجاح']);
    }

    /**
     * Assign a user to a section and set their role title in the department structure.
     */
    public function assignMemberSection(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'department_id' => 'required|exists:departments,id',
            'section_id' => 'nullable|exists:department_sections,id',
            'role_title' => 'nullable|string|max:255',
        ]);

        if (! $this->departmentAccess->canManageDepartment($user, (int) $data['department_id'])) {
            return response()->json(['success' => false, 'message' => 'غير مصرح لك بتسكين الأعضاء في هذه الإدارة'], 403);
        }

        $department = Department::findOrFail($data['department_id']);
        if ($deny = $this->assertWritable($user, $department)) {
            return $deny;
        }

        $profile = $this->resolveTeamProfileForAssignment((int) $data['user_id'], (int) $data['department_id']);
        if ($profile instanceof JsonResponse) {
            return $profile;
        }

        $teamMember = TeamMember::updateOrCreate(
            ['user_id' => $data['user_id'], 'department_id' => $data['department_id']],
            [
                'team_profile_id' => $profile->id,
                'section_id' => $data['section_id'] ?? null,
                'role_title' => $data['role_title'] ?? 'عضو قسم',
                'status' => 'active',
            ],
        );

        return response()->json([
            'success' => true,
            'message' => 'تم تسكين العضو في التقسيم الإداري بنجاح',
            'team_member' => $teamMember,
        ]);
    }
}
