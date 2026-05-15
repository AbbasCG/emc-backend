<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Department;
use App\Models\Instructor;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\Registration;
use App\Models\Report;
use App\Models\Track;
use App\Models\User;
use App\Models\VolunteerProfile;
use App\Models\Workshop;
use Illuminate\Support\Facades\DB;

class ReportGeneratorService
{
    public function generate(string $type, array $params, int $userId): Report
    {
        $data    = $this->buildData($type, $params);
        $summary = $this->buildSummary($type, $data);

        return Report::create([
            'title'            => $this->buildTitle($type, $params),
            'type'             => $type,
            'reportable_type'  => $params['reportable_type'] ?? null,
            'reportable_id'    => $params['reportable_id'] ?? null,
            'generated_by'     => $userId,
            'data'             => $data,
            'summary'          => $summary,
            'status'           => 'generated',
            'generated_at'     => now(),
        ]);
    }

    private function buildTitle(string $type, array $params): string
    {
        $titles = [
            'financial_report'    => 'Financial Report',
            'course_report'       => 'Course Report',
            'workshop_report'     => 'Workshop Report',
            'program_report'      => 'Program Report',
            'quality_report'      => 'Quality Report',
            'department_report'   => 'Department Report',
            'partnership_report'  => 'Partnership Report',
            'hr_report'           => 'HR & Volunteers Report',
        ];

        $title = $titles[$type] ?? ucfirst(str_replace('_', ' ', $type));
        $title .= ' — ' . now()->format('Y-m-d');

        return $title;
    }

    private function buildData(string $type, array $params): array
    {
        return match ($type) {
            'financial_report'   => $this->financialData($params),
            'course_report'      => $this->courseData($params),
            'workshop_report'    => $this->workshopData($params),
            'program_report'     => $this->programData($params),
            'department_report'  => $this->departmentData($params),
            'partnership_report' => $this->partnershipData($params),
            'hr_report'          => $this->hrData($params),
            default              => ['params' => $params, 'note' => 'Generic report'],
        };
    }

    private function buildSummary(string $type, array $data): string
    {
        return match ($type) {
            'financial_report'  => sprintf(
                'Total confirmed revenue: %s EUR. Total registrations: %s. Pending payments: %s.',
                $data['confirmed_revenue'] ?? 0,
                $data['total_registrations'] ?? 0,
                $data['pending_payments'] ?? 0
            ),
            'hr_report'         => sprintf(
                'Total volunteers: %s. Active: %s. Total hours logged: %s.',
                $data['total_volunteers'] ?? 0,
                $data['active_volunteers'] ?? 0,
                $data['total_hours_logged'] ?? 0
            ),
            default             => 'Report generated on ' . now()->toDateTimeString(),
        };
    }

    private function financialData(array $params): array
    {
        $from = $params['from'] ?? now()->startOfMonth()->toDateString();
        $to   = $params['to'] ?? now()->toDateString();

        $confirmedRevenue = Payment::where('status', 'Confirmed')
            ->whereDate('confirmed_at', '>=', $from)
            ->whereDate('confirmed_at', '<=', $to)
            ->sum('amount');

        $pendingPayments = Payment::where('status', 'Pending Payment')->count();

        $byCourse = DB::table('payments')
            ->join('registrations', 'payments.registration_id', '=', 'registrations.id')
            ->join('courses', 'registrations.course_id', '=', 'courses.id')
            ->where('payments.status', 'Confirmed')
            ->whereBetween(DB::raw('DATE(payments.confirmed_at)'), [$from, $to])
            ->select('courses.title', DB::raw('SUM(payments.amount) as revenue'), DB::raw('COUNT(*) as count'))
            ->groupBy('courses.id', 'courses.title')
            ->orderByDesc('revenue')
            ->get();

        $totalRegistrations = Registration::whereBetween(DB::raw('DATE(created_at)'), [$from, $to])->count();

        return compact('from', 'to', 'confirmedRevenue', 'pendingPayments', 'byCourse', 'totalRegistrations');
    }

    private function courseData(array $params): array
    {
        $courseId = $params['reportable_id'] ?? null;
        $query    = Course::with(['instructor', 'registrations', 'workshops']);

        if ($courseId) {
            $query->where('id', $courseId);
        }

        return $query->get()->map(fn ($c) => [
            'id'                  => $c->id,
            'title'               => $c->title,
            'instructor'          => $c->instructor?->name,
            'total_registrations' => $c->registrations->count(),
            'status'              => $c->status,
        ])->toArray();
    }

    private function workshopData(array $params): array
    {
        $workshopId = $params['reportable_id'] ?? null;
        $query      = Workshop::withCount(['sessions']);

        if ($workshopId) {
            $query->where('id', $workshopId);
        }

        return $query->get()->map(fn ($w) => [
            'id'             => $w->id,
            'title'          => $w->title,
            'sessions_count' => $w->sessions_count,
        ])->toArray();
    }

    private function programData(array $params): array
    {
        return [
            'total_courses'       => Course::count(),
            'total_tracks'        => Track::count(),
            'total_workshops'     => Workshop::count(),
            'total_registrations' => Registration::count(),
            'top_courses'         => DB::table('registrations')
                ->join('courses', 'registrations.course_id', '=', 'courses.id')
                ->select('courses.title', DB::raw('COUNT(*) as registrations'))
                ->groupBy('courses.id', 'courses.title')
                ->orderByDesc('registrations')
                ->limit(5)
                ->get(),
        ];
    }

    private function departmentData(array $params): array
    {
        return Department::withCount(['tasks', 'meetings', 'volunteers'])
            ->get()
            ->map(fn ($d) => [
                'name'           => $d->name,
                'tasks_count'    => $d->tasks_count,
                'meetings_count' => $d->meetings_count,
                'volunteers'     => $d->volunteers_count,
            ])->toArray();
    }

    private function partnershipData(array $params): array
    {
        return [
            'total_partners'  => Partner::count(),
            'active_partners' => Partner::where('status', 'active')->count(),
            'by_type'         => Partner::select('type', DB::raw('COUNT(*) as count'))
                ->groupBy('type')->get(),
            'recent_requests' => \App\Models\PartnershipRequest::latest()->limit(10)->get(),
        ];
    }

    private function hrData(array $params): array
    {
        return [
            'total_volunteers'  => VolunteerProfile::count(),
            'active_volunteers' => VolunteerProfile::where('status', 'active')->count(),
            'total_hours_logged'=> VolunteerProfile::sum('hours_logged'),
            'by_status'         => VolunteerProfile::select('status', DB::raw('COUNT(*) as count'))
                ->groupBy('status')->get(),
            'by_department'     => DB::table('volunteer_profiles')
                ->join('departments', 'volunteer_profiles.department_id', '=', 'departments.id')
                ->select('departments.name', DB::raw('COUNT(*) as count'))
                ->groupBy('departments.id', 'departments.name')
                ->get(),
        ];
    }
}
