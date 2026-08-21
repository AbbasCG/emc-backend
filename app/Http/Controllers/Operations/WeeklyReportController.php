<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\WeeklyDepartmentReport;
use App\Services\ImpactPoints;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * تقرير الإثنين الأسبوعي لكل إدارة: أداء الأسبوع، ماذا أنجزوا، ماذا سينجزون،
 * العوائق والاحتياجات. أسبوع واحد = صف واحد لكل إدارة (unique).
 */
class WeeklyReportController extends Controller
{
    /** إثنين الأسبوع الحالي — التقرير يُنسب دائمًا إلى أسبوعٍ يبدأ بإثنين. */
    private function currentWeekStart(): CarbonImmutable
    {
        return CarbonImmutable::now()->startOfWeek(CarbonImmutable::MONDAY);
    }

    public function index(Request $request): JsonResponse
    {
        $reports = WeeklyDepartmentReport::query()
            ->with(['department:id,name', 'submitter:id,name', 'ratings.user:id,name'])
            ->when($request->department_id, fn ($q) => $q->where('department_id', $request->department_id))
            ->when($request->week_start, fn ($q) => $q->whereDate('week_start', $request->week_start))
            ->orderByDesc('week_start')
            ->paginate((int) ($request->per_page ?? 15));

        return response()->json(['success' => true, 'data' => $reports]);
    }

    /** أي الإدارات لم تسلِّم تقرير هذا الأسبوع بعد؟ يغذي لافتة الاستحقاق يوم الإثنين. */
    public function due(): JsonResponse
    {
        $weekStart = $this->currentWeekStart();

        $submitted = WeeklyDepartmentReport::query()
            ->whereDate('week_start', $weekStart)
            ->pluck('department_id');

        $missing = Department::query()
            ->whereNotIn('id', $submitted)
            ->orderBy('sort_order')
            ->get(['id', 'name']);

        return response()->json([
            'success' => true,
            'data'    => [
                'week_start' => $weekStart->toDateString(),
                'missing'    => $missing,
                'submitted'  => $submitted->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'department_id' => 'required|exists:departments,id',
            'week_start'    => 'nullable|date',
            'achievements'  => 'required|string',
            'planned'       => 'required|string',
            'blockers'      => 'nullable|string',
            'needs'         => 'nullable|string',
            'notes'         => 'nullable|string',
            'ratings'                => 'array',
            'ratings.*.user_id'      => 'required|exists:users,id',
            'ratings.*.scores'       => 'nullable|array',
            'ratings.*.scores.*'     => 'integer|min:1|max:10',
            'ratings.*.note'         => 'nullable|string|max:500',
        ]);

        $weekStart = isset($data['week_start'])
            ? CarbonImmutable::parse($data['week_start'])->startOfWeek(CarbonImmutable::MONDAY)
            : $this->currentWeekStart();

        // المطابقة بـwhereDate لا بالمساواة النصية: عمود التاريخ يُخزَّن مع وقت
        // صفري فلا يطابق '2026-08-17' حرفيًا — وupdateOrCreate كان يصطدم بقيد
        // الفرادة عند إعادة التسليم.
        $payload = [
            'achievements' => $data['achievements'],
            'planned'      => $data['planned'],
            'blockers'     => $data['blockers'] ?? null,
            'needs'        => $data['needs'] ?? null,
            'notes'        => $data['notes'] ?? null,
            'submitted_by' => $request->user()->id,
            'submitted_at' => now(),
        ];

        $report = WeeklyDepartmentReport::query()
            ->where('department_id', $data['department_id'])
            ->whereDate('week_start', $weekStart->toDateString())
            ->first();

        if ($report) {
            $report->update($payload);
        } else {
            $report = WeeklyDepartmentReport::create([
                ...$payload,
                'department_id' => $data['department_id'],
                'week_start'    => $weekStart->toDateString(),
            ]);
        }

        // التقرير الأسبوعي يُعاد حفظه بالاستبدال (upsert) — فتُستبدل تقييماته
        // ومنح نقاطه السابقة بدل مراكمتها، حتى لا يُحتسب الأسبوع مرتين.
        $report->ratings()->delete();
        ImpactPoints::revokeSource('weekly_report', $report->id);

        foreach ($data['ratings'] ?? [] as $row) {
            $scores = array_filter($row['scores'] ?? []);
            $avg    = $scores !== [] ? round(array_sum($scores) / count($scores), 1) : null;

            $report->ratings()->create([
                'user_id'   => $row['user_id'],
                'scores'    => $scores !== [] ? $scores : null,
                'avg_score' => $avg,
                'note'      => $row['note'] ?? null,
            ]);

            if ($avg !== null) {
                ImpactPoints::awardEvaluationBonus(
                    $row['user_id'],
                    $avg,
                    'تقرير الإثنين — أسبوع ' . $weekStart->toDateString(),
                    $request->user()->id,
                    'weekly_report',
                    $report->id,
                );
            }
        }

        return response()->json([
            'success' => true,
            'data'    => $report->load(['department:id,name', 'submitter:id,name', 'ratings.user:id,name']),
        ], 201);
    }
}
