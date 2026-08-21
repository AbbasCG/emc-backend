<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AnalyticsEvent;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * لوحة «تحليلات الزوار»: من زارنا، من أين، ماذا فعل، وما الأخطاء التي واجهته —
 * كلها من جدول analytics_events الذاتي، بلا أي خدمة خارجية.
 */
class VisitorAnalyticsController extends Controller
{
    private function since(Request $request): CarbonImmutable
    {
        $days = min(90, max(1, (int) ($request->days ?? 14)));

        return CarbonImmutable::now()->subDays($days - 1)->startOfDay();
    }

    public function overview(Request $request): JsonResponse
    {
        $since = $this->since($request);
        $base  = AnalyticsEvent::query()->where('created_at', '>=', $since);

        $daily = (clone $base)
            ->selectRaw("date(created_at) as day,
                count(*) as events,
                sum(case when type = 'pageview' then 1 else 0 end) as pageviews,
                sum(case when type = 'error' then 1 else 0 end) as errors,
                count(distinct visitor_id) as visitors")
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'totals' => [
                    'visitors'  => (clone $base)->whereNotNull('visitor_id')->distinct('visitor_id')->count('visitor_id'),
                    'pageviews' => (clone $base)->where('type', 'pageview')->count(),
                    'events'    => (clone $base)->where('type', 'event')->count(),
                    'errors'    => (clone $base)->where('type', 'error')->count(),
                ],
                'daily'  => $daily,
                'since'  => $since->toDateString(),
            ],
        ]);
    }

    /** القوائم العلوية: الصفحات، المصادر، الأجهزة، المتصفحات، المناطق الزمنية، أحداث القمع. */
    public function top(Request $request): JsonResponse
    {
        $since = $this->since($request);

        $rank = function (string $column, ?string $type = null, int $limit = 10) use ($since) {
            return AnalyticsEvent::query()
                ->where('created_at', '>=', $since)
                ->when($type, fn ($q) => $q->where('type', $type))
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->select($column . ' as value', DB::raw('count(*) as total'), DB::raw('count(distinct visitor_id) as visitors'))
                ->groupBy('value')
                ->orderByDesc('total')
                ->limit($limit)
                ->get();
        };

        return response()->json([
            'success' => true,
            'data'    => [
                'pages'     => $rank('path', 'pageview'),
                'referrers' => $rank('referrer', 'pageview'),
                'devices'   => $rank('device'),
                'browsers'  => $rank('browser'),
                'timezones' => $rank('timezone'),
                'events'    => $rank('name', 'event'),
            ],
        ]);
    }

    /** آخر الأخطاء التي واجهت الزوار — الوقود المباشر للإصلاح المستمر. */
    public function errors(Request $request): JsonResponse
    {
        $rows = AnalyticsEvent::query()
            ->where('type', 'error')
            ->latest('created_at')
            ->limit((int) ($request->limit ?? 50))
            ->get(['id', 'name', 'path', 'browser', 'os', 'device', 'meta', 'created_at']);

        return response()->json(['success' => true, 'data' => $rows]);
    }
}
