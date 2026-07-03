<?php

namespace App\Services\Certificate;

use App\Models\ActivityLog;
use App\Models\Certificate;
use App\Models\CertificateGenerationBatch;
use App\Models\CertificateTemplate;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CertificateAnalyticsService
{
    // ── Date resolution ───────────────────────────────────────────────────────

    public function resolveDateRange(array $filters): array
    {
        $preset = $filters['preset'] ?? 'last_30_days';

        if (! empty($filters['date_from']) || ! empty($filters['date_to'])) {
            return [
                'from' => $filters['date_from'] ? Carbon::parse($filters['date_from'])->startOfDay() : null,
                'to'   => $filters['date_to']   ? Carbon::parse($filters['date_to'])->endOfDay()   : null,
            ];
        }

        return match ($preset) {
            'today'        => ['from' => now()->startOfDay(),          'to' => now()->endOfDay()],
            'last_7_days'  => ['from' => now()->subDays(6)->startOfDay(), 'to' => now()->endOfDay()],
            'this_month'   => ['from' => now()->startOfMonth(),        'to' => now()->endOfMonth()],
            'last_month'   => ['from' => now()->subMonth()->startOfMonth(), 'to' => now()->subMonth()->endOfMonth()],
            'this_year'    => ['from' => now()->startOfYear(),         'to' => now()->endOfYear()],
            'all_time'     => ['from' => null,                         'to' => null],
            default        => ['from' => now()->subDays(29)->startOfDay(), 'to' => now()->endOfDay()],
        };
    }

    private function baseQuery(array $filters): \Illuminate\Database\Eloquent\Builder
    {
        $range = $this->resolveDateRange($filters);

        return Certificate::query()
            ->when($range['from'], fn ($q) => $q->where('created_at', '>=', $range['from']))
            ->when($range['to'],   fn ($q) => $q->where('created_at', '<=', $range['to']))
            ->when($filters['status'] ?? null,            fn ($q, $v) => $q->where('status', $v))
            ->when($filters['approval_status'] ?? null,   fn ($q, $v) => $q->where('approval_status', $v))
            ->when($filters['certificate_type'] ?? null,  fn ($q, $v) => $q->where('certificate_type', $v))
            ->when($filters['template_id'] ?? null,       fn ($q, $v) => $q->where('certificate_template_id', $v))
            ->when($filters['course_id'] ?? null,         fn ($q, $v) => $q->where('course_id', $v))
            ->when($filters['workshop_id'] ?? null,       fn ($q, $v) => $q->where('workshop_id', $v))
            ->when($filters['learning_path_id'] ?? null,  fn ($q, $v) => $q->where('learning_path_id', $v))
            ->when($filters['issued_by'] ?? null,         fn ($q, $v) => $q->where('issued_by', $v))
            ->when($filters['student_id'] ?? null,        fn ($q, $v) => $q->where('user_id', $v));
    }

    private function cacheKey(string $section, array $filters): string
    {
        // Sort keys so different insertion orders produce the same cache key.
        ksort($filters);
        return 'cert_analytics.' . $section . '.' . md5(json_encode($filters, JSON_SORT_KEYS));
    }

    // ── Part 1: Overview ─────────────────────────────────────────────────────

    public function getOverviewStats(array $filters = []): array
    {
        $ttl = 300;

        return Cache::remember($this->cacheKey('overview', $filters), $ttl, function () use ($filters) {
            $base = $this->baseQuery($filters);

            $counts = (clone $base)
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(status = 'issued') as issued,
                    SUM(status IN ('pending','pending_approval','pending_generation')) as pending,
                    SUM(status = 'approved') as approved,
                    SUM(status = 'rejected') as rejected,
                    SUM(status = 'revoked') as revoked,
                    SUM(status = 'generation_failed') as generation_failed,
                    SUM(pdf_generation_status = 'ready') as pdf_ready,
                    SUM(pdf_generation_status = 'failed') as pdf_failed,
                    SUM(pdf_generation_status = 'generating') as pdf_generating,
                    SUM(pdf_generation_status = 'pending') as pdf_pending
                ")
                ->first();

            // This-month/last-month totals for trend comparison
            $thisMonth = Certificate::whereYear('created_at', now()->year)
                ->whereMonth('created_at', now()->month)
                ->count();

            $lastMonth = Certificate::whereYear('created_at', now()->subMonth()->year)
                ->whereMonth('created_at', now()->subMonth()->month)
                ->count();

            // Average approval time: issued_at - created_at for issued certs (in hours)
            $avgApprovalHours = (clone $base)
                ->where('status', 'issued')
                ->whereNotNull('issued_at')
                ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, created_at, issued_at)) as avg_hours')
                ->value('avg_hours');

            // Average PDF generation time (pdf_generated_at - created_at for ready PDFs)
            $avgPdfHours = (clone $base)
                ->where('pdf_generation_status', 'ready')
                ->whereNotNull('pdf_generated_at')
                ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, created_at, pdf_generated_at)) as avg_minutes')
                ->value('avg_minutes');

            // Downloads this period
            $range = $this->resolveDateRange($filters);
            $downloadsThisPeriod = ActivityLog::where('action', 'certificate.downloaded')
                ->where('entity_type', Certificate::class)
                ->when($range['from'], fn ($q) => $q->where('created_at', '>=', $range['from']))
                ->when($range['to'],   fn ($q) => $q->where('created_at', '<=', $range['to']))
                ->count();

            // Batch success rate
            $batchTotal     = CertificateGenerationBatch::count();
            $batchCompleted = CertificateGenerationBatch::where('status', 'completed')->count();

            return [
                'total'               => (int) ($counts->total ?? 0),
                'issued'              => (int) ($counts->issued ?? 0),
                'pending'             => (int) ($counts->pending ?? 0),
                'approved'            => (int) ($counts->approved ?? 0),
                'rejected'            => (int) ($counts->rejected ?? 0),
                'revoked'             => (int) ($counts->revoked ?? 0),
                'generation_failed'   => (int) ($counts->generation_failed ?? 0),
                'pdf_ready'           => (int) ($counts->pdf_ready ?? 0),
                'pdf_failed'          => (int) ($counts->pdf_failed ?? 0),
                'pdf_generating'      => (int) ($counts->pdf_generating ?? 0),
                'pdf_pending'         => (int) ($counts->pdf_pending ?? 0),
                'this_month'          => $thisMonth,
                'last_month'          => $lastMonth,
                'downloads_period'    => $downloadsThisPeriod,
                'avg_approval_hours'  => $avgApprovalHours !== null ? round((float) $avgApprovalHours, 1) : null,
                'avg_pdf_minutes'     => $avgPdfHours !== null ? round((float) $avgPdfHours, 1) : null,
                'batch_success_rate'  => $batchTotal > 0 ? round($batchCompleted / $batchTotal * 100, 1) : null,
            ];
        });
    }

    // ── Part 2: Trends ───────────────────────────────────────────────────────

    public function getTrendStats(array $filters = []): array
    {
        return Cache::remember($this->cacheKey('trends', $filters), 300, function () use ($filters) {
            $range       = $this->resolveDateRange($filters);
            $granularity = $filters['granularity'] ?? 'daily';

            $groupFormat = match ($granularity) {
                'weekly'  => "DATE(DATE_SUB(created_at, INTERVAL WEEKDAY(created_at) DAY))",
                'monthly' => "DATE_FORMAT(created_at, '%Y-%m-01')",
                default   => "DATE(created_at)",
            };

            $certs = Certificate::query()
                ->when($range['from'], fn ($q) => $q->where('created_at', '>=', $range['from']))
                ->when($range['to'],   fn ($q) => $q->where('created_at', '<=', $range['to']))
                ->selectRaw("
                    {$groupFormat} as date,
                    COUNT(*) as created,
                    SUM(status = 'issued') as issued,
                    SUM(pdf_generation_status = 'failed') as pdf_failed,
                    SUM(status = 'approved') as approved
                ")
                ->groupByRaw($groupFormat)
                ->orderByRaw($groupFormat)
                ->get();

            // Download trends from activity log
            $downloads = ActivityLog::where('action', 'certificate.downloaded')
                ->where('entity_type', Certificate::class)
                ->when($range['from'], fn ($q) => $q->where('created_at', '>=', $range['from']))
                ->when($range['to'],   fn ($q) => $q->where('created_at', '<=', $range['to']))
                ->selectRaw("{$groupFormat} as date, COUNT(*) as downloads")
                ->groupByRaw($groupFormat)
                ->pluck('downloads', 'date');

            return $certs->map(fn ($r) => [
                'date'       => $r->date,
                'created'    => (int) $r->created,
                'issued'     => (int) $r->issued,
                'pdf_failed' => (int) $r->pdf_failed,
                'approved'   => (int) $r->approved,
                'downloads'  => (int) ($downloads[$r->date] ?? 0),
            ])->values()->all();
        });
    }

    // ── Part 3: Status distribution ──────────────────────────────────────────

    public function getStatusDistribution(array $filters = []): array
    {
        return Cache::remember($this->cacheKey('status_dist', $filters), 300, function () use ($filters) {
            $base = $this->baseQuery($filters);

            $byStatus = (clone $base)
                ->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status');

            $byApproval = (clone $base)
                ->selectRaw('approval_status, COUNT(*) as count')
                ->whereNotNull('approval_status')
                ->groupBy('approval_status')
                ->pluck('count', 'approval_status');

            $byPdf = (clone $base)
                ->selectRaw('pdf_generation_status, COUNT(*) as count')
                ->whereNotNull('pdf_generation_status')
                ->groupBy('pdf_generation_status')
                ->pluck('count', 'pdf_generation_status');

            $byType = (clone $base)
                ->selectRaw('certificate_type, COUNT(*) as count')
                ->groupBy('certificate_type')
                ->pluck('count', 'certificate_type');

            return [
                'by_status'          => $byStatus,
                'by_approval_status' => $byApproval,
                'by_pdf_status'      => $byPdf,
                'by_type'            => $byType,
            ];
        });
    }

    // ── Part 4: Approval metrics ─────────────────────────────────────────────

    public function getApprovalMetrics(array $filters = []): array
    {
        return Cache::remember($this->cacheKey('approval', $filters), 300, function () use ($filters) {
            $base = $this->baseQuery($filters);

            $pending = (clone $base)
                ->whereIn('approval_status', ['pending_approval', 'program_manager_approved', 'admin_approved'])
                ->count();

            $avgFirstApprovalHours = (clone $base)
                ->whereIn('approval_status', ['program_manager_approved', 'admin_approved', 'both_approved'])
                ->whereNotNull('updated_at')
                ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, created_at, updated_at)) as avg_hours')
                ->value('avg_hours');

            $avgFinalApprovalHours = (clone $base)
                ->where('approval_status', 'both_approved')
                ->whereNotNull('issued_at')
                ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, created_at, issued_at)) as avg_hours')
                ->value('avg_hours');

            $completedThisMonth = Certificate::where('approval_status', 'both_approved')
                ->whereYear('updated_at', now()->year)
                ->whereMonth('updated_at', now()->month)
                ->count();

            $rejectedThisMonth = Certificate::where('status', 'rejected')
                ->whereYear('updated_at', now()->year)
                ->whereMonth('updated_at', now()->month)
                ->count();

            // Stuck approvals (pending > 7 days)
            $stuckThreshold = now()->subDays(7);
            $stuck = (clone $base)
                ->whereIn('approval_status', ['pending_approval', 'program_manager_approved', 'admin_approved'])
                ->where('created_at', '<', $stuckThreshold)
                ->with(['user:id,name,email'])
                ->select('id', 'certificate_code', 'title', 'user_id', 'approval_status', 'created_at')
                ->orderBy('created_at')
                ->limit(20)
                ->get();

            // Top approvers from activity log
            $topApprovers = ActivityLog::where('action', 'certificate.approved')
                ->selectRaw('user_id, COUNT(*) as count')
                ->whereNotNull('user_id')
                ->groupBy('user_id')
                ->orderByDesc('count')
                ->limit(5)
                ->with('user:id,name')
                ->get()
                ->map(fn ($r) => [
                    'user_id' => $r->user_id,
                    'name'    => $r->user?->name ?? 'مجهول',
                    'count'   => (int) $r->count,
                ]);

            return [
                'pending_approvals'        => $pending,
                'avg_first_approval_hours' => $avgFirstApprovalHours !== null ? round((float) $avgFirstApprovalHours, 1) : null,
                'avg_final_approval_hours' => $avgFinalApprovalHours !== null ? round((float) $avgFinalApprovalHours, 1) : null,
                'completed_this_month'     => $completedThisMonth,
                'rejected_this_month'      => $rejectedThisMonth,
                'stuck_approvals'          => $stuck,
                'top_approvers'            => $topApprovers,
            ];
        });
    }

    // ── Part 5: PDF generation metrics ───────────────────────────────────────

    public function getPdfGenerationMetrics(array $filters = []): array
    {
        return Cache::remember($this->cacheKey('pdf', $filters), 60, function () use ($filters) {
            $base = $this->baseQuery($filters);

            $stats = (clone $base)
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(pdf_generation_status = 'ready') as successful,
                    SUM(pdf_generation_status = 'failed') as failed,
                    SUM(pdf_generation_status = 'generating') as in_progress,
                    SUM(pdf_generation_status = 'pending') as pending,
                    SUM(pdf_path IS NOT NULL AND pdf_generation_status = 'ready') as has_pdf,
                    SUM(pdf_path IS NULL AND status = 'issued') as issued_missing_pdf,
                    AVG(CASE WHEN pdf_generation_status = 'ready' AND pdf_generated_at IS NOT NULL
                        THEN TIMESTAMPDIFF(SECOND, created_at, pdf_generated_at) END) as avg_seconds,
                    SUM(pdf_file_size) as total_size_bytes
                ")
                ->first();

            // Latest failures
            $latestFailed = (clone $base)
                ->where('pdf_generation_status', 'failed')
                ->orWhere('status', 'generation_failed')
                ->select('id', 'certificate_code', 'title', 'pdf_last_error', 'updated_at', 'user_id')
                ->with('user:id,name')
                ->orderByDesc('updated_at')
                ->limit(15)
                ->get();

            // Repeated failures: certificates that have been retried > once
            $repeatedFailures = ActivityLog::where('action', 'certificate.retry_queued')
                ->selectRaw('entity_id, COUNT(*) as retry_count')
                ->groupBy('entity_id')
                ->having('retry_count', '>', 1)
                ->count();

            return [
                'total'               => (int) ($stats->total ?? 0),
                'successful'          => (int) ($stats->successful ?? 0),
                'failed'              => (int) ($stats->failed ?? 0),
                'in_progress'         => (int) ($stats->in_progress ?? 0),
                'pending'             => (int) ($stats->pending ?? 0),
                'has_pdf'             => (int) ($stats->has_pdf ?? 0),
                'issued_missing_pdf'  => (int) ($stats->issued_missing_pdf ?? 0),
                'avg_generation_secs' => $stats->avg_seconds !== null ? round((float) $stats->avg_seconds, 1) : null,
                'total_size_bytes'    => (int) ($stats->total_size_bytes ?? 0),
                'repeated_failures'   => $repeatedFailures,
                'latest_failed'       => $latestFailed,
            ];
        });
    }

    // ── Part 6: Download analytics ───────────────────────────────────────────

    public function getDownloadMetrics(array $filters = []): array
    {
        return Cache::remember($this->cacheKey('downloads', $filters), 300, function () use ($filters) {
            $range = $this->resolveDateRange($filters);

            $dlBase = ActivityLog::where('action', 'certificate.downloaded')
                ->where('entity_type', Certificate::class)
                ->when($range['from'], fn ($q) => $q->where('created_at', '>=', $range['from']))
                ->when($range['to'],   fn ($q) => $q->where('created_at', '<=', $range['to']));

            $stats = (clone $dlBase)
                ->selectRaw('COUNT(*) as total, COUNT(DISTINCT entity_id) as unique_certs, COUNT(DISTINCT user_id) as unique_students')
                ->first();

            // Most downloaded — bulk-load certificates to avoid N+1
            $topDownloads = (clone $dlBase)
                ->selectRaw('entity_id as certificate_id, COUNT(*) as download_count')
                ->groupBy('entity_id')
                ->orderByDesc('download_count')
                ->limit(10)
                ->get();

            $certMap = Certificate::whereIn('id', $topDownloads->pluck('certificate_id'))
                ->get(['id', 'certificate_code', 'title', 'user_id'])
                ->keyBy('id');

            $mostDownloaded = $topDownloads->map(fn ($r) => [
                'certificate_id'   => $r->certificate_id,
                'certificate_code' => $certMap->get($r->certificate_id)?->certificate_code,
                'title'            => $certMap->get($r->certificate_id)?->title,
                'download_count'   => (int) $r->download_count,
            ]);

            // Students who have NOT downloaded their issued certificate
            $notDownloaded = Certificate::where('status', 'issued')
                ->whereNotNull('pdf_path')
                ->whereNotIn('id', function ($sub) {
                    $sub->select('entity_id')
                        ->from('activity_logs')
                        ->where('action', 'certificate.downloaded')
                        ->whereNotNull('entity_id');
                })
                ->with('user:id,name,email')
                ->select('id', 'certificate_code', 'title', 'user_id', 'issued_at')
                ->orderByDesc('issued_at')
                ->limit(20)
                ->get();

            return [
                'total_downloads'      => (int) ($stats->total ?? 0),
                'unique_certificates'  => (int) ($stats->unique_certs ?? 0),
                'unique_students'      => (int) ($stats->unique_students ?? 0),
                'most_downloaded'      => $mostDownloaded,
                'not_downloaded'       => $notDownloaded,
            ];
        });
    }

    // ── Part 7: Template usage ───────────────────────────────────────────────

    public function getTemplateUsageStats(array $filters = []): array
    {
        return Cache::remember($this->cacheKey('templates', $filters), 300, function () use ($filters) {
            $range = $this->resolveDateRange($filters);

            // Two queries instead of withCount (3 JOINs) + correlated subqueries per template.
            // Query 1: all aggregate stats grouped by template in one pass.
            $stats = DB::table('certificates')
                ->selectRaw("
                    certificate_template_id,
                    COUNT(*) as total_certificates,
                    SUM(status = 'issued') as issued_certificates,
                    SUM(pdf_generation_status = 'failed') as pdf_failures,
                    MAX(created_at) as last_used_at,
                    AVG(CASE WHEN pdf_generation_status = 'ready' AND pdf_generated_at IS NOT NULL
                        THEN TIMESTAMPDIFF(SECOND, created_at, pdf_generated_at) END) as avg_pdf_seconds
                ")
                ->groupBy('certificate_template_id')
                ->get()
                ->keyBy('certificate_template_id');

            // Query 2: load templates.
            $templates = CertificateTemplate::get(['id', 'name', 'is_active', 'is_default', 'code_prefix']);

            return $templates->map(function ($t) use ($stats) {
                $s = $stats->get($t->id);
                return [
                    'id'                  => $t->id,
                    'name'                => $t->name,
                    'is_active'           => (bool) $t->is_active,
                    'is_default'          => (bool) $t->is_default,
                    'code_prefix'         => $t->code_prefix,
                    'total_certificates'  => (int) ($s->total_certificates ?? 0),
                    'issued_certificates' => (int) ($s->issued_certificates ?? 0),
                    'pdf_failures'        => (int) ($s->pdf_failures ?? 0),
                    'last_used_at'        => $s->last_used_at ?? null,
                    'avg_pdf_seconds'     => $s && $s->avg_pdf_seconds !== null ? round((float) $s->avg_pdf_seconds, 1) : null,
                ];
            })->values()->all();
        });
    }

    // ── Part 8: Course analytics ─────────────────────────────────────────────

    public function getCourseCertificateStats(array $filters = []): array
    {
        return Cache::remember($this->cacheKey('courses', $filters), 300, function () use ($filters) {
            $range = $this->resolveDateRange($filters);

            $rows = Certificate::query()
                ->join('courses', 'certificates.course_id', '=', 'courses.id')
                ->when($range['from'], fn ($q) => $q->where('certificates.created_at', '>=', $range['from']))
                ->when($range['to'],   fn ($q) => $q->where('certificates.created_at', '<=', $range['to']))
                ->selectRaw("
                    courses.id as course_id,
                    courses.title as course_title,
                    COUNT(certificates.id) as total,
                    SUM(certificates.status = 'issued') as issued,
                    SUM(certificates.status IN ('pending','pending_generation','approved')) as pending,
                    SUM(certificates.status = 'rejected') as rejected,
                    SUM(certificates.pdf_generation_status = 'failed') as pdf_failed
                ")
                ->groupBy('courses.id', 'courses.title')
                ->orderByDesc('total')
                ->limit(50)
                ->get();

            // Download count per course
            $downloadsByCourse = ActivityLog::where('action', 'certificate.downloaded')
                ->join('certificates', 'activity_logs.entity_id', '=', 'certificates.id')
                ->selectRaw('certificates.course_id, COUNT(*) as downloads')
                ->whereNotNull('certificates.course_id')
                ->groupBy('certificates.course_id')
                ->pluck('downloads', 'certificates.course_id');

            return $rows->map(fn ($r) => [
                'course_id'    => $r->course_id,
                'course_title' => $r->course_title,
                'total'        => (int) $r->total,
                'issued'       => (int) $r->issued,
                'pending'      => (int) $r->pending,
                'rejected'     => (int) $r->rejected,
                'pdf_failed'   => (int) $r->pdf_failed,
                'downloads'    => (int) ($downloadsByCourse[$r->course_id] ?? 0),
                'download_rate'=> $r->issued > 0 ? round(($downloadsByCourse[$r->course_id] ?? 0) / $r->issued * 100, 1) : 0,
            ])->values()->all();
        });
    }

    // ── Part 9: Workshop analytics ───────────────────────────────────────────

    public function getWorkshopCertificateStats(array $filters = []): array
    {
        return Cache::remember($this->cacheKey('workshops', $filters), 300, function () use ($filters) {
            $range = $this->resolveDateRange($filters);

            $rows = Certificate::query()
                ->join('workshops', 'certificates.workshop_id', '=', 'workshops.id')
                ->when($range['from'], fn ($q) => $q->where('certificates.created_at', '>=', $range['from']))
                ->when($range['to'],   fn ($q) => $q->where('certificates.created_at', '<=', $range['to']))
                ->selectRaw("
                    workshops.id as workshop_id,
                    workshops.title as workshop_title,
                    COUNT(certificates.id) as total,
                    SUM(certificates.status = 'issued') as issued,
                    SUM(certificates.status IN ('pending','pending_generation','approved')) as pending,
                    SUM(certificates.status = 'rejected') as rejected,
                    SUM(certificates.pdf_generation_status = 'failed') as pdf_failed
                ")
                ->groupBy('workshops.id', 'workshops.title')
                ->orderByDesc('total')
                ->limit(50)
                ->get();

            $downloadsByWorkshop = ActivityLog::where('action', 'certificate.downloaded')
                ->join('certificates', 'activity_logs.entity_id', '=', 'certificates.id')
                ->selectRaw('certificates.workshop_id, COUNT(*) as downloads')
                ->whereNotNull('certificates.workshop_id')
                ->groupBy('certificates.workshop_id')
                ->pluck('downloads', 'certificates.workshop_id');

            return $rows->map(fn ($r) => [
                'workshop_id'    => $r->workshop_id,
                'workshop_title' => $r->workshop_title,
                'total'          => (int) $r->total,
                'issued'         => (int) $r->issued,
                'pending'        => (int) $r->pending,
                'rejected'       => (int) $r->rejected,
                'pdf_failed'     => (int) $r->pdf_failed,
                'downloads'      => (int) ($downloadsByWorkshop[$r->workshop_id] ?? 0),
                'download_rate'  => $r->issued > 0 ? round(($downloadsByWorkshop[$r->workshop_id] ?? 0) / $r->issued * 100, 1) : 0,
            ])->values()->all();
        });
    }

    // ── Part 10: Learning path analytics ─────────────────────────────────────

    public function getLearningPathCertificateStats(array $filters = []): array
    {
        return Cache::remember($this->cacheKey('learning_paths', $filters), 300, function () use ($filters) {
            $range = $this->resolveDateRange($filters);

            $rows = Certificate::query()
                ->whereNotNull('learning_path_id')
                ->join('learning_paths', 'certificates.learning_path_id', '=', 'learning_paths.id')
                ->when($range['from'], fn ($q) => $q->where('certificates.created_at', '>=', $range['from']))
                ->when($range['to'],   fn ($q) => $q->where('certificates.created_at', '<=', $range['to']))
                ->selectRaw("
                    learning_paths.id as path_id,
                    learning_paths.title as path_title,
                    COUNT(certificates.id) as total,
                    SUM(certificates.status = 'issued') as issued,
                    SUM(certificates.status IN ('pending','pending_generation','approved')) as pending,
                    SUM(certificates.status = 'rejected') as rejected,
                    SUM(certificates.pdf_generation_status = 'failed') as pdf_failed
                ")
                ->groupBy('learning_paths.id', 'learning_paths.title')
                ->orderByDesc('total')
                ->limit(50)
                ->get();

            return $rows->map(fn ($r) => [
                'path_id'     => $r->path_id,
                'path_title'  => $r->path_title,
                'total'       => (int) $r->total,
                'issued'      => (int) $r->issued,
                'pending'     => (int) $r->pending,
                'rejected'    => (int) $r->rejected,
                'pdf_failed'  => (int) $r->pdf_failed,
            ])->values()->all();
        });
    }

    // ── Part 11: Batch performance ───────────────────────────────────────────

    public function getBatchPerformanceStats(array $filters = []): array
    {
        return Cache::remember($this->cacheKey('batches', $filters), 300, function () use ($filters) {
            $range = $this->resolveDateRange($filters);

            $stats = CertificateGenerationBatch::query()
                ->when($range['from'], fn ($q) => $q->where('created_at', '>=', $range['from']))
                ->when($range['to'],   fn ($q) => $q->where('created_at', '<=', $range['to']))
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(status = 'completed') as completed,
                    SUM(status = 'failed') as failed,
                    SUM(status = 'completed_with_errors') as completed_with_errors,
                    SUM(status = 'processing') as processing,
                    SUM(total_recipients) as total_recipients,
                    SUM(generated_count) as total_generated,
                    SUM(failed_count) as total_failed,
                    MAX(total_recipients) as largest_batch,
                    AVG(CASE WHEN completed_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, created_at, completed_at) END) as avg_completion_minutes
                ")
                ->first();

            $batchRows = CertificateGenerationBatch::query()
                ->when($range['from'], fn ($q) => $q->where('created_at', '>=', $range['from']))
                ->when($range['to'],   fn ($q) => $q->where('created_at', '<=', $range['to']))
                ->with('createdBy:id,name')
                ->orderByDesc('created_at')
                ->limit(30)
                ->get(['id', 'batch_code', 'status', 'total_recipients', 'generated_count', 'failed_count', 'created_by', 'created_at', 'completed_at']);

            return [
                'total'                  => (int) ($stats->total ?? 0),
                'completed'              => (int) ($stats->completed ?? 0),
                'failed'                 => (int) ($stats->failed ?? 0),
                'completed_with_errors'  => (int) ($stats->completed_with_errors ?? 0),
                'processing'             => (int) ($stats->processing ?? 0),
                'total_recipients'       => (int) ($stats->total_recipients ?? 0),
                'total_generated'        => (int) ($stats->total_generated ?? 0),
                'total_failed'           => (int) ($stats->total_failed ?? 0),
                'largest_batch'          => (int) ($stats->largest_batch ?? 0),
                'avg_completion_minutes' => $stats->avg_completion_minutes !== null ? round((float) $stats->avg_completion_minutes, 1) : null,
                'batches'                => $batchRows,
            ];
        });
    }

    // ── Part 12: Failure insights ────────────────────────────────────────────

    public function getFailureInsights(array $filters = []): array
    {
        return Cache::remember($this->cacheKey('failures', $filters), 60, function () use ($filters) {
            $range = $this->resolveDateRange($filters);

            $insights = [];

            // Missing template
            $missingTemplate = Certificate::whereNull('certificate_template_id')
                ->when($range['from'], fn ($q) => $q->where('created_at', '>=', $range['from']))
                ->when($range['to'],   fn ($q) => $q->where('created_at', '<=', $range['to']))
                ->selectRaw('COUNT(*) as count, MAX(created_at) as latest')
                ->first();

            if ($missingTemplate->count > 0) {
                $insights[] = [
                    'type'              => 'missing_template',
                    'label'             => 'قالب غير مُسنَد',
                    'count'             => (int) $missingTemplate->count,
                    'latest_occurrence' => $missingTemplate->latest,
                    'recommended_action'=> 'تعيين قالب افتراضي أو تحديث الشهادات المتضررة.',
                ];
            }

            // PDF generation failed
            $pdfFailed = Certificate::where('pdf_generation_status', 'failed')
                ->orWhere('status', 'generation_failed')
                ->when($range['from'], fn ($q) => $q->where('created_at', '>=', $range['from']))
                ->when($range['to'],   fn ($q) => $q->where('created_at', '<=', $range['to']))
                ->selectRaw('COUNT(*) as count, MAX(updated_at) as latest')
                ->first();

            if ($pdfFailed->count > 0) {
                $insights[] = [
                    'type'              => 'pdf_generation_failed',
                    'label'             => 'فشل إنشاء ملف PDF',
                    'count'             => (int) $pdfFailed->count,
                    'latest_occurrence' => $pdfFailed->latest,
                    'recommended_action'=> 'استخدام أمر certificates:retry-failed أو إعادة المحاولة يدوياً.',
                ];
            }

            // Issued without PDF
            $issuedNoPdf = Certificate::where('status', 'issued')
                ->whereNull('pdf_path')
                ->when($range['from'], fn ($q) => $q->where('created_at', '>=', $range['from']))
                ->when($range['to'],   fn ($q) => $q->where('created_at', '<=', $range['to']))
                ->selectRaw('COUNT(*) as count, MAX(issued_at) as latest')
                ->first();

            if ($issuedNoPdf->count > 0) {
                $insights[] = [
                    'type'              => 'issued_missing_pdf',
                    'label'             => 'شهادة صادرة بدون ملف PDF',
                    'count'             => (int) $issuedNoPdf->count,
                    'latest_occurrence' => $issuedNoPdf->latest,
                    'recommended_action'=> 'تشغيل أمر certificates:repair-missing-pdfs.',
                ];
            }

            // Stuck in pending_generation > 30 min
            $stuckGeneration = Certificate::where('status', 'pending_generation')
                ->where('created_at', '<', now()->subMinutes(30))
                ->when($range['from'], fn ($q) => $q->where('created_at', '>=', $range['from']))
                ->selectRaw('COUNT(*) as count, MAX(created_at) as latest')
                ->first();

            if ($stuckGeneration->count > 0) {
                $insights[] = [
                    'type'              => 'stuck_pending_generation',
                    'label'             => 'عالقة في انتظار الإنشاء',
                    'count'             => (int) $stuckGeneration->count,
                    'latest_occurrence' => $stuckGeneration->latest,
                    'recommended_action'=> 'التحقق من عمال قائمة الانتظار أو إعادة تشغيل الوظيفة.',
                ];
            }

            // Invalid approval state
            $badApproval = Certificate::where('status', 'issued')
                ->where(fn ($q) => $q->whereNull('approval_status')->orWhere('approval_status', 'rejected'))
                ->when($range['from'], fn ($q) => $q->where('created_at', '>=', $range['from']))
                ->when($range['to'],   fn ($q) => $q->where('created_at', '<=', $range['to']))
                ->selectRaw('COUNT(*) as count, MAX(issued_at) as latest')
                ->first();

            if ($badApproval->count > 0) {
                $insights[] = [
                    'type'              => 'invalid_approval_state',
                    'label'             => 'حالة اعتماد غير صحيحة',
                    'count'             => (int) $badApproval->count,
                    'latest_occurrence' => $badApproval->latest,
                    'recommended_action'=> 'مراجعة حالة الاعتماد لهذه الشهادات.',
                ];
            }

            // Duplicate prevented (from activity log)
            $duplicates = ActivityLog::where('action', 'certificate.duplicate_prevented')
                ->when($range['from'], fn ($q) => $q->where('created_at', '>=', $range['from']))
                ->when($range['to'],   fn ($q) => $q->where('created_at', '<=', $range['to']))
                ->selectRaw('COUNT(*) as count, MAX(created_at) as latest')
                ->first();

            if ($duplicates->count > 0) {
                $insights[] = [
                    'type'              => 'duplicate_prevented',
                    'label'             => 'تم منع إصدار مكرر',
                    'count'             => (int) $duplicates->count,
                    'latest_occurrence' => $duplicates->latest,
                    'recommended_action'=> 'مراجعة منطق إصدار الشهادات لتجنب الطلبات المكررة.',
                ];
            }

            return $insights;
        });
    }
}
