<?php

namespace App\Services\UsersAnalytics;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * «التحليلات»: account totals and growth, login trend, new vs returning, and the role / status / country mix.
 *
 * Soft-deleted accounts are excluded from every account count (they are reported once, separately, in `status`).
 * KPIs that do not move with the period say so in `cumulative` so the UI can label them.
 */
final class UsersOverviewReport
{
    /** @return array<string, mixed> */
    public function build(AnalyticsPeriod $period, bool $compare): array
    {
        $previous = $compare ? $period->previous() : null;

        $logins = new LoginDataset($period);
        $now = $logins->totals();
        $before = $previous ? (new LoginDataset($previous))->totals() : null;

        $createdNow = $this->createdBetween($period);
        $createdPrev = $previous ? $this->createdBetween($previous) : null;

        $first = LoginDataset::firstEventAt();

        return [
            'meta' => [
                'period'          => $period->toArray(),
                'previous_period' => $previous?->toArray(),
                'generated_at'    => now()->toIso8601String(),
                'cumulative'      => ['total_accounts'],
                'login_history'   => [
                    'first_event_at' => $first,
                    // history starts after the window does (or there is none): login figures are lower bounds for this window
                    'partial'        => $first === null || $first > $period->startUtc()->toIso8601String(),
                ],
            ],
            'kpis' => [
                // cumulative: today's total, whatever the window
                'total_accounts'          => ['value' => User::query()->count(), 'cumulative' => true],
                'accounts_created'        => $this->kpi($createdNow, $createdPrev),
                'successful_logins'       => $this->kpi($now['events'], $before['events'] ?? null),
                'unique_logged_in_users'  => $this->kpi($now['users'], $before['users'] ?? null),
                // growth of registrations versus the previous window of the same length; null when there is nothing to compare
                'registration_growth_pct' => [
                    'value'      => ($createdPrev !== null && $createdPrev > 0) ? round(($createdNow - $createdPrev) / $createdPrev * 100, 1) : null,
                    'previous'   => null,
                    'change_pct' => null,
                ],
            ],
            'charts' => [
                'accounts_growth'  => $this->accountsGrowth($period),
                'login_trend'      => $this->loginTrend($logins),
                'new_vs_returning' => $this->newVsReturning($logins),
                'by_role'          => $this->byRole(),
                'by_status'        => $this->byStatus(),
                'by_country'       => $this->byCountry(),
            ],
        ];
    }

    /** @return array{value: int, previous: ?int, change_pct: ?float} */
    private function kpi(int $value, ?int $previous): array
    {
        return [
            'value'      => $value,
            'previous'   => $previous,
            'change_pct' => ($previous !== null && $previous > 0) ? round(($value - $previous) / $previous * 100, 1) : null,
        ];
    }

    private function createdBetween(AnalyticsPeriod $p): int
    {
        return User::query()->whereBetween('created_at', [$p->startUtc(), $p->endUtc()])->count();
    }

    /**
     * New accounts per local day, with the running total of accounts at the end of that day.
     *
     * @return array<int, array{date: string, created: int, total: int}>
     */
    private function accountsGrowth(AnalyticsPeriod $period): array
    {
        $date = TimeBuckets::date('created_at', $period);
        $perDay = array_fill_keys($period->localDays(), 0);

        $rows = DB::table('users')
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$period->startUtc()->format('Y-m-d H:i:s'), $period->endUtc()->format('Y-m-d H:i:s')])
            ->selectRaw("{$date} as d, COUNT(*) as c")
            ->groupBy('d')
            ->get();

        foreach ($rows as $r) {
            if (isset($perDay[(string) $r->d])) {
                $perDay[(string) $r->d] = (int) $r->c;
            }
        }

        $running = User::query()->where('created_at', '<', $period->startUtc())->count();
        $out = [];
        foreach ($perDay as $day => $created) {
            $running += $created;
            $out[] = ['date' => $day, 'created' => $created, 'total' => $running];
        }

        return $out;
    }

    /** @return array<int, array{date: string, events: int, users: int}> */
    private function loginTrend(LoginDataset $logins): array
    {
        $out = [];
        foreach ($logins->perDay() as $day => $d) {
            $out[] = ['date' => $day, 'events' => $d['events'], 'users' => $d['users']];
        }

        return $out;
    }

    /** @return array<int, array{date: string, new: int, returning: int}> */
    private function newVsReturning(LoginDataset $logins): array
    {
        $out = [];
        foreach ($logins->newVsReturningPerDay() as $day => $d) {
            $out[] = ['date' => $day, 'new' => $d['new'], 'returning' => $d['returning']];
        }

        return $out;
    }

    /** @return array<int, array{role: string, count: int}> */
    private function byRole(): array
    {
        return User::query()
            ->selectRaw('role, COUNT(*) as c')
            ->groupBy('role')
            ->orderByDesc('c')
            ->get()
            ->map(fn ($r) => ['role' => (string) ($r->role ?: 'unknown'), 'count' => (int) $r->c])
            ->all();
    }

    /**
     * Account state right now (cumulative). `active` = enabled and not deleted; `deleted` is reported on its own.
     *
     * @return array{active: int, inactive: int, deleted: int, verified: int, unverified: int}
     */
    private function byStatus(): array
    {
        $row = User::query()->selectRaw(
            'SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
             SUM(CASE WHEN is_active = 1 THEN 0 ELSE 1 END) as inactive,
             SUM(CASE WHEN email_verified_at IS NOT NULL THEN 1 ELSE 0 END) as verified,
             SUM(CASE WHEN email_verified_at IS NULL THEN 1 ELSE 0 END) as unverified'
        )->first();

        return [
            'active'     => (int) ($row->active ?? 0),
            'inactive'   => (int) ($row->inactive ?? 0),
            'deleted'    => User::onlyTrashed()->count(),
            'verified'   => (int) ($row->verified ?? 0),
            'unverified' => (int) ($row->unverified ?? 0),
        ];
    }

    /**
     * Country mix from what people typed at registration (`country_code`, else `country`). Self-reported: there is no
     * GeoIP, so «unknown» is everyone who left it empty and `coverage_pct` says how much of the base answered.
     *
     * @return array{items: array<int, array{country: string, count: int}>, other: int, unknown: int, coverage_pct: float, self_reported: true}
     */
    private function byCountry(): array
    {
        $total = User::query()->count();
        $expr = "COALESCE(NULLIF(country_code, ''), NULLIF(country, ''))";

        $rows = User::query()
            ->whereRaw("{$expr} IS NOT NULL")
            ->selectRaw("{$expr} as country_key, COUNT(*) as c")
            ->groupBy('country_key')
            ->orderByDesc('c')
            ->get();

        $limit = (int) config('users-analytics.top_limit', 12);
        $known = (int) $rows->sum('c');
        $items = $rows->take($limit)->map(fn ($r) => ['country' => (string) $r->country_key, 'count' => (int) $r->c])->values()->all();
        $shown = array_sum(array_column($items, 'count'));

        return [
            'items'         => $items,
            'other'         => $known - $shown,
            'unknown'       => $total - $known,
            'coverage_pct'  => $total > 0 ? round($known / $total * 100, 1) : 0.0,
            'self_reported' => true,
        ];
    }
}
