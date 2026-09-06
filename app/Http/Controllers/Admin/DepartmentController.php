<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\DepartmentResource;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Department;
use App\Models\Task;
use App\Models\TeamProfile;
use App\Models\VolunteerHrProfile;
use App\Services\DepartmentLeadershipService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DepartmentController extends Controller
{
    public function __construct(private readonly DepartmentLeadershipService $leadership)
    {
    }

    /**
     * Only a real department_manager is restricted to their own department
     * (mirrors index()'s scoping — show()/overview() previously had none,
     * letting any department_manager view any department by id).
     */
    private function assertCanViewDepartment(Request $request, Department $department): void
    {
        $user = $request->user();
        if (! $user || $user->role !== 'department_manager' || $user->isAdmin() || $user->isSuperAdmin()) {
            return;
        }

        $ownDeptId = Department::where('leader_id', $user->id)->value('id');
        if ($ownDeptId && $ownDeptId === $department->id) {
            return;
        }

        abort(403, 'غير مصرح لك بعرض هذا القسم.');
    }

    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $user  = $request->user();
        $query = Department::query()
            ->select('departments.*')
            ->addSelect(DB::raw(
                "(SELECT COUNT(*) FROM volunteer_requests
                  WHERE volunteer_requests.desired_department = departments.name_ar
                  AND volunteer_requests.deleted_at IS NULL
                 ) AS volunteer_requests_count"
            ))
            ->addSelect(DB::raw(
                "(SELECT COUNT(*) FROM volunteer_requests
                  WHERE volunteer_requests.desired_department = departments.name_ar
                  AND volunteer_requests.status = 'pending'
                  AND volunteer_requests.deleted_at IS NULL
                 ) AS pending_volunteer_count"
            ))
            ->withCount([
                'teamProfiles as members_count',
                'teamProfiles as leaders_count' => fn ($q) => $q->where('is_leader', true),
                'courses as courses_count',
                'tasks as open_tasks_count' => fn ($q) => $q->whereNotIn('status', ['completed', 'cancelled']),
            ])
            ->with(['leader:id,name,email,role', 'sections', 'children'])
            ->whereNull('parent_id')
            ->active()
            ->orderBy('sort_order');

        // Scope: department_manager sees only their own department(s).
        // Resolution order: leader_id in DB → user.department string → all (for admin/super_admin).
        if ($user && $user->role === 'department_manager' && ! $user->isAdmin() && ! $user->isSuperAdmin()) {
            $ownDeptId = Department::where('leader_id', $user->id)->value('id');

            if ($ownDeptId) {
                $query->where('departments.id', $ownDeptId);
            } else {
                $deptName = trim((string) $user->department);
                if ($deptName !== '') {
                    $query->where(function ($q) use ($deptName) {
                        $q->where('name_ar', $deptName)
                          ->orWhere('name', $deptName)
                          ->orWhere('slug', $deptName)
                          ->orWhere('slug', str_replace(' ', '-', strtolower($deptName)));
                    });
                } else {
                    // No department linked — return empty with a hint
                    return response()->json([
                        'success' => true,
                        'data'    => [],
                        'message' => 'لم يتم ربط حسابك بإدارة. تواصل مع المسؤول.',
                        'no_department_linked' => true,
                    ]);
                }
            }
        }

        $departments = $query->get();

        return response()->json([
            'success' => true,
            'data'    => DepartmentResource::collection($departments),
        ]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'slug'        => 'nullable|string|unique:departments,slug',
            'description' => 'nullable|string',
            'leader_id'   => 'nullable|exists:users,id',
            'parent_id'   => 'nullable|exists:departments,id',
            'status'      => 'in:active,inactive,archived',
            'icon'        => 'nullable|string|max:100',
            'color'       => 'nullable|string|max:50',
            'sort_order'  => 'integer',
        ]);

        $data['slug'] = $data['slug'] ?? Str::slug($data['name']);

        $department = Department::create($data);

        if (array_key_exists('leader_id', $data)) {
            $this->leadership->syncFromDepartmentLeaderId($department, $data['leader_id']);
        }

        return response()->json([
            'success' => true,
            'data'    => new DepartmentResource($department->load(['leader', 'sections'])),
        ], 201);
    }

    public function show(Request $request, Department $department): \Illuminate\Http\JsonResponse
    {
        $this->assertCanViewDepartment($request, $department);

        $department->loadCount([
            'teamProfiles as members_count',
            'teamProfiles as leaders_count' => fn ($q) => $q->where('is_leader', true),
            'courses as courses_count',
            'tasks as open_tasks_count' => fn ($q) => $q->whereNotIn('status', ['completed', 'cancelled']),
        ]);

        $department->load(['leader:id,name,email,role', 'sections.leader', 'children', 'teamMembers.user']);

        $department->volunteer_requests_count = \App\Models\VolunteerRequest::where('desired_department', $department->name_ar)
            ->whereNull('deleted_at')
            ->count();

        $department->pending_volunteer_count = \App\Models\VolunteerRequest::where('desired_department', $department->name_ar)
            ->where('status', 'pending')
            ->whereNull('deleted_at')
            ->count();

        return response()->json([
            'success' => true,
            'data'    => new DepartmentResource($department),
        ]);
    }

    // GET /api/operations/departments/{department}/overview
    // Single aggregated payload for the department workspace page — every
    // section's data is scoped to this department_id and computed from the
    // real tables (never derived from whatever rows the frontend happens to
    // have loaded).
    public function overview(Request $request, Department $department): \Illuminate\Http\JsonResponse
    {
        $this->assertCanViewDepartment($request, $department);

        $department->load(['leader:id,name,email,role,phone', 'sections.leader:id,name,email,role', 'children:id,name_ar,parent_id']);

        $now = now();
        $startOfMonth = $now->copy()->startOfMonth();

        // ── Members (team_profiles — the same table /dashboard/members and
        // /dashboard/super-admin/crud/team already read, so no second source
        // of truth is introduced) ──────────────────────────────────────────
        $membersQuery = TeamProfile::where('department_id', $department->id);
        $membersCount = (clone $membersQuery)->count();
        $activeMembersCount = (clone $membersQuery)->where('is_active', true)->count();
        $lastJoinDate = (clone $membersQuery)->max('joined_at');
        $newMembersThisMonth = (clone $membersQuery)->where('joined_at', '>=', $startOfMonth)->count();
        $members = (clone $membersQuery)
            ->orderByDesc('is_executive')->orderByDesc('is_leader')->orderByDesc('joined_at')
            ->limit(8)
            ->get(['id', 'user_id', 'name_ar', 'position_ar', 'email', 'image', 'is_active', 'is_leader', 'is_executive', 'joined_at']);

        // ── Volunteer HR profiles (approved = "team", open = "applications") ─
        $volunteersQuery = VolunteerHrProfile::where('department_id', $department->id);
        $approvedVolunteers = (clone $volunteersQuery)->where('status', 'approved')
            ->orderByDesc('approved_at')->limit(8)
            ->get(['id', 'full_name', 'job_title', 'join_date', 'weekly_hours', 'availability', 'status', 'approved_at']);
        $applicationsQuery = (clone $volunteersQuery)->whereIn('status', VolunteerHrProfile::OPEN_STATUSES);
        $applicationStats = [
            'total'        => (clone $volunteersQuery)->count(),
            'submitted'    => (clone $volunteersQuery)->where('status', 'submitted')->count(),
            'under_review' => (clone $volunteersQuery)->where('status', 'under_review')->count(),
            'approved'     => (clone $volunteersQuery)->where('status', 'approved')->count(),
            'rejected'     => (clone $volunteersQuery)->where('status', 'rejected')->count(),
        ];
        $recentApplications = (clone $applicationsQuery)
            ->orderByDesc('submitted_at')->limit(6)
            ->get(['id', 'full_name', 'email', 'job_title', 'submitted_at', 'status']);

        // ── Courses (the only LMS entity with a real department_id column) ──
        $coursesQuery = Course::where('department_id', $department->id);
        $coursesCount = (clone $coursesQuery)->count();
        $courses = (clone $coursesQuery)
            ->with('instructor:id,name')
            ->withCount('registrations')
            ->orderByDesc('created_at')->limit(6)
            ->get(['id', 'title', 'type', 'status', 'instructor_id', 'start_date', 'end_date']);

        // ── Tasks ─────────────────────────────────────────────────────────
        $tasksQuery = Task::where('department_id', $department->id);
        $openTasksQuery = (clone $tasksQuery)->whereNotIn('status', ['completed', 'cancelled']);
        $openTasksCount = (clone $openTasksQuery)->count();
        $overdueTasksCount = (clone $openTasksQuery)->whereNotNull('due_date')->where('due_date', '<', $now)->count();
        $completedThisMonth = (clone $tasksQuery)->where('status', 'completed')->where('updated_at', '>=', $startOfMonth)->count();
        $openTasks = (clone $openTasksQuery)
            ->with('assignedTo:id,name')
            ->orderByRaw('due_date IS NULL, due_date ASC')
            ->limit(8)
            ->get(['id', 'title', 'assigned_to', 'priority', 'status', 'due_date']);

        // ── Leadership ────────────────────────────────────────────────────
        $sectionLeads = $department->sections->filter(fn ($s) => $s->leader)->map(fn ($s) => [
            'id' => $s->leader->id, 'name' => $s->leader->name, 'email' => $s->leader->email,
            'role' => $s->leader->role, 'title' => $s->name . ' — قائد قسم فرعي',
        ])->values();

        // ── Recent activity — Department's own audit trail plus the entities
        // that actually belong to it (never every historical row platform-wide).
        $memberIds = (clone $membersQuery)->pluck('id');
        $volunteerIds = (clone $volunteersQuery)->pluck('id');
        $taskIds = (clone $tasksQuery)->pluck('id');
        $recentActivity = AuditLog::query()
            ->where(function ($q) use ($department, $memberIds, $volunteerIds, $taskIds) {
                $q->where(fn ($qq) => $qq->where('entity_type', 'Department')->where('entity_id', $department->id))
                    ->orWhere(fn ($qq) => $qq->where('entity_type', 'TeamProfile')->whereIn('entity_id', $memberIds))
                    ->orWhere(fn ($qq) => $qq->where('entity_type', 'VolunteerHrProfile')->whereIn('entity_id', $volunteerIds))
                    ->orWhere(fn ($qq) => $qq->where('entity_type', 'Task')->whereIn('entity_id', $taskIds));
            })
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'action', 'entity_type', 'entity_name', 'description', 'user_name', 'created_at']);

        // ── Completeness — every rule is backed by a real column, no guesses ─
        $missing = [];
        if (! $department->leader_id) $missing[] = 'لا يوجد مدير للقسم';
        if (! trim((string) ($department->description_ar ?? $department->description))) $missing[] = 'لا يوجد وصف للقسم';
        if ($activeMembersCount === 0) $missing[] = 'لا يوجد أعضاء نشطون';
        if ($coursesCount === 0) $missing[] = 'لا توجد برامج أو دورات مرتبطة';
        if (! $department->icon && ! $department->color) $missing[] = 'لم يتم تخصيص أيقونة أو لون للقسم';
        $totalRules = 5;
        $percentage = (int) round((($totalRules - count($missing)) / $totalRules) * 100);

        return response()->json([
            'success' => true,
            'data' => [
                'department' => new DepartmentResource($department),
                'kpis' => [
                    'total_members'          => $membersCount,
                    'active_members'         => $activeMembersCount,
                    'approved_volunteers'    => $applicationStats['approved'],
                    'pending_applications'   => $applicationStats['submitted'] + $applicationStats['under_review'],
                    'programs_linked'        => $coursesCount,
                    'courses_linked'         => $coursesCount,
                    'open_tasks'             => $openTasksCount,
                    'overdue_tasks'          => $overdueTasksCount,
                    'leadership_count'       => ($department->leader_id ? 1 : 0) + $sectionLeads->count(),
                    'new_members_this_month' => $newMembersThisMonth,
                    'activity_rate'          => $membersCount > 0 ? (int) round(($activeMembersCount / $membersCount) * 100) : 0,
                    'last_join_date'         => $lastJoinDate,
                    'completed_tasks_this_month' => $completedThisMonth,
                ],
                'leadership' => [
                    'manager' => $department->leader ? [
                        'id' => $department->leader->id, 'name' => $department->leader->name,
                        'email' => $department->leader->email, 'role' => $department->leader->role,
                        'phone' => $department->leader->phone,
                    ] : null,
                    'section_leads' => $sectionLeads,
                ],
                'members' => $members->map(fn ($m) => [
                    'id' => $m->id, 'user_id' => $m->user_id, 'name' => $m->name_ar, 'position' => $m->position_ar,
                    'email' => $m->email, 'image' => $m->image, 'is_active' => $m->is_active,
                    'is_leader' => $m->is_leader, 'is_executive' => $m->is_executive, 'joined_at' => $m->joined_at,
                ]),
                'approved_volunteers' => $approvedVolunteers,
                'volunteer_applications' => [
                    'stats' => $applicationStats,
                    'recent' => $recentApplications,
                ],
                'courses' => $courses->map(fn ($c) => [
                    'id' => $c->id, 'title' => $c->title, 'type' => $c->type, 'status' => $c->status,
                    'instructor' => $c->instructor?->name, 'students_count' => $c->registrations_count,
                    'start_date' => $c->start_date, 'end_date' => $c->end_date,
                ]),
                'tasks' => $openTasks->map(fn ($t) => [
                    'id' => $t->id, 'title' => $t->title, 'assignee' => $t->assignedTo?->name,
                    'priority' => $t->priority, 'status' => $t->status, 'due_date' => $t->due_date,
                    'is_overdue' => $t->due_date && $t->due_date->lt($now) && ! in_array($t->status, ['completed', 'cancelled'], true),
                ]),
                'recent_activity' => $recentActivity,
                'completeness' => [
                    'percentage' => $percentage,
                    'missing'    => $missing,
                ],
            ],
        ]);
    }

    public function update(Request $request, Department $department): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'name'        => 'string|max:255',
            'slug'        => 'string|unique:departments,slug,' . $department->id,
            'description' => 'nullable|string',
            'leader_id'   => 'nullable|exists:users,id',
            'parent_id'   => 'nullable|exists:departments,id',
            'status'      => 'in:active,inactive,archived',
            'icon'        => 'nullable|string|max:100',
            'color'       => 'nullable|string|max:50',
            'sort_order'  => 'integer',
        ]);

        $department->update($data);

        if (array_key_exists('leader_id', $data)) {
            $this->leadership->syncFromDepartmentLeaderId($department, $data['leader_id']);
        }

        return response()->json([
            'success' => true,
            'data'    => new DepartmentResource($department->load(['leader', 'sections'])),
        ]);
    }

    public function destroy(Department $department): \Illuminate\Http\JsonResponse
    {
        $department->delete();

        return response()->json(['success' => true, 'message' => 'Department deleted.']);
    }
}
