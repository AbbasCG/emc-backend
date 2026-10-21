<?php

namespace App\Services\Forms\Logic;

use Illuminate\Http\UploadedFile;

/**
 * The conditional-logic specification of Smart Forms, and the one engine that evaluates it on the server.
 *
 * A block (question, section heading, step break, descriptive text) may carry ONE rule:
 *
 *   {"action": "show"|"hide", "match": "all"|"any", "conditions": [{"question": <id>, "operator": "...", "value": ...}, ...]}
 *
 * It is data, never code: the operators below are a closed list, each one checked against the TYPE of the question it reads, and
 * nothing in a rule is ever evaluated as an expression. The browser evaluates the very same specification (formLogic.ts); both
 * are held to the same test vectors (tests/fixtures/form_logic_vectors.json), and the server's answer is the one that counts.
 *
 * SEMANTICS (every one of them is pinned by a test vector)
 *   - Blocks are evaluated in document order. A condition reads only the answers of blocks that come EARLIER and are themselves
 *     VISIBLE: the answer of a hidden question counts as no answer, so hiding cascades; a question that comes later, or that does
 *     not exist, is simply unanswered. Evaluation therefore always terminates and has one result, whatever the data.
 *   - Unanswered: `is_empty` is true; `not_equals`, `not_contains` and `none_of` are true (the answer is not that value); every other
 *     operator is false.
 *   - `show` shows the block when the conditions hold; `hide` hides it when they hold. `all` is AND, `any` is OR.
 *   - A step break (`page_break`) governs the blocks after it up to the next step break; a section heading governs the blocks after
 *     it up to the next section heading. A block is visible only when its own rule, its step and its section all allow it.
 *   - Text is compared as written (trimmed, case-sensitive); numbers numerically; dates and times as ISO strings.
 *
 * Saving a draft checks the SHAPE of every rule (known operator for the question's type, a value of the right kind, a source that
 * exists). Publishing also refuses a rule that reads a question that comes after it, one that reads itself, and circular chains.
 */
final class FormLogic
{
    public const ACTIONS = ['show', 'hide'];

    public const MATCHES = ['all', 'any'];

    public const MAX_CONDITIONS = 10;

    public const MAX_LIST = 50;

    public const TEXT_MAX = 255;

    private const TEXT = ['text', 'textarea', 'email', 'phone', 'url'];

    private const NUMERIC = ['number', 'star_rating', 'linear_scale'];

    private const SINGLE = ['select', 'radio'];

    private const MULTI = ['checkbox', 'multiselect'];

    private const EMPTINESS = ['is_empty', 'is_not_empty'];

    private const ORDERED = ['gt', 'gte', 'lt', 'lte'];

    /** The operators of every question type. A block that is not answered (headings, text, step breaks) cannot be a source. */
    public static function operatorsFor(string $type): array
    {
        return match (true) {
            in_array($type, self::TEXT, true)    => ['equals', 'not_equals', 'contains', 'not_contains', ...self::EMPTINESS],
            in_array($type, self::NUMERIC, true) => ['equals', 'not_equals', ...self::ORDERED, ...self::EMPTINESS],
            $type === 'date' || $type === 'time' => ['equals', 'not_equals', ...self::ORDERED, ...self::EMPTINESS],
            in_array($type, self::SINGLE, true)  => ['equals', 'not_equals', 'any_of', 'none_of', ...self::EMPTINESS],
            in_array($type, self::MULTI, true)   => ['contains', 'not_contains', 'any_of', 'none_of', ...self::EMPTINESS],
            $type === 'yes_no'                   => ['equals', 'not_equals', ...self::EMPTINESS],
            $type === 'consent', $type === 'file', $type === 'image' => self::EMPTINESS,
            default                              => [],
        };
    }

    /** Can a rule read this block's answer? */
    public static function canBeSource(string $type): bool
    {
        return self::operatorsFor($type) !== [];
    }

    // ── Reading and checking a rule ──────────────────────────────────────────

    /**
     * Checks one rule as an editor sent it and returns its canonical form.
     *
     * @param  array<int, array{type: string, options?: array<int, string>|null}>  $sources  every block of the form by id
     * @param  array<int|string, int>  $idMap  rewrites the ids the editor used (its temporary ids) to stored ids
     * @return array{0: array<string, mixed>|null, 1: array<string, string>} the rule (null when there is none) and messages by field path
     */
    public static function normalize(mixed $raw, array $sources, array $idMap = []): array
    {
        if ($raw === null || $raw === []) {
            return [null, []];
        }
        if (! is_array($raw)) {
            return [null, ['logic' => 'صيغة الشرط غير صالحة.']];
        }
        $conditions = $raw['conditions'] ?? null;
        if (! is_array($conditions) || $conditions === []) {
            return [null, []]; // a rule without conditions is no rule
        }

        $errors = [];
        $action = $raw['action'] ?? 'show';
        $match = $raw['match'] ?? 'all';
        if (! in_array($action, self::ACTIONS, true)) {
            $errors['logic.action'] = 'اختر إظهار العنصر أو إخفاءه.';
        }
        if (! in_array($match, self::MATCHES, true)) {
            $errors['logic.match'] = 'اختر تحقق كل الشروط أو أي شرط منها.';
        }
        if (count($conditions) > self::MAX_CONDITIONS) {
            $errors['logic.conditions'] = 'عدد الشروط يتجاوز الحد المسموح ('.self::MAX_CONDITIONS.').';
        }

        $out = [];
        foreach (array_slice(array_values($conditions), 0, self::MAX_CONDITIONS) as $i => $c) {
            $path = "logic.conditions.$i";
            $c = is_array($c) ? $c : [];
            $rawId = $c['question'] ?? $c['question_id'] ?? null;
            $id = is_int($rawId) || (is_string($rawId) && ctype_digit(ltrim($rawId, '-'))) ? (int) $rawId : null;
            $id = $id !== null ? ($idMap[$id] ?? $id) : null;
            $source = $id !== null ? ($sources[$id] ?? null) : null;

            if ($source === null) {
                $errors["$path.question"] = 'الشرط يعتمد على سؤال غير موجود أو محذوف.';

                continue;
            }
            if (! self::canBeSource($source['type'])) {
                $errors["$path.question"] = 'لا يمكن بناء شرط على هذا العنصر لأنه لا يُجاب عنه.';

                continue;
            }
            $operator = $c['operator'] ?? null;
            if (! is_string($operator) || ! in_array($operator, self::operatorsFor($source['type']), true)) {
                $errors["$path.operator"] = 'هذا المشغّل لا يناسب نوع السؤال المختار.';

                continue;
            }

            $condition = ['question' => $id, 'operator' => $operator];
            if (! in_array($operator, self::EMPTINESS, true)) {
                [$value, $message] = self::value($source, $operator, $c['value'] ?? null);
                if ($message !== null) {
                    $errors["$path.value"] = $message;

                    continue;
                }
                $condition['value'] = $value;
            }
            $out[] = $condition;
        }

        if ($errors !== []) {
            return [null, $errors];
        }

        return [['action' => $action, 'match' => $match, 'conditions' => $out], []];
    }

    /** @return array{0: mixed, 1: string|null} */
    private static function value(array $source, string $operator, mixed $raw): array
    {
        $type = $source['type'];
        $options = self::optionsOf($source);

        if (in_array($operator, ['any_of', 'none_of'], true)) {
            $list = is_array($raw) ? array_values(array_unique(array_map(fn ($v) => is_scalar($v) ? trim((string) $v) : '', $raw))) : [];
            if ($list === [] || count($list) > self::MAX_LIST || in_array('', $list, true)) {
                return [null, 'اختر خياراً واحداً على الأقل.'];
            }
            foreach ($list as $item) {
                if (! in_array($item, $options, true)) {
                    return [null, 'القيمة المختارة ليست من خيارات السؤال.'];
                }
            }

            return [$list, null];
        }

        if (! is_scalar($raw) || is_bool($raw)) {
            return [null, 'أدخل قيمة للشرط.'];
        }
        $text = trim((string) $raw);
        if ($text === '') {
            return [null, 'أدخل قيمة للشرط.'];
        }

        return match (true) {
            in_array($type, self::NUMERIC, true) => is_numeric($text) && is_finite((float) $text) ? [self::number($text), null] : [null, 'أدخل رقماً صالحاً.'],
            $type === 'date'                     => self::isDate($text) ? [$text, null] : [null, 'أدخل تاريخاً صالحاً.'],
            $type === 'time'                     => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $text) === 1 ? [$text, null] : [null, 'أدخل وقتاً صالحاً بصيغة HH:MM.'],
            $type === 'yes_no'                   => in_array($text, ['yes', 'no'], true) ? [$text, null] : [null, 'اختر نعم أو لا.'],
            in_array($type, [...self::SINGLE, ...self::MULTI], true) => in_array($text, $options, true) ? [$text, null] : [null, 'القيمة المختارة ليست من خيارات السؤال.'],
            default                              => mb_strlen($text) <= self::TEXT_MAX ? [$text, null] : [null, 'القيمة أطول من المسموح ('.self::TEXT_MAX.' حرفاً).'],
        };
    }

    // ── Structure: references, order, cycles ─────────────────────────────────

    /**
     * What is wrong with the way rules refer to each other. Empty for a form that can be published.
     *
     * @param  list<array{id: int, type: string, label?: string, logic?: array<string, mixed>|null}>  $blocks  in document order
     * @return list<array{id: int, code: string, message: string}>
     */
    public static function issues(array $blocks): array
    {
        $position = [];
        $byId = [];
        foreach ($blocks as $i => $b) {
            $position[$b['id']] = $i;
            $byId[$b['id']] = $b;
        }

        $graph = self::dependencies($blocks);
        $inCycle = self::cyclic($graph);
        $issues = [];

        foreach ($blocks as $b) {
            $name = self::name($b);
            foreach (self::sourcesOf($b) as $sourceId) {
                if (! isset($byId[$sourceId])) {
                    $issues[] = ['id' => $b['id'], 'code' => 'missing', 'message' => "شرط «{$name}» يعتمد على سؤال غير موجود أو محذوف."];
                } elseif ($sourceId === $b['id']) {
                    $issues[] = ['id' => $b['id'], 'code' => 'self', 'message' => "شرط «{$name}» يعتمد على نفسه."];
                } elseif (isset($inCycle[$b['id']])) {
                    continue;
                } elseif ($position[$sourceId] > $position[$b['id']]) {
                    $issues[] = ['id' => $b['id'], 'code' => 'forward', 'message' => "شرط «{$name}» يعتمد على سؤال «".self::name($byId[$sourceId])."» الذي يأتي بعده. يجب أن يعتمد الشرط على سؤال يسبقه."];
                }
            }
        }
        foreach ($blocks as $b) {
            if (isset($inCycle[$b['id']]) && self::sourcesOf($b) !== []) {
                $names = implode(' ← ', array_map(fn ($id) => '«'.self::name($byId[$id]).'»', $inCycle[$b['id']]));
                $issues[] = ['id' => $b['id'], 'code' => 'cycle', 'message' => 'شرط «'.self::name($b)."» جزء من حلقة شرطية دائرية: {$names}. اكسر الحلقة."];
            }
        }

        return $issues;
    }

    /**
     * Every block depends on the blocks its rule reads, and on the step and the section it sits in (whose rule hides it).
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return array<int, list<int>>
     */
    private static function dependencies(array $blocks): array
    {
        $graph = [];
        $step = null;
        $section = null;
        foreach ($blocks as $b) {
            $edges = self::sourcesOf($b);
            if ($b['type'] !== 'page_break' && $step !== null) {
                $edges[] = $step;
            }
            if (! in_array($b['type'], ['page_break', 'section'], true) && $section !== null) {
                $edges[] = $section;
            }
            $graph[$b['id']] = array_values(array_unique($edges));
            if ($b['type'] === 'page_break') {
                $step = $b['id'];
            }
            if ($b['type'] === 'section') {
                $section = $b['id'];
            }
        }

        return $graph;
    }

    /**
     * The blocks that sit on a dependency cycle, each with the cycle it is on (a list of ids).
     *
     * @param  array<int, list<int>>  $graph
     * @return array<int, list<int>>
     */
    private static function cyclic(array $graph): array
    {
        $found = [];
        $state = [];
        $stack = [];

        $visit = function (int $node) use (&$visit, &$state, &$stack, &$found, $graph): void {
            $state[$node] = 1;
            $stack[] = $node;
            foreach ($graph[$node] ?? [] as $next) {
                if (! isset($graph[$next])) {
                    continue;
                }
                if (($state[$next] ?? 0) === 1) {
                    $cycle = array_slice($stack, (int) array_search($next, $stack, true));
                    foreach ($cycle as $member) {
                        $found[$member] ??= $cycle;
                    }
                } elseif (($state[$next] ?? 0) === 0) {
                    $visit($next);
                }
            }
            array_pop($stack);
            $state[$node] = 2;
        };

        foreach (array_keys($graph) as $node) {
            if (($state[$node] ?? 0) === 0) {
                $visit($node);
            }
        }

        return $found;
    }

    /** @return list<int> */
    private static function sourcesOf(array $block): array
    {
        $ids = [];
        foreach ($block['logic']['conditions'] ?? [] as $c) {
            if (isset($c['question'])) {
                $ids[] = (int) $c['question'];
            }
        }

        return array_values(array_unique($ids));
    }

    private static function name(array $block): string
    {
        $label = trim((string) ($block['label'] ?? ''));

        return $label !== '' ? $label : match ($block['type'] ?? '') {
            'page_break' => 'فاصل الخطوة', 'section' => 'قسم', 'description' => 'فقرة توضيحية', default => 'عنصر',
        };
    }

    // ── Evaluation ───────────────────────────────────────────────────────────

    /**
     * Which blocks a respondent sees, given the answers.
     *
     * @param  list<array{id: int, type: string, options?: array<int, string>|null, logic?: array<string, mixed>|null}>  $blocks  in document order
     * @param  array<int, mixed>  $answers  by question id: a string, a list of strings, an uploaded file, or nothing
     * @return array<int, bool> by block id
     */
    public static function visibility(array $blocks, array $answers): array
    {
        $byId = [];
        foreach ($blocks as $b) {
            $byId[$b['id']] = $b;
        }

        $visible = [];
        $effective = [];
        $stepShown = true;
        $sectionShown = true;

        foreach ($blocks as $b) {
            $own = self::ownVisibility($b['logic'] ?? null, $byId, $effective);

            switch ($b['type']) {
                case 'page_break':
                    $stepShown = $own;
                    $shown = $own;
                    break;
                case 'section':
                    $shown = $own && $stepShown;
                    $sectionShown = $own;
                    break;
                default:
                    $shown = $own && $stepShown && $sectionShown;
            }

            $visible[$b['id']] = $shown;
            if ($shown && self::canBeSource($b['type'])) {
                $answer = self::answerOf($b['type'], $answers[$b['id']] ?? null);
                if ($answer !== null) {
                    $effective[$b['id']] = $answer;
                }
            }
        }

        return $visible;
    }

    /**
     * @param  array<string, mixed>|null  $logic
     * @param  array<int, array<string, mixed>>  $byId
     * @param  array<int, string|list<string>|true>  $effective
     */
    private static function ownVisibility(?array $logic, array $byId, array $effective): bool
    {
        $conditions = $logic['conditions'] ?? [];
        if ($conditions === []) {
            return true;
        }

        $results = array_map(function (array $c) use ($byId, $effective): bool {
            $source = $byId[(int) ($c['question'] ?? 0)] ?? null;
            if ($source === null || ! in_array($c['operator'] ?? null, self::operatorsFor($source['type']), true)) {
                return self::holds((string) ($c['operator'] ?? ''), $source['type'] ?? '', null, null);
            }

            return self::holds($c['operator'], $source['type'], $effective[$source['id']] ?? null, $c['value'] ?? null);
        }, $conditions);

        $met = ($logic['match'] ?? 'all') === 'any' ? in_array(true, $results, true) : ! in_array(false, $results, true);

        return ($logic['action'] ?? 'show') === 'hide' ? ! $met : $met;
    }

    /** One operator against one answer (null = unanswered). See the class comment for what unanswered means. */
    private static function holds(string $operator, string $type, string|array|bool|null $answer, mixed $value): bool
    {
        if ($operator === 'is_empty') {
            return $answer === null;
        }
        if ($operator === 'is_not_empty') {
            return $answer !== null;
        }
        if ($answer === null) {
            return in_array($operator, ['not_equals', 'not_contains', 'none_of'], true);
        }
        $answer = $answer === true ? '' : $answer;

        $numeric = in_array($type, self::NUMERIC, true);
        $multi = in_array($type, self::MULTI, true);
        $list = $multi ? (array) $answer : [];
        $listValue = is_array($value) ? array_map('strval', $value) : [];
        // null: the two cannot be ordered (the answer is not a number). Then only "is not" holds; never an ordering operator.
        $cmp = self::compare($numeric, $answer, $value);

        return match ($operator) {
            'equals'       => $cmp === 0,
            'not_equals'   => $cmp !== 0,
            'gt'           => $cmp !== null && $cmp > 0,
            'gte'          => $cmp !== null && $cmp >= 0,
            'lt'           => $cmp !== null && $cmp < 0,
            'lte'          => $cmp !== null && $cmp <= 0,
            'contains'     => $multi ? in_array((string) $value, $list, true) : str_contains((string) $answer, (string) $value),
            'not_contains' => $multi ? ! in_array((string) $value, $list, true) : ! str_contains((string) $answer, (string) $value),
            'any_of'       => $multi ? array_intersect($list, $listValue) !== [] : in_array((string) $answer, $listValue, true),
            'none_of'      => $multi ? array_intersect($list, $listValue) === [] : ! in_array((string) $answer, $listValue, true),
            default        => false,
        };
    }

    /**
     * -1, 0, 1 — or null when the two cannot be compared (the answer is not a number): then no ordering operator holds and
     * only the "is not" operators do.
     */
    private static function compare(bool $numeric, string|array $answer, mixed $value): ?int
    {
        if (is_array($answer) || is_array($value)) {
            return null;
        }
        if ($numeric) {
            if (! is_numeric($answer) || ! is_numeric($value)) {
                return null;
            }

            return (float) $answer <=> (float) $value;
        }

        return strcmp((string) $answer, (string) $value) <=> 0;
    }

    /**
     * An answer as the engine reads it: trimmed text, a list of texts for multiple choice, true for an attachment, or null when
     * nothing was answered (nothing, an empty string, an empty list).
     *
     * @return string|list<string>|true|null
     */
    public static function answerOf(string $type, mixed $raw): string|array|bool|null
    {
        if (in_array($type, ['file', 'image'], true)) {
            return $raw instanceof UploadedFile || (is_string($raw) && $raw !== '') || $raw === true ? true : null;
        }
        if (in_array($type, self::MULTI, true)) {
            $list = array_values(array_filter(array_map(fn ($v) => is_scalar($v) ? trim((string) $v) : '', is_array($raw) ? $raw : [$raw]), fn ($v) => $v !== ''));

            return $list === [] ? null : $list;
        }
        if (! is_scalar($raw) || is_bool($raw)) {
            return null;
        }
        $text = trim((string) $raw);

        return $text === '' ? null : $text;
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return list<string> */
    private static function optionsOf(array $source): array
    {
        $options = array_values(array_filter((array) ($source['options'] ?? []), fn ($o) => is_string($o) && $o !== ''));
        if ($options === [] && ($source['type'] ?? '') === 'checkbox') {
            return ['نعم', 'لا'];
        }

        return $options;
    }

    private static function number(string $text): int|float
    {
        $n = (float) $text;

        return floor($n) === $n && abs($n) < 1e15 ? (int) $n : $n;
    }

    private static function isDate(string $text): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
