<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\WeeklyDepartmentReport;
use App\Services\DepartmentAccessService;
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
    public function __construct(private readonly DepartmentAccessService $departmentAccess)
    {
    }

    /** إثنين الأسبوع الحالي — التقرير يُنسب دائمًا إلى أسبوعٍ يبدأ بإثنين. */
    private function currentWeekStart(): CarbonImmutable
    {
        return CarbonImmutable::now()->startOfWeek(CarbonImmutable::MONDAY);
    }

    /**
     * قاعدة العمل المؤكدة: كل إدارة تُسلِّم تقريرها الأسبوعي يوم الإثنين نفسه —
     * أي أن موعد الاستحقاق هو نهاية يوم الإثنين (بداية الأسبوع نفسها)، لا
     * الأربعاء كما كان مفترَضًا سابقًا خطأً. لا يوجد عمود "deadline" في قاعدة
     * البيانات، فهذا الحساب مصدر الحقيقة الوحيد ويُستخدم من كل مكان (لافتة
     * الاستحقاق، بطاقات الملخص، حالة "متأخر" المشتقة) — الواجهة الأمامية لا
     * تُعيد حساب هذا التاريخ أبدًا، بل تعرض فقط ما يُعيده الخادم.
     * التوقيت: يُقرأ عبر CarbonImmutable::now()/startOfWeek بتوقيت التطبيق
     * الافتراضي (config('app.timezone') = UTC)، فلا يوجد أي تعارض بين توقيت
     * حساب week_start وتوقيت التحقق من الاستحقاق — كلاهما بنفس المنطقة.
     */
    private function deadline(CarbonImmutable $weekStart): CarbonImmutable
    {
        return $weekStart->endOfDay();
    }

    private function isDeadlinePassed(CarbonImmutable $weekStart): bool
    {
        return CarbonImmutable::now()->greaterThan($this->deadline($weekStart));
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Quality & Governance is a cross-department oversight function — its
        // members read every department's weekly reports. Everyone else is
        // scoped to their accessible departments (membership ∪ leadership ∪
        // the full privileged-role bypass) via DepartmentAccessService, which
        // already no-ops for super_admin/tech_admin/executive_admin/admin.
        $reports = WeeklyDepartmentReport::query()
            ->with(['department:id,name', 'submitter:id,name', 'ratings.user:id,name'])
            ->when(
                ! $user->inDepartment('quality-governance'),
                fn ($q) => $this->departmentAccess->scopeQuery($q, $user)
            )
            ->when($request->department_id, fn ($q) => $q->where('department_id', $request->department_id))
            ->when($request->week_start, fn ($q) => $q->whereDate('week_start', $request->week_start))
            ->when($request->week_from, fn ($q) => $q->whereDate('week_start', '>=', $request->week_from))
            ->when($request->week_to, fn ($q) => $q->whereDate('week_start', '<=', $request->week_to))
            ->when($request->q, function ($q) use ($request) {
                $term = trim((string) $request->q);
                $q->where(function ($sub) use ($term) {
                    $sub->whereHas('department', fn ($d) => $d->where('name', 'like', "%{$term}%")
                        ->orWhere('name_ar', 'like', "%{$term}%"))
                        ->orWhereHas('submitter', fn ($s) => $s->where('name', 'like', "%{$term}%"));
                });
            })
            ->orderByDesc('week_start')
            ->paginate((int) ($request->per_page ?? 15));

        return response()->json(['success' => true, 'data' => $reports]);
    }

    /** أي الإدارات (ضمن نطاق المستخدم) لم تسلِّم تقرير هذا الأسبوع بعد؟ يغذي لافتة الاستحقاق يوم الإثنين. */
    public function due(Request $request): JsonResponse
    {
        $weekStart = $this->currentWeekStart();

        $submitted = WeeklyDepartmentReport::query()
            ->whereDate('week_start', $weekStart)
            ->pluck('department_id');

        $missing = $this->departmentAccess
            ->scopeQuery(Department::query(), $request->user(), 'id')
            ->whereNotIn('id', $submitted)
            ->orderBy('sort_order')
            ->get(['id', 'name']);

        return response()->json([
            'success' => true,
            'data'    => [
                'week_start'      => $weekStart->toDateString(),
                'missing'         => $missing,
                'submitted'       => $submitted->count(),
                'deadline'        => $this->deadline($weekStart)->toIso8601String(),
                'deadline_passed' => $this->isDeadlinePassed($weekStart),
            ],
        ]);
    }

    /**
     * إحصاءات الأسبوع الحالي ضمن نطاق المستخدم — تغذي بطاقات الملخص أعلى
     * الصفحة. مشتقة بالكامل من department_id/week_start الفعليين، لا أرقام
     * ثابتة.
     */
    public function summary(Request $request): JsonResponse
    {
        $user      = $request->user();
        $weekStart = $this->currentWeekStart();

        $expectedIds = $this->departmentAccess
            ->scopeQuery(Department::query(), $user, 'id')
            ->pluck('id');

        $submittedIds = WeeklyDepartmentReport::query()
            ->whereDate('week_start', $weekStart)
            ->whereIn('department_id', $expectedIds)
            ->pluck('department_id');

        $expected        = $expectedIds->count();
        $submitted       = $submittedIds->count();
        $missing         = max(0, $expected - $submitted);
        $deadlinePassed  = $this->isDeadlinePassed($weekStart);
        $overdue         = $deadlinePassed ? $missing : 0;
        $inProgress      = $deadlinePassed ? 0 : $missing;

        return response()->json([
            'success' => true,
            'data'    => [
                'week_start'      => $weekStart->toDateString(),
                'deadline'        => $this->deadline($weekStart)->toIso8601String(),
                'expected'        => $expected,
                'submitted'       => $submitted,
                'overdue'         => $overdue,
                'in_progress'     => $inProgress,
                'completion_rate' => $expected > 0 ? round(($submitted / $expected) * 100) : 0,
                'deadline_passed' => $deadlinePassed,
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

        // Report creation requires actual department LEADERSHIP (departments.leader_id),
        // not mere active membership — see DepartmentAccessService::canManageDepartment.
        if (! $this->departmentAccess->canManageDepartment($request->user(), (int) $data['department_id'])) {
            return response()->json(['success' => false, 'message' => 'ليست لديك صلاحية إنشاء تقرير لهذه الإدارة — هذا الإجراء متاح لقائد الإدارة فقط.'], 403);
        }

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
