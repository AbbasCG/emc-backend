<?php

namespace App\Services\UsersAnalytics;

use App\Models\User;

/**
 * «النشاط»: who logged in, how often they came back, when, and who has gone quiet.
 *
 * Everything per-window comes from LoginDataset (LOGIN events — see its notes on what that does and does not cover).
 * «المستخدمون الذين سجلوا الدخول» is the honest name for the headline: with no broader activity events there is no
 * basis for calling anyone «active». Sessions that continue without a new login are not seen.
 */
final class UsersActivityReport
{
    /** @return array<string, mixed> */
    public function build(AnalyticsPeriod $period, bool $compare): array
    {
        $previous = $compare ? $period->previous() : null;
        $logins = new LoginDataset($period);
        $prev = $previous ? new LoginDataset($previous) : null;

        $now = $logins->totals();
        $before = $prev?->totals();
        $split = $logins->newVsReturning();
        $prevSplit = $prev?->newVsReturning();

        $frequency = $logins->frequency();
        $backOnAnotherDay = $frequency['2-3'] + $frequency['4-7'] + $frequency['8+'];

        $first = LoginDataset::firstEventAt();

        return [
            'meta' => [
                'period'          => $period->toArray(),
                'previous_period' => $previous?->toArray(),
                'generated_at'    => now()->toIso8601String(),
                'cumulative'      => ['inactivity', 'never_logged_in'],
                'login_history'   => [
                    'first_event_at' => $first,
                    'partial'        => $first === null || $first > $period->startUtc()->toIso8601String(),
                ],
                'excludes'        => ['sessions_without_new_login', 'failed_logins'],
            ],
            'kpis' => [
                'login_events'               => $this->kpi($now['events'], $before['events'] ?? null),
                'unique_logged_in_users'     => $this->kpi($now['users'], $before['users'] ?? null),
                'returning_users'            => $this->kpi($split['returning'], $prevSplit['returning'] ?? null),
                'new_users_logged_in'        => $this->kpi($split['new'], $prevSplit['new'] ?? null),
                'users_back_on_another_day'  => [
                    'value'     => $backOnAnotherDay,
                    'share_pct' => $now['users'] > 0 ? round($backOnAnotherDay / $now['users'] * 100, 1) : null,
                ],
            ],
            'charts' => [
                'login_trend'      => $this->trend($logins),
                'heatmap'          => ['weekday_hour' => $logins->heatmap(), 'weekday_base' => 'iso (index 0 = Monday)', 'timezone' => $period->timezone],
                'return_frequency' => array_map(fn ($label, $users) => ['label' => (string) $label, 'users' => $users], array_keys($frequency), $frequency),
                'by_role'          => $this->roles($logins->byRole()),
            ],
            // cumulative, as of now: not affected by the window
            'inactivity'      => $this->inactivity(),
            'never_logged_in' => $this->neverLoggedIn(),
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

    /** @return array<int, array{date: string, events: int, users: int}> */
    private function trend(LoginDataset $logins): array
    {
        $out = [];
        foreach ($logins->perDay() as $day => $d) {
            $out[] = ['date' => $day, 'events' => $d['events'], 'users' => $d['users']];
        }

        return $out;
    }

    /**
     * @param  array<string, array{events: int, users: int}> $byRole
     * @return array<int, array{role: string, events: int, users: int}>
     */
    private function roles(array $byRole): array
    {
        $out = [];
        foreach ($byRole as $role => $v) {
            $out[] = ['role' => (string) $role, 'events' => $v['events'], 'users' => $v['users']];
        }

        return $out;
    }

    /**
     * Enabled accounts whose last login is older than N days (cumulative). `users.last_login_at` is a single timestamp,
     * so this says «no login since», never «no activity».
     *
     * @return array<int, array{days: int, users: int}>
     */
    private function inactivity(): array
    {
        $out = [];
        foreach ((array) config('users-analytics.inactivity_days', [30, 60, 90]) as $days) {
            $cutoff = now()->subDays((int) $days);
            $out[] = [
                'days'  => (int) $days,
                'users' => User::query()->where('is_active', true)->whereNotNull('last_login_at')->where('last_login_at', '<', $cutoff)->count(),
            ];
        }

        return $out;
    }

    /** Enabled accounts with no recorded login at all (cumulative). */
    private function neverLoggedIn(): int
    {
        return User::query()->where('is_active', true)->whereNull('last_login_at')->count();
    }
}
