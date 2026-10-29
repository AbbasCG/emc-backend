<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DynamicForm;
use App\Services\DepartmentAccessService;
use App\Services\Forms\Export\SpreadsheetSafe;
use App\Services\Forms\FormAccessService;
use App\Services\Forms\FormActivityService;
use App\Services\Forms\FormAnalyticsService;
use App\Services\Forms\FormResponseQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Form analytics (Smart Forms Phase 7). Reading a form's analytics is reading its responses in summary: it needs exactly the right
 * to read those responses (viewResponses), like the response table and the exports. There is no separate analytics permission.
 */
class FormAnalyticsController extends Controller
{
    private const FILTER_KEYS = ['search', 'status', 'assignee', 'version', 'from', 'to', 'source', 'corrected'];

    public function __construct(
        private readonly FormAnalyticsService $analytics,
        private readonly FormAccessService $access,
        private readonly DepartmentAccessService $departments,
    ) {}

    public function show(Request $request, DynamicForm $form): JsonResponse
    {
        $this->authorize('viewResponses', $form);

        return response()->json(['success' => true, 'data' => $this->analytics->summary($form, $this->filters($request))]);
    }

    /** The summary as a CSV file: one line per figure. Opens in Excel with Arabic intact; cells cannot run as formulas. */
    public function export(Request $request, DynamicForm $form): StreamedResponse
    {
        $this->authorize('viewResponses', $form);
        $summary = $this->analytics->summary($form, $this->filters($request));

        $rows = [['القسم', 'البند', 'القيمة', 'النسبة %']];
        $t = $summary['totals'];
        $rows[] = ['الإجمالي', 'عدد الردود', $t['responses'], ''];
        $rows[] = ['الإجمالي', 'ردود مسجلي الدخول', $t['signed_in'], ''];
        $rows[] = ['الإجمالي', 'ردود بدون تسجيل دخول', $t['anonymous'], ''];
        $rows[] = ['الإجمالي', 'ردود عبر روابط المشاركة', $t['via_share_link'], ''];
        foreach ($t['by_status'] as $status => $count) {
            $rows[] = ['الحالات', \App\Services\Forms\Export\FormExportColumns::statusLabel($status), $count, ''];
        }
        if ($summary['comparison'] !== null) {
            $c = $summary['comparison'];
            $rows[] = ['المقارنة', 'الفترة الحالية', $c['current'], ''];
            $rows[] = ['المقارنة', "الفترة السابقة ({$c['previous_from']} – {$c['previous_to']})", $c['previous'], $c['change_percent'] ?? ''];
        }
        foreach ($summary['series']['points'] as $point) {
            $rows[] = ['الردود عبر الزمن', $point['period'], $point['count'], ''];
        }
        foreach ($summary['questions'] as $q) {
            $rows[] = [$q['label'], 'أجاب', $q['answered'], $q['answer_rate'] ?? ''];
            $stats = $q['stats'];
            if ($stats['kind'] === 'choice') {
                foreach ($stats['options'] as $o) {
                    $rows[] = [$q['label'], $o['label'], $o['count'], $o['percent'] ?? ''];
                }
            } elseif ($stats['kind'] === 'numeric') {
                $rows[] = [$q['label'], 'المتوسط', $stats['average'] ?? '', ''];
                $rows[] = [$q['label'], 'الأدنى', $stats['min'] ?? '', ''];
                $rows[] = [$q['label'], 'الأعلى', $stats['max'] ?? '', ''];
                foreach ($stats['distribution'] ?? [] as $d) {
                    $rows[] = [$q['label'], 'القيمة '.$d['value'], $d['count'], $d['percent'] ?? ''];
                }
            }
        }

        FormActivityService::record($form, $request->user(), 'analytics_exported', ['format' => 'csv', 'rows' => count($rows) - 1], $request);

        $name = 'form-'.$form->id.'-analytics-'.now(FormResponseQuery::DISPLAY_TIMEZONE)->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($cell) => is_int($cell) || is_float($cell) ? (string) $cell : SpreadsheetSafe::cell((string) $cell), $row), escape: '');
            }
            fclose($out);
        }, Str::ascii($name), [
            'Content-Type'           => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'private, no-store',
        ]);
    }

    /** Response counts across the forms whose responses this person may read: the department view. */
    public function overview(Request $request): JsonResponse
    {
        $this->authorize('viewAny', DynamicForm::class);
        $data = $request->validate([
            'department_id' => ['nullable', 'integer'],
            'from'          => ['nullable', 'date_format:Y-m-d'],
            'to'            => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return response()->json([
            'success' => true,
            'data'    => $this->analytics->overview($request->user(), $data, $this->access),
            'meta'    => ['departments' => $this->departments->manifest($request->user())['readable_departments']],
        ]);
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        $data = $request->validate(FormResponseQuery::rules());

        return FormResponseQuery::normalize(array_intersect_key($data, array_flip(self::FILTER_KEYS)), $request->user());
    }
}
