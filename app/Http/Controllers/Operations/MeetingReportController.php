<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Http\Resources\OperationsMeetingReportResource;
use App\Models\MeetingReport;
use App\Models\MeetingReportRating;
use App\Services\DepartmentAccessService;
use App\Services\ImpactPoints;
use App\Services\MeetingAccessService;
use App\Services\MeetingParticipantService;
use App\Models\MeetingParticipant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * تقارير ما بعد الاجتماع: محضر منظَّم (أنجزوا/سينجزون/يحتاجون ولماذا/قرارات)
 * مع تقييم لكل حاضر — مصمَّم ليكون سريعًا على المدير وسهل القياس لاحقًا.
 */
class MeetingReportController extends Controller
{
    public function __construct(private readonly DepartmentAccessService $departmentAccess)
    {
    }

    /**
     * Max rows per page. The page never needs more, and an unbounded per_page
     * lets one request pull the whole archive.
     */
    private const MAX_PER_PAGE = 50;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'department_id' => 'nullable|integer',
            'q'             => 'nullable|string|max:100',
            'date_from'     => 'nullable|date',
            'date_to'       => 'nullable|date',
            'per_page'      => 'nullable|integer|min:1',
        ]);

        // Official reports are for oversight (global, quality) and for whoever manages
        // the report's department. A plain department member does not read them, and a
        // filter can only narrow this scope, never widen it.
        $readable = MeetingAccessService::for($request)->readableReportDepartmentIds($user);
        $scoped = fn () => $readable === null
            ? MeetingReport::query()
            : MeetingReport::query()->whereIn('department_id', $readable);

        $search = trim((string) $request->query('q', ''));

        $reports = $scoped()
            ->with(['department:id,name,name_ar', 'author:id,name', 'meeting:id,title,type,meeting_date,department_id'])
            ->withCount([
                'ratings',
                'ratings as attended_count' => fn ($q) => $q->where('attended', true),
            ])
            ->when($request->filled('department_id'), fn ($q) => $q->where('department_id', (int) $request->query('department_id')))
            ->when($search !== '', function ($q) use ($search) {
                // A literal % or _ only widens the match inside the user's own
                // department scope, so it is left as a wildcard.
                $like = '%'.$search.'%';
                $q->where(fn ($w) => $w->where('title', 'like', $like)
                    ->orWhereHas('meeting', fn ($m) => $m->where('title', 'like', $like)));
            })
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('meeting_date', '>=', $request->query('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('meeting_date', '<=', $request->query('date_to')))
            ->orderByRaw('meeting_date is null')
            ->orderByDesc('meeting_date')
            ->orderByDesc('id')
            ->paginate(min(max((int) $request->query('per_page', 15), 1), self::MAX_PER_PAGE));

        // Attendance for the whole page in one query.
        $summaries = app(MeetingParticipantService::class)->summariesFor(
            $reports->getCollection()->pluck('meeting_id')->filter()->map(fn ($i) => (int) $i)->unique()->values()->all()
        );
        $reports = $reports->through(fn (MeetingReport $r) => (new OperationsMeetingReportResource($r))
            ->attendance($this->attendanceFor($r, $summaries[$r->meeting_id] ?? null))
            ->resolve());

        return response()->json([
            'success' => true,
            'data'    => $reports,
            'stats'   => $this->stats($scoped),
        ]);
    }

    /**
     * Headline numbers for the page, over the reports this user may read
     * (not over the current search/filter, so the cards stay stable while
     * filtering). Only figures the data genuinely supports.
     *
     * @param  \Closure(): \Illuminate\Database\Eloquent\Builder<MeetingReport>  $scoped
     * @return array{total: int, this_month: int, latest_at: string|null, attendance_rate: int|null}
     */
    private function stats(\Closure $scoped): array
    {
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        $ratings = MeetingReportRating::query()
            ->whereIn('meeting_report_id', $scoped()->select('meeting_reports.id'));
        $totalRatings = (clone $ratings)->count();
        $attended = (clone $ratings)->where('attended', true)->count();
        $latest = $scoped()->max('created_at');

        return [
            'total'      => $scoped()->count(),
            // A row without a meeting date counts in the month it was filed.
            'this_month' => $scoped()->where(function ($q) use ($monthStart, $monthEnd) {
                $q->whereBetween('meeting_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                    ->orWhere(fn ($n) => $n->whereNull('meeting_date')->whereBetween('created_at', [$monthStart, $monthEnd]));
            })->count(),
            'latest_at'  => $latest ? \Illuminate\Support\Carbon::parse($latest)->toIso8601String() : null,
            'attendance_rate' => $totalRatings > 0 ? (int) round($attended / $totalRatings * 100) : null,
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'department_id'          => 'required|exists:departments,id',
            'meeting_id'             => 'nullable|exists:meetings,id',
            'title'                  => 'required|string|max:255',
            'meeting_date'           => 'required|date',
            'achieved'               => 'nullable|string',
            'planned'                => 'nullable|string',
            'needs'                  => 'nullable|string',
            'needs_reason'           => 'nullable|string',
            'decisions'              => 'nullable|string',
            'notes'                  => 'nullable|string',
            'ratings'                => 'array',
            'ratings.*.user_id'      => 'required|exists:users,id',
            'ratings.*.attended'     => 'boolean',
            'ratings.*.scores'       => 'nullable|array',
            'ratings.*.scores.*'     => 'integer|min:1|max:10',
            'ratings.*.note'         => 'nullable|string|max:500',
        ]);

        // Report creation requires actual department LEADERSHIP (departments.leader_id),
        // not mere active membership — see DepartmentAccessService::canManageDepartment.
        if (! $this->departmentAccess->canManageDepartment($request->user(), (int) $data['department_id'])) {
            return response()->json(['success' => false, 'message' => 'ليست لديك صلاحية إنشاء تقرير لهذه الإدارة — هذا الإجراء متاح لقائد الإدارة فقط.'], 403);
        }

        $report = MeetingReport::create([
            ...collect($data)->except('ratings')->all(),
            'created_by' => $request->user()->id,
        ]);

        foreach ($data['ratings'] ?? [] as $row) {
            $attended = $row['attended'] ?? true;
            $scores   = $attended ? array_filter($row['scores'] ?? []) : [];
            $avg      = $scores !== [] ? round(array_sum($scores) / count($scores), 1) : null;

            $report->ratings()->updateOrCreate(
                ['user_id' => $row['user_id']],
                [
                    'attended'  => $attended,
                    'scores'    => $scores !== [] ? $scores : null,
                    'avg_score' => $avg,
                    'note'      => $row['note'] ?? null,
                ],
            );

            // ربط التقييم بنقاط الأثر: حضور (§12 بسقفه الشهري) + مكافأة تميز التقييم.
            if ($attended) {
                ImpactPoints::awardMeetingAttendance(
                    $row['user_id'],
                    $report->title,
                    $request->user()->id,
                    'meeting_report',
                    $report->id,
                );
                if ($avg !== null) {
                    ImpactPoints::awardEvaluationBonus(
                        $row['user_id'],
                        $avg,
                        "اجتماع: {$report->title}",
                        $request->user()->id,
                        'meeting_report',
                        $report->id,
                    );
                }
            }
        }

        return response()->json([
            'success' => true,
            'data'    => $report->load(['department:id,name', 'ratings.user:id,name']),
        ], 201);
    }

    public function show(Request $request, MeetingReport $meetingReport): JsonResponse
    {
        if (! MeetingAccessService::for($request)->canViewReportsOf($request->user(), $meetingReport->department_id !== null ? (int) $meetingReport->department_id : null)) {
            return response()->json(['success' => false, 'message' => 'ليست لديك صلاحية عرض هذا التقرير.'], 403);
        }

        $meetingReport->load(['department:id,name,name_ar', 'author:id,name', 'meeting:id,title,type,meeting_date,department_id', 'ratings.user:id,name'])
            ->loadCount(['ratings', 'ratings as attended_count' => fn ($q) => $q->where('attended', true)]);

        $summary = $meetingReport->meeting_id
            ? (app(MeetingParticipantService::class)->summariesFor([(int) $meetingReport->meeting_id])[$meetingReport->meeting_id] ?? null)
            : null;
        $attendance = $this->attendanceFor($meetingReport, $summary);

        // Who attended / who did not, by name (this endpoint is already limited to oversight and managers).
        if ($attendance !== null && $meetingReport->meeting_id && $summary !== null) {
            $people = MeetingParticipant::query()->where('meeting_id', $meetingReport->meeting_id)->with('user:id,name')->get();
            $present = [MeetingParticipant::STATUS_ATTENDED, MeetingParticipant::STATUS_JOINED];
            $attendance['attendees'] = $people->filter(fn ($p) => in_array($p->status, $present, true))->map(fn ($p) => $p->user?->name)->filter()->values()->all();
            $attendance['absent'] = $people->where('status', MeetingParticipant::STATUS_ABSENT)->map(fn ($p) => $p->user?->name)->filter()->values()->all();
        } elseif ($attendance !== null) {
            $attendance['attendees'] = $meetingReport->ratings->where('attended', true)->map(fn ($r) => $r->user?->name)->filter()->values()->all();
            $attendance['absent'] = $meetingReport->ratings->where('attended', false)->map(fn ($r) => $r->user?->name)->filter()->values()->all();
        }

        return response()->json([
            'success' => true,
            'data'    => (new OperationsMeetingReportResource($meetingReport))->attendance($attendance)->detailed()->resolve(),
        ]);
    }

    /**
     * Attendance summary of one report: from the meeting's participants when it has
     * any, else from the per-member ratings of a department report, else null.
     *
     * @param  array{invited: int, attended: int, absent: int}|null  $fromParticipants
     * @return array{invited: int, attended: int, absent: int}|null
     */
    private function attendanceFor(MeetingReport $report, ?array $fromParticipants): ?array
    {
        if ($fromParticipants !== null) {
            return $fromParticipants;
        }

        $total = (int) ($report->ratings_count ?? 0);
        if ($total === 0) {
            return null;
        }

        $attended = (int) ($report->attended_count ?? 0);

        return ['invited' => $total, 'attended' => $attended, 'absent' => max(0, $total - $attended)];
    }
}
