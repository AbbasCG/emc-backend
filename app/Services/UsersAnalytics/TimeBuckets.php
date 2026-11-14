<?php

namespace App\Services\UsersAnalytics;

use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * SQL expressions that put a UTC timestamp column on the reporting zone's calendar (local date, hour, weekday).
 *
 * The database stores UTC. Grouping by SQL DATE() would put a login at 23:30 UTC (01:30 Amsterdam) on the wrong day, and
 * converting zones in SQL is not portable (MariaDB needs its tz tables loaded, SQLite has none). So the zone's UTC offsets
 * for THIS window are computed in PHP (DateTimeZone transitions: DST starts and ends inside the window are honoured) and
 * baked into a CASE expression that shifts the column by a fixed number of seconds per segment. Aggregation then stays in
 * SQL — a handful of grouped rows per query whatever the number of events — and the result is exact on MariaDB and SQLite.
 */
final class TimeBuckets
{
    /** Local calendar date, `YYYY-MM-DD`. */
    public static function date(string $column, AnalyticsPeriod $period): string
    {
        return self::segmented($column, $period, fn (string $shifted) => match (self::driver()) {
            'sqlite' => "date({$shifted})",
            default  => "DATE({$shifted})",
        });
    }

    /** Local hour of day, 0–23. */
    public static function hour(string $column, AnalyticsPeriod $period): string
    {
        return self::segmented($column, $period, fn (string $shifted) => match (self::driver()) {
            'sqlite' => "CAST(strftime('%H', {$shifted}) AS INTEGER)",
            default  => "HOUR({$shifted})",
        });
    }

    /** Local ISO weekday index, 0 = Monday … 6 = Sunday. */
    public static function weekday(string $column, AnalyticsPeriod $period): string
    {
        return self::segmented($column, $period, fn (string $shifted) => match (self::driver()) {
            'sqlite' => "((CAST(strftime('%w', {$shifted}) AS INTEGER) + 6) % 7)",
            default  => "WEEKDAY({$shifted})",
        });
    }

    /**
     * The zone's UTC offsets over the window: [[untilUtc|null, offsetSeconds], …], oldest first; the last segment is open.
     *
     * @return array<int, array{0: ?string, 1: int}>
     */
    public static function segments(AnalyticsPeriod $period): array
    {
        $zone = new DateTimeZone($period->timezone);
        $transitions = $zone->getTransitions($period->startUtc()->getTimestamp(), $period->endUtc()->getTimestamp());

        $segments = [];
        $count = count($transitions);
        foreach ($transitions as $i => $t) {
            $until = ($i + 1 < $count) ? gmdate('Y-m-d H:i:s', $transitions[$i + 1]['ts']) : null;
            $segments[] = [$until, (int) $t['offset']];
        }

        return $segments;
    }

    /** @param \Closure(string): string $expression builds the SQL from an already-shifted timestamp expression */
    private static function segmented(string $column, AnalyticsPeriod $period, \Closure $expression): string
    {
        $segments = self::segments($period);
        if (count($segments) === 1) {
            return $expression(self::shift($column, $segments[0][1]));
        }

        $sql = 'CASE';
        foreach ($segments as [$until, $offset]) {
            $sql .= $until === null
                ? ' ELSE '.$expression(self::shift($column, $offset))
                : " WHEN {$column} < '{$until}' THEN ".$expression(self::shift($column, $offset));
        }

        return $sql.' END';
    }

    private static function shift(string $column, int $offsetSeconds): string
    {
        return match (self::driver()) {
            'sqlite' => sprintf("datetime(%s, '%+d seconds')", $column, $offsetSeconds),
            default  => sprintf('DATE_ADD(%s, INTERVAL %d SECOND)', $column, $offsetSeconds),
        };
    }

    private static function driver(): string
    {
        return DB::connection()->getDriverName();
    }
}
