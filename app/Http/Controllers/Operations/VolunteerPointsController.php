<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\VolunteerPointAward;
use App\Models\VolunteerPointRedemption;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * نقاط أثر EMC — تنفيذ «سياسة التقدير والمكافآت والتدرج للمتطوعين v1.0 (2026)».
 *
 * Lifetime = مجموع كل المنح (لا ينقص عند الاستبدال).
 * Available = Lifetime - مجموع الاستبدالات.
 * 1,000 نقطة = €10 رصيد تعليمي داخلي غير نقدي.
 *
 * المستوى يُحتسب من شرطين معًا (المدة تعطي الأهلية، والنقاط تثبت الاستحقاق)؛
 * والاعتماد النوعي لمستوى «رائد أثر EMC» قرار إداري لا يُمنح آليًا — لذلك
 * تُعيد الواجهة البرمجية «المستوى المؤهَّل له» ويبقى الاعتماد النهائي للإدارة.
 */
class VolunteerPointsController extends Controller
{
    /** سلم المستويات: [الاسم، أشهر الحد الأدنى، نقاط الحد الأدنى، باقة سديم]. */
    public const LEVELS = [
        ['id' => 'volunteer',   'title' => 'متطوع EMC',     'months' => 0,  'points' => 0,      'sadeem' => 'Open Community'],
        ['id' => 'contributor', 'title' => 'متطوع مشارك',   'months' => 3,  'points' => 1500,   'sadeem' => 'Standard'],
        ['id' => 'active',      'title' => 'متطوع فاعل',    'months' => 6,  'points' => 3500,   'sadeem' => 'Premium'],
        ['id' => 'advanced',    'title' => 'متطوع متقدم',   'months' => 9,  'points' => 6000,   'sadeem' => 'Premium'],
        ['id' => 'senior',      'title' => 'متطوع أول',     'months' => 12, 'points' => 10000,  'sadeem' => 'VIP'],
        ['id' => 'impact_leader', 'title' => 'رائد أثر EMC', 'months' => 24, 'points' => 22000, 'sadeem' => 'VIP+'],
    ];

    /** €10 لكل 1,000 نقطة. */
    public const EURO_PER_1000 = 10;

    private function summarize(User $user): array
    {
        $lifetime = (int) VolunteerPointAward::where('user_id', $user->id)->sum('points');
        $redeemed = (int) VolunteerPointRedemption::where('user_id', $user->id)->sum('points');
        $months   = (int) $user->created_at->diffInMonths(now());

        $eligible = self::LEVELS[0];
        foreach (self::LEVELS as $level) {
            if ($months >= $level['months'] && $lifetime >= $level['points']) {
                $eligible = $level;
            }
        }

        $next = null;
        foreach (self::LEVELS as $level) {
            if ($level['points'] > $eligible['points']) {
                $next = $level;
                break;
            }
        }

        return [
            'lifetime_points'  => $lifetime,
            'available_points' => max(0, $lifetime - $redeemed),
            'redeemed_points'  => $redeemed,
            'credit_eur'       => round(max(0, $lifetime - $redeemed) / 1000 * self::EURO_PER_1000, 2),
            'active_months'    => $months,
            'eligible_level'   => $eligible,
            'next_level'       => $next,
        ];
    }

    /** ملخصي: النقاط والمستوى المؤهَّل له وباقة سديم. */
    public function mySummary(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->summarize($request->user())]);
    }

    /** سجل منحي (أحدث أولًا). */
    public function myAwards(Request $request): JsonResponse
    {
        $awards = VolunteerPointAward::query()
            ->where('user_id', $request->user()->id)
            ->with('awarder:id,name')
            ->orderByDesc('awarded_at')
            ->paginate((int) ($request->per_page ?? 20));

        return response()->json(['success' => true, 'data' => $awards]);
    }

    /** منح نقاط — للمدراء المخوَّلين فقط (مقيَّدة في المسار). */
    public function award(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id'  => 'required|exists:users,id',
            'category' => 'required|in:' . implode(',', VolunteerPointAward::CATEGORIES),
            'points'   => 'required|integer|min:-5000|max:5000|not_in:0',
            'reason'   => 'required|string|max:500',
        ]);

        $award = VolunteerPointAward::create([
            ...$data,
            'awarded_by' => $request->user()->id,
            'awarded_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'data'    => $award->load(['user:id,name', 'awarder:id,name']),
        ], 201);
    }

    /** لوحة صدارة الأثر — تُغذي Hall of Impact واختيار أفضل متطوع. */
    public function leaderboard(Request $request): JsonResponse
    {
        // whereHas('user'): awards survive a user's SOFT delete (the FK only
        // cascades on a hard delete), and the eager load below would then yield
        // `user: null` — a row the client cannot render. Such rows are dropped
        // server-side instead of shipping a null identity to every reader.
        $rows = VolunteerPointAward::query()
            ->select('user_id', DB::raw('SUM(points) as lifetime_points'))
            ->whereHas('user')
            ->when($request->since, fn ($q) => $q->where('awarded_at', '>=', $request->since))
            ->groupBy('user_id')
            ->orderByDesc('lifetime_points')
            ->with('user:id,name,role')
            ->limit((int) ($request->limit ?? 20))
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
        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'points'  => 'required|integer|min:100|max:100000',
            'purpose' => 'required|string|max:255',
            'note'    => 'nullable|string|max:500',
        ]);

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
            'approved_by' => $request->user()->id,
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
                'monthly_cap'   => 2500,
                'version'       => '1.0',
            ],
        ]);
    }
}
