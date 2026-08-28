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
    public function index(Request $request): JsonResponse
    {
        $units = DepartmentalUnit::withCount(['teamMembers' => fn ($q) => $q->where('status', 'active')])
            ->when($request->filled('department_id'), fn ($q) => $q->where('department_id', $request->department_id))
            ->orderBy('name_ar')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $units->map(fn ($u) => $this->format($u)),
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
