<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\HrApplication;
use App\Models\HrInterview;
use App\Models\HrOnboarding;
use App\Models\Instructor;
use App\Models\Task;
use App\Models\User;
use App\Models\VolunteerProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HrDashboardController extends Controller
{
    // ── GET /api/hr/dashboard ────────────────────────────────────────────────

    public function dashboard(): JsonResponse
    {
        $staffRoles = ['admin', 'super_admin', 'executive_admin', 'hr_manager',
                       'finance_manager', 'marketing_manager', 'quality_manager',
                       'department_manager', 'section_lead', 'support_agent',
                       'instructor', 'teacher'];

        return response()->json([
            'success' => true,
            'data'    => [
                'total_team_members'  => User::whereIn('role', $staffRoles)->count(),
                'active_volunteers'   => VolunteerProfile::where('status', 'active')->count(),
                'active_instructors'  => Instructor::count(),
                'pending_applications'=> HrApplication::where('status', 'pending')->count(),
                'departments_count'   => Department::where('status', 'active')->count(),
                'onboarding_pending'  => HrOnboarding::where('status', 'pending')->count(),
                'upcoming_interviews' => HrInterview::where('status', 'scheduled')
                                            ->where('scheduled_at', '>=', now())
                                            ->count(),
                'hr_tasks_due'        => Task::where('status', '!=', 'done')
                                            ->whereNotNull('due_date')
                                            ->where('due_date', '<=', now()->addDays(7))
                                            ->count(),
            ],
        ]);
    }

    // ── GET /api/hr/team ─────────────────────────────────────────────────────

    public function team(Request $request): JsonResponse
    {
        $staffRoles = ['admin', 'super_admin', 'executive_admin', 'hr_manager',
                       'finance_manager', 'marketing_manager', 'quality_manager',
                       'department_manager', 'section_lead', 'support_agent',
                       'instructor', 'teacher'];

        $members = User::whereIn('role', $staffRoles)
            ->when($request->role, fn ($q) => $q->where('role', $request->role))
            ->when($request->search, function ($q) use ($request) {
                $q->where(function ($sub) use ($request) {
                    $sub->where('name', 'like', "%{$request->search}%")
                        ->orWhere('email', 'like', "%{$request->search}%");
                });
            })
            ->orderBy('name')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $members->map(fn (User $u) => [
                'id'         => $u->id,
                'name'       => $u->name,
                'email'      => $u->email,
                'role'       => $u->role,
                'created_at' => $u->created_at,
            ]),
            'meta'    => [
                'total'        => $members->total(),
                'current_page' => $members->currentPage(),
                'last_page'    => $members->lastPage(),
            ],
        ]);
    }

    // ── GET /api/hr/volunteers ───────────────────────────────────────────────

    public function volunteers(Request $request): JsonResponse
    {
        $volunteers = VolunteerProfile::with(['user', 'department', 'section'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->department_id, fn ($q) => $q->where('department_id', $request->department_id))
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $volunteers->map(fn ($v) => [
                'id'                => $v->id,
                'name'              => $v->user?->name,
                'email'             => $v->user?->email,
                'department'        => $v->department?->name,
                'section'           => $v->section?->name,
                'status'            => $v->status,
                'skills'            => $v->skills,
                'availability'      => $v->availability,
                'hours_logged'      => $v->hours_logged,
                'onboarding_status' => $v->onboarding_status,
                'notes'             => $v->notes,
                'created_at'        => $v->created_at,
            ]),
            'meta'    => [
                'total'        => $volunteers->total(),
                'current_page' => $volunteers->currentPage(),
                'last_page'    => $volunteers->lastPage(),
            ],
        ]);
    }

    // ── GET /api/hr/instructors ──────────────────────────────────────────────

    public function instructors(Request $request): JsonResponse
    {
        $instructors = Instructor::with(['user', 'courses'])
            ->when($request->search, function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%");
            })
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $instructors->map(fn ($i) => [
                'id'           => $i->id,
                'name'         => $i->name,
                'title'        => $i->title,
                'email'        => $i->user?->email,
                'courses_count'=> $i->courses->count(),
                'created_at'   => $i->created_at,
            ]),
            'meta'    => [
                'total'        => $instructors->total(),
                'current_page' => $instructors->currentPage(),
                'last_page'    => $instructors->lastPage(),
            ],
        ]);
    }

    // ── GET /api/hr/applications ─────────────────────────────────────────────

    public function applications(Request $request): JsonResponse
    {
        $apps = HrApplication::with(['department', 'reviewer'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->type, fn ($q) => $q->where('type', $request->type))
            ->when($request->department_id, fn ($q) => $q->where('department_id', $request->department_id))
            ->when($request->search, function ($q) use ($request) {
                $q->where(function ($sub) use ($request) {
                    $sub->where('applicant_name', 'like', "%{$request->search}%")
                        ->orWhere('applicant_email', 'like', "%{$request->search}%")
                        ->orWhere('position', 'like', "%{$request->search}%");
                });
            })
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $apps->map(fn ($a) => [
                'id'              => $a->id,
                'applicant_name'  => $a->applicant_name,
                'applicant_email' => $a->applicant_email,
                'applicant_phone' => $a->applicant_phone,
                'position'        => $a->position,
                'type'            => $a->type,
                'status'          => $a->status,
                'department'      => $a->department?->name,
                'reviewed_by'     => $a->reviewer?->name,
                'reviewed_at'     => $a->reviewed_at,
                'notes'           => $a->notes,
                'created_at'      => $a->created_at,
            ]),
            'meta'    => [
                'total'        => $apps->total(),
                'current_page' => $apps->currentPage(),
                'last_page'    => $apps->lastPage(),
            ],
        ]);
    }

    // ── POST /api/hr/applications ────────────────────────────────────────────

    public function storeApplication(Request $request): JsonResponse
    {
        $data = $request->validate([
            'applicant_name'  => 'required|string|max:200',
            'applicant_email' => 'required|email|max:200',
            'applicant_phone' => 'nullable|string|max:30',
            'position'        => 'required|string|max:200',
            'type'            => 'required|in:job,volunteer,internship',
            'cover_letter'    => 'nullable|string',
            'department_id'   => 'nullable|exists:departments,id',
            'notes'           => 'nullable|string',
        ]);

        $app = HrApplication::create($data);

        return response()->json(['success' => true, 'data' => $app], 201);
    }

    // ── PATCH /api/hr/applications/{id}/status ───────────────────────────────

    public function updateApplicationStatus(Request $request, HrApplication $application): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|in:pending,reviewing,interview,accepted,rejected',
            'notes'  => 'nullable|string',
        ]);

        $application->update([
            'status'      => $data['status'],
            'notes'       => $data['notes'] ?? $application->notes,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return response()->json(['success' => true, 'data' => $application->fresh()]);
    }

    // ── GET /api/hr/departments ──────────────────────────────────────────────

    public function departments(): JsonResponse
    {
        $departments = Department::withCount(['teamMembers', 'volunteers', 'tasks'])
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($d) => [
                'id'                  => $d->id,
                'name'                => $d->name,
                'slug'                => $d->slug,
                'status'              => $d->status,
                'icon'                => $d->icon,
                'color'               => $d->color,
                'team_members_count'  => $d->team_members_count,
                'volunteers_count'    => $d->volunteers_count,
                'tasks_count'         => $d->tasks_count,
            ]);

        return response()->json(['success' => true, 'data' => $departments]);
    }

    // ── GET /api/hr/onboarding ───────────────────────────────────────────────

    public function onboarding(Request $request): JsonResponse
    {
        $records = HrOnboarding::with(['user', 'assignedTo'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $records->map(fn ($o) => [
                'id'          => $o->id,
                'user'        => ['id' => $o->user?->id, 'name' => $o->user?->name, 'email' => $o->user?->email],
                'status'      => $o->status,
                'steps'       => $o->steps ?? [],
                'start_date'  => $o->start_date,
                'target_date' => $o->target_date,
                'assigned_to' => $o->assignedTo?->name,
                'notes'       => $o->notes,
                'created_at'  => $o->created_at,
            ]),
            'meta'    => [
                'total'        => $records->total(),
                'current_page' => $records->currentPage(),
                'last_page'    => $records->lastPage(),
            ],
        ]);
    }

    // ── POST /api/hr/onboarding ──────────────────────────────────────────────

    public function storeOnboarding(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id'     => 'required|exists:users,id',
            'steps'       => 'nullable|array',
            'steps.*.title' => 'required_with:steps|string|max:200',
            'start_date'  => 'nullable|date',
            'target_date' => 'nullable|date|after_or_equal:start_date',
            'assigned_to' => 'nullable|exists:users,id',
            'notes'       => 'nullable|string',
        ]);

        $record = HrOnboarding::create($data + ['status' => 'pending']);

        return response()->json(['success' => true, 'data' => $record], 201);
    }

    // ── PATCH /api/hr/onboarding/{id} ───────────────────────────────────────

    public function updateOnboarding(Request $request, HrOnboarding $hrOnboarding): JsonResponse
    {
        $data = $request->validate([
            'status'      => 'sometimes|in:pending,in_progress,completed',
            'steps'       => 'sometimes|array',
            'target_date' => 'sometimes|nullable|date',
            'notes'       => 'sometimes|nullable|string',
        ]);

        $hrOnboarding->update($data);

        return response()->json(['success' => true, 'data' => $hrOnboarding->fresh()]);
    }

    // ── GET /api/hr/tasks ────────────────────────────────────────────────────

    public function tasks(Request $request): JsonResponse
    {
        $hrDeptIds = Department::where('slug', 'human-resources')->pluck('id');

        $tasks = Task::with(['assignedTo', 'department'])
            ->when(
                $hrDeptIds->isNotEmpty(),
                fn ($q) => $q->whereIn('department_id', $hrDeptIds),
            )
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->priority, fn ($q) => $q->where('priority', $request->priority))
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $tasks->map(fn ($t) => [
                'id'          => $t->id,
                'title'       => $t->title,
                'description' => $t->description,
                'status'      => $t->status,
                'priority'    => $t->priority,
                'assigned_to' => $t->assignedTo?->name,
                'due_date'    => $t->due_date,
                'created_at'  => $t->created_at,
            ]),
            'meta'    => [
                'total'        => $tasks->total(),
                'current_page' => $tasks->currentPage(),
                'last_page'    => $tasks->lastPage(),
            ],
        ]);
    }

    // ── GET /api/hr/interviews ───────────────────────────────────────────────

    public function interviews(Request $request): JsonResponse
    {
        $interviews = HrInterview::with(['application', 'interviewer'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->upcoming, fn ($q) => $q->where('scheduled_at', '>=', now()))
            ->orderBy('scheduled_at')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $interviews->map(fn ($i) => [
                'id'              => $i->id,
                'candidate_name'  => $i->candidate_name,
                'candidate_email' => $i->candidate_email,
                'position'        => $i->position,
                'type'            => $i->type,
                'status'          => $i->status,
                'scheduled_at'    => $i->scheduled_at,
                'duration_minutes'=> $i->duration_minutes,
                'interviewer'     => $i->interviewer?->name,
                'location'        => $i->location,
                'notes'           => $i->notes,
            ]),
            'meta'    => [
                'total'        => $interviews->total(),
                'current_page' => $interviews->currentPage(),
                'last_page'    => $interviews->lastPage(),
            ],
        ]);
    }

    // ── POST /api/hr/interviews ──────────────────────────────────────────────

    public function storeInterview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'application_id'   => 'nullable|exists:hr_applications,id',
            'candidate_name'   => 'required|string|max:200',
            'candidate_email'  => 'required|email|max:200',
            'position'         => 'required|string|max:200',
            'type'             => 'required|in:online,in_person,phone',
            'scheduled_at'     => 'required|date|after:now',
            'duration_minutes' => 'nullable|integer|min:15|max:480',
            'interviewer_id'   => 'nullable|exists:users,id',
            'location'         => 'nullable|string|max:500',
            'notes'            => 'nullable|string',
        ]);

        $interview = HrInterview::create($data + ['status' => 'scheduled']);

        return response()->json(['success' => true, 'data' => $interview], 201);
    }

    // ── GET /api/hr/documents ────────────────────────────────────────────────

    public function documents(Request $request): JsonResponse
    {
        $hrDeptIds = Department::where('slug', 'human-resources')->pluck('id');

        $docs = \App\Models\Document::with(['uploadedBy'])
            ->when(
                $hrDeptIds->isNotEmpty(),
                fn ($q) => $q->whereIn('department_id', $hrDeptIds),
                fn ($q) => $q->whereNull('department_id'),
            )
            ->when($request->search, function ($q) use ($request) {
                $q->where('title', 'like', "%{$request->search}%");
            })
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $docs->map(fn ($d) => [
                'id'          => $d->id,
                'title'       => $d->title,
                'file_type'   => $d->file_type,
                'file_size'   => $d->file_size,
                'uploaded_by' => $d->uploadedBy?->name,
                'created_at'  => $d->created_at,
            ]),
            'meta'    => [
                'total'        => $docs->total(),
                'current_page' => $docs->currentPage(),
                'last_page'    => $docs->lastPage(),
            ],
        ]);
    }
}
