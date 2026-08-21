<?php

namespace App\Services;

use App\Models\ClassGroup;
use App\Models\ClassGroupSchedule;
use App\Models\LmsSession;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Materializes ClassGroupSchedule's weekly recurring pattern into concrete
 * LmsSession rows for a bounded date range. Deliberately only supports what
 * ClassGroupSchedule actually stores (day_of_week/start_time/end_time) — no
 * RRULE, since no RRULE storage exists anywhere in this schema.
 */
class ClassSessionGenerationService
{
    /**
     * Dry-run: the concrete dates that generate() would create sessions for,
     * without writing anything. Same duplicate-skipping logic as generate().
     *
     * @return array<int, array{date: string, day_of_week: string, start_time: string, end_time: string, already_exists: bool}>
     */
    public function preview(ClassGroup $group, CarbonInterface $from, CarbonInterface $to): array
    {
        $schedules = $this->activeSchedules($group);
        if ($schedules->isEmpty() || ! $group->canPerformTeachingActions()) {
            return [];
        }

        $existing = $this->existingDateKeys($group, $from, $to);
        $out = [];

        foreach ($this->iterateOccurrences($schedules, $from, $to) as $occurrence) {
            $out[] = [
                'date'           => $occurrence['date'],
                'day_of_week'    => $occurrence['day_of_week'],
                'start_time'     => $occurrence['start_time'],
                'end_time'       => $occurrence['end_time'],
                'already_exists' => in_array($this->dedupKey($group->id, $occurrence['date'], $occurrence['start_time'], $occurrence['end_time']), $existing, true),
            ];
        }

        return $out;
    }

    /**
     * Writes concrete LmsSession rows for the date range. Idempotent: a
     * repeated call with the same range never creates duplicates — dedup key
     * is (class_group_id, session_date, start_time, end_time), checked
     * against existing rows before insert.
     *
     * @return array{created_count: int, skipped_count: int, sessions: Collection<int, LmsSession>}
     */
    public function generate(ClassGroup $group, CarbonInterface $from, CarbonInterface $to, User $actor): array
    {
        $schedules = $this->activeSchedules($group);
        if ($schedules->isEmpty() || ! $group->canPerformTeachingActions()) {
            return ['created_count' => 0, 'skipped_count' => 0, 'sessions' => collect()];
        }

        $existing = $this->existingDateKeys($group, $from, $to);
        $created = collect();
        $skipped = 0;

        \DB::transaction(function () use ($schedules, $from, $to, $group, $existing, &$created, &$skipped) {
            foreach ($this->iterateOccurrences($schedules, $from, $to) as $occurrence) {
                $key = $this->dedupKey($group->id, $occurrence['date'], $occurrence['start_time'], $occurrence['end_time']);
                if (in_array($key, $existing, true)) {
                    $skipped++;
                    continue;
                }

                $session = LmsSession::create([
                    'course_id'      => $group->course_id,
                    'class_group_id' => $group->id,
                    'instructor_id'  => $group->instructor_id,
                    'title'          => $group->name,
                    'session_date'   => $occurrence['date'],
                    'start_time'     => $occurrence['start_time'],
                    'end_time'       => $occurrence['end_time'],
                    'location'       => $occurrence['location'],
                    'status'         => LmsSession::STATUS_SCHEDULED,
                ]);

                $created->push($session);
                $existing[] = $key; // guard against duplicate schedule rows for the same slot within one call
            }
        });

        return ['created_count' => $created->count(), 'skipped_count' => $skipped, 'sessions' => $created];
    }

    /** @return Collection<int, ClassGroupSchedule> */
    private function activeSchedules(ClassGroup $group): Collection
    {
        return ClassGroupSchedule::where('class_group_id', $group->id)
            ->where('is_active', true)
            ->get();
    }

    /** Existing (class_group_id, date, start, end) keys already in the DB for this range — checked before every insert. */
    private function existingDateKeys(ClassGroup $group, CarbonInterface $from, CarbonInterface $to): array
    {
        return LmsSession::where('class_group_id', $group->id)
            // Inclusive date bounds also work when SQLite stores a cast date
            // with a midnight time component (whereBetween would omit `to`).
            ->whereDate('session_date', '>=', $from->toDateString())
            ->whereDate('session_date', '<=', $to->toDateString())
            ->get(['session_date', 'start_time', 'end_time'])
            ->map(fn ($s) => $this->dedupKey($group->id, $s->session_date->toDateString(), $s->start_time, $s->end_time))
            ->all();
    }

    private function dedupKey(int $groupId, string $date, string $start, string $end): string
    {
        // SQL time columns are returned as HH:MM:SS, while schedule input may
        // arrive as HH:MM. Normalize both sides so a repeated generation run
        // cannot miss an existing slot only because of display precision.
        $normaliseTime = static function (string $time): string {
            $parts = array_pad(explode(':', $time), 3, '00');

            return implode(':', array_slice($parts, 0, 3));
        };

        return "{$groupId}|{$date}|{$normaliseTime($start)}|{$normaliseTime($end)}";
    }

    /**
     * Walks every day in [from, to] and yields one occurrence per active
     * schedule row whose day_of_week matches that date — supports multiple
     * weekdays per class naturally, since each ClassGroupSchedule row is
     * checked independently against every date in range.
     *
     * @param Collection<int, ClassGroupSchedule> $schedules
     * @return \Generator<array{date: string, day_of_week: string, start_time: string, end_time: string, location: ?string}>
     */
    private function iterateOccurrences(Collection $schedules, CarbonInterface $from, CarbonInterface $to): \Generator
    {
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->endOfDay();

        while ($cursor->lte($end)) {
            $dayName = strtolower($cursor->englishDayOfWeek);

            foreach ($schedules as $schedule) {
                if ($schedule->day_of_week === $dayName) {
                    yield [
                        'date'        => $cursor->toDateString(),
                        'day_of_week' => $dayName,
                        'start_time'  => $schedule->start_time,
                        'end_time'    => $schedule->end_time,
                        'location'    => $schedule->location,
                    ];
                }
            }

            $cursor->addDay();
        }
    }
}
