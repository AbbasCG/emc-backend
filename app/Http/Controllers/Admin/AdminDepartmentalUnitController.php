<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DepartmentalUnit;
use App\Services\AuditLogService;
use App\Services\DepartmentAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Minimal CRUD for departmental_units — the "technical unit" concept used by
 * EMC Tickets to scope employee assignment. Deliberately no hard delete: a
 * unit already referenced by tickets/team_members must never disappear from
 * under them, only be deactivated (is_active=false), matching how the rest
 * of this codebase treats departments/units as soft-toggleable, not
 * removable, records.
 */
class AdminDepartmentalUnitController extends Controller
{
    public function __construct(private readonly DepartmentAccessService $departmentAccess)
    {
    }

    private function assertCanManage(Request $request, int $departmentId): void
    {
        if (! $this->departmentAccess->canManageDepartment($request->user(), $departmentId)) {
            abort(403, 'إدارة الوحدات التقنية متاحة فقط لقائد الإدارة المعنية أو مشرف عام.');
        }
    }

    // GET /api/admin/departmental-units?department_id=X
    //
    // READ scope mirrors every other department-scoped read: a user sees the units of the departments they
    // may access (member, leader, or global); global and read-only-global roles see all. A department the
    // user cannot access is refused (403) rather than returned or silently emptied, and without a
    // department_id a restricted user receives only their accessible departments' units. `meta.can_manage`
    // reports whether THIS user may create or change units of the requested department, so the screen shows
    // write controls from the server's answer, never from a guess.
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->validate(['department_id' => ['sometimes', 'nullable', 'integer', 'min:1']]);
        $departmentId = $request->filled('department_id') ? (int) $request->input('department_id') : null;

        if ($departmentId !== null && ! $this->departmentAccess->canAccessDepartment($user, $departmentId)) {
            abort(403, 'لا تملك صلاحية عرض وحدات هذه الإدارة.');
        }

        $units = DepartmentalUnit::withCount(['teamMembers' => fn ($q) => $q->where('status', 'active')])
            ->when($departmentId !== null, fn ($q) => $q->where('department_id', $departmentId))
            ->tap(fn ($q) => $departmentId === null ? $this->departmentAccess->scopeQuery($q, $user) : $q)
            ->orderBy('name_ar')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $units->map(fn ($u) => $this->format($u)),
            'meta' => ['can_manage' => $departmentId !== null && $this->departmentAccess->canManageDepartment($user, $departmentId)],
        ]);
    }

    // POST /api/admin/departmental-units
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'department_id' => 'required|exists:departments,id',
            'code'          => 'required|string|max:50|unique:departmental_units,code',
            'name_ar'       => 'required|string|max:150',
            'name_en'       => 'nullable|string|max:150',
            'description'   => 'nullable|string',
        ]);

        $this->assertCanManage($request, (int) $data['department_id']);

        $data['is_active'] = true;
        $unit = DepartmentalUnit::create($data);
        $unit->loadCount('teamMembers');

        AuditLogService::log('created', 'DepartmentalUnit', $unit->id, $unit->name_ar, null, $this->format($unit), null, $request);

        return response()->json(['success' => true, 'message' => 'تم إنشاء الوحدة التقنية بنجاح', 'data' => $this->format($unit)], 201);
    }

    // PUT/PATCH /api/admin/departmental-units/{unit}
    public function update(Request $request, DepartmentalUnit $departmentalUnit): JsonResponse
    {
        $this->assertCanManage($request, (int) $departmentalUnit->department_id);

        $data = $request->validate([
            'name_ar'     => 'sometimes|string|max:150',
            'name_en'     => 'nullable|string|max:150',
            'description' => 'nullable|string',
            'is_active'   => 'sometimes|boolean',
        ]);

        $oldSnap = $this->format($departmentalUnit);
        $departmentalUnit->update($data);
        $departmentalUnit->loadCount('teamMembers');

        AuditLogService::log('updated', 'DepartmentalUnit', $departmentalUnit->id, $departmentalUnit->name_ar,
            $oldSnap, $this->format($departmentalUnit), null, $request);

        return response()->json(['success' => true, 'message' => 'تم تحديث الوحدة التقنية بنجاح', 'data' => $this->format($departmentalUnit)]);
    }

    private function format(DepartmentalUnit $u): array
    {
        return [
            'id'           => $u->id,
            'department_id' => $u->department_id,
            'code'         => $u->code,
            'name_ar'      => $u->name_ar,
            'name_en'      => $u->name_en,
            'description'  => $u->description,
            'is_active'    => (bool) $u->is_active,
            'member_count' => $u->team_members_count ?? 0,
        ];
    }
}
