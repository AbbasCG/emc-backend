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
use App\Models\VolunteerRequest;
use App\Models\TeamProfile;
use App\Models\CourseSession;
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

        $hrDeptIds = Department::where('slug', 'human-resources')->pluck('id');

        // Volunteer Requests (public طلب التطوع form submissions)
        $pendingVolunteerRequests  = VolunteerRequest::where('status', 'pending')->count();
        $acceptedVolunteers        = VolunteerRequest::where('status', 'accepted')->count();
        $waitingConversion         = VolunteerRequest::where('status', 'accepted')->whereNull('converted_to_member_at')->count();
        $convertedVolunteers       = VolunteerRequest::where('status', 'converted_to_member')->count();

        // Internal team / members
        $totalMembers  = User::whereIn('role', $staffRoles)->count();
        $activeMembers = User::whereIn('role', $staffRoles)->where('is_active', true)->count();
        $teamProfiles  = TeamProfile::where('is_active', true)->count();

        // Instructors
        $instructorCount = Instructor::count();

        // Tasks
        $openHrTasks = $hrDeptIds->isNotEmpty()
            ? Task::whereIn('department_id', $hrDeptIds)->where('status', '!=', 'done')->count()
            : Task::where('status', '!=', 'done')->whereNotNull('due_date')->where('due_date', '<=', now()->addDays(7))->count();

        // Recent volunteer requests (last 6)
        $recentRequests = VolunteerRequest::with('reviewer')
            ->latest()
            ->limit(6)
            ->get()
            ->map(fn ($r) => [
                'id'                 => $r->id,
                'full_name'          => $r->full_name,
                'email'              => $r->email,
                'desired_department' => $r->desired_department,
                'status'             => $r->status,
                'created_at'         => $r->created_at,
            ]);

        // Accepted volunteers pending conversion (last 5)
        $acceptedList = VolunteerRequest::where('status', 'accepted')
            ->whereNull('converted_to_member_at')
            ->latest('accepted_at')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'id'                 => $r->id,
                'full_name'          => $r->full_name,
                'email'              => $r->email,
                'desired_department' => $r->desired_department,
                'accepted_at'        => $r->accepted_at,
            ]);

        // Recent instructors (last 4)
        $recentInstructors = Instructor::with('user')
            ->latest()
            ->limit(4)
            ->get()
            ->map(fn ($i) => [
                'id'            => $i->id,
                'name'          => $i->name,
                'title'         => $i->title,
                'email'         => $i->user?->email,
                'courses_count' => $i->courses()->count(),
            ]);

        // Department headcount from TeamProfile
        $deptBreakdown = Department::withCount(['teamProfiles as members_count' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($d) => [
                'name'          => $d->name_ar ?? $d->name,
                'members_count' => $d->members_count,
            ])
            ->filter(fn ($d) => $d['members_count'] > 0)
            ->values();

        return response()->json([
            'success' => true,
            'data'    => [
                'stats' => [
                    'total_members'               => $totalMembers,
                    'active_members'              => $activeMembers,
                    'team_members'                => $teamProfiles,
                    'instructors'                 => $instructorCount,
                    'pending_volunteer_requests'  => $pendingVolunteerRequests,
                    'accepted_volunteers'         => $acceptedVolunteers,
                    'volunteers_waiting_conversion' => $waitingConversion,
                    'converted_volunteers'        => $convertedVolunteers,
                    'departments_count'           => Department::where('status', 'active')->count(),
                    'open_hr_tasks'               => $openHrTasks,
                ],
                'recent_volunteer_requests' => $recentRequests,
                'accepted_volunteers'       => $acceptedList,
                'recent_instructors'        => $recentInstructors,
                'department_breakdown'      => $deptBreakdown,
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
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = $request->search;
                $q->where(function ($sub) use ($term) {
                    $sub->where('name', 'like', "%{$term}%")
                        ->orWhere('title', 'like', "%{$term}%")
                        ->orWhereHas('user', fn ($u) => $u->where('email', 'like', "%{$term}%")
                            ->orWhere('phone', 'like', "%{$term}%"));
                });
            })
            ->when($request->filled('status'), function ($q) use ($request) {
                $active = $request->status === 'active';
                $q->whereHas('user', fn ($u) => $u->where('is_active', $active));
            })
            ->latest()
            ->paginate((int) $request->query('per_page', 30));

        $rows = $instructors->map(function ($i) {
            $allCourses    = $i->courses;
            $activeCourses = $allCourses->whereIn('status', ['published', 'active']);
            $user          = $i->user;

            return [
                'id'                   => $i->id,
                'user_id'              => $i->user_id,
                'name'                 => $i->name,
                'email'                => $user?->email,
                'phone'                => $user?->phone,
                'avatar_url'           => $user?->avatar_url,
                'instructor_image_url' => $i->image ? asset('storage/' . $i->image) : null,
                'title'                => $i->title,
                'bio'                  => $i->bio,
                'is_active'            => (bool) ($user?->is_active ?? true),
                'assigned_courses_count' => $allCourses->count(),
                'active_courses_count'   => $activeCourses->count(),
                'workshops_count'        => $i->workshops()->count(),
                'expertise'              => [],
                'created_at'             => $i->created_at,
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => $rows,
            'meta'    => [
                'total'        => $instructors->total(),
                'current_page' => $instructors->currentPage(),
                'last_page'    => $instructors->lastPage(),
            ],
        ]);
    }

    // ── GET /api/hr/instructors/{id} ─────────────────────────────────────────

    public function instructorShow(Instructor $instructor): JsonResponse
    {
        $instructor->loadMissing(['user', 'courses', 'workshops']);

        $allCourses    = $instructor->courses;
        $activeCourses = $allCourses->whereIn('status', ['published', 'active']);
        $user          = $instructor->user;

        $studentsCount = \App\Models\Registration::whereIn('course_id', $allCourses->pluck('id'))
            ->where('status', '!=', 'cancelled')
            ->distinct('email')
            ->count();

        return response()->json([
            'success' => true,
            'data'    => [
                'id'                     => $instructor->id,
                'user_id'                => $instructor->user_id,
                'name'                   => $instructor->name,
                'email'                  => $user?->email,
                'phone'                  => $user?->phone,
                'avatar_url'             => $user?->avatar_url,
                'instructor_image_url'   => $instructor->image ? asset('storage/' . $instructor->image) : null,
                'title'                  => $instructor->title,
                'bio'                    => $instructor->bio,
                'is_active'              => (bool) ($user?->is_active ?? true),
                'assigned_courses_count' => $allCourses->count(),
                'active_courses_count'   => $activeCourses->count(),
                'workshops_count'        => $instructor->workshops->count(),
                'students_count'         => $studentsCount,
                'expertise'              => [],
                'courses'                => $allCourses->map(fn ($c) => [
                    'id'     => $c->id,
                    'title'  => $c->title,
                    'status' => $c->status,
                    'slug'   => $c->slug,
                ]),
                'created_at'             => $instructor->created_at,
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
