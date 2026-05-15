<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Course;
use App\Models\Department;
use App\Models\Evaluation;
use App\Models\FinancialTransaction;
use App\Models\Instructor;
use App\Models\LmsSession;
use App\Models\MarketingItem;
use App\Models\Meeting;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\Registration;
use App\Models\SupportTicket;
use App\Models\Task;
use App\Models\Track;
use App\Models\User;
use App\Models\VolunteerProfile;
use App\Models\Workshop;
use Illuminate\Support\Facades\DB;

class KpiService
{
    public function overview(): array
    {
        $totalStudents      = User::where('role', 'student')->count();
        $totalInstructors   = Instructor::count();
        $totalVolunteers    = VolunteerProfile::whereIn('status', ['active', 'accepted'])->count();
        $totalWorkshops     = Workshop::count();
        $totalCourses       = Course::count();
        $totalTracks        = Track::count();
        $totalRegistrations = Registration::count();

        // Attendance rate: present / total attendance records
        $totalAttendance   = Attendance::count();
        $presentAttendance = Attendance::where('status', 'present')->count();
        $attendanceRate    = $totalAttendance > 0
            ? round(($presentAttendance / $totalAttendance) * 100, 1)
            : 0;

        // Completion rate: registrations with status 'registered' / total
        $completedRegs  = Registration::where('status', 'registered')->count();
        $completionRate = $totalRegistrations > 0
            ? round(($completedRegs / $totalRegistrations) * 100, 1)
            : 0;

        // Student satisfaction: average rating from evaluations
        $studentSatisfaction = round((float) Evaluation::avg('rating') ?? 0, 1);

        $activePartnerships = Partner::where('status', 'active')->count();
        $openTasks          = Task::whereNotIn('status', ['completed', 'cancelled'])->count();
        $overdueTasks       = Task::whereNotNull('due_date')
            ->where('due_date', '<', now())
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->count();

        $openTickets = SupportTicket::whereNotIn('status', ['resolved', 'closed'])->count();

        return compact(
            'totalStudents', 'totalInstructors', 'totalVolunteers',
            'totalWorkshops', 'totalCourses', 'totalTracks',
            'totalRegistrations', 'attendanceRate', 'completionRate',
            'studentSatisfaction', 'activePartnerships',
            'openTasks', 'overdueTasks', 'openTickets'
        );
    }

    public function education(): array
    {
        $activeCourses = Course::where('status', 'active')->count();
        $activeTracks  = Track::where('status', 'active')->count();

        $now = now()->toDateString();
        $upcomingSessions  = LmsSession::where('status', 'scheduled')
            ->where('session_date', '>=', $now)->count();
        $completedSessions = LmsSession::where('status', 'completed')->count();

        $avgAttendance = 0;
        $totalSessions = LmsSession::where('status', 'completed')->count();
        if ($totalSessions > 0) {
            $totalPresent = Attendance::where('status', 'present')->count();
            $totalRecords = Attendance::count();
            $avgAttendance = $totalRecords > 0
                ? round(($totalPresent / $totalRecords) * 100, 1)
                : 0;
        }

        $totalSubmissions  = \App\Models\AssignmentSubmission::count();
        $totalAssignments  = \App\Models\Assignment::count();
        $enrolledCount     = Registration::count();
        $assignmentCompletion = ($totalAssignments > 0 && $enrolledCount > 0)
            ? round(($totalSubmissions / ($totalAssignments * max($enrolledCount, 1))) * 100, 1)
            : 0;

        $recentCompletions = \App\Models\StudentProgress::where('status', 'completed')
            ->where('updated_at', '>=', now()->subDays(30))->count();

        return compact(
            'activeCourses', 'activeTracks', 'upcomingSessions',
            'completedSessions', 'avgAttendance', 'assignmentCompletion',
            'recentCompletions'
        );
    }

    public function finance(): array
    {
        // Sum from financial_transactions if populated, else fall back to payments
        $totalRevenue = FinancialTransaction::where('type', 'revenue')
            ->where('status', 'confirmed')->sum('amount');

        $confirmedRevenue = Payment::where('status', 'Confirmed')->sum('amount');
        $pendingRevenue   = Payment::where('status', 'Pending Payment')->sum('amount');
        $failedPayments   = Payment::where('status', 'Failed')->count();

        $revenueByCourse = DB::table('payments')
            ->join('registrations', 'payments.registration_id', '=', 'registrations.id')
            ->join('courses', 'registrations.course_id', '=', 'courses.id')
            ->where('payments.status', 'Confirmed')
            ->select('courses.title', DB::raw('SUM(payments.amount) as revenue'), DB::raw('COUNT(payments.id) as payments_count'))
            ->groupBy('courses.id', 'courses.title')
            ->orderByDesc('revenue')
            ->limit(10)
            ->get();

        $monthlyRevenue = DB::table('payments')
            ->where('status', 'Confirmed')
            ->whereNotNull('confirmed_at')
            ->select(
                DB::raw("DATE_FORMAT(confirmed_at, '%Y-%m') as month"),
                DB::raw('SUM(amount) as revenue'),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('month')
            ->orderBy('month', 'desc')
            ->limit(12)
            ->get();

        $revenueByTrack = DB::table('payments')
            ->join('registrations', 'payments.registration_id', '=', 'registrations.id')
            ->join('tracks', 'registrations.track_id', '=', 'tracks.id')
            ->where('payments.status', 'Confirmed')
            ->select('tracks.name', DB::raw('SUM(payments.amount) as revenue'))
            ->groupBy('tracks.id', 'tracks.name')
            ->orderByDesc('revenue')
            ->get();

        return compact(
            'totalRevenue', 'confirmedRevenue', 'pendingRevenue',
            'failedPayments', 'revenueByCourse', 'revenueByTrack', 'monthlyRevenue'
        );
    }

    public function departments(): array
    {
        $departments = Department::withCount([
            'tasks',
            'meetings',
            'volunteers',
        ])->with('leader:id,name')->orderBy('sort_order')->get();

        $tasksByDept = Task::select('department_id', DB::raw('COUNT(*) as total'))
            ->whereNotNull('department_id')
            ->groupBy('department_id')
            ->pluck('total', 'department_id');

        $overdueTasks = Task::select('department_id', DB::raw('COUNT(*) as total'))
            ->whereNotNull('department_id')
            ->whereNotNull('due_date')
            ->where('due_date', '<', now())
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->groupBy('department_id')
            ->pluck('total', 'department_id');

        $meetingsByDept = Meeting::select('department_id', DB::raw('COUNT(*) as total'))
            ->whereNotNull('department_id')
            ->groupBy('department_id')
            ->pluck('total', 'department_id');

        return [
            'departments'       => $departments->map(fn ($d) => [
                'id'               => $d->id,
                'name'             => $d->name,
                'slug'             => $d->slug,
                'leader'           => $d->leader?->name,
                'total_tasks'      => $tasksByDept[$d->id] ?? 0,
                'overdue_tasks'    => $overdueTasks[$d->id] ?? 0,
                'total_meetings'   => $meetingsByDept[$d->id] ?? 0,
                'total_volunteers' => $d->volunteers_count,
            ]),
        ];
    }

    public function marketing(): array
    {
        $total     = MarketingItem::count();
        $published = MarketingItem::where('status', 'published')->count();
        $scheduled = MarketingItem::where('status', 'scheduled')->count();
        $inReview  = MarketingItem::where('status', 'review')->count();

        $byPlatform = MarketingItem::select('platform', DB::raw('COUNT(*) as count'))
            ->groupBy('platform')
            ->orderByDesc('count')
            ->get();

        $byStatus = MarketingItem::select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->get();

        return compact('total', 'published', 'scheduled', 'inReview', 'byPlatform', 'byStatus');
    }

    public function partnerships(): array
    {
        $total           = Partner::count();
        $active          = Partner::where('status', 'active')->count();
        $pendingRequests = \App\Models\PartnershipRequest::where('status', 'new')->count();
        $underReview     = \App\Models\PartnershipRequest::where('status', 'under_review')->count();
        $approved        = \App\Models\PartnershipRequest::where('status', 'approved')->count();

        $byType = Partner::select('type', DB::raw('COUNT(*) as count'))
            ->groupBy('type')
            ->orderByDesc('count')
            ->get();

        return compact('total', 'active', 'pendingRequests', 'underReview', 'approved', 'byType');
    }

    public function hr(): array
    {
        $total              = VolunteerProfile::count();
        $applicants         = VolunteerProfile::where('status', 'applicant')->count();
        $underReview        = VolunteerProfile::where('status', 'under_review')->count();
        $activeVolunteers   = VolunteerProfile::where('status', 'active')->count();
        $inactiveVolunteers = VolunteerProfile::where('status', 'inactive')->count();

        $byDepartment = VolunteerProfile::select('department_id', DB::raw('COUNT(*) as count'))
            ->whereNotNull('department_id')
            ->groupBy('department_id')
            ->with('department:id,name')
            ->get()
            ->map(fn ($v) => [
                'department' => $v->department?->name ?? 'Unknown',
                'count'      => $v->count,
            ]);

        $totalHoursLogged = VolunteerProfile::sum('hours_logged');

        $openTickets   = SupportTicket::whereNotIn('status', ['resolved', 'closed'])->count();
        $resolvedThisMonth = SupportTicket::where('status', 'resolved')
            ->where('updated_at', '>=', now()->startOfMonth())
            ->count();

        return compact(
            'total', 'applicants', 'underReview', 'activeVolunteers', 'inactiveVolunteers',
            'byDepartment', 'totalHoursLogged', 'openTickets', 'resolvedThisMonth'
        );
    }
}
