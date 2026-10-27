<?php

namespace App\Services\Forms;

use App\Models\DynamicForm;
use App\Models\FormQuestion;
use App\Models\FormSubmission;
use App\Models\FormVersion;
use App\Models\User;
use App\Services\Forms\Export\FormExportColumns;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Smart Forms Phase 7: what a form's stored responses say, computed from those responses only.
 *
 * Nothing here is estimated or invented. Every number is a count, a share or an average of stored rows, over exactly the responses
 * the response table shows for the same filters (FormResponseQuery), so a chart can never disagree with the table it summarises.
 * What the platform does not record is reported as not measurable, with the reason, instead of a guess:
 *   - a completion rate (people who opened the form but did not send it) needs abandoned attempts, which are not stored;
 *   - a question's answer rate counts only the responses given to a version that contained the question, and a question hidden
 *     by a condition is legitimately unanswered.
 *
 * Free-text answers are never quoted here: the summary counts them. Reading what people wrote stays in the response table, under
 * its own permission and history.
 */
class FormAnalyticsService
{
    public const CHOICE_SINGLE = ['radio', 'select'];

    public const CHOICE_MULTI = ['checkbox', 'multiselect'];

    public const NUMERIC = ['number', 'star_rating', 'linear_scale'];

    /** Above this many days the series is weekly, above the second monthly: a chart stays readable whatever the range. */
    private const DAILY_MAX_DAYS = 92;

    private const WEEKLY_MAX_DAYS = 730;

    /**
     * @param  array<string, mixed>  $filters  normalised (FormResponseQuery::normalize)
     * @return array<string, mixed>
     */
    public function summary(DynamicForm $form, array $filters): array
    {
        $base = FormResponseQuery::build($form, $filters);
        $ids = (clone $base)->reorder()->select('form_submissions.id');

        $total = (clone $base)->count();
        $edges = (clone $base)->reorder()->selectRaw('min(form_submissions.submitted_at) as first_at, max(form_submissions.submitted_at) as last_at')->first();
        $signedIn = (clone $base)->whereNotNull('form_submissions.user_id')->count();
        $viaLink = (clone $base)->whereNotNull('form_submissions.share_link_id')->count();

        return [
            'filters'    => $filters,
            'timezone'   => FormResponseQuery::DISPLAY_TIMEZONE,
            'totals'     => [
                'responses'        => $total,
                'signed_in'        => $signedIn,
                'anonymous'        => $total - $signedIn,
                'via_share_link'   => $viaLink,
                'first_response_at' => $this->iso($edges?->first_at),
                'last_response_at'  => $this->iso($edges?->last_at),
                'by_status'        => $this->statusBreakdown($form, $filters),
            ],
            'completion' => [
                'rate'   => null,
                'reason' => 'not_tracked',
                'note'   => 'لا تُسجَّل المحاولات غير المكتملة، لذلك لا يمكن حساب نسبة الإكمال. الأرقام هنا عن الردود المرسلة فقط.',
            ],
            'series'     => $this->series($base, $filters, $edges?->first_at, $edges?->last_at),
            'comparison' => $this->comparison($form, $filters),
            'questions'  => $this->questions($form, $filters, $ids, $total),
        ];
    }

    // ── Responses over time ──────────────────────────────────────────────────

    /** @return array{granularity: string, points: list<array{period: string, count: int}>} */
    private function series($base, array $filters, $firstAt, $lastAt): array
    {
        $tz = FormResponseQuery::DISPLAY_TIMEZONE;
        if ($firstAt === null && ! isset($filters['from'])) {
            return ['granularity' => 'day', 'points' => []];
        }

        $start = isset($filters['from']) ? CarbonImmutable::parse($filters['from'], $tz) : CarbonImmutable::parse($firstAt, 'UTC')->setTimezone($tz);
        $end = isset($filters['to']) ? CarbonImmutable::parse($filters['to'], $tz) : ($lastAt !== null ? CarbonImmutable::parse($lastAt, 'UTC')->setTimezone($tz) : $start);
        $start = $start->startOfDay();
        $end = $end->startOfDay();
        $days = $start->diffInDays($end) + 1;
        $granularity = $days <= self::DAILY_MAX_DAYS ? 'day' : ($days <= self::WEEKLY_MAX_DAYS ? 'week' : 'month');

        $bucket = fn (CarbonImmutable $d) => match ($granularity) {
            'day'   => $d->format('Y-m-d'),
            'week'  => $d->startOfWeek(CarbonImmutable::MONDAY)->format('Y-m-d'),
            'month' => $d->format('Y-m'),
        };

        // Bucketed in PHP from the stored instants, so the display timezone (and its DST changes) is applied exactly, on every database.
        $counts = [];
        (clone $base)->reorder()->select(['form_submissions.id', 'form_submissions.submitted_at'])
            ->chunkById(2000, function ($rows) use (&$counts, $bucket, $tz) {
                foreach ($rows as $row) {
                    if ($row->submitted_at === null) {
                        continue;
                    }
                    $key = $bucket(CarbonImmutable::parse($row->submitted_at)->setTimezone($tz));
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                }
            }, 'form_submissions.id', 'id');

        $points = [];
        $cursor = $granularity === 'week' ? $start->startOfWeek(CarbonImmutable::MONDAY) : ($granularity === 'month' ? $start->startOfMonth() : $start);
        $guard = 0;
        while ($cursor <= $end && $guard++ < 800) {
            $key = $bucket($cursor);
            $points[] = ['period' => $key, 'count' => $counts[$key] ?? 0];
            $cursor = match ($granularity) {
                'day'   => $cursor->addDay(),
                'week'  => $cursor->addWeek(),
                'month' => $cursor->addMonth(),
            };
        }

        return ['granularity' => $granularity, 'points' => $points];
    }

    /**
     * With a date range chosen: the same filters over the period of the same length just before it.
     *
     * @return array{current: int, previous: int, previous_from: string, previous_to: string, change_percent: float|null}|null
     */
    private function comparison(DynamicForm $form, array $filters): ?array
    {
        if (! isset($filters['from'], $filters['to'])) {
            return null;
        }
        $tz = FormResponseQuery::DISPLAY_TIMEZONE;
        $from = CarbonImmutable::parse($filters['from'], $tz);
        $to = CarbonImmutable::parse($filters['to'], $tz);
        $length = $from->diffInDays($to) + 1;
        $prevTo = $from->subDay();
        $prevFrom = $prevTo->subDays($length - 1);

        $current = FormResponseQuery::build($form, $filters)->count();
        $previous = FormResponseQuery::build($form, ['from' => $prevFrom->format('Y-m-d'), 'to' => $prevTo->format('Y-m-d')] + $filters)->count();

        return [
            'current'        => $current,
            'previous'       => $previous,
            'previous_from'  => $prevFrom->format('Y-m-d'),
            'previous_to'    => $prevTo->format('Y-m-d'),
            // No previous responses: a percentage would be infinite, so there is none.
            'change_percent' => $previous === 0 ? null : round(($current - $previous) / $previous * 100, 1),
        ];
    }

    // ── Per question ─────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function questions(DynamicForm $form, array $filters, $ids, int $total): array
    {
        $columns = array_values(array_filter(FormExportColumns::forForm($form, $filters['version'] ?? null), fn ($c) => $c['group'] === 'question'));
        if ($columns === []) {
            return [];
        }
        $definitions = $this->definitions($form);
        $questionIds = array_map(fn ($c) => (int) substr($c['key'], 2), $columns);

        // Which versions asked each question, to count only the responses that could have answered it.
        $versionsAsking = [];
        foreach (FormVersion::query()->where('form_id', $form->id)->get(['id', 'schema']) as $version) {
            foreach ($version->schema['questions'] ?? [] as $q) {
                $versionsAsking[(int) $q['id']][] = (int) $version->id;
            }
        }
        $perVersion = FormResponseQuery::build($form, $filters)->reorder()
            ->selectRaw('form_submissions.form_version_id as v, count(*) as c')->groupBy('form_submissions.form_version_id')->pluck('c', 'v');
        $unversioned = (int) ($perVersion[''] ?? 0); // responses that predate versions (the key of a null group)

        // One grouped query for the questions whose values are summarised, one count for the others.
        $valued = array_values(array_filter($questionIds, fn ($id) => in_array($definitions[$id]['type'] ?? '', [...self::CHOICE_SINGLE, ...self::CHOICE_MULTI, ...self::NUMERIC, 'yes_no', 'date', 'time'], true)));
        $grouped = [];
        if ($valued !== []) {
            DB::table('form_answers')->whereIn('submission_id', $ids)->whereIn('question_id', $valued)->whereNotNull('value')->where('value', '!=', '')
                ->selectRaw('question_id, value, count(*) as c')->groupBy('question_id', 'value')
                ->orderBy('question_id')->get()
                ->each(function ($row) use (&$grouped) {
                    $grouped[(int) $row->question_id][] = [(string) $row->value, (int) $row->c];
                });
        }
        $answered = DB::table('form_answers')->whereIn('submission_id', $ids)->whereIn('question_id', $questionIds)
            ->where(fn ($q) => $q->where(fn ($v) => $v->whereNotNull('value')->where('value', '!=', ''))->orWhereNotNull('file_path'))
            ->selectRaw('question_id, count(distinct submission_id) as c')->groupBy('question_id')->pluck('c', 'question_id');

        $out = [];
        foreach ($columns as $column) {
            $id = (int) substr($column['key'], 2);
            $definition = $definitions[$id] ?? ['type' => $column['type'], 'options' => null, 'config' => null];
            $type = (string) ($definition['type'] ?? $column['type']);

            $eligible = $unversioned;
            foreach ($versionsAsking[$id] ?? [] as $versionId) {
                $eligible += (int) ($perVersion[$versionId] ?? 0);
            }
            $count = (int) ($answered[$id] ?? 0);

            $out[] = [
                'id'          => $id,
                'label'       => $column['label'],
                'type'        => $type,
                'removed'     => $column['removed'],
                'answered'    => $count,
                'eligible'    => $eligible,
                'answer_rate' => $eligible > 0 ? round($count / $eligible * 100, 1) : null,
                'stats'       => $this->stats($type, $definition, $grouped[$id] ?? [], $count),
            ];
        }

        return $out;
    }

    /**
     * @param  list<array{0: string, 1: int}>  $values  distinct stored value => how many responses
     * @return array<string, mixed>
     */
    private function stats(string $type, array $definition, array $values, int $answered): array
    {
        if ($type === 'yes_no') {
            $counts = ['yes' => 0, 'no' => 0];
            foreach ($values as [$value, $c]) {
                if (isset($counts[$value])) {
                    $counts[$value] += $c;
                }
            }

            return ['kind' => 'choice', 'options' => [
                ['value' => 'yes', 'label' => 'نعم', 'count' => $counts['yes'], 'percent' => $this->share($counts['yes'], $answered)],
                ['value' => 'no', 'label' => 'لا', 'count' => $counts['no'], 'percent' => $this->share($counts['no'], $answered)],
            ]];
        }

        if (in_array($type, self::CHOICE_SINGLE, true) || in_array($type, self::CHOICE_MULTI, true)) {
            $multi = in_array($type, self::CHOICE_MULTI, true);
            $counts = [];
            foreach ((array) ($definition['options'] ?? []) as $option) {
                $counts[(string) $option] = 0;
            }
            foreach ($values as [$value, $c]) {
                $picked = $multi ? (json_decode($value, true) ?: [$value]) : [$value];
                foreach ((array) $picked as $p) {
                    // An option that a later version renamed or removed keeps its answers: it is listed after the current ones.
                    $counts[(string) $p] = ($counts[(string) $p] ?? 0) + $c;
                }
            }
            $current = array_map('strval', (array) ($definition['options'] ?? []));
            $options = [];
            foreach ($counts as $value => $c) {
                $options[] = ['value' => (string) $value, 'label' => (string) $value, 'count' => $c, 'percent' => $this->share($c, $answered), 'retired' => ! in_array((string) $value, $current, true)];
            }

            // For several choices the shares add up to more than 100%: each is "of the people who answered".
            return ['kind' => 'choice', 'multiple' => $multi, 'options' => $options];
        }

        if (in_array($type, self::NUMERIC, true)) {
            $n = 0;
            $sum = 0.0;
            $min = $max = null;
            $distribution = [];
            foreach ($values as [$value, $c]) {
                if (! is_numeric($value)) {
                    continue;
                }
                $v = (float) $value;
                $n += $c;
                $sum += $v * $c;
                $min = $min === null ? $v : min($min, $v);
                $max = $max === null ? $v : max($max, $v);
                $distribution[$this->numberKey($v)] = ($distribution[$this->numberKey($v)] ?? 0) + $c;
            }

            $scale = null;
            if ($type === 'star_rating') {
                $scale = ['min' => 1, 'max' => (int) ($definition['config']['scale']['max'] ?? FormQuestionConfig::DEFAULT_STARS)];
            } elseif ($type === 'linear_scale') {
                $scale = ['min' => (int) ($definition['config']['scale']['min'] ?? 1), 'max' => (int) ($definition['config']['scale']['max'] ?? 5)];
            }
            // A rating or scale lists every step (zero included); a free number lists the values given only when there are few.
            if ($scale !== null) {
                $steps = [];
                for ($i = $scale['min']; $i <= $scale['max']; $i++) {
                    $steps[] = ['value' => (string) $i, 'count' => $distribution[(string) $i] ?? 0, 'percent' => $this->share($distribution[(string) $i] ?? 0, $n)];
                }
            } else {
                ksort($distribution, SORT_NUMERIC);
                $steps = count($distribution) <= 20
                    ? array_map(fn ($k, $c) => ['value' => (string) $k, 'count' => $c, 'percent' => $this->share($c, $n)], array_keys($distribution), $distribution)
                    : null;
            }

            return [
                'kind'         => 'numeric',
                'count'        => $n,
                'average'      => $n > 0 ? round($sum / $n, 2) : null,
                'min'          => $min,
                'max'          => $max,
                'scale'        => $scale,
                'distribution' => $steps,
            ];
        }

        if ($type === 'date' || $type === 'time') {
            $sorted = array_map(fn ($v) => $v[0], $values);
            sort($sorted);

            return ['kind' => 'range', 'earliest' => $sorted[0] ?? null, 'latest' => $sorted !== [] ? end($sorted) : null];
        }

        // Text, contact details, consent and files: how many answered, never what they wrote.
        return ['kind' => in_array($type, ['file', 'image'], true) ? 'files' : ($type === 'consent' ? 'consent' : 'count')];
    }

    /**
     * Options and settings of every question, from the newest version that has it, else from the form's own row (removed ones too).
     *
     * @return array<int, array{type: string, options: array<int, string>|null, config: array<string, mixed>|null}>
     */
    private function definitions(DynamicForm $form): array
    {
        $out = [];
        foreach (FormQuestion::withTrashed()->where('form_id', $form->id)->get(['id', 'type', 'options', 'config']) as $q) {
            $out[(int) $q->id] = ['type' => (string) $q->type, 'options' => $q->options, 'config' => $q->config];
        }
        foreach (FormVersion::query()->where('form_id', $form->id)->orderBy('version_number')->get(['schema']) as $version) {
            foreach ($version->schema['questions'] ?? [] as $q) {
                $out[(int) $q['id']] = ['type' => (string) $q['type'], 'options' => $q['options'] ?? null, 'config' => $q['config'] ?? null];
            }
        }

        return $out;
    }

    // ── Across forms ─────────────────────────────────────────────────────────

    /**
     * The forms whose responses this person may read, with their response counts over a period: the department view.
     *
     * @param  array{department_id?: int|null, from?: string|null, to?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function overview(User $user, array $filters, FormAccessService $access): array
    {
        $tz = FormResponseQuery::DISPLAY_TIMEZONE;
        $forms = $access->scope(DynamicForm::query(), $user)
            ->with(['department:id,name,name_ar'])
            ->when(array_key_exists('department_id', $filters) && $filters['department_id'] !== null, fn ($q) => $q->where('department_id', (int) $filters['department_id']))
            ->orderByDesc('id')->limit(500)->get();
        $access->prime($user, $forms->pluck('id')->all());
        $forms = $forms->filter(fn (DynamicForm $f) => $access->canViewResponses($user, $f))->values();

        $range = function ($query) use ($filters, $tz) {
            if (! empty($filters['from'])) {
                $query->where('submitted_at', '>=', CarbonImmutable::parse($filters['from'], $tz)->startOfDay()->utc());
            }
            if (! empty($filters['to'])) {
                $query->where('submitted_at', '<=', CarbonImmutable::parse($filters['to'], $tz)->endOfDay()->utc());
            }

            return $query;
        };

        $ids = $forms->pluck('id')->all();
        $counts = $ids === [] ? collect() : $range(FormSubmission::query()->whereIn('form_id', $ids))
            ->selectRaw('form_id, count(*) as c, max(submitted_at) as last_at')->groupBy('form_id')->get()->keyBy('form_id');
        $open = $ids === [] ? collect() : $range(FormSubmission::query()->whereIn('form_id', $ids)->whereIn('status', [FormSubmission::NEW, FormSubmission::IN_REVIEW, FormSubmission::NEEDS_FOLLOWUP]))
            ->selectRaw('form_id, count(*) as c')->groupBy('form_id')->pluck('c', 'form_id');

        $rows = $forms->map(function (DynamicForm $f) use ($counts, $open) {
            $c = $counts->get($f->id);

            return [
                'id'           => $f->id,
                'title'        => $f->title,
                'status'       => $f->status,
                'department'   => $f->department ? ['id' => $f->department->id, 'name' => $f->department->name_ar ?: $f->department->name] : null,
                'responses'    => (int) ($c->c ?? 0),
                'open'         => (int) ($open[$f->id] ?? 0),
                'last_response_at' => $this->iso($c->last_at ?? null),
            ];
        })->sortByDesc('responses')->values()->all();

        return [
            'filters' => $filters,
            'totals'  => [
                'forms'        => count($rows),
                'active_forms' => count(array_filter($rows, fn ($r) => $r['status'] === 'active')),
                'responses'    => array_sum(array_column($rows, 'responses')),
                'open'         => array_sum(array_column($rows, 'open')),
            ],
            'forms'   => $rows,
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Responses per status within the filters. The response table's chips ignore their own status filter (they show what choosing
     * each status would give); a summary must add up to its total, so a status outside the filter counts zero here.
     *
     * @return array<string, int>
     */
    private function statusBreakdown(DynamicForm $form, array $filters): array
    {
        $counts = FormResponseQuery::statusCounts($form, $filters);
        if (! empty($filters['status'])) {
            foreach ($counts as $status => $n) {
                if (! in_array($status, $filters['status'], true)) {
                    $counts[$status] = 0;
                }
            }
        }

        return $counts;
    }

    private function share(int $part, int $whole): ?float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : null;
    }

    private function numberKey(float $v): string
    {
        return floor($v) === $v ? (string) (int) $v : rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.');
    }

    private function iso(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value, 'UTC')->setTimezone(FormResponseQuery::DISPLAY_TIMEZONE)->toIso8601String();
    }
}
