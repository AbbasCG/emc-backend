<?php

namespace App\Services\UsersAnalytics;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Successful logins in one window, aggregated by the database from `audit_logs` rows with action = 'LOGIN'.
 *
 * WHAT THIS IS — and is not
 *   AuthController::login() writes exactly one LOGIN row per successful password login. That is the only login event the
 *   platform records. A person who stays signed in (a long-lived token or session) produces NO event, so these figures
 *   count logins, not visits or activity, and history only goes back to the first recorded event (see firstEventAt()).
 *   Failed attempts are a different action (LOGIN_FAILED) and are never counted. `users.last_login_at` is NOT used to
 *   count anything: it holds a single timestamp per user.
 *
 * SCALE
 *   Every method is ONE grouped query that returns a handful of rows (days, weekday×hour cells, roles, 4 buckets), so
 *   memory and round-trips do not grow with the number of events. The window scan uses (action, created_at).
 */
final class LoginDataset
{
    public function __construct(private readonly AnalyticsPeriod $period)
    {
    }

    /** Earliest LOGIN event ever recorded (UTC ISO 8601), or null. Tells the UI how far back the data goes. */
    public static function firstEventAt(): ?string
    {
        $first = DB::table('audit_logs')->where('action', 'LOGIN')->min('created_at');

        return $first ? CarbonImmutable::parse((string) $first, 'UTC')->toIso8601String() : null;
    }

    /** @return array{events: int, users: int} */
    public function totals(): array
    {
        $row = $this->base()->selectRaw('COUNT(*) as events, COUNT(DISTINCT al.user_id) as users')->first();

        return ['events' => (int) ($row->events ?? 0), 'users' => (int) ($row->users ?? 0)];
    }

    /**
     * Per local day: events and distinct users. Every day of the window is present.
     *
     * @return array<string, array{events: int, users: int}>
     */
    public function perDay(): array
    {
        $days = array_fill_keys($this->period->localDays(), ['events' => 0, 'users' => 0]);
        $date = TimeBuckets::date('al.created_at', $this->period);

        foreach ($this->base()->selectRaw("{$date} as d, COUNT(*) as events, COUNT(DISTINCT al.user_id) as users")->groupBy('d')->get() as $r) {
            if (isset($days[(string) $r->d])) {
                $days[(string) $r->d] = ['events' => (int) $r->events, 'users' => (int) $r->users];
            }
        }

        return $days;
    }

    /**
     * Weekday × hour matrix of login events in the reporting zone.
     *
     * @return array<int, array<int, int>> [0..6 (Monday first) → [0..23 → events]]
     */
    public function heatmap(): array
    {
        $matrix = array_fill(0, 7, array_fill(0, 24, 0));
        $weekday = TimeBuckets::weekday('al.created_at', $this->period);
        $hour = TimeBuckets::hour('al.created_at', $this->period);

        foreach ($this->base()->selectRaw("{$weekday} as w, {$hour} as h, COUNT(*) as c")->groupBy('w', 'h')->get() as $r) {
            $matrix[(int) $r->w][(int) $r->h] = (int) $r->c;
        }

        return $matrix;
    }

    /**
     * Logins per role AS IT WAS when the user logged in (audit_logs.user_role), events and distinct users.
     *
     * @return array<string, array{events: int, users: int}> most used first
     */
    public function byRole(): array
    {
        $out = [];
        $rows = $this->base()
            ->selectRaw("COALESCE(NULLIF(al.user_role, ''), 'unknown') as role, COUNT(*) as events, COUNT(DISTINCT al.user_id) as users")
            ->groupBy('role')
            ->orderByDesc('events')
            ->get();

        foreach ($rows as $r) {
            $out[(string) $r->role] = ['events' => (int) $r->events, 'users' => (int) $r->users];
        }

        return $out;
    }

    /**
     * Distinct users who logged in, split by account age: created inside the window (new) or before it (returning).
     * Account age — not login history, which may be partial — decides.
     *
     * @return array{new: int, returning: int}
     */
    public function newVsReturning(): array
    {
        $start = $this->period->startUtc()->format('Y-m-d H:i:s');
        $row = $this->base()
            ->join('users as u', 'u.id', '=', 'al.user_id')
            ->selectRaw(
                'COUNT(DISTINCT CASE WHEN u.created_at >= ? THEN al.user_id END) as new_users, COUNT(DISTINCT CASE WHEN u.created_at < ? THEN al.user_id END) as returning_users',
                [$start, $start]
            )
            ->first();

        return ['new' => (int) ($row->new_users ?? 0), 'returning' => (int) ($row->returning_users ?? 0)];
    }

    /**
     * Per local day: distinct users, split new / returning. Every day of the window is present.
     *
     * @return array<string, array{new: int, returning: int}>
     */
    public function newVsReturningPerDay(): array
    {
        $days = array_fill_keys($this->period->localDays(), ['new' => 0, 'returning' => 0]);
        $start = $this->period->startUtc()->format('Y-m-d H:i:s');
        $date = TimeBuckets::date('al.created_at', $this->period);

        $rows = $this->base()
            ->join('users as u', 'u.id', '=', 'al.user_id')
            ->selectRaw(
                "{$date} as d, COUNT(DISTINCT CASE WHEN u.created_at >= ? THEN al.user_id END) as new_users, COUNT(DISTINCT CASE WHEN u.created_at < ? THEN al.user_id END) as returning_users",
                [$start, $start]
            )
            ->groupBy('d')
            ->get();

        foreach ($rows as $r) {
            if (isset($days[(string) $r->d])) {
                $days[(string) $r->d] = ['new' => (int) $r->new_users, 'returning' => (int) $r->returning_users];
            }
        }

        return $days;
    }

    /**
     * How many distinct local days each logged-in user came on, bucketed 1 / 2-3 / 4-7 / 8+.
     *
     * @return array<string, int> bucket label => users
     */
    public function frequency(): array
    {
        $date = TimeBuckets::date('al.created_at', $this->period);
        $perUser = $this->base()->selectRaw("al.user_id, COUNT(DISTINCT {$date}) as days")->groupBy('al.user_id');

        $rows = DB::query()->fromSub($perUser, 't')
            ->selectRaw("CASE WHEN days <= 1 THEN '1' WHEN days <= 3 THEN '2-3' WHEN days <= 7 THEN '4-7' ELSE '8+' END as bucket, COUNT(*) as users")
            ->groupBy('bucket')
            ->get();

        $out = ['1' => 0, '2-3' => 0, '4-7' => 0, '8+' => 0];
        foreach ($rows as $r) {
            $out[(string) $r->bucket] = (int) $r->users;
        }

        return $out;
    }

    private function base(): Builder
    {
        return DB::table('audit_logs as al')
            ->where('al.action', 'LOGIN')
            ->whereNotNull('al.user_id')
            ->whereBetween('al.created_at', [$this->period->startUtc()->format('Y-m-d H:i:s'), $this->period->endUtc()->format('Y-m-d H:i:s')]);
    }
}
