<?php

namespace Tests\Unit;

use App\Services\Forms\Logic\FormLogic;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The conditional-logic engine on its own: the shared vectors (the browser engine is held to the very same file), the operators
 * each question type offers, how a rule is read and refused, and the structural checks (order, missing sources, cycles).
 */
class FormLogicEngineTest extends TestCase
{
    /** @return array<string, array{0: array<string, mixed>}> */
    public static function vectors(): array
    {
        $cases = json_decode((string) file_get_contents(__DIR__.'/../fixtures/form_logic_vectors.json'), true, 512, JSON_THROW_ON_ERROR);
        $out = [];
        foreach ($cases as $i => $case) {
            $out[sprintf('%03d %s', $i + 1, $case['name'])] = [$case];
        }

        return $out;
    }

    #[DataProvider('vectors')]
    public function test_the_shared_vectors(array $case): void
    {
        $answers = [];
        foreach ($case['answers'] as $id => $value) {
            $answers[(int) $id] = $value;
        }

        $visible = FormLogic::visibility($case['blocks'], $answers);

        foreach ($case['expect'] as $id => $expected) {
            $this->assertSame($expected, $visible[(int) $id], "block $id");
        }
    }

    public function test_the_vector_file_is_substantial_and_covers_every_operator(): void
    {
        $cases = json_decode((string) file_get_contents(__DIR__.'/../fixtures/form_logic_vectors.json'), true);
        $this->assertGreaterThan(100, count($cases));

        $used = [];
        array_walk_recursive($cases, function ($v, $k) use (&$used) {
            if ($k === 'operator') {
                $used[$v] = true;
            }
        });
        foreach (['equals', 'not_equals', 'contains', 'not_contains', 'is_empty', 'is_not_empty', 'gt', 'gte', 'lt', 'lte', 'any_of', 'none_of'] as $operator) {
            $this->assertArrayHasKey($operator, $used, "no vector uses $operator");
        }
    }

    public function test_every_question_type_offers_only_operators_that_make_sense_for_it(): void
    {
        $this->assertSame(['equals', 'not_equals', 'contains', 'not_contains', 'is_empty', 'is_not_empty'], FormLogic::operatorsFor('text'));
        $this->assertSame(['equals', 'not_equals', 'gt', 'gte', 'lt', 'lte', 'is_empty', 'is_not_empty'], FormLogic::operatorsFor('number'));
        $this->assertSame(FormLogic::operatorsFor('number'), FormLogic::operatorsFor('date'));
        $this->assertSame(['equals', 'not_equals', 'any_of', 'none_of', 'is_empty', 'is_not_empty'], FormLogic::operatorsFor('select'));
        $this->assertSame(['contains', 'not_contains', 'any_of', 'none_of', 'is_empty', 'is_not_empty'], FormLogic::operatorsFor('checkbox'));
        $this->assertSame(['equals', 'not_equals', 'is_empty', 'is_not_empty'], FormLogic::operatorsFor('yes_no'));
        $this->assertSame(['is_empty', 'is_not_empty'], FormLogic::operatorsFor('file'));
        $this->assertSame(['is_empty', 'is_not_empty'], FormLogic::operatorsFor('consent'));

        foreach (['section', 'description', 'page_break', 'nonsense'] as $type) {
            $this->assertSame([], FormLogic::operatorsFor($type), "$type is not an answer source");
            $this->assertFalse(FormLogic::canBeSource($type));
        }
    }

    // ── Reading a rule ───────────────────────────────────────────────────────

    /** @return array<int, array{type: string, options: list<string>|null}> */
    private function sources(): array
    {
        return [
            1 => ['type' => 'text', 'options' => null], 2 => ['type' => 'number', 'options' => null], 3 => ['type' => 'select', 'options' => ['أ', 'ب']],
            4 => ['type' => 'checkbox', 'options' => ['س', 'ص']], 5 => ['type' => 'date', 'options' => null], 6 => ['type' => 'yes_no', 'options' => null],
            7 => ['type' => 'section', 'options' => null], 8 => ['type' => 'time', 'options' => null], 9 => ['type' => 'file', 'options' => null],
            10 => ['type' => 'checkbox', 'options' => null],
        ];
    }

    private function rule(array ...$conditions): array
    {
        return ['action' => 'show', 'match' => 'all', 'conditions' => $conditions];
    }

    public function test_a_valid_rule_is_stored_in_its_canonical_form(): void
    {
        [$rule, $errors] = FormLogic::normalize([
            'action' => 'hide', 'match' => 'any', 'extra' => 'dropped',
            'conditions' => [
                ['question' => '2', 'operator' => 'gte', 'value' => '18.0', 'junk' => 1],
                ['question_id' => 3, 'operator' => 'any_of', 'value' => ['أ', 'أ', 'ب']],
                ['question' => 1, 'operator' => 'is_empty', 'value' => 'ignored'],
                ['question' => 5, 'operator' => 'lt', 'value' => '2026-02-28'],
                ['question' => 8, 'operator' => 'gt', 'value' => '13:30'],
                ['question' => 6, 'operator' => 'equals', 'value' => 'yes'],
                ['question' => 4, 'operator' => 'contains', 'value' => 'س'],
            ],
        ], $this->sources());

        $this->assertSame([], $errors);
        $this->assertSame([
            'action' => 'hide', 'match' => 'any', 'conditions' => [
                ['question' => 2, 'operator' => 'gte', 'value' => 18],
                ['question' => 3, 'operator' => 'any_of', 'value' => ['أ', 'ب']],
                ['question' => 1, 'operator' => 'is_empty'],
                ['question' => 5, 'operator' => 'lt', 'value' => '2026-02-28'],
                ['question' => 8, 'operator' => 'gt', 'value' => '13:30'],
                ['question' => 6, 'operator' => 'equals', 'value' => 'yes'],
                ['question' => 4, 'operator' => 'contains', 'value' => 'س'],
            ],
        ], $rule);
    }

    public function test_no_rule_and_an_empty_rule_are_no_rule(): void
    {
        foreach ([null, [], ['conditions' => []], ['action' => 'show', 'conditions' => []]] as $raw) {
            $this->assertSame([null, []], FormLogic::normalize($raw, $this->sources()));
        }
        $this->assertSame('صيغة الشرط غير صالحة.', FormLogic::normalize('boom', $this->sources())[1]['logic']);
    }

    public static function refusals(): array
    {
        return [
            'unknown action'               => [['action' => 'delete', 'match' => 'all', 'conditions' => [['question' => 1, 'operator' => 'equals', 'value' => 'x']]], 'logic.action'],
            'unknown match'                => [['action' => 'show', 'match' => 'xor', 'conditions' => [['question' => 1, 'operator' => 'equals', 'value' => 'x']]], 'logic.match'],
            'missing source'               => [['conditions' => [['question' => 99, 'operator' => 'equals', 'value' => 'x']]], 'logic.conditions.0.question'],
            'no source at all'             => [['conditions' => [['operator' => 'equals', 'value' => 'x']]], 'logic.conditions.0.question'],
            'a heading as source'          => [['conditions' => [['question' => 7, 'operator' => 'is_empty']]], 'logic.conditions.0.question'],
            'unknown operator'             => [['conditions' => [['question' => 1, 'operator' => 'matches_regex', 'value' => '.*']]], 'logic.conditions.0.operator'],
            'operator of another type'     => [['conditions' => [['question' => 1, 'operator' => 'gt', 'value' => 'x']]], 'logic.conditions.0.operator'],
            'ordering on a choice'         => [['conditions' => [['question' => 3, 'operator' => 'gt', 'value' => 'أ']]], 'logic.conditions.0.operator'],
            'equals on multiple choice'    => [['conditions' => [['question' => 4, 'operator' => 'equals', 'value' => 'س']]], 'logic.conditions.0.operator'],
            'contains on a number'         => [['conditions' => [['question' => 2, 'operator' => 'contains', 'value' => '5']]], 'logic.conditions.0.operator'],
            'a value is required'          => [['conditions' => [['question' => 1, 'operator' => 'equals']]], 'logic.conditions.0.value'],
            'a blank value'                => [['conditions' => [['question' => 1, 'operator' => 'equals', 'value' => '   ']]], 'logic.conditions.0.value'],
            'a non-numeric number'         => [['conditions' => [['question' => 2, 'operator' => 'gt', 'value' => 'abc']]], 'logic.conditions.0.value'],
            'an impossible date'           => [['conditions' => [['question' => 5, 'operator' => 'lt', 'value' => '2026-02-30']]], 'logic.conditions.0.value'],
            'a date in the wrong format'   => [['conditions' => [['question' => 5, 'operator' => 'lt', 'value' => '30/01/2026']]], 'logic.conditions.0.value'],
            'a time out of range'          => [['conditions' => [['question' => 8, 'operator' => 'gt', 'value' => '25:00']]], 'logic.conditions.0.value'],
            'an option the question lacks' => [['conditions' => [['question' => 3, 'operator' => 'equals', 'value' => 'د']]], 'logic.conditions.0.value'],
            'a list with a foreign option' => [['conditions' => [['question' => 3, 'operator' => 'any_of', 'value' => ['أ', 'د']]]], 'logic.conditions.0.value'],
            'an empty list'                => [['conditions' => [['question' => 3, 'operator' => 'any_of', 'value' => []]]], 'logic.conditions.0.value'],
            'yes/no with another word'     => [['conditions' => [['question' => 6, 'operator' => 'equals', 'value' => 'maybe']]], 'logic.conditions.0.value'],
            'an array as a scalar value'   => [['conditions' => [['question' => 1, 'operator' => 'equals', 'value' => ['a']]]], 'logic.conditions.0.value'],
            'a boolean value'              => [['conditions' => [['question' => 2, 'operator' => 'equals', 'value' => true]]], 'logic.conditions.0.value'],
            'text that is too long'        => [['conditions' => [['question' => 1, 'operator' => 'equals', 'value' => str_repeat('ا', 256)]]], 'logic.conditions.0.value'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_a_wrong_rule_is_refused_with_a_message_at_the_field(array $raw, string $field): void
    {
        [$rule, $errors] = FormLogic::normalize($raw, $this->sources());

        $this->assertNull($rule);
        $this->assertArrayHasKey($field, $errors);
        $this->assertNotSame('', $errors[$field]);
    }

    public function test_too_many_conditions_are_refused(): void
    {
        $many = array_fill(0, FormLogic::MAX_CONDITIONS + 1, ['question' => 1, 'operator' => 'equals', 'value' => 'x']);

        $this->assertArrayHasKey('logic.conditions', FormLogic::normalize(['conditions' => $many], $this->sources())[1]);
    }

    public function test_the_editors_temporary_ids_are_rewritten_to_stored_ids(): void
    {
        [$rule, $errors] = FormLogic::normalize(['conditions' => [['question' => -3, 'operator' => 'equals', 'value' => 'x']]], $this->sources(), [-3 => 1]);
        $this->assertSame([], $errors);
        $this->assertSame(1, $rule['conditions'][0]['question']);

        // An unmapped negative id is not a block.
        $this->assertArrayHasKey('logic.conditions.0.question', FormLogic::normalize(['conditions' => [['question' => -3, 'operator' => 'equals', 'value' => 'x']]], $this->sources())[1]);
    }

    public function test_a_checkbox_without_options_offers_its_default_options(): void
    {
        [$rule, $errors] = FormLogic::normalize(['conditions' => [['question' => 10, 'operator' => 'contains', 'value' => 'نعم']]], $this->sources());

        $this->assertSame([], $errors);
        $this->assertSame('نعم', $rule['conditions'][0]['value']);
    }

    // ── Structure ────────────────────────────────────────────────────────────

    private function block(int $id, string $type = 'text', ?array $logic = null, string $label = ''): array
    {
        return ['id' => $id, 'type' => $type, 'label' => $label ?: "س$id", 'options' => null, 'logic' => $logic];
    }

    private function reads(int $id): array
    {
        return ['action' => 'show', 'match' => 'all', 'conditions' => [['question' => $id, 'operator' => 'is_not_empty']]];
    }

    public function test_rules_that_read_earlier_blocks_have_no_issues(): void
    {
        $this->assertSame([], FormLogic::issues([$this->block(1), $this->block(2, 'text', $this->reads(1)), $this->block(3, 'text', $this->reads(2))]));
        $this->assertSame([], FormLogic::issues([]));
    }

    public function test_a_rule_that_reads_a_later_block_is_reported_by_name(): void
    {
        $issues = FormLogic::issues([$this->block(1, 'text', $this->reads(2), 'الاسم'), $this->block(2, 'text', null, 'العمر')]);

        $this->assertCount(1, $issues);
        $this->assertSame(['id' => 1, 'code' => 'forward'], ['id' => $issues[0]['id'], 'code' => $issues[0]['code']]);
        $this->assertStringContainsString('«الاسم»', $issues[0]['message']);
        $this->assertStringContainsString('«العمر»', $issues[0]['message']);
    }

    public function test_a_rule_that_reads_itself_or_a_missing_block_is_reported(): void
    {
        $self = FormLogic::issues([$this->block(1, 'text', $this->reads(1))]);
        $this->assertSame('self', $self[0]['code']);

        $missing = FormLogic::issues([$this->block(1), $this->block(2, 'text', $this->reads(99))]);
        $this->assertSame('missing', $missing[0]['code']);
        $this->assertSame(2, $missing[0]['id']);
    }

    public function test_a_circular_chain_is_reported_on_every_block_in_it(): void
    {
        // 1 reads 3, 2 reads 1, 3 reads 2: a ring, whatever the order on the page.
        $issues = FormLogic::issues([$this->block(1, 'text', $this->reads(3)), $this->block(2, 'text', $this->reads(1)), $this->block(3, 'text', $this->reads(2))]);

        $cycle = array_values(array_filter($issues, fn ($i) => $i['code'] === 'cycle'));
        $this->assertSame([1, 2, 3], collect($cycle)->pluck('id')->sort()->values()->all());
        $this->assertStringContainsString('حلقة', $cycle[0]['message']);
    }

    public function test_a_section_that_reads_a_block_inside_itself_is_a_cycle(): void
    {
        // The section's rule reads question 2, which sits inside it and is hidden by that very rule.
        $issues = FormLogic::issues([$this->block(1, 'section', $this->reads(2)), $this->block(2)]);

        $this->assertContains('cycle', array_column($issues, 'code'));
    }

    public function test_a_step_that_reads_a_block_after_it_is_reported(): void
    {
        $issues = FormLogic::issues([$this->block(1, 'page_break', $this->reads(2)), $this->block(2)]);

        $this->assertNotEmpty($issues);
    }

    public function test_evaluation_always_terminates_and_is_deterministic_even_for_a_tangled_form(): void
    {
        $blocks = [$this->block(1, 'text', $this->reads(3)), $this->block(2, 'text', $this->reads(1)), $this->block(3, 'text', $this->reads(2))];

        $first = FormLogic::visibility($blocks, [1 => 'a', 2 => 'b', 3 => 'c']);

        $this->assertSame($first, FormLogic::visibility($blocks, [1 => 'a', 2 => 'b', 3 => 'c']));
        $this->assertSame([1 => false, 2 => false, 3 => false], $first, 'a block can only read what came before it, so nothing in a ring is ever shown');
    }

    public function test_answers_of_other_shapes_are_read_safely(): void
    {
        $this->assertNull(FormLogic::answerOf('text', ['array']));
        $this->assertNull(FormLogic::answerOf('text', true));
        $this->assertSame('5', FormLogic::answerOf('number', 5));
        $this->assertSame(['a'], FormLogic::answerOf('checkbox', 'a'));
        $this->assertNull(FormLogic::answerOf('checkbox', ['', '  ']));
        $this->assertTrue(FormLogic::answerOf('file', true));
        $this->assertNull(FormLogic::answerOf('file', null));
        $this->assertNull(FormLogic::answerOf('text', "  \t "));
    }
}
