<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DepartmentalUnit;
use App\Models\TeamMember;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminTeamMemberController extends Controller
{
    // GET /api/admin/team-members
    public function index(Request $request): JsonResponse
    {
        $members = TeamMember::with(['user:id,name,email,role', 'department:id,name', 'section:id,name', 'unit:id,name_ar,name_en,department_id'])
            ->when($request->department_id, fn ($q) => $q->where('department_id', $request->department_id))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('unit_id'), function ($q) use ($request) {
                if ($request->unit_id === 'null') {
                    $q->whereNull('unit_id');
                } else {
                    $q->where('unit_id', $request->unit_id);
                }
            })
            ->when($request->search, function ($q) use ($request) {
                $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$request->search}%")
                    ->orWhere('email', 'like', "%{$request->search}%"));
            })
            ->latest()
            ->paginate(min((int) $request->query('per_page', 20), 100));

        return response()->json([
            'success' => true,
            'data'    => $members->map(fn ($m) => $this->format($m)),
            'meta'    => [
                'total'        => $members->total(),
                'current_page' => $members->currentPage(),
                'last_page'    => $members->lastPage(),
            ],
        ]);
    }

    // POST /api/admin/team-members
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id'       => [
                'required', 'exists:users,id',
                // Mirrors the team_members unique(['user_id','department_id']) DB
                // constraint with a clean 422 instead of a raw QueryException —
                // one user may belong to several departments, just never the
                // same department twice.
                Rule::unique('team_members')->where(fn ($q) => $q->where('department_id', $request->input('department_id'))),
            ],
            'department_id' => 'required|exists:departments,id',
            'section_id'    => 'nullable|exists:department_sections,id',
            'unit_id'       => 'nullable|exists:departmental_units,id',
            'role_title'    => 'nullable|string|max:255',
            'status'        => 'nullable|in:active,inactive,on_leave',
            'joined_at'     => 'nullable|date',
        ], [
            'user_id.unique' => 'هذا المستخدم عضو بالفعل في هذه الإدارة.',
        ]);

        $data['status'] = $data['status'] ?? 'active';

        if (! empty($data['unit_id']) && ! $this->unitBelongsToDepartment($data['unit_id'], $data['department_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'الوحدة التقنية المختارة لا تتبع الإدارة المحددة.',
            ], 422);
        }

        $member = TeamMember::create($data);
        $member->load(['user:id,name,email,role', 'department:id,name', 'section:id,name', 'unit:id,name_ar,name_en,department_id']);

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
        $teamMember->load(['user:id,name,email,role', 'department:id,name', 'section:id,name']);

        return response()->json(['success' => true, 'data' => $this->format($teamMember)]);
    }

    // PUT /api/admin/team-members/{teamMember}
    public function update(Request $request, TeamMember $teamMember): JsonResponse
    {
        $data = $request->validate([
            'department_id' => 'sometimes|exists:departments,id',
            'section_id'    => 'nullable|exists:department_sections,id',
            'unit_id'       => 'nullable|exists:departmental_units,id',
            'role_title'    => 'nullable|string|max:255',
            'status'        => 'sometimes|in:active,inactive,on_leave',
            'joined_at'     => 'nullable|date',
        ]);

        $effectiveDepartmentId = $data['department_id'] ?? $teamMember->department_id;

        if (
            isset($data['department_id']) && $data['department_id'] != $teamMember->department_id
            && TeamMember::where('user_id', $teamMember->user_id)
                ->where('department_id', $data['department_id'])
                ->where('id', '!=', $teamMember->id)
                ->exists()
        ) {
            return response()->json([
                'success' => false,
                'message' => 'هذا المستخدم عضو بالفعل في الإدارة المستهدفة.',
            ], 422);
        }

        if (! empty($data['unit_id']) && ! $this->unitBelongsToDepartment($data['unit_id'], $effectiveDepartmentId)) {
            return response()->json([
                'success' => false,
                'message' => 'الوحدة التقنية المختارة لا تتبع الإدارة المحددة.',
            ], 422);
        }

        $oldSnap = $this->format($teamMember);
        $oldStatus = $teamMember->status;

        $teamMember->update($data);
        $teamMember->load(['user:id,name,email,role', 'department:id,name', 'section:id,name', 'unit:id,name_ar,name_en,department_id']);

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

    private function format(TeamMember $m): array
    {
        return [
            'id'            => $m->id,
            'user'          => $m->user ? ['id' => $m->user->id, 'name' => $m->user->name, 'email' => $m->user->email, 'role' => $m->user->role] : null,
            'department'    => $m->department ? ['id' => $m->department->id, 'name' => $m->department->name] : null,
            'section'       => $m->section ? ['id' => $m->section->id, 'name' => $m->section->name] : null,
            'unit'          => $m->unit ? ['id' => $m->unit->id, 'name_ar' => $m->unit->name_ar, 'name_en' => $m->unit->name_en] : null,
            'role_title'    => $m->role_title,
            'status'        => $m->status,
            'joined_at'     => $m->joined_at,
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
