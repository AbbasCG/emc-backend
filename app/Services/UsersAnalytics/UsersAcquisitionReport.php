<?php

namespace App\Services\UsersAnalytics;

use App\Models\AnalyticsEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * «الاستقطاب»: where accounts say they came from, and (separately) which campaigns brought visits.
 *
 * NOTHING HERE IS INVENTED. What the platform actually stores:
 *   - users.how_did_you_hear_about_us  — optional free text typed at sign-up. Reported as «معلن ذاتيًا»; an empty value
 *     is «unknown», never «direct».
 *   - analytics_events.utm_*           — recorded per page view, only for visitors who accepted analytics cookies, and
 *     only on the page whose URL carried the parameters. There is NO link between a visit and the account it later
 *     becomes, so UTM figures are VISITS, shown apart from account sign-ups.
 * Referral codes, direct-registration detection and conversion rate need data that is not captured yet (see
 * docs/architecture/USER_ATTRIBUTION_TRACKING_DESIGN.md); they are reported as unavailable with the reason.
 */
final class UsersAcquisitionReport
{
    /** @return array<string, mixed> */
    public function build(AnalyticsPeriod $period, bool $compare): array
    {
        $previous = $compare ? $period->previous() : null;

        return [
            'meta' => [
                'period'          => $period->toArray(),
                'previous_period' => $previous?->toArray(),
                'generated_at'    => now()->toIso8601String(),
            ],
            'self_reported' => $this->selfReported($period, $previous),
            'utm_visits'    => $this->utmVisits($period),
            'unavailable'   => [
                'referral_codes' => [
                    'available' => false,
                    'reason'    => 'لا يوجد رمز إحالة يُحفظ مع الحساب حاليًا.',
                ],
                'direct_registration' => [
                    'available' => false,
                    'reason'    => 'لا يُعتبر الحساب بلا مصدر «تسجيلًا مباشرًا»: المصدر غير مسجَّل أصلًا، فيُعرض غير معروف.',
                ],
                'conversion_rate' => [
                    'available' => false,
                    'reason'    => 'الزيارات مجهولة الهوية ومشروطة بموافقة الكوكيز ولا ترتبط بالحسابات المنشأة، فلا يمكن حساب معدل تحويل صادق.',
                ],
                'utm_to_account' => [
                    'available' => false,
                    'reason'    => 'طلب التسجيل لا يحمل معرّف الزائر ولا معاملات UTM، فلا ربط بين التسجيل وحملته.',
                ],
            ],
        ];
    }

    /**
     * Accounts created in the window, grouped by the source they typed (trimmed, case-insensitive).
     *
     * @return array<string, mixed>
     */
    private function selfReported(AnalyticsPeriod $period, ?AnalyticsPeriod $previous): array
    {
        $total = $this->createdBetween($period);
        $withSource = $this->sourceRows($period);
        $answered = (int) $withSource->sum('c');
        $limit = (int) config('users-analytics.top_limit', 12);

        $items = $withSource->take($limit)->map(fn ($r) => ['source' => (string) $r->src_key, 'count' => (int) $r->c])->values()->all();
        $shown = array_sum(array_column($items, 'count'));

        $prevCounts = [];
        if ($previous) {
            foreach ($this->sourceRows($previous) as $r) {
                $prevCounts[(string) $r->src_key] = (int) $r->c;
            }
            foreach ($items as &$item) {
                $prev = $prevCounts[$item['source']] ?? 0;
                $item['previous'] = $prev;
                $item['change_pct'] = $prev > 0 ? round(($item['count'] - $prev) / $prev * 100, 1) : null;
            }
            unset($item);
        }

        return [
            'total_accounts_created' => $total,
            'items'                  => $items,
            'other'                  => $answered - $shown,
            'unknown'                => $total - $answered,
            'coverage_pct'           => $total > 0 ? round($answered / $total * 100, 1) : null,
            'daily'                  => $this->dailyForTop($period, array_column($items, 'source')),
            'free_text'              => true,
        ];
    }

    private function createdBetween(AnalyticsPeriod $p): int
    {
        return User::query()->whereBetween('created_at', [$p->startUtc(), $p->endUtc()])->count();
    }

    /** @return \Illuminate\Support\Collection<int, object{source: string, c: int}> answered sources, most common first */
    private function sourceRows(AnalyticsPeriod $p)
    {
        $expr = "LOWER(TRIM(how_did_you_hear_about_us))";

        return User::query()
            ->whereBetween('created_at', [$p->startUtc(), $p->endUtc()])
            ->whereRaw("{$expr} <> ''")
            ->selectRaw("{$expr} as src_key, COUNT(*) as c")
            ->groupBy('src_key')
            ->orderByDesc('c')
            ->get();
    }

    /**
     * Accounts per local day for the leading sources, so each channel's growth can be drawn.
     *
     * @param  array<int, string> $sources
     * @return array<int, array{source: string, series: array<int, array{date: string, count: int}>}>
     */
    private function dailyForTop(AnalyticsPeriod $period, array $sources): array
    {
        $sources = array_slice($sources, 0, 5);
        if ($sources === []) {
            return [];
        }

        $date = TimeBuckets::date('created_at', $period);
        $expr = "LOWER(TRIM(how_did_you_hear_about_us))";
        $days = $period->localDays();
        $acc = [];
        foreach ($sources as $s) {
            $acc[$s] = array_fill_keys($days, 0);
        }

        User::query()
            ->whereBetween('created_at', [$period->startUtc(), $period->endUtc()])
            ->whereIn(DB::raw($expr), $sources)
            ->selectRaw("{$expr} as src_key, {$date} as d, COUNT(*) as c")
            ->groupBy('src_key', 'd')
            ->get()
            ->each(function ($r) use (&$acc) {
                if (isset($acc[(string) $r->src_key][(string) $r->d])) {
                    $acc[(string) $r->src_key][(string) $r->d] += (int) $r->c;
                }
            });

        $out = [];
        foreach ($acc as $source => $perDay) {
            $series = [];
            foreach ($perDay as $day => $count) {
                $series[] = ['date' => $day, 'count' => $count];
            }
            $out[] = ['source' => $source, 'series' => $series];
        }

        return $out;
    }

    /**
     * Page views that carried UTM parameters, by source / medium / campaign. VISITS from consenting visitors only —
     * never account sign-ups.
     *
     * @return array<string, mixed>
     */
    private function utmVisits(AnalyticsPeriod $period): array
    {
        $base = fn () => AnalyticsEvent::query()
            ->where('type', 'pageview')
            ->whereBetween('created_at', [$period->startUtc(), $period->endUtc()]);

        $limit = (int) config('users-analytics.top_limit', 12);
        $group = function (string $column) use ($base, $limit) {
            return $base()
                ->whereNotNull($column)->where($column, '<>', '')
                ->selectRaw("{$column} as name, COUNT(*) as visits, COUNT(DISTINCT visitor_id) as visitors")
                ->groupBy($column)
                ->orderByDesc('visits')
                ->limit($limit)
                ->get()
                ->map(fn ($r) => ['name' => (string) $r->name, 'visits' => (int) $r->visits, 'visitors' => (int) $r->visitors])
                ->all();
        };

        return [
            'unit'            => 'visits',
            'consent_gated'   => true,
            'linked_to_accounts' => false,
            'total_pageviews' => $base()->count(),
            'with_utm'        => $base()->where(fn ($q) => $q->whereNotNull('utm_source')->orWhereNotNull('utm_medium')->orWhereNotNull('utm_campaign'))->count(),
            'by_source'       => $group('utm_source'),
            'by_medium'       => $group('utm_medium'),
            'by_campaign'     => $group('utm_campaign'),
        ];
    }
}
