<?php

namespace App\Services\UsersAnalytics;

use Carbon\CarbonImmutable;

/**
 * One reporting window, shared by every figure of a response.
 *
 * Boundaries are whole LOCAL days (config users-analytics.timezone) converted to UTC for querying, so «اليوم» means the
 * reporting zone's day and not a UTC day. The comparison period is the window of the same length immediately before.
 */
final class AnalyticsPeriod
{
    private function __construct(
        public readonly CarbonImmutable $startLocal,
        public readonly CarbonImmutable $endLocal,
        public readonly string $timezone,
    ) {
    }

    public static function make(string $from, string $to, ?string $timezone = null): self
    {
        $tz = $timezone ?? (string) config('users-analytics.timezone', 'UTC');

        return new self(
            CarbonImmutable::createFromFormat('Y-m-d', $from, $tz)->startOfDay(),
            CarbonImmutable::createFromFormat('Y-m-d', $to, $tz)->endOfDay(),
            $tz,
        );
    }

    /** The default window: the last N days ending today (inclusive). */
    public static function lastDays(int $days, ?string $timezone = null): self
    {
        $tz = $timezone ?? (string) config('users-analytics.timezone', 'UTC');
        $today = CarbonImmutable::now($tz)->startOfDay();

        return self::make($today->subDays(max(1, $days) - 1)->toDateString(), $today->toDateString(), $tz);
    }

    public function startUtc(): CarbonImmutable
    {
        return $this->startLocal->utc();
    }

    public function endUtc(): CarbonImmutable
    {
        return $this->endLocal->utc();
    }

    /** Number of local calendar days in the window. */
    public function days(): int
    {
        // calendar days, computed on plain dates so a 23/25-hour DST day cannot skew the count
        $from = CarbonImmutable::createFromFormat('Y-m-d', $this->startLocal->toDateString(), 'UTC')->startOfDay();
        $to = CarbonImmutable::createFromFormat('Y-m-d', $this->endLocal->toDateString(), 'UTC')->startOfDay();

        return (int) round($from->diffInDays($to)) + 1;
    }

    /** The window of the same length that ends the day before this one starts. */
    public function previous(): self
    {
        $days = $this->days();
        $prevEnd = $this->startLocal->subDay();
        $prevStart = $prevEnd->subDays($days - 1);

        return self::make($prevStart->toDateString(), $prevEnd->toDateString(), $this->timezone);
    }

    /** @return array<int, string> every local day (Y-m-d) in the window, oldest first */
    public function localDays(): array
    {
        $days = [];
        $cursor = $this->startLocal->startOfDay();
        for ($i = 0, $n = $this->days(); $i < $n; $i++) {
            $days[] = $cursor->toDateString();
            $cursor = $cursor->addDay();
        }

        return $days;
    }

    public function cacheKey(): string
    {
        return $this->startLocal->toDateString().'_'.$this->endLocal->toDateString().'_'.$this->timezone;
    }

    /** @return array{from: string, to: string, days: int, timezone: string} */
    public function toArray(): array
    {
        return [
            'from'     => $this->startLocal->toDateString(),
            'to'       => $this->endLocal->toDateString(),
            'days'     => $this->days(),
            'timezone' => $this->timezone,
        ];
    }
}
