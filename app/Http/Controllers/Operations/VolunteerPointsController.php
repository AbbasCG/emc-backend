<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\VolunteerPointAward;
use App\Models\VolunteerPointRedemption;
use App\Services\AuditLogService;
use App\Services\ImpactPointsRecipients;
use App\Support\ImpactLevels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * نقاط أثر EMC — تنفيذ «سياسة التقدير والمكافآت والتدرج للمتطوعين v1.0 (2026)».
 *
 * Lifetime = مجموع كل المنح (لا ينقص عند الاستبدال).
 * Available = Lifetime - مجموع الاستبدالات.
 * 1,000 نقطة = €10 رصيد تعليمي داخلي غير نقدي.
 *
 * السجل «دفتر» للإضافة فقط: لا تعديل ولا حذف لصفوفه. التصحيح صفٌّ جديد منسوب
 * لصاحبه (فئة adjustment) — لا استبدال للمجموع أبدًا.
 *
 * المستوى يُحتسب من شرطين معًا (المدة تعطي الأهلية، والنقاط تثبت الاستحقاق)؛
 * والاعتماد النوعي لمستوى «رائد أثر EMC» قرار إداري لا يُمنح آليًا — لذلك
 * تُعيد الواجهة البرمجية «المستوى المؤهَّل له» ويبقى الاعتماد النهائي للإدارة.
 *
 * الصلاحية التجارية (عرض القائمة/السجل، المنح، التصحيح) تُفحص هنا من خلال
 * ImpactPointsRecipients — لا من الأدوار ولا من صلاحية فتح الصفحة.
 */
class VolunteerPointsController extends Controller
{
    /** سلم المستويات — المصدر الوحيد في App\Support\ImpactLevels. */
    public const LEVELS = ImpactLevels::LEVELS;

    /** €10 لكل 1,000 نقطة. */
    public const EURO_PER_1000 = 10;

    /**
     * السقف الشهري لنقاط المستلم الموجبة (كل المصادر) — يُعلَن في policy()
     * ويُطبَّق على المنح اليدوية والتصحيحات الموجبة. الخصم (نقاط سالبة) لا
     * يُحتسب ضمنه.
     */
    public const MONTHLY_CAP = 2500;

    /** أقصى عدد صفوف للوحة الصدارة لغير المخوَّلين — لا قائمة كاملة بالأسماء. */
    private const LEADERBOARD_MAX = 20;

    private const MEMBERS_SORTS = ['points', 'recent', 'department', 'name'];

    public function __construct(private readonly ImpactPointsRecipients $recipients)
    {
    }

    private function summarize(User $user): array
    {
        $lifetime = (int) VolunteerPointAward::where('user_id', $user->id)->sum('points');
        $redeemed = (int) VolunteerPointRedemption::where('user_id', $user->id)->sum('points');
        $months   = (int) $user->created_at->diffInMonths(now());

        $eligible = ImpactLevels::eligible($lifetime, $months);

        return [
            'lifetime_points'  => $lifetime,
            'available_points' => max(0, $lifetime - $redeemed),
            'redeemed_points'  => $redeemed,
            'credit_eur'       => round(max(0, $lifetime - $redeemed) / 1000 * self::EURO_PER_1000, 2),
            'active_months'    => $months,
            'eligible_level'   => $eligible,
            'next_level'       => ImpactLevels::next($eligible),
        ];
    }

    /** ملخصي: النقاط والمستوى المؤهَّل له وباقة سديم + صلاحياتي المحسوبة من الخادم. */
    public function mySummary(Request $request): JsonResponse
    {
        $actor = $request->user();
        $capabilities = $this->recipients->capabilities($actor);

        // Capabilities come from the server so the page never guesses business
        // authority from a role list; every endpoint re-checks them anyway.
        return response()->json(['success' => true, 'data' => $this->summarize($actor) + [
            'can_award'    => $capabilities['can_award'],
            'capabilities' => $capabilities,
            'monthly_cap'  => self::MONTHLY_CAP,
        ]]);
    }

    /** المستحقون الذين يجوز للمستخدم منحهم — أعضاء فريق EMC الفعليون ضمن نطاقه. */
    public function recipients(Request $request): JsonResponse
    {
        if (! $this->recipients->canAward($request->user())) {
            return $this->forbidden();
        }

        return response()->json(['success' => true, 'data' => $this->recipients->forActor($request->user())]);
    }

    private function forbidden(string $message = 'ليست لديك صلاحية منح نقاط الأثر.'): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], 403);
    }

    private function rejected(string $message, string $code): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'code' => $code], 422);
    }

    /**
     * لا يجوز أن ينزل الرصيد التراكمي عن النقاط المستبدلة أصلًا: الرصيد التعليمي
     * صُرف مقابلها، وخصمها بأثر رجعي يجعل المتاح صفرًا بلا مقابل.
     */
    private function balanceViolation(int $userId, int $signedPoints): ?JsonResponse
    {
        if ($signedPoints >= 0) {
            return null;
        }

        $lifetime = (int) VolunteerPointAward::where('user_id', $userId)->sum('points');
        $redeemed = (int) VolunteerPointRedemption::where('user_id', $userId)->sum('points');

        if ($lifetime + $signedPoints < max(0, $redeemed)) {
            $max = max(0, $lifetime - max(0, $redeemed));

            return $this->rejected(
                "لا يمكن خصم ".abs($signedPoints)." نقطة: الرصيد التراكمي {$lifetime} والمستبدل {$redeemed}. أقصى خصم ممكن {$max} نقطة.",
                'NEGATIVE_BALANCE'
            );
        }

        return null;
    }

    private function monthlyCapViolation(int $userId, int $signedPoints): ?JsonResponse
    {
        if ($signedPoints <= 0) {
            return null;
        }

        $used = $this->recipients->monthPoints($userId);
        if ($used + $signedPoints > self::MONTHLY_CAP) {
            $left = max(0, self::MONTHLY_CAP - $used);

            return $this->rejected("يتجاوز السقف الشهري (".self::MONTHLY_CAP." نقطة) — المتبقي لهذا العضو هذا الشهر {$left} نقطة.", 'MONTHLY_CAP_EXCEEDED');
        }

        return null;
    }

    /** سجل منحي (أحدث أولًا). الملاحظة الداخلية لا تظهر هنا أبدًا. */
    public function myAwards(Request $request): JsonResponse
    {
        $awards = VolunteerPointAward::query()
            ->where('user_id', $request->user()->id)
            ->with('awarder:id,name')
            ->orderByDesc('awarded_at')
            ->paginate((int) ($request->per_page ?? 20));

        return response()->json(['success' => true, 'data' => $awards]);
    }

    /**
     * قائمة كل من لديهم نقاط ضمن نطاق المستخدم — للمخوَّلين فقط.
     *
     * الصفوف هي المستحقون الفعليون (عضو فريق EMC نشط)، فلا يظهر طالب LMS ولا
     * مدرّس دورات. الفلاتر والفرز والترقيم هنا؛ مؤشرات KPI تُحسب على كامل
     * النطاق لا على الصفحة الحالية.
     */
    public function members(Request $request): JsonResponse
    {
        $actor = $request->user();

        if (! $this->recipients->canViewAll($actor)) {
            return $this->forbidden('ليست لديك صلاحية عرض قائمة المستفيدين من نقاط الأثر.');
        }

        $params = $request->validate([
            'search'           => 'nullable|string|max:100',
            'department_id'    => 'nullable|integer',
            'level'            => 'nullable|string|max:40',
            'sort'             => 'nullable|in:'.implode(',', self::MEMBERS_SORTS),
            'direction'        => 'nullable|in:asc,desc',
            'page'             => 'nullable|integer|min:1',
            'per_page'         => 'nullable|integer|min:1|max:100',
            'only_with_points' => 'nullable|boolean',
        ]);

        /** @var Collection<int, array<string, mixed>> $scope */
        $scope = $this->recipients->forActor($actor, includeSelf: true)
            ->map(fn (array $r) => $r + ['is_self' => $r['user_id'] === $actor->id]);

        $withPoints = $scope->filter(fn ($r) => $r['awards_count'] > 0);
        $top = $scope->filter(fn ($r) => $r['month_points'] > 0)->sortByDesc('month_points')->first();

        $kpis = [
            'total_points'        => (int) $withPoints->sum('lifetime_points'),
            'members_with_points' => $withPoints->count(),
            'scope_members'       => $scope->count(),
            'month_points_total'  => (int) $scope->sum('month_points'),
            'top_contributor_month' => $top ? [
                'user_id'    => $top['user_id'],
                'name'       => $top['name'],
                'department' => $top['department'],
                'points'     => $top['month_points'],
            ] : null,
        ];

        $filterOptions = [
            'departments' => $scope->unique('department_id')->map(fn ($r) => ['id' => $r['department_id'], 'name' => $r['department']])
                ->sortBy('name')->values()->all(),
            'levels' => collect(ImpactLevels::LEVELS)->map(fn ($l) => ['id' => $l['id'], 'title' => $l['title']])->all(),
        ];

        $rows = ($request->boolean('only_with_points', true) ? $withPoints : $scope);

        if (filled($params['search'] ?? null)) {
            $needle = mb_strtolower(trim($params['search']));
            $rows = $rows->filter(fn ($r) => str_contains(mb_strtolower($r['name'].' '.($r['department'] ?? '').' '.($r['role_title'] ?? '')), $needle));
        }
        if (filled($params['department_id'] ?? null)) {
            $rows = $rows->where('department_id', (int) $params['department_id']);
        }
        if (filled($params['level'] ?? null)) {
            $rows = $rows->filter(fn ($r) => $r['level']['id'] === $params['level']);
        }

        $sort = $params['sort'] ?? 'points';
        $desc = ($params['direction'] ?? ($sort === 'name' || $sort === 'department' ? 'asc' : 'desc')) === 'desc';

        $rows = match ($sort) {
            'recent'     => $rows->sortBy(fn ($r) => $r['last_activity_at'] ?? '', SORT_REGULAR, $desc),
            'department' => $rows->sortBy(fn ($r) => [$r['department'] ?? '', -$r['lifetime_points']], SORT_REGULAR, $desc),
            'name'       => $rows->sortBy('name', SORT_REGULAR, $desc),
            default      => $rows->sortBy('lifetime_points', SORT_REGULAR, $desc),
        };

        $perPage = (int) ($params['per_page'] ?? 20);
        $page = max(1, (int) ($params['page'] ?? 1));
        $total = $rows->count();

        return response()->json([
            'success' => true,
            'data'    => $rows->forPage($page, $perPage)->values(),
            'meta'    => [
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'last_page'    => max(1, (int) ceil($total / $perPage)),
                'kpis'         => $kpis,
                'filters'      => $filterOptions,
            ],
            'capabilities' => $this->recipients->capabilities($actor),
        ]);
    }

    /**
     * سجل نقاط عضو: صاحبه دائمًا، وغيره للمخوَّلين ضمن نطاقهم فقط. الملاحظة
     * الداخلية لا تظهر إلا للمخوَّل وهو يقرأ سجل غيره.
     */
    public function history(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        $own = $actor->id === $user->id;

        if (! $own && ! $this->recipients->canViewHistoryOf($actor, $user->id)) {
            return $this->forbidden('ليست لديك صلاحية عرض سجل نقاط هذا العضو.');
        }

        $perPage = max(1, min(100, (int) ($request->per_page ?? 20)));

        $entries = VolunteerPointAward::query()
            ->where('user_id', $user->id)
            ->with('awarder:id,name')
            ->orderByDesc('awarded_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        if (! $own) {
            $entries->getCollection()->each->makeVisible('note');
        }

        $profile = $this->recipients->describe($user->id);

        return response()->json([
            'success' => true,
            'data'    => [
                'user'    => ['id' => $user->id, 'name' => $user->name] + [
                    'department' => $profile['department'] ?? null,
                    'role_title' => $profile['role_title'] ?? null,
                ],
                'summary' => $this->summarize($user),
                'entries' => $entries,
            ],
        ]);
    }

    /** منح نقاط — لحاملي صلاحية التكريم، لأعضاء الفريق ضمن نطاقهم فقط. */
    public function award(Request $request): JsonResponse
    {
        $actor = $request->user();

        if (! $this->recipients->canAward($actor)) {
            return $this->forbidden();
        }

        $data = $request->validate([
            'user_id'  => 'required|exists:users,id',
            'category' => 'required|in:' . implode(',', VolunteerPointAward::CATEGORIES),
            'points'   => 'required|integer|min:-5000|max:5000|not_in:0',
            'reason'   => 'required|string|max:500',
        ]);

        $recipientId = (int) $data['user_id'];

        if ($recipientId === $actor->id) {
            return $this->rejected('لا يمكنك منح نقاط لنفسك.', 'SELF_AWARD');
        }

        if (! $this->recipients->isAwardableBy($actor, $recipientId)) {
            return $this->rejected('هذا المستخدم ليس عضوًا فعّالًا في فريق EMC ضمن نطاق صلاحيتك.', 'RECIPIENT_NOT_ELIGIBLE');
        }

        if ($violation = $this->monthlyCapViolation($recipientId, $data['points']) ?? $this->balanceViolation($recipientId, $data['points'])) {
            return $violation;
        }

        $award = VolunteerPointAward::create([
            ...$data,
            'awarded_by' => $actor->id,
            'awarded_at' => now(),
        ]);

        AuditLogService::log('IMPACT_POINTS_AWARDED', 'VolunteerPointAward', $award->id, null, null, [
            'user_id' => $recipientId, 'category' => $data['category'], 'points' => $data['points'],
        ]);

        return response()->json([
            'success' => true,
            'data'    => $award->load(['user:id,name', 'awarder:id,name']),
        ], 201);
    }

    /**
     * تصحيح نقاط عضو (إضافة أو خصم) — صفٌّ جديد في الدفتر، لا استبدال للمجموع.
     *
     * السبب إلزامي ويظهر للعضو في سجله؛ الملاحظة اختيارية وداخلية للمخوَّلين.
     */
    public function adjust(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();

        if (! $this->recipients->canAdjust($actor)) {
            return $this->forbidden('ليست لديك صلاحية تصحيح نقاط الأثر.');
        }

        $data = $request->validate([
            'direction' => 'required|in:add,subtract',
            'points'    => 'required|integer|min:1|max:5000',
            'reason'    => 'required|string|min:3|max:500',
            'note'      => 'nullable|string|max:1000',
        ]);

        if ($user->id === $actor->id) {
            return $this->rejected('لا يمكنك تصحيح نقاط نفسك.', 'SELF_ADJUSTMENT');
        }

        if (! $this->recipients->isAwardableBy($actor, $user->id)) {
            return $this->rejected('هذا المستخدم ليس عضوًا فعّالًا في فريق EMC ضمن نطاق صلاحيتك.', 'RECIPIENT_NOT_ELIGIBLE');
        }

        $signed = $data['direction'] === 'add' ? (int) $data['points'] : -(int) $data['points'];

        // Lock the member's row so two simultaneous adjustments cannot both pass
        // the cap/balance check against the same old total.
        $result = DB::transaction(function () use ($user, $actor, $signed, $data) {
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            if ($violation = $this->monthlyCapViolation($user->id, $signed) ?? $this->balanceViolation($user->id, $signed)) {
                return $violation;
            }

            $before = (int) VolunteerPointAward::where('user_id', $user->id)->sum('points');

            $entry = VolunteerPointAward::create([
                'user_id'    => $user->id,
                'category'   => VolunteerPointAward::CATEGORY_ADJUSTMENT,
                'points'     => $signed,
                'reason'     => trim($data['reason']),
                'note'       => filled($data['note'] ?? null) ? trim($data['note']) : null,
                'awarded_by' => $actor->id,
                'awarded_at' => now(),
            ]);

            // Before/after totals are the audit trail's point: who moved whose
            // balance from what to what, and why. The internal note's TEXT stays
            // in the ledger row only; the log records that one exists.
            AuditLogService::log(
                action:      'IMPACT_POINTS_ADJUSTED',
                entityType:  'VolunteerPointAward',
                entityId:    $entry->id,
                entityName:  $user->name,
                oldValues:   ['total_points' => $before],
                newValues:   ['total_points' => $before + $signed],
                extra:       [
                    'user_id'   => $user->id,
                    'points'    => $signed,
                    'direction' => $data['direction'],
                    'reason'    => $entry->reason,
                    'has_note'  => $entry->note !== null,
                ],
                description: "تصحيح نقاط أثر {$user->name}: ".($signed > 0 ? '+' : '').$signed,
            );

            return ['entry' => $entry, 'before' => $before, 'after' => $before + $signed];
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        $result['entry']->makeVisible('note')->load('awarder:id,name');

        return response()->json([
            'success' => true,
            'data'    => [
                'entry'        => $result['entry'],
                'total_before' => $result['before'],
                'total_after'  => $result['after'],
            ],
        ], 201);
    }

    /**
     * لوحة صدارة الأثر — تُغذي Hall of Impact واختيار أفضل متطوع.
     *
     * فقط أعضاء EMC الفعليون (لا طلاب LMS ولا مدرّسو دورات). وللمطّلع العادي
     * سقفٌ على عدد الصفوف: القائمة الكاملة بالأسماء حقٌّ للمخوَّلين عبر members().
     */
    public function leaderboard(Request $request): JsonResponse
    {
        $max = $this->recipients->canViewAll($request->user()) ? 100 : self::LEADERBOARD_MAX;
        $limit = max(1, min($max, (int) ($request->limit ?? self::LEADERBOARD_MAX)));

        // whereHas('user'): awards survive a user's SOFT delete (the FK only
        // cascades on a hard delete), and the eager load below would then yield
        // `user: null` — a row the client cannot render. Such rows are dropped
        // server-side instead of shipping a null identity to every reader.
        $rows = VolunteerPointAward::query()
            ->select('user_id', DB::raw('SUM(points) as lifetime_points'))
            ->whereHas('user')
            ->whereIn('user_id', $this->recipients->validRecipientIds())
            ->when($request->since, fn ($q) => $q->where('awarded_at', '>=', $request->since))
            ->groupBy('user_id')
            ->orderByDesc('lifetime_points')
            ->with('user:id,name,role')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'user'            => $row->user,
                'lifetime_points' => (int) $row->lifetime_points,
            ]);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    /** استبدال نقاط برصيد تعليمي — تسجيل معتمد من HR/الإدارة. */
    public function redeem(Request $request): JsonResponse
    {
        $actor = $request->user();

        if (! $this->recipients->canAward($actor)) {
            return $this->forbidden();
        }

        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'points'  => 'required|integer|min:100|max:100000',
            'purpose' => 'required|string|max:255',
            'note'    => 'nullable|string|max:500',
        ]);

        if ((int) $data['user_id'] === $actor->id) {
            return $this->rejected('لا يمكنك اعتماد استبدال نقاطك بنفسك.', 'SELF_REDEMPTION');
        }

        if (! $this->recipients->isAwardableBy($actor, (int) $data['user_id'])) {
            return $this->rejected('هذا المستخدم ليس عضوًا فعّالًا في فريق EMC ضمن نطاق صلاحيتك.', 'RECIPIENT_NOT_ELIGIBLE');
        }

        $user     = User::findOrFail($data['user_id']);
        $summary  = $this->summarize($user);

        if ($data['points'] > $summary['available_points']) {
            return response()->json([
                'success' => false,
                'message' => "الرصيد المتاح {$summary['available_points']} نقطة فقط.",
            ], 422);
        }

        $redemption = VolunteerPointRedemption::create([
            ...$data,
            'approved_by' => $actor->id,
        ]);

        AuditLogService::log('IMPACT_POINTS_REDEEMED', 'VolunteerPointRedemption', $redemption->id, null, null, [
            'user_id' => (int) $data['user_id'], 'points' => $data['points'],
        ]);

        return response()->json(['success' => true, 'data' => $redemption], 201);
    }

    /** مصفوفة السياسة للواجهة (المستويات + قيمة النقطة). */
    public function policy(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'levels'        => self::LEVELS,
                'euro_per_1000' => self::EURO_PER_1000,
                'monthly_cap'   => self::MONTHLY_CAP,
                'version'       => '1.0',
            ],
        ]);
    }
}
