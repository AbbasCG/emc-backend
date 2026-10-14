<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DepartmentalUnit;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminTeamMemberController extends Controller
{
    private const RELATIONS = ['user:id,name,email,role', 'department:id,name_ar', 'section:id,name', 'unit:id,name_ar,name_en,department_id', 'teamProfile:id,department_id,position_ar', 'teamProfile.department:id,name_ar'];

    // GET /api/admin/team-members
    //
    // Filtering, sorting and paging all happen here. Who may call it is decided by the route's role gate
    // (global admin roles) and is unchanged. `meta.stats` counts the whole department/search/role scope in
    // one aggregate query and ignores the status and "no unit" filters, because those are what the summary
    // cards select: pressing a card must not zero the others.
    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'department_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'nullable', 'in:active,inactive,on_leave'],
            'unit_id' => ['sometimes', 'nullable', 'string', 'max:20'],
            'role' => ['sometimes', 'nullable', 'string', 'max:50'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sort' => ['sometimes', 'nullable', 'in:name,department,unit,status,created_at'],
            'dir' => ['sometimes', 'nullable', 'in:asc,desc'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $scope = TeamMember::query()
            ->when($v['department_id'] ?? null, fn ($q, $d) => $q->where('team_members.department_id', $d))
            ->when($v['role'] ?? null, fn ($q, $r) => $q->whereHas('user', fn ($u) => $u->where('role', $r)))
            ->when($v['search'] ?? null, function ($q, $term) {
                // `!` is the LIKE escape: the same on MySQL and SQLite, so a typed % or _ is literal.
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($term)).'%';
                $q->whereHas('user', fn ($u) => $u->whereRaw("name LIKE ? ESCAPE '!'", [$like])->orWhereRaw("email LIKE ? ESCAPE '!'", [$like]));
            });

        $stats = (clone $scope)->reorder()->selectRaw(
            "COUNT(*) AS total,
             SUM(CASE WHEN team_members.status = 'active' THEN 1 ELSE 0 END) AS active_count,
             SUM(CASE WHEN team_members.status = 'inactive' THEN 1 ELSE 0 END) AS inactive_count,
             SUM(CASE WHEN team_members.status = 'on_leave' THEN 1 ELSE 0 END) AS on_leave_count,
             SUM(CASE WHEN team_members.unit_id IS NULL THEN 1 ELSE 0 END) AS without_unit"
        )->first();

        $query = (clone $scope)
            ->when($v['status'] ?? null, fn ($q, $st) => $q->where('team_members.status', $st))
            ->when(isset($v['unit_id']) && $v['unit_id'] !== '', function ($q) use ($v) {
                $v['unit_id'] === 'null' ? $q->whereNull('team_members.unit_id') : $q->where('team_members.unit_id', (int) $v['unit_id']);
            })
            ->with(self::RELATIONS)
            ->select('team_members.*');

        $dir = ($v['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        match ($v['sort'] ?? 'created_at') {
            'name' => $query->leftJoin('users', 'users.id', '=', 'team_members.user_id')->orderBy('users.name', $dir),
            'department' => $query->leftJoin('departments', 'departments.id', '=', 'team_members.department_id')->orderBy('departments.name_ar', $dir),
            'unit' => $query->leftJoin('departmental_units', 'departmental_units.id', '=', 'team_members.unit_id')->orderByRaw('departmental_units.name_ar IS NULL')->orderBy('departmental_units.name_ar', $dir),
            'status' => $query->orderBy('team_members.status', $dir),
            default => $query->orderBy('team_members.created_at', $dir),
        };
        $query->orderBy('team_members.id', 'desc');

        $members = $query->paginate((int) ($v['per_page'] ?? 20));

        return response()->json([
            'success' => true,
            'data'    => $members->getCollection()->map(fn ($m) => $this->format($m))->values(),
            'meta'    => [
                'total'        => $members->total(),
                'current_page' => $members->currentPage(),
                'last_page'    => $members->lastPage(),
                'per_page'     => $members->perPage(),
                'stats'        => [
                    'total' => (int) ($stats->total ?? 0),
                    'active' => (int) ($stats->active_count ?? 0),
                    'inactive' => (int) ($stats->inactive_count ?? 0),
                    'on_leave' => (int) ($stats->on_leave_count ?? 0),
                    'without_unit' => (int) ($stats->without_unit ?? 0),
                ],
            ],
        ]);
    }

    /**
     * Resolve the ONE canonical member profile for a user — business rule:
     * one User has exactly one TeamProfile (see the architecture audit).
     * Never trusts a client-supplied department_id/role_title — this is what
     * stops "member belongs to Technology, request sends Finance" tampering.
     *
     * If a user unexpectedly has more than one TeamProfile (a legacy/data
     * integrity violation the business rule says should not exist), this
     * deliberately REJECTS rather than silently picking one — guessing which
     * profile/department the admin meant would be exactly the kind of
     * silent-wrong-department bug this whole correction exists to prevent.
     */
    private function resolveCanonicalIdentity(int $userId): TeamProfile|string|null
    {
        $profiles = TeamProfile::where('user_id', $userId)->get();
        if ($profiles->count() > 1) {
            return 'ambiguous';
        }

        return $profiles->first();
    }

    // POST /api/admin/team-members
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id'    => 'required|exists:users,id',
            'unit_id'    => 'nullable|exists:departmental_units,id',
            'status'     => 'nullable|in:active,inactive,on_leave',
        ]);

        $profile = $this->resolveCanonicalIdentity($data['user_id']);
        if ($profile === 'ambiguous') {
            return response()->json([
                'success' => false,
                'message' => 'يمتلك هذا المستخدم أكثر من ملف عضو واحد — يجب دمجها في ملف واحد من صفحة "الأعضاء" قبل إسناده لإدارة.',
            ], 422);
        }
        if (! $profile || ! $profile->department_id) {
            return response()->json([
                'success' => false,
                'message' => 'هذا العضو غير مرتبط بإدارة في بيانات الفريق الحالية.',
            ], 422);
        }

        $data['team_profile_id'] = $profile->id;
        $data['department_id'] = $profile->department_id;
        $data['role_title'] = $profile->position_ar;
        $data['status'] = $data['status'] ?? 'active';

        // One operational membership per canonical member (not per department
        // — a person has exactly one TeamProfile, so at most one TeamMember).
        if (TeamMember::where('team_profile_id', $profile->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'هذا العضو لديه عضوية عملياتية بالفعل.',
            ], 422);
        }

        if (! empty($data['unit_id']) && ! $this->unitBelongsToDepartment($data['unit_id'], $data['department_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'الوحدة التقنية المختارة لا تتبع الإدارة المحددة.',
            ], 422);
        }

        $member = TeamMember::create($data);
        $member->load(['user:id,name,email,role', 'department:id,name_ar', 'section:id,name', 'unit:id,name_ar,name_en,department_id', 'teamProfile:id,department_id,position_ar', 'teamProfile.department:id,name_ar']);

        AuditLogService::log('created', 'TeamMember', $member->id,
            $member->user?->name ?? "member#{$member->id}",
            null, $this->format($member), null, $request);

        return response()->json([
            'success' => true,
            'message' => 'Team member added successfully',
            'data'    => $this->format($member),
        ], 201);
    }

    // GET /api/admin/team-members/{teamMember}
    public function show(TeamMember $teamMember): JsonResponse
    {
        $teamMember->load(['user:id,name,email,role', 'department:id,name_ar', 'section:id,name', 'unit:id,name_ar,name_en,department_id', 'teamProfile:id,department_id,position_ar', 'teamProfile.department:id,name_ar']);

        return response()->json(['success' => true, 'data' => $this->format($teamMember)]);
    }

    // PUT /api/admin/team-members/{teamMember}
    // Deliberately does NOT accept department_id/role_title — member identity,
    // department, and job title belong to the canonical team_profiles record
    // (see AdminMemberController) and are never editable from this
    // operational-placement page. Only unit and status are ever adjustable
    // here; to move a member's department, update their canonical member
    // profile, not this row (which would then need to be deleted and
    // recreated, since it always mirrors the profile's department).
    public function update(Request $request, TeamMember $teamMember): JsonResponse
    {
        $data = $request->validate([
            'unit_id' => 'nullable|exists:departmental_units,id',
            'status'  => 'sometimes|in:active,inactive,on_leave',
        ]);

        if (! empty($data['unit_id']) && ! $this->unitBelongsToDepartment($data['unit_id'], $teamMember->department_id)) {
            return response()->json([
                'success' => false,
                'message' => 'الوحدة التقنية المختارة لا تتبع الإدارة المحددة.',
            ], 422);
        }

        $oldSnap = $this->format($teamMember);
        $oldStatus = $teamMember->status;

        $teamMember->update($data);
        $teamMember->load(['user:id,name,email,role', 'department:id,name_ar', 'section:id,name', 'unit:id,name_ar,name_en,department_id', 'teamProfile:id,department_id,position_ar', 'teamProfile.department:id,name_ar']);

        $action = isset($data['status']) && $data['status'] !== $oldStatus ? 'status_changed' : 'updated';
        AuditLogService::log($action, 'TeamMember', $teamMember->id,
            $teamMember->user?->name ?? "member#{$teamMember->id}",
            $oldSnap, $this->format($teamMember), null, $request);

        return response()->json([
            'success' => true,
            'message' => 'Team member updated successfully',
            'data'    => $this->format($teamMember),
        ]);
    }

    // DELETE /api/admin/team-members/{teamMember}
    public function destroy(TeamMember $teamMember): JsonResponse
    {
        $snap  = $this->format($teamMember);
        $label = $teamMember->user?->name ?? "member#{$teamMember->id}";
        $id    = $teamMember->id;

        $teamMember->delete();

        AuditLogService::log('deleted', 'TeamMember', $id, $label, $snap, null, null, request());

        return response()->json(['success' => true, 'message' => 'Team member removed successfully']);
    }

    /**
     * Stage 3 — department/title are now read from the linked canonical
     * TeamProfile (authoritative) rather than the legacy team_members
     * columns, which are kept in sync (see AdminTeamProfileController) but
     * are no longer the source consulted for display. Falls back to the
     * legacy column only if a row somehow lacks its profile relation
     * (should not happen now that team_profile_id is NOT NULL, but this
     * keeps format() from ever showing nothing at all).
     */
    private function format(TeamMember $m): array
    {
        $profile = $m->teamProfile;
        $department = $profile?->department ?? $m->department;

        return [
            'id'            => $m->id,
            'user'          => $m->user ? ['id' => $m->user->id, 'name' => $m->user->name, 'email' => $m->user->email, 'role' => $m->user->role] : null,
            'department'    => $department ? ['id' => $department->id, 'name' => $department->name_ar] : null,
            'section'       => $m->section ? ['id' => $m->section->id, 'name' => $m->section->name] : null,
            'unit'          => $m->unit ? ['id' => $m->unit->id, 'name_ar' => $m->unit->name_ar, 'name_en' => $m->unit->name_en] : null,
            'role_title'    => $profile?->position_ar ?? $m->role_title,
            'status'        => $m->status,
            'created_at'    => $m->created_at,
        ];
    }

    /** Phase 4 consistency rule: a unit can only be assigned to a member of the SAME department. */
    private function unitBelongsToDepartment(int $unitId, ?int $departmentId): bool
    {
        if (! $departmentId) {
            return false;
        }

        return DepartmentalUnit::where('id', $unitId)->where('department_id', $departmentId)->exists();
    }
}
