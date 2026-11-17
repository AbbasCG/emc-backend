<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\TeamProfile;
use App\Models\User;
use App\Models\VolunteerPointAward;
use App\Models\VolunteerPointRedemption;
use App\Models\VolunteerProfile;
use App\Http\Resources\VolunteerRequestResource;
use App\Models\VolunteerRequest;
use App\Models\VolunteerStatusLog;
use App\Services\VolunteerSourceResolver;
use App\Models\WeeklyReportRating;
use App\Services\ActivityLogger;
use App\Services\DepartmentRosterService;
use App\Services\VolunteerHubSyncService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VolunteerHubController extends Controller
{
    /**
     * قائمة المتطوعين الموحدة مع إحصائيات النقاط والتقييمات والملخص التفاعلي
     */
    public function index(Request $request, VolunteerHubSyncService $sync): JsonResponse
    {
        // ضمان وجود ملف تطوعي لكل متطوع: دور volunteer، أو عضو فريق من نوع متطوع له حساب، أو طلب تطوع تم تحويله
        // (at most once a minute; null = it ran recently and was skipped)
        $syncResult = $sync->ensureProfilesThrottled();

        $withoutAccount = $sync->withoutAccountCount();

        $query = VolunteerProfile::query()
            ->with(['user', 'department', 'section']);

        // فلترة بالبحث (الاسم أو البريد)
        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // فلترة بالإدارة
        if ($request->filled('department_id')) {
            $query->where('department_id', (int) $request->department_id);
        }

        // فلترة بالحالة (active, inactive, on_leave, withdrawn)
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $allMatching = $query->get();

        if ($allMatching->isEmpty()) {
            return response()->json([
                'success' => true,
                'data'    => [
                    'volunteers'  => [],
                    'departments' => Department::select('id', 'name')->orderBy('name')->get(),
                    'summary'     => [
                        'total_volunteers'    => 0,
                        'total_actual_points' => 0,
                        'total_used_points'   => 0,
                        'total_balance'       => 0,
                        'status_counts'       => [
                            'active'    => 0,
                            'inactive'  => 0,
                            'on_leave'  => 0,
                            'withdrawn' => 0,
                        ],
                        'without_account'     => $withoutAccount,
                    ],
                ],
            ]);
        }

        $userIds = $allMatching->pluck('user_id')->filter()->unique()->all();

        // الإدارة عند غيابها من الملف التطوعي: ملف الفريق ثم الإدارة الأساسية ثم نص users.department
        // (free-text guesses are display-only; reliable sources were already written by the sync.) When the sync ran in this very
        // request it already examined these users, so they are not derived a second time.
        $blankUserIds = $allMatching->whereNull('department_id')->pluck('user_id')->filter()->map(fn ($id) => (int) $id)->all();
        $alreadyExamined = $syncResult ? array_flip($syncResult['examined']) : [];
        $derivedDepartments = ($syncResult['derived'] ?? [])
            + $sync->derivedDepartments(array_values(array_filter($blankUserIds, fn ($id) => ! isset($alreadyExamined[$id]))));

        // 1. حساب النقاط الفعلية (مجموع الموجب في الليدجر)
        $actualPointsMap = VolunteerPointAward::query()
            ->whereIn('user_id', $userIds)
            ->where('points', '>', 0)
            ->groupBy('user_id')
            ->selectRaw('user_id, SUM(points) as total')
            ->pluck('total', 'user_id');

        // 2. حساب الخصومات من الليدجر (النقاط السالبة)
        $deductionsMap = VolunteerPointAward::query()
            ->whereIn('user_id', $userIds)
            ->where('points', '<', 0)
            ->groupBy('user_id')
            ->selectRaw('user_id, ABS(SUM(points)) as total')
            ->pluck('total', 'user_id');

        // 3. حساب النقاط المستخدمة / المستبدلة
        $redemptionsMap = VolunteerPointRedemption::query()
            ->whereIn('user_id', $userIds)
            ->groupBy('user_id')
            ->selectRaw('user_id, SUM(points) as total')
            ->pluck('total', 'user_id');

        // 4. آخر تقييم أسبوعي لكل عضو
        $lastRatingsMap = WeeklyReportRating::query()
            ->whereIn('weekly_report_ratings.user_id', $userIds)
            ->join('weekly_department_reports', 'weekly_report_ratings.weekly_department_report_id', '=', 'weekly_department_reports.id')
            ->orderByDesc('weekly_department_reports.week_start')
            ->select('weekly_report_ratings.user_id', 'weekly_report_ratings.avg_score')
            ->get()
            ->unique('user_id')
            ->pluck('avg_score', 'user_id');

        // 5. التحقق من صفة القيادة (قائد إدارة أو قائد فريق)
        $leaderDeptIds = Department::query()->whereNotNull('leader_id')->pluck('leader_id')->flip();
        $leaderTeamIds = TeamProfile::query()->where('is_leader', true)->whereNotNull('user_id')->pluck('user_id')->flip();

        $volunteers = $allMatching->map(function (VolunteerProfile $v) use (
            $actualPointsMap,
            $deductionsMap,
            $redemptionsMap,
            $lastRatingsMap,
            $leaderDeptIds,
            $leaderTeamIds,
            $derivedDepartments
        ) {
            $uId = $v->user_id;
            $actual = (int) ($actualPointsMap[$uId] ?? 0);
            $deductions = (int) ($deductionsMap[$uId] ?? 0);
            $redemptions = (int) ($redemptionsMap[$uId] ?? 0);
            $used = $deductions + $redemptions;
            $balance = $actual - $used;
            $lastRating = isset($lastRatingsMap[$uId]) ? round((float) $lastRatingsMap[$uId], 1) : null;

            // تسوية الحالة للحالات الأربع
            $st = $v->status;
            if (! in_array($st, ['active', 'inactive', 'on_leave', 'withdrawn'], true)) {
                $st = ($st === 'withdrawn') ? 'withdrawn' : (($st === 'inactive') ? 'inactive' : 'active');
            }

            $isLeader = isset($leaderDeptIds[$uId]) || isset($leaderTeamIds[$uId]);

            $department = $v->department ?? ($derivedDepartments[$uId] ?? null);

            return [
                'id'                 => $v->id,
                'user_id'            => $uId,
                'name'               => $v->user?->name ?? 'متطوع',
                'email'              => $v->user?->email,
                'avatar'             => $v->user?->avatar,
                'department_id'      => $department?->id,
                'department_name'    => $department ? ($department->name ?? $department->name_ar ?? 'غير محدد') : 'غير محدد',
                'is_leader'          => $isLeader,
                'status'             => $st,
                'hours_logged'       => (float) $v->hours_logged,
                'actual_points'      => $actual,
                'used_points'        => $used,
                'balance'            => $balance,
                'last_weekly_rating' => $lastRating,
                'created_at'         => $v->created_at?->toIso8601String(),
            ];
        });

        // الترتيب بحسب الاختيار
        $sortBy = $request->input('sort', 'points');
        $direction = strtolower($request->input('direction', 'desc'));

        $volunteers = $volunteers->sortBy(function ($item) use ($sortBy) {
            return match ($sortBy) {
                'name'        => $item['name'],
                'balance'     => $item['balance'],
                'last_rating' => $item['last_weekly_rating'] ?? -1,
                default       => $item['actual_points'],
            };
        }, SORT_REGULAR, $direction === 'desc')->values();

        // حساب الملخص المتأثر بالفلاتر بدقة
        $summary = [
            'total_volunteers'    => $volunteers->count(),
            'total_actual_points' => $volunteers->sum('actual_points'),
            'total_used_points'   => $volunteers->sum('used_points'),
            'total_balance'       => $volunteers->sum('balance'),
            'status_counts'       => [
                'active'    => $volunteers->where('status', 'active')->count(),
                'inactive'  => $volunteers->where('status', 'inactive')->count(),
                'on_leave'  => $volunteers->where('status', 'on_leave')->count(),
                'withdrawn' => $volunteers->where('status', 'withdrawn')->count(),
            ],
            // متطوعون فعّالون في الفريق بلا حساب مستخدم: لا يمكنهم الاحتفاظ بنقاط أثر
            'without_account'     => $withoutAccount,
        ];

        return response()->json([
            'success' => true,
            'data'    => [
                'volunteers'  => $volunteers,
                'departments' => Department::select('id', 'name')->orderBy('name')->get(),
                'summary'     => $summary,
            ],
        ]);
    }

    /**
     * تعديل حالة المتطوع مع تسجيل الأثر في جدول الحركات داخل Transaction واحدة
     */
    public function updateStatus(Request $request, VolunteerProfile $volunteer): JsonResponse
    {
        $actor = $request->user();

        // الصلاحية محصورة في السوبر أدمن، الإدارة التنفيذية، ومدير الموارد البشرية
        $allowedRoles = ['super_admin', 'executive_admin', 'hr_manager', 'admin', 'tech_admin'];
        if (! in_array($actor->role, $allowedRoles, true)) {
            return response()->json([
                'success' => false,
                'message' => 'تعديل حالة المتطوع مخصص لمدير الموارد البشرية والإدارة التنفيذية فقط.',
            ], 403);
        }

        $data = $request->validate([
            'status' => 'required|in:active,inactive,on_leave,withdrawn',
            'reason' => 'required|string|min:3|max:1000',
        ]);

        $oldStatus = $volunteer->status;
        $newStatus = $data['status'];
        $reason = trim($data['reason']);

        DB::transaction(function () use ($volunteer, $oldStatus, $newStatus, $reason, $actor, $request) {
            $volunteer->update(['status' => $newStatus]);

            VolunteerStatusLog::create([
                'volunteer_profile_id' => $volunteer->id,
                'user_id'              => $volunteer->user_id,
                'old_status'           => $oldStatus,
                'new_status'           => $newStatus,
                'reason'               => $reason,
                'changed_by'           => $actor->id,
            ]);

            ActivityLogger::log('volunteer.status_changed', $volunteer, [
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'reason'     => $reason,
            ], $request);
        });

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث حالة المتطوع وتوثيق الحركة في سجل النشاط بنجاح.',
            'data'    => [
                'id'         => $volunteer->id,
                'status'     => $newStatus,
                'old_status' => $oldStatus,
            ],
        ]);
    }

    /**
     * سجل النشاط: الخط الزمني لتغييرات الحالة للمتطوع
     */
    public function statusLogs(VolunteerProfile $volunteer): JsonResponse
    {
        $logs = $volunteer->statusLogs()
            ->with('changer:id,name,avatar')
            ->get()
            ->map(function (VolunteerStatusLog $log) {
                return [
                    'id'              => $log->id,
                    'old_status'      => $log->old_status,
                    'new_status'      => $log->new_status,
                    'reason'          => $log->reason,
                    'changed_by_name' => $log->changer?->name ?? 'مسؤول النظام',
                    'changed_by_id'   => $log->changed_by,
                    'created_at'      => $log->created_at?->toIso8601String(),
                    'date_formatted'  => $log->created_at ? Carbon::parse($log->created_at)->locale('ar')->isoFormat('D MMMM YYYY، h:mm a') : '—',
                ];
            });

        return response()->json([
            'success' => true,
            'data'    => $logs,
        ]);
    }

    /**
     * البيانات الشخصية للمتطوع: ملفه التطوعي وحسابه وطلب التطوع الأصلي وملف الفريق. لا تُعاد كلمة المرور ولا الرموز ولا أسرار المصادقة.
     */
    public function profile(Request $request, VolunteerProfile $volunteer, VolunteerSourceResolver $resolver, VolunteerHubSyncService $sync): JsonResponse
    {
        $volunteer->loadMissing(['user', 'department']);
        $user = $volunteer->user;

        $found = $resolver->resolve($volunteer);
        /** @var VolunteerRequest|null $applicantRequest */
        $applicantRequest = $found['request'];
        $team = $found['team_profile'];

        $applicantRequest?->load(['acceptedBy:id,name', 'reviewer:id,name']);

        $department = $this->departmentFor($volunteer, $sync);

        // Audit every read of this personal data: who looked at which volunteer, never the values themselves.
        try {
            ActivityLogger::log('volunteer.profile_viewed', $volunteer, [
                'volunteer_profile_id' => $volunteer->id,
                'source'               => $found['source'],
                'match'                => $found['match'],
            ], $request);
        } catch (\Throwable $e) {
            report($e); // a failing audit write must not turn a read into a 500
        }

        $st = in_array($volunteer->status, VolunteerProfile::STATUSES, true) ? $volunteer->status : VolunteerProfile::STATUS_ACTIVE;

        return response()->json([
            'success' => true,
            'data'    => [
                'volunteer' => [
                    'id'              => $volunteer->id,
                    'user_id'         => $volunteer->user_id,
                    'name'            => $user?->name ?? 'متطوع',
                    'email'           => $user?->email,
                    // The request's phone is only trusted when the request is certainly this person's (linked by the team profile), never by e-mail alone.
                    'phone'           => $user?->phone ?: ($team?->phone ?: ($found['match'] === 'email' ? null : $applicantRequest?->phone)),
                    'status'          => $st,
                    'department_id'   => $department?->id,
                    'department_name' => $this->departmentLabel($department),
                    'hours_logged'    => (float) $volunteer->hours_logged,
                    'created_at'      => $volunteer->created_at?->toIso8601String(),
                    'account'         => [
                        'has_account'          => $user !== null,
                        'email_verified'       => $user?->email_verified_at !== null,
                        'must_change_password' => (bool) ($user?->must_change_password ?? false),
                        'created_at'           => $user?->created_at?->toIso8601String(),
                    ],
                ],
                'request'      => $applicantRequest ? (new VolunteerRequestResource($applicantRequest))->resolve($request) : null,
                'source'       => $found['source'],
                // How the request was linked: team_source | converted_member | email (null without a request)
                'match'        => $found['match'],
                'team_profile' => $team ? [
                    'id'          => $team->id,
                    'position_ar' => $team->position_ar,
                    'member_type' => $team->member_type,
                    'joined_at'   => $team->joined_at?->toIso8601String(),
                    'is_active'   => (bool) $team->is_active,
                    'phone'       => $team->phone,
                    'email'       => $team->email,
                    'country'     => $team->country,
                    'city'        => $team->city,
                    'gender'      => $team->gender,
                ] : null,
            ],
        ]);
    }

    /**
     * السجل الزمني الحقيقي لنشاط المتطوع (الأحدث أولًا): التقديم والقبول والتحويل وإنشاء الملف وتغييرات الحالة والنقاط والتقييمات.
     * يضمّ الملف التطوعي دائمًا حدث «إنشاء الملف التطوعي»، فلا يكون السجل فارغًا أبدًا.
     */
    public function activity(VolunteerProfile $volunteer, VolunteerSourceResolver $resolver, VolunteerHubSyncService $sync): JsonResponse
    {
        $volunteer->loadMissing(['user', 'department']);
        $userId = $volunteer->user_id ? (int) $volunteer->user_id : null;
        $limit  = 100;

        /** @var list<array{at: Carbon, rank: int, event: array<string, mixed>}> $events */
        $events = [];
        $push = function (string $id, string $type, string $title, ?string $description, ?Carbon $at, ?string $actor, array $meta = [], int $rank = 0) use (&$events) {
            if ($at === null) {
                return;
            }
            $events[] = ['at' => $at, 'rank' => $rank, 'event' => [
                'id'             => $id,
                'type'           => $type,
                'title'          => $title,
                'description'    => $description,
                'occurred_at'    => $at->toIso8601String(),
                'date_formatted' => $at->copy()->locale('ar')->isoFormat('D MMMM YYYY، h:mm a'),
                'actor_name'     => $actor,
                'meta'           => (object) $meta,
            ]];
        };

        // ── الطلب الأصلي: تقديم / قبول / تحويل ──────────────────────────────
        $found = $resolver->resolve($volunteer);
        /** @var VolunteerRequest|null $applicantRequest */
        $applicantRequest = $found['request'];
        if ($applicantRequest !== null) {
            $applicantRequest->load(['acceptedBy:id,name', 'convertedBy:id,name']);
            $reqId = $applicantRequest->id;

            $push("request_submitted_{$reqId}", 'request_submitted', 'قدّم طلب التطوع',
                $applicantRequest->desired_department ? 'الإدارة المطلوبة: ' . $applicantRequest->desired_department : null,
                $applicantRequest->created_at, null,
                ['request_id' => $reqId, 'desired_department' => $applicantRequest->desired_department, 'match' => $found['match']], 0);

            $acceptedAt = $applicantRequest->accepted_at
                ?? (in_array($applicantRequest->status, ['accepted', 'converted_to_member'], true) ? $applicantRequest->reviewed_at : null);
            $push("request_accepted_{$reqId}", 'request_accepted', 'قُبل طلب التطوع', null,
                $acceptedAt, $applicantRequest->acceptedBy?->name, ['request_id' => $reqId, 'match' => $found['match']], 1);

            $department = $this->departmentFor($volunteer, $sync);
            $push("converted_to_member_{$reqId}", 'converted_to_member', 'تحويل إلى عضو وإنشاء حساب',
                $department ? $this->departmentLabel($department) : null,
                $applicantRequest->converted_to_member_at, $applicantRequest->convertedBy?->name,
                ['request_id' => $reqId, 'member_id' => $applicantRequest->converted_member_id, 'department_id' => $department?->id, 'match' => $found['match']], 2);
        }

        // ── إنشاء الملف التطوعي (موجود دائمًا) ───────────────────────────────
        $push("profile_created_{$volunteer->id}", 'profile_created', 'إنشاء الملف التطوعي في مركز المتطوعين', null,
            $volunteer->created_at, null, ['volunteer_profile_id' => $volunteer->id, 'status' => $volunteer->status], 3);

        // ── تغييرات الحالة ───────────────────────────────────────────────────
        $statusLabels = ['active' => 'نشط', 'inactive' => 'غير نشط', 'on_leave' => 'في إجازة', 'withdrawn' => 'منسحب'];
        $label = fn (?string $v) => $v === null ? '—' : ($statusLabels[$v] ?? $v);

        VolunteerStatusLog::query()
            ->where('volunteer_profile_id', $volunteer->id)
            ->with('changer:id,name')
            ->latest()->latest('id')->limit($limit)
            ->get()
            ->each(function (VolunteerStatusLog $log) use ($push, $label) {
                $push("status_change_{$log->id}", 'status_change', 'تغيير حالة المتطوع',
                    'من «' . $label($log->old_status) . '» إلى «' . $label($log->new_status) . '»' . ($log->reason ? ' — ' . $log->reason : ''),
                    $log->created_at, $log->changer?->name,
                    ['old_status' => $log->old_status, 'new_status' => $log->new_status, 'reason' => $log->reason], 4);
            });

        if ($userId !== null) {
            // ── نقاط الأثر ───────────────────────────────────────────────────
            VolunteerPointAward::query()
                ->where('user_id', $userId)
                ->with('awarder:id,name')
                ->orderByDesc('awarded_at')->orderByDesc('id')->limit($limit)
                ->get()
                ->each(function (VolunteerPointAward $a) use ($push) {
                    $pts      = (int) $a->points;
                    $positive = $pts >= 0;
                    $push("points_{$a->id}", $positive ? 'points_awarded' : 'points_deducted',
                        $positive ? 'منح نقاط أثر' : 'خصم نقاط أثر',
                        ($positive ? '+' : '-') . abs($pts) . ' نقطة — ' . ($a->reason ?: 'منح نقاط أثر'),
                        $a->awarded_at ?? $a->created_at, $a->awarder?->name,
                        ['points' => $pts, 'category' => $a->category, 'reason' => $a->reason], 5);
                });

            // ── الاستبدال ────────────────────────────────────────────────────
            VolunteerPointRedemption::query()
                ->where('user_id', $userId)
                ->with('approver:id,name')
                ->latest()->latest('id')->limit($limit)
                ->get()
                ->each(function (VolunteerPointRedemption $r) use ($push) {
                    $push("redemption_{$r->id}", 'points_redeemed', 'استبدال نقاط أثر',
                        abs((int) $r->points) . ' نقطة — ' . ($r->purpose ?: 'طلب استبدال مكافأة'),
                        $r->created_at, $r->approver?->name,
                        ['points' => abs((int) $r->points), 'purpose' => $r->purpose], 5);
                });

            // ── التقييمات الأسبوعية (لا يحفظ الجدول مُقيِّمًا، فيبقى الفاعل فارغًا) ──
            WeeklyReportRating::query()
                ->where('user_id', $userId)
                ->with('report.department')
                ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)
                ->get()
                ->each(function (WeeklyReportRating $r) use ($push) {
                    $week = $r->report?->week_start ? Carbon::parse($r->report->week_start)->format('Y-m-d') : null;
                    $avg  = $r->avg_score !== null ? round((float) $r->avg_score, 1) : null;
                    $push("rating_{$r->id}", 'weekly_rating', 'تقييم أسبوعي',
                        ($avg !== null ? 'المتوسط ' . $avg : 'تقييم') . ($week ? ' — أسبوع ' . $week : ''),
                        $r->created_at, null,
                        ['avg_score' => $avg, 'report_id' => $r->weekly_department_report_id, 'week_start' => $week,
                         'department_name' => $r->report?->department?->name, 'note' => $r->note], 5);
                });
        }

        // الأحدث أولًا؛ عند تساوي الوقت يأتي الحدث الأعلى رتبة منطقيًا (التحويل قبل القبول قبل التقديم).
        usort($events, fn ($a, $b) => [$b['at']->getTimestamp(), $b['rank']] <=> [$a['at']->getTimestamp(), $a['rank']]);

        return response()->json([
            'success' => true,
            'data'    => array_map(fn ($e) => $e['event'], array_slice($events, 0, $limit)),
        ]);
    }

    /** Department of a hub volunteer, the way the list shows it: the profile's own, else the derived fallback (display only). */
    private function departmentFor(VolunteerProfile $volunteer, VolunteerHubSyncService $sync): ?Department
    {
        if ($volunteer->department !== null) {
            return $volunteer->department;
        }

        return $volunteer->user_id ? ($sync->derivedDepartments([(int) $volunteer->user_id])[(int) $volunteer->user_id] ?? null) : null;
    }

    private function departmentLabel(?Department $department): string
    {
        return $department ? ($department->name ?? $department->name_ar ?? 'غير محدد') : 'غير محدد';
    }

    /**
     * نقاط الأثر: جدول حركات النقاط الكامل (Ledger) للمتطوع
     */
    public function pointsLedger(VolunteerProfile $volunteer): JsonResponse
    {
        $userId = $volunteer->user_id;

        $awards = VolunteerPointAward::where('user_id', $userId)
            ->with('awarder:id,name')
            ->get();

        $redemptions = VolunteerPointRedemption::where('user_id', $userId)
            ->with('approver:id,name')
            ->get();

        $ledger = [];

        foreach ($awards as $a) {
            $pts = (int) $a->points;
            $ledger[] = [
                'id'          => 'award_' . $a->id,
                'date'        => ($a->awarded_at ?? $a->created_at)?->toIso8601String(),
                'category'    => $a->category,
                'reason'      => $a->reason ?? 'منح نقاط أثر',
                'points'      => $pts,
                'type'        => $pts >= 0 ? 'earn' : 'deduction',
                'source'      => $a->source_type ? class_basename($a->source_type) : 'منح إداري/آلي',
                'creator'     => $a->awarder?->name ?? 'النظام',
            ];
        }

        foreach ($redemptions as $r) {
            $pts = (int) $r->points;
            $ledger[] = [
                'id'          => 'redemption_' . $r->id,
                'date'        => $r->created_at?->toIso8601String(),
                'category'    => 'استبدال/صرف',
                'reason'      => $r->purpose ?? $r->note ?? 'طلب استبدال مكافأة',
                'points'      => -abs($pts),
                'type'        => 'redemption',
                'source'      => 'استبدال مكافأة',
                'creator'     => $r->approver?->name ?? 'إدارة الموارد',
            ];
        }

        // الترتيب من الأحدث للأقدم
        usort($ledger, fn ($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));

        $actual = (int) $awards->where('points', '>', 0)->sum('points');
        $deductions = (int) abs($awards->where('points', '<', 0)->sum('points'));
        $used = $deductions + (int) $redemptions->sum('points');
        $balance = $actual - $used;

        return response()->json([
            'success' => true,
            'data'    => [
                'ledger'        => $ledger,
                'actual_points' => $actual,
                'used_points'   => $used,
                'balance'       => $balance,
            ],
        ]);
    }

    /**
     * سجل التقييمات الأسبوعية بالمعايير العشرة ومتوسطاتها
     */
    public function weeklyRatings(VolunteerProfile $volunteer): JsonResponse
    {
        $userId = $volunteer->user_id;

        $ratings = WeeklyReportRating::where('user_id', $userId)
            ->with(['report.department'])
            ->get();

        if ($ratings->isEmpty()) {
            return response()->json([
                'success' => true,
                'data'    => [
                    'ratings'           => [],
                    'monthly_averages'  => [],
                    'criteria_averages' => [],
                    'overall_avg'       => null,
                ],
            ]);
        }

        $formattedRatings = $ratings->map(function (WeeklyReportRating $r) {
            $date = $r->report?->week_start ?? $r->report?->created_at ?? $r->created_at;
            return [
                'id'              => $r->id,
                'report_id'       => $r->weekly_department_report_id,
                'date'            => $date ? Carbon::parse($date)->format('Y-m-d') : null,
                'department_name' => $r->report?->department?->name ?? 'الإدارة',
                'scores'          => $r->scores ?? [],
                'avg_score'       => round((float) $r->avg_score, 1),
                'note'            => $r->note,
            ];
        })->sortByDesc('date')->values();

        // حساب متوسط كل شهر
        $months = [];
        foreach ($formattedRatings as $fr) {
            if (! $fr['date']) continue;
            $mKey = substr($fr['date'], 0, 7); // YYYY-MM
            if (! isset($months[$mKey])) {
                $cDate = Carbon::parse($fr['date'])->locale('ar');
                $months[$mKey] = [
                    'month_key'   => $mKey,
                    'month_label' => $cDate->isoFormat('MMMM YYYY'),
                    'scores_sum'  => 0,
                    'count'       => 0,
                ];
            }
            $months[$mKey]['scores_sum'] += $fr['avg_score'];
            $months[$mKey]['count'] += 1;
        }

        $monthlyAverages = collect($months)->map(function ($m) {
            return [
                'month_key'   => $m['month_key'],
                'month_label' => $m['month_label'],
                'avg_score'   => round($m['scores_sum'] / max(1, $m['count']), 1),
                'count'       => $m['count'],
            ];
        })->sortByDesc('month_key')->values()->all();

        // حساب متوسط كل معيار من المعايير العشرة
        $criteriaTotals = [];
        $criteriaCounts = [];
        foreach ($formattedRatings as $fr) {
            if (is_array($fr['scores'])) {
                foreach ($fr['scores'] as $critKey => $critVal) {
                    if (is_numeric($critVal) && $critVal > 0) {
                        $criteriaTotals[$critKey] = ($criteriaTotals[$critKey] ?? 0) + (float) $critVal;
                        $criteriaCounts[$critKey] = ($criteriaCounts[$critKey] ?? 0) + 1;
                    }
                }
            }
        }

        $criteriaAverages = [];
        foreach ($criteriaTotals as $k => $sum) {
            $cnt = $criteriaCounts[$k] ?? 1;
            $criteriaAverages[$k] = round($sum / max(1, $cnt), 1);
        }

        $overallAvg = round($formattedRatings->avg('avg_score'), 1);

        return response()->json([
            'success' => true,
            'data'    => [
                'ratings'           => $formattedRatings,
                'monthly_averages'  => $monthlyAverages,
                'criteria_averages' => $criteriaAverages,
                'overall_avg'       => $overallAvg,
            ],
        ]);
    }
}
