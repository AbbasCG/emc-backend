<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Services\ActivityLogger;
use App\Services\Certificate\CertificateAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Validation\ValidationException;

class CertificateAnalyticsController extends Controller
{
    private array $VALID_PRESETS = ['today', 'last_7_days', 'last_30_days', 'this_month', 'last_month', 'this_year', 'all_time'];

    public function __construct(
        private readonly CertificateAnalyticsService $analytics,
    ) {}

    // ── Filter validation & extraction ───────────────────────────────────────

    private function filters(Request $request): array
    {
        $request->validate([
            'preset'            => 'nullable|string|in:' . implode(',', $this->VALID_PRESETS),
            'date_from'         => 'nullable|date',
            'date_to'           => 'nullable|date|after_or_equal:date_from',
            'status'            => 'nullable|string',
            'approval_status'   => 'nullable|string',
            'certificate_type'  => 'nullable|string',
            'template_id'       => 'nullable|integer',
            'course_id'         => 'nullable|integer',
            'workshop_id'       => 'nullable|integer',
            'learning_path_id'  => 'nullable|integer',
            'issued_by'         => 'nullable|integer',
            'student_id'        => 'nullable|integer',
            'granularity'       => 'nullable|string|in:daily,weekly,monthly',
        ]);

        return array_filter($request->only([
            'preset', 'date_from', 'date_to', 'status', 'approval_status',
            'certificate_type', 'template_id', 'course_id', 'workshop_id',
            'learning_path_id', 'issued_by', 'student_id', 'granularity',
        ]), fn ($v) => $v !== null && $v !== '');
    }

    private function ok(mixed $data, array $meta = []): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data, 'meta' => $meta]);
    }

    // ── Endpoints ─────────────────────────────────────────────────────────────

    public function overview(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Certificate::class);
        return $this->ok($this->analytics->getOverviewStats($this->filters($request)));
    }

    public function trends(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Certificate::class);
        $filters = $this->filters($request);
        $range   = $this->analytics->resolveDateRange($filters);

        return $this->ok(
            $this->analytics->getTrendStats($filters),
            ['granularity' => $filters['granularity'] ?? 'daily', 'range' => $range],
        );
    }

    public function statusDistribution(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Certificate::class);
        return $this->ok($this->analytics->getStatusDistribution($this->filters($request)));
    }

    public function approval(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Certificate::class);
        return $this->ok($this->analytics->getApprovalMetrics($this->filters($request)));
    }

    public function pdf(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Certificate::class);
        return $this->ok($this->analytics->getPdfGenerationMetrics($this->filters($request)));
    }

    public function downloads(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Certificate::class);
        return $this->ok($this->analytics->getDownloadMetrics($this->filters($request)));
    }

    public function templates(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Certificate::class);
        return $this->ok($this->analytics->getTemplateUsageStats($this->filters($request)));
    }

    public function courses(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Certificate::class);
        return $this->ok($this->analytics->getCourseCertificateStats($this->filters($request)));
    }

    public function workshops(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Certificate::class);
        return $this->ok($this->analytics->getWorkshopCertificateStats($this->filters($request)));
    }

    public function learningPaths(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Certificate::class);
        return $this->ok($this->analytics->getLearningPathCertificateStats($this->filters($request)));
    }

    public function batches(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Certificate::class);
        return $this->ok($this->analytics->getBatchPerformanceStats($this->filters($request)));
    }

    public function failures(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Certificate::class);
        return $this->ok($this->analytics->getFailureInsights($this->filters($request)));
    }

    // ── Export ────────────────────────────────────────────────────────────────

    public function export(Request $request, string $type): \Symfony\Component\HttpFoundation\StreamedResponse|JsonResponse
    {
        $this->authorize('viewAny', Certificate::class);

        $request->validate(['type' => 'nullable|string']);
        $filters = $this->filters($request);

        $allowed = ['overview', 'trends', 'templates', 'courses', 'workshops', 'learning_paths', 'failures', 'batches'];
        if (! in_array($type, $allowed, true)) {
            return response()->json(['success' => false, 'message' => 'نوع التصدير غير صالح.'], 422);
        }

        ActivityLogger::log('certificate_analytics.export', null, [], $request, null, [
            'export_type' => $type,
            'filters'     => $filters,
        ]);

        $data = match ($type) {
            'overview'       => [$this->analytics->getOverviewStats($filters)],
            'trends'         => $this->analytics->getTrendStats($filters),
            'templates'      => $this->analytics->getTemplateUsageStats($filters),
            'courses'        => $this->analytics->getCourseCertificateStats($filters),
            'workshops'      => $this->analytics->getWorkshopCertificateStats($filters),
            'learning_paths' => $this->analytics->getLearningPathCertificateStats($filters),
            'batches'        => $this->analytics->getBatchPerformanceStats($filters)['batches'] ?? [],
            'failures'       => $this->analytics->getFailureInsights($filters),
        };

        $rows = is_array($data) ? $data : [$data];
        if (empty($rows)) {
            return response()->json(['success' => false, 'message' => 'لا توجد بيانات للتصدير.'], 422);
        }

        $headers = array_keys((array) ($rows[0] instanceof \Illuminate\Database\Eloquent\Model ? $rows[0]->toArray() : (array) $rows[0]));
        $filename = "certificates-analytics-{$type}-" . now()->format('Y-m-d') . '.csv';

        return Response::stream(function () use ($rows, $headers) {
            $handle = fopen('php://output', 'w');
            // UTF-8 BOM for Arabic Excel support
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $headers);

            foreach ($rows as $row) {
                $arr = $row instanceof \Illuminate\Database\Eloquent\Model ? $row->toArray() : (array) $row;
                $safe = array_map(fn ($v) => is_array($v) || is_object($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v, $arr);
                fputcsv($handle, array_values($safe));
            }

            fclose($handle);
        }, 200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
