<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Attendance;
use App\Models\Course;
use App\Models\Evaluation;
use App\Models\Instructor;
use App\Models\QualityCorrectiveAction;
use App\Models\QualityIncident;
use App\Models\QualityReview;
use App\Models\Registration;
use App\Models\User;
use App\Models\Workshop;
use App\Models\WorkshopRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QualityController extends Controller
{
    public function dashboard(): JsonResponse
    {
        $now = now();

        // ─── Course/Workshop lifecycle ────────────────────────────────────────────
        $activeCourseIds = Course::whereIn('status', ['published', 'active'])
            ->where(function ($q) use ($now) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $now);
            })->pluck('id');

        $endedCourses = Course::with('instructor')
            ->whereIn('status', ['published', 'active'])
            ->whereNotNull('end_date')
            ->where('end_date', '<', $now)
            ->orderByDesc('end_date')
            ->get();

        $activeWorkshopIds = Workshop::where(function ($q) use ($now) {
            $q->whereNull('end_date')->orWhere('end_date', '>=', $now);
        })->pluck('id');

        $endedWorkshops = Workshop::with('instructor')
            ->whereNotNull('end_date')
            ->where('end_date', '<', $now)
            ->orderByDesc('end_date')
            ->get();

        $activeCount    = $activeCourseIds->count() + $activeWorkshopIds->count();
        $completedCount = $endedCourses->count() + $endedWorkshops->count();

        // ─── Evaluation readiness ─────────────────────────────────────────────────
        $reviewedCourseIds   = QualityReview::where('reviewable_type', Course::class)->pluck('reviewable_id');
        $reviewedWorkshopIds = QualityReview::where('reviewable_type', Workshop::class)->pluck('reviewable_id');

        $pendingEvalCourses   = $endedCourses->whereNotIn('id', $reviewedCourseIds->toArray());
        $pendingEvalWorkshops = $endedWorkshops->whereNotIn('id', $reviewedWorkshopIds->toArray());
        $pendingEvaluations   = $pendingEvalCourses->count() + $pendingEvalWorkshops->count();

        // ─── Quality score ────────────────────────────────────────────────────────
        $qualityAvgScore = QualityReview::whereNotNull('overall_score')->avg('overall_score');
        $qualityScore    = $qualityAvgScore ? round($qualityAvgScore * 10, 1) : 0;

        // ─── Attendance & participants ────────────────────────────────────────────
        $totalEnrolled  = Registration::whereIn('status', ['confirmed', 'active', 'attended', 'Confirmed', 'Attended'])->count();
        $totalAttended  = Attendance::whereIn('status', ['present', 'attended'])->count();
        $attendanceRate = $totalEnrolled > 0 ? round(($totalAttended / $totalEnrolled) * 100, 1) : 0;

        // ─── Ratings ─────────────────────────────────────────────────────────────
        $avgRating        = Evaluation::whereNotNull('rating')->avg('rating') ?? 0;
        $totalEvaluations = Evaluation::count();

        // ─── Critical counts ─────────────────────────────────────────────────────
        $criticalIncidents       = QualityIncident::where('severity', 'critical')->where('status', 'open')->count();
        $openIncidents           = QualityIncident::where('status', 'open')->count();
        $overdueCorrectiveActions = QualityCorrectiveAction::whereIn('status', ['open', 'in_progress'])
            ->whereNotNull('due_date')->whereDate('due_date', '<', $now)->count();
        $pendingWorkshopRequests = WorkshopRequest::whereIn('status', ['pending', 'under_review'])->count();
        $pendingReviews          = QualityReview::where('status', 'submitted')->count();

        // ─── Build alerts ─────────────────────────────────────────────────────────
        $alerts = [];
        if ($criticalIncidents > 0) {
            $alerts[] = ['type' => 'critical', 'severity' => 'critical', 'title' => 'حوادث حرجة مفتوحة', 'message' => "يوجد {$criticalIncidents} حادثة حرجة تحتاج متابعة فورية", 'count' => $criticalIncidents];
        }
        if ($pendingEvaluations > 0) {
            $alerts[] = ['type' => 'warning', 'severity' => 'high', 'title' => 'برامج بانتظار التقييم', 'message' => "{$pendingEvaluations} برنامج مكتمل لم يُقيَّم بعد", 'count' => $pendingEvaluations];
        }
        if ($attendanceRate > 0 && $attendanceRate < 70) {
            $alerts[] = ['type' => 'warning', 'severity' => 'medium', 'title' => 'نسبة حضور منخفضة', 'message' => "نسبة الحضور الإجمالية {$attendanceRate}% أقل من الحد المقبول 70%"];
        }
        if ($avgRating > 0 && $avgRating < 3) {
            $alerts[] = ['type' => 'warning', 'severity' => 'medium', 'title' => 'تقييمات منخفضة', 'message' => "متوسط التقييم " . round($avgRating, 1) . "/5 أقل من الحد المقبول 3/5"];
        }
        if ($overdueCorrectiveActions > 0) {
            $alerts[] = ['type' => 'warning', 'severity' => 'medium', 'title' => 'إجراءات تصحيحية متأخرة', 'message' => "{$overdueCorrectiveActions} إجراء تصحيحي متأخر عن موعده", 'count' => $overdueCorrectiveActions];
        }
        if ($pendingWorkshopRequests > 0) {
            $alerts[] = ['type' => 'info', 'severity' => 'low', 'title' => 'طلبات برامج معلقة', 'message' => "{$pendingWorkshopRequests} طلب برنامج ينتظر مراجعة الجودة", 'count' => $pendingWorkshopRequests];
        }
        if ($pendingReviews > 3) {
            $alerts[] = ['type' => 'info', 'severity' => 'low', 'title' => 'مراجعات معلقة', 'message' => "{$pendingReviews} مراجعة تنتظر الاعتماد", 'count' => $pendingReviews];
        }

        $criticalAlertsCount = collect($alerts)->where('type', 'critical')->count()
            + collect($alerts)->where('severity', 'high')->count();

        // ─── Upcoming program ─────────────────────────────────────────────────────
        $upcomingCourse = Course::with('instructor')
            ->whereIn('status', ['published', 'active'])
            ->whereNotNull('start_date')->where('start_date', '>=', $now)
            ->orderBy('start_date')->first();

        $upcomingWorkshop = Workshop::with('instructor')
            ->whereNotNull('start_date')->where('start_date', '>=', $now)
            ->orderBy('start_date')->first();

        $upcomingData = null;
        if ($upcomingCourse || $upcomingWorkshop) {
            [$upType, $up] = match (true) {
                $upcomingCourse && $upcomingWorkshop => $upcomingCourse->start_date <= $upcomingWorkshop->start_date
                    ? ['course', $upcomingCourse] : ['workshop', $upcomingWorkshop],
                (bool) $upcomingCourse  => ['course', $upcomingCourse],
                default                 => ['workshop', $upcomingWorkshop],
            };
            $upParticipants = $upType === 'course'
                ? Registration::where('course_id', $up->id)->whereIn('status', ['confirmed', 'active', 'Confirmed'])->count()
                : Registration::where('workshop_id', $up->id)->whereIn('status', ['confirmed', 'active', 'Confirmed'])->count();

            $upcomingData = [
                'id'                 => $up->id,
                'type'               => $upType,
                'title'              => $up->title,
                'instructor'         => $up->instructor ? ['name' => $up->instructor->name, 'title' => $up->instructor->title ?? null] : null,
                'starts_at'          => $up->start_date,
                'ends_at'            => $up->end_date,
                'location_type'      => $up->location_type ?? null,
                'location'           => $up->location ?? null,
                'meeting_url_exists' => !empty($up->meeting_link ?? null),
                'participants_count' => $upParticipants,
                'checklist_status'   => 'pending',
            ];
        }

        // ─── Latest completed program ─────────────────────────────────────────────
        $latestEndedCourse   = $endedCourses->first();
        $latestEndedWorkshop = $endedWorkshops->first();

        $latestCompletedData = null;
        if ($latestEndedCourse || $latestEndedWorkshop) {
            [$lcType, $lc] = match (true) {
                $latestEndedCourse && $latestEndedWorkshop => $latestEndedCourse->end_date >= $latestEndedWorkshop->end_date
                    ? ['course', $latestEndedCourse] : ['workshop', $latestEndedWorkshop],
                (bool) $latestEndedCourse  => ['course', $latestEndedCourse],
                default                    => ['workshop', $latestEndedWorkshop],
            };

            $lcParticipants = $lcType === 'course'
                ? Registration::where('course_id', $lc->id)->whereIn('status', ['confirmed', 'active', 'attended', 'Confirmed', 'Attended'])->count()
                : Registration::where('workshop_id', $lc->id)->whereIn('status', ['confirmed', 'active', 'attended', 'Confirmed', 'Attended'])->count();

            $lcEvals = $lcType === 'course'
                ? Evaluation::where('course_id', $lc->id) : Evaluation::where('workshop_id', $lc->id);
            $lcEvalCount  = $lcEvals->count();
            $lcAvgRating  = $lcEvals->avg('rating');

            $lcReview = $lcType === 'course'
                ? QualityReview::where('reviewable_type', Course::class)->where('reviewable_id', $lc->id)->latest()->first()
                : QualityReview::where('reviewable_type', Workshop::class)->where('reviewable_id', $lc->id)->latest()->first();

            $latestCompletedData = [
                'id'               => $lc->id,
                'type'             => $lcType,
                'title'            => $lc->title,
                'instructor'       => $lc->instructor ? ['name' => $lc->instructor->name] : null,
                'completed_at'     => $lc->end_date,
                'participants_count' => $lcParticipants,
                'attended_count'   => 0,
                'attendance_rate'  => 0,
                'evaluations_count' => $lcEvalCount,
                'average_rating'   => $lcAvgRating ? round($lcAvgRating, 2) : null,
                'quality_status'   => $lcReview ? $lcReview->status : 'not_reviewed',
            ];
        }

        // ─── Evaluation readiness list ────────────────────────────────────────────
        $evalReadiness = [];
        foreach ($pendingEvalCourses->take(8) as $course) {
            $eCount = Evaluation::where('course_id', $course->id)->count();
            $eAvg   = Evaluation::where('course_id', $course->id)->avg('rating');
            $pCount = Registration::where('course_id', $course->id)
                ->whereIn('status', ['confirmed', 'active', 'attended', 'Confirmed', 'Attended'])->count();
            $evalReadiness[] = [
                'id'               => $course->id,
                'type'             => 'course',
                'title'            => $course->title,
                'completed_at'     => $course->end_date,
                'participants_count' => $pCount,
                'evaluations_count' => $eCount,
                'average_rating'   => $eAvg ? round($eAvg, 2) : null,
                'reason'           => $eCount === 0 ? 'no_evaluation' : ($eAvg < 3 ? 'low_rating' : 'no_review'),
                'instructor'       => $course->instructor ? $course->instructor->name : null,
            ];
        }
        foreach ($pendingEvalWorkshops->take(4) as $ws) {
            $eCount = Evaluation::where('workshop_id', $ws->id)->count();
            $eAvg   = Evaluation::where('workshop_id', $ws->id)->avg('rating');
            $pCount = Registration::where('workshop_id', $ws->id)
                ->whereIn('status', ['confirmed', 'active', 'attended', 'Confirmed', 'Attended'])->count();
            $evalReadiness[] = [
                'id'               => $ws->id,
                'type'             => 'workshop',
                'title'            => $ws->title,
                'completed_at'     => $ws->end_date,
                'participants_count' => $pCount,
                'evaluations_count' => $eCount,
                'average_rating'   => $eAvg ? round($eAvg, 2) : null,
                'reason'           => $eCount === 0 ? 'no_evaluation' : 'no_review',
                'instructor'       => $ws->instructor ? $ws->instructor->name : null,
            ];
        }

        // ─── Instructor quality ────────────────────────────────────────────────────
        $instructors = Instructor::orderByDesc('id')->limit(8)->get();
        $instructorQuality = $instructors->map(function ($inst) use ($now) {
            $activeCourses    = Course::where('instructor_id', $inst->id)
                ->whereIn('status', ['published', 'active'])
                ->where(function ($q) use ($now) { $q->whereNull('end_date')->orWhere('end_date', '>=', $now); })
                ->count();
            $completedCourses = Course::where('instructor_id', $inst->id)
                ->whereNotNull('end_date')->where('end_date', '<', $now)->count();
            $iAvgRating       = Evaluation::where('instructor_id', $inst->id)->avg('rating');
            $iEvalCount       = Evaluation::where('instructor_id', $inst->id)->count();
            return [
                'id'               => $inst->id,
                'name'             => $inst->name,
                'title'            => $inst->title,
                'image'            => $inst->image ? asset('storage/' . $inst->image) : null,
                'active_courses'   => $activeCourses,
                'completed_courses' => $completedCourses,
                'average_rating'   => $iAvgRating ? round($iAvgRating, 2) : null,
                'evaluation_count' => $iEvalCount,
                'pending_issues'   => 0,
            ];
        })->filter(fn ($i) => $i['active_courses'] + $i['completed_courses'] > 0)->values();

        // ─── Rating analytics ─────────────────────────────────────────────────────
        $ratingDistribution = Evaluation::whereNotNull('rating')
            ->select(DB::raw('ROUND(rating) as score'), DB::raw('COUNT(*) as count'))
            ->groupBy('score')->orderBy('score')->get()
            ->pluck('count', 'score');

        $latestComments = Evaluation::whereNotNull('comment')
            ->where('comment', '!=', '')
            ->latest()->limit(5)
            ->get(['id', 'rating', 'comment', 'created_at']);

        // ─── Recent activities ────────────────────────────────────────────────────
        $recentActivities = AuditLog::with('user')->latest()->limit(10)->get()
            ->map(fn ($l) => [
                'id'          => $l->id,
                'action'      => $l->action,
                'entity_type' => $l->entity_type ?? null,
                'description' => $l->description ?? $l->action,
                'created_at'  => $l->created_at,
                'user'        => $l->user ? ['name' => $l->user->name] : null,
            ]);

        // ─── Monthly trend ────────────────────────────────────────────────────────
        $monthlyTrend = QualityReview::select(
            DB::raw("DATE_FORMAT(created_at, '%Y-%m') as month"),
            DB::raw('COUNT(*) as count'),
            DB::raw('AVG(overall_score) as avg_score')
        )->whereDate('created_at', '>=', $now->copy()->subMonths(6))
            ->groupBy('month')->orderBy('month')->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'stats' => [
                    'quality_score'       => $qualityScore,
                    'active_programs'     => $activeCount,
                    'completed_programs'  => $completedCount,
                    'pending_evaluations' => $pendingEvaluations,
                    'critical_alerts'     => $criticalAlertsCount,
                    'total_participants'  => $totalEnrolled,
                    'total_attendance'    => $totalAttended,
                    'attendance_rate'     => $attendanceRate,
                    'average_rating'      => round($avgRating, 2),
                    'total_evaluations'   => $totalEvaluations,
                    'open_incidents'      => $openIncidents,
                ],
                'upcoming_program'          => $upcomingData,
                'latest_completed_program'  => $latestCompletedData,
                'evaluation_readiness'      => $evalReadiness,
                'instructor_quality'        => $instructorQuality,
                'rating_analytics'          => [
                    'average'       => round($avgRating, 2),
                    'total_count'   => $totalEvaluations,
                    'distribution'  => $ratingDistribution,
                    'latest_comments' => $latestComments,
                ],
                'monthly_trend'    => $monthlyTrend,
                'alerts'           => $alerts,
                'recent_activities' => $recentActivities,
            ],
        ]);
    }

    public function workshops(Request $request): JsonResponse
    {
        $workshops = WorkshopRequest::when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->search, fn ($q) => $q->where(function ($sub) use ($request) {
                $sub->where('program_name', 'like', "%{$request->search}%")
                    ->orWhere('requester_name', 'like', "%{$request->search}%")
                    ->orWhere('requester_department', 'like', "%{$request->search}%");
            }))
            ->when($request->from, fn ($q) => $q->whereDate('created_at', '>=', $request->from))
            ->when($request->to, fn ($q) => $q->whereDate('created_at', '<=', $request->to))
            ->latest()
            ->paginate(20);

        return response()->json(['success' => true, 'data' => $workshops->through(fn ($w) => [
            'id'                   => $w->id,
            'program_name'         => $w->program_name,
            'requester_name'       => $w->requester_name,
            'requester_email'      => $w->requester_email,
            'requester_department' => $w->requester_department,
            'speaker_name'         => $w->speaker_name,
            'categories'           => $w->categories,
            'status'               => $w->status,
            'price_type'           => $w->price_type,
            'price_amount'         => $w->price_amount,
            'created_at'           => $w->created_at,
            'workflow_status'      => $w->workflow_status ?? null,
        ])]);
    }

    public function incidents(Request $request): JsonResponse
    {
        $incidents = QualityIncident::with(['reporter', 'assignee'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->severity, fn ($q) => $q->where('severity', $request->severity))
            ->when($request->priority, fn ($q) => $q->where('priority', $request->priority))
            ->when($request->search, fn ($q) => $q->where(function ($sub) use ($request) {
                $sub->where('title', 'like', "%{$request->search}%")
                    ->orWhere('description', 'like', "%{$request->search}%");
            }))
            ->latest()
            ->paginate(20);

        return response()->json(['success' => true, 'data' => $incidents]);
    }

    public function storeIncident(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'severity'    => 'nullable|in:low,medium,high,critical',
            'priority'    => 'nullable|in:low,medium,high,urgent',
            'status'      => 'nullable|in:open,in_progress,resolved,closed',
            'category'    => 'nullable|string|max:255',
            'related_type' => 'nullable|string|max:255',
            'related_id'  => 'nullable|integer',
            'evidence'    => 'nullable|string',
            'resolution'  => 'nullable|string',
            'due_date'    => 'nullable|date',
            'assignee_id' => 'nullable|exists:users,id',
        ]);

        $validated['reporter_id'] = auth()->id();

        $incident = QualityIncident::create($validated);

        return response()->json(['success' => true, 'data' => $incident->load(['reporter', 'assignee'])], 201);
    }

    public function updateIncident(Request $request, QualityIncident $qualityIncident): JsonResponse
    {
        $validated = $request->validate([
            'title'       => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'severity'    => 'nullable|in:low,medium,high,critical',
            'priority'    => 'nullable|in:low,medium,high,urgent',
            'status'      => 'nullable|in:open,in_progress,resolved,closed',
            'category'    => 'nullable|string|max:255',
            'related_type' => 'nullable|string|max:255',
            'related_id'  => 'nullable|integer',
            'evidence'    => 'nullable|string',
            'resolution'  => 'nullable|string',
            'due_date'    => 'nullable|date',
            'assignee_id' => 'nullable|exists:users,id',
        ]);

        if (isset($validated['status']) && $validated['status'] === 'resolved' && $qualityIncident->status !== 'resolved') {
            $validated['resolved_at'] = now();
        }

        $qualityIncident->update($validated);

        return response()->json(['success' => true, 'data' => $qualityIncident->fresh(['reporter', 'assignee'])]);
    }

    public function correctiveActions(Request $request): JsonResponse
    {
        $actions = QualityCorrectiveAction::with(['createdBy', 'assignee', 'incident'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->priority, fn ($q) => $q->where('priority', $request->priority))
            ->when($request->search, fn ($q) => $q->where(function ($sub) use ($request) {
                $sub->where('title', 'like', "%{$request->search}%")
                    ->orWhere('description', 'like', "%{$request->search}%");
            }))
            ->latest()
            ->paginate(20);

        return response()->json(['success' => true, 'data' => $actions]);
    }

    public function storeCorrectiveAction(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title'               => 'required|string|max:255',
            'description'         => 'nullable|string',
            'priority'            => 'nullable|in:low,medium,high,urgent',
            'status'              => 'nullable|in:open,in_progress,completed,cancelled',
            'progress'            => 'nullable|integer|min:0|max:100',
            'due_date'            => 'nullable|date',
            'notes'               => 'nullable|string',
            'assignee_id'         => 'nullable|exists:users,id',
            'quality_incident_id' => 'nullable|exists:quality_incidents,id',
            'quality_review_id'   => 'nullable|exists:quality_reviews,id',
        ]);

        $validated['created_by'] = auth()->id();

        $action = QualityCorrectiveAction::create($validated);

        return response()->json(['success' => true, 'data' => $action->load(['createdBy', 'assignee', 'incident'])], 201);
    }

    public function updateCorrectiveAction(Request $request, QualityCorrectiveAction $qualityCorrectiveAction): JsonResponse
    {
        $validated = $request->validate([
            'title'               => 'nullable|string|max:255',
            'description'         => 'nullable|string',
            'priority'            => 'nullable|in:low,medium,high,urgent',
            'status'              => 'nullable|in:open,in_progress,completed,cancelled',
            'progress'            => 'nullable|integer|min:0|max:100',
            'due_date'            => 'nullable|date',
            'notes'               => 'nullable|string',
            'assignee_id'         => 'nullable|exists:users,id',
            'quality_incident_id' => 'nullable|exists:quality_incidents,id',
            'quality_review_id'   => 'nullable|exists:quality_reviews,id',
        ]);

        if (isset($validated['status']) && $validated['status'] === 'completed' && $qualityCorrectiveAction->status !== 'completed') {
            $validated['completed_at'] = now();
        }

        $qualityCorrectiveAction->update($validated);

        return response()->json(['success' => true, 'data' => $qualityCorrectiveAction->fresh(['createdBy', 'assignee', 'incident'])]);
    }

    public function team(): JsonResponse
    {
        $team = User::whereIn('role', ['quality_manager', 'admin', 'super_admin'])
            ->where('is_active', true)
            ->get(['id', 'name', 'email', 'phone', 'role', 'department', 'avatar']);

        $team = $team->map(function ($u) {
            $assignedReviews  = QualityReview::where('reviewer_id', $u->id)->count();
            $completedReviews = QualityReview::where('reviewer_id', $u->id)->where('status', 'approved')->count();
            $pendingReviews   = QualityReview::where('reviewer_id', $u->id)->whereIn('status', ['draft', 'submitted'])->count();

            return array_merge($u->toArray(), [
                'assigned_reviews'  => $assignedReviews,
                'completed_reviews' => $completedReviews,
                'pending_reviews'   => $pendingReviews,
                'avatar_url'        => $u->avatar_url ?? null,
            ]);
        });

        return response()->json(['success' => true, 'data' => $team]);
    }

    public function auditLogs(Request $request): JsonResponse
    {
        $logs = AuditLog::with('user')
            ->when($request->search, fn ($q) => $q->where(function ($sub) use ($request) {
                $sub->where('action', 'like', "%{$request->search}%")
                    ->orWhere('entity_type', 'like', "%{$request->search}%")
                    ->orWhereHas('user', fn ($uq) => $uq->where('name', 'like', "%{$request->search}%"));
            }))
            ->when($request->action, fn ($q) => $q->where('action', $request->action))
            ->when($request->from, fn ($q) => $q->whereDate('created_at', '>=', $request->from))
            ->when($request->to, fn ($q) => $q->whereDate('created_at', '<=', $request->to))
            ->latest()
            ->paginate(30);

        return response()->json(['success' => true, 'data' => $logs->through(fn ($l) => [
            'id'          => $l->id,
            'action'      => $l->action,
            'model_type'  => $l->entity_type,
            'model_id'    => $l->entity_id,
            'description' => $l->description,
            'old_values'  => $l->old_values,
            'new_values'  => $l->new_values,
            'ip_address'  => $l->ip_address,
            'user_agent'  => $l->user_agent,
            'created_at'  => $l->created_at,
            'user'        => $l->user ? ['id' => $l->user->id, 'name' => $l->user->name, 'email' => $l->user->email] : null,
        ])]);
    }

    public function compliance(): JsonResponse
    {
        $totalWorkshops    = WorkshopRequest::count();
        $approvedWorkshops = WorkshopRequest::where('status', 'approved')->count();
        $workshopCompliance = $totalWorkshops > 0 ? round(($approvedWorkshops / $totalWorkshops) * 100, 1) : 0;

        $totalReviews    = QualityReview::count();
        $approvedReviews = QualityReview::where('status', 'approved')->count();
        $reviewCompliance = $totalReviews > 0 ? round(($approvedReviews / $totalReviews) * 100, 1) : 0;

        $overdueCorrectiveActions = QualityCorrectiveAction::whereIn('status', ['open', 'in_progress'])
            ->whereNotNull('due_date')->whereDate('due_date', '<', now())->count();
        $totalCorrectiveActions = QualityCorrectiveAction::count();

        $overallCompliance = collect([$workshopCompliance, $reviewCompliance])->avg();

        return response()->json(['success' => true, 'data' => [
            'overall_compliance'      => round($overallCompliance, 1),
            'workshop_compliance'     => $workshopCompliance,
            'review_compliance'       => $reviewCompliance,
            'pending_actions'         => $overdueCorrectiveActions,
            'total_corrective_actions' => $totalCorrectiveActions,
            'workshops_approved'      => $approvedWorkshops,
            'workshops_total'         => $totalWorkshops,
            'reviews_approved'        => $approvedReviews,
            'reviews_total'           => $totalReviews,
        ]]);
    }
}
