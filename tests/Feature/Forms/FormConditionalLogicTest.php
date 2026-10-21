<?php

namespace Tests\Feature\Forms;

use App\Models\ActivityLog;
use App\Models\DynamicForm;
use App\Models\FormAnswer;
use App\Models\FormQuestion;
use App\Models\FormSubmission;
use App\Models\FormVersion;
use App\Models\User;
use App\Services\Forms\FormSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesForms;
use Tests\TestCase;

/**
 * Conditional logic and steps, end to end through the same endpoints the builder and the public form use: what a draft may
 * hold, what publishing refuses, what a version freezes and restores, and what the SERVER does with a submission whatever the
 * browser sent.
 */
class FormConditionalLogicTest extends TestCase
{
    use MakesForms;
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('private');
        $this->owner = $this->makeUser('operations_manager');
    }

    private function as(User $user)
    {
        return $this->actingAs($user, 'sanctum');
    }

    /** A rule as the builder sends it: sources are named by the (temporary or stored) id of the block. */
    private function when(int $source, string $operator, mixed $value = null, string $action = 'show', string $match = 'all'): array
    {
        return ['action' => $action, 'match' => $match, 'conditions' => [['question' => $source, 'operator' => $operator] + ($value !== null ? ['value' => $value] : [])]];
    }

    /** @param  list<array<string, mixed>>  $questions  with temporary ids (-1, -2…) so rules can read one another */
    private function create(array $questions, array $extra = [], bool $publish = true): DynamicForm
    {
        $res = $this->as($this->owner)->postJson('/api/operations/forms', ['title' => 'نموذج مشروط', 'questions' => $questions] + $extra)->assertCreated();
        $form = DynamicForm::findOrFail($res->json('data.id'));
        if ($publish) {
            $this->as($this->owner)->postJson("/api/operations/forms/{$form->id}/publish")->assertOk();
        }

        return $form->refresh();
    }

    private function id(DynamicForm $form, string $label): int
    {
        return (int) FormQuestion::where('form_id', $form->id)->where('label', $label)->value('id');
    }

    private function submit(DynamicForm $form, array $answers, array $files = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson("/api/forms/{$form->slug}/submit", ['answers' => $answers] + ($files ? ['files' => $files] : []));
    }

    /** The standard form: «هل أنت موظف؟» (yes/no) → «اسم الشركة» (required, shown only for employees). */
    private function employerForm(array $extra = []): DynamicForm
    {
        return $this->create([
            ['id' => -1, 'type' => 'yes_no', 'label' => 'هل أنت موظف؟', 'is_required' => true],
            ['id' => -2, 'type' => 'text', 'label' => 'اسم الشركة', 'is_required' => true, 'logic' => $this->when(-1, 'equals', 'yes')],
            ['id' => -3, 'type' => 'text', 'label' => 'ملاحظات'],
        ], $extra);
    }

    // ── The draft ────────────────────────────────────────────────────────────

    public function test_a_rule_may_read_a_block_created_in_the_same_save_and_is_stored_with_real_ids(): void
    {
        $form = $this->employerForm();

        $yesNo = $this->id($form, 'هل أنت موظف؟');
        $rule = FormQuestion::findOrFail($this->id($form, 'اسم الشركة'))->logic;
        $this->assertSame(['action' => 'show', 'match' => 'all', 'conditions' => [['question' => $yesNo, 'operator' => 'equals', 'value' => 'yes']]], $rule);

        $read = $this->as($this->owner)->getJson("/api/operations/forms/{$form->id}")->assertOk()->json('data.questions');
        $this->assertSame($rule, collect($read)->firstWhere('label', 'اسم الشركة')['logic']);
        $this->assertNull(collect($read)->firstWhere('label', 'ملاحظات')['logic']);
    }

    /** @return array<string, array{0: string, 1: string, 2: mixed, 3: string, 4: string}> source label ('' = a block that does not exist), operator, value, action, error field */
    public static function wrongRules(): array
    {
        return [
            'an operator of another type' => ['المصدر', 'gt', 5, 'show', 'questions.1.logic.conditions.0.operator'],
            'a missing source'            => ['', 'equals', 'yes', 'show', 'questions.1.logic.conditions.0.question'],
            'a value the source lacks'    => ['المصدر', 'equals', 'maybe', 'show', 'questions.1.logic.conditions.0.value'],
            'a heading as the source'     => ['قسم', 'is_empty', null, 'show', 'questions.1.logic.conditions.0.question'],
            'an unknown action'           => ['المصدر', 'equals', 'yes', 'explode', 'questions.1.logic.action'],
            'no value for equals'         => ['المصدر', 'equals', null, 'show', 'questions.1.logic.conditions.0.value'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('wrongRules')]
    public function test_a_malformed_rule_refuses_the_whole_save_and_changes_nothing(string $sourceLabel, string $operator, mixed $value, string $action, string $field): void
    {
        $form = $this->create([
            ['id' => -1, 'type' => 'yes_no', 'label' => 'المصدر'],
            ['id' => -2, 'type' => 'text', 'label' => 'التابع'],
            ['id' => -3, 'type' => 'section', 'label' => 'قسم'],
        ], [], publish: false);
        $revision = $form->draft_revision;
        $payload = FormQuestion::where('form_id', $form->id)->orderBy('sort_order')->get()->map(fn ($q) => ['id' => $q->id, 'type' => $q->type, 'label' => $q->label])->all();
        $source = $sourceLabel === '' ? 777 : $this->id($form, $sourceLabel);
        $payload[1]['logic'] = ['action' => $action, 'match' => 'all', 'conditions' => [['question' => $source, 'operator' => $operator] + ($value !== null ? ['value' => $value] : [])]];
        $payload[1]['label'] = 'التابع (معدّل)';

        $res = $this->as($this->owner)->putJson("/api/operations/forms/{$form->id}", ['questions' => $payload])->assertStatus(422);

        $this->assertArrayHasKey($field, $res->json('errors'));
        $this->assertSame($revision, $form->refresh()->draft_revision);
        $this->assertSame('التابع', FormQuestion::findOrFail($payload[1]['id'])->label, 'the other edits of the same save did not go through either');
    }

    public function test_leaving_the_rule_out_keeps_it_and_sending_null_removes_it(): void
    {
        $form = $this->employerForm();
        $company = $this->id($form, 'اسم الشركة');
        $before = FormQuestion::findOrFail($company)->logic;
        $questions = FormQuestion::where('form_id', $form->id)->orderBy('sort_order')->get()->map(fn ($q) => ['id' => $q->id, 'type' => $q->type, 'label' => $q->label, 'is_required' => $q->is_required])->all();

        $this->as($this->owner)->putJson("/api/operations/forms/{$form->id}", ['questions' => $questions])->assertOk();
        $this->assertSame($before, FormQuestion::findOrFail($company)->logic, 'a client that does not know about rules does not erase them');

        $questions[1]['logic'] = null;
        $this->as($this->owner)->putJson("/api/operations/forms/{$form->id}", ['questions' => $questions])->assertOk();
        $this->assertNull(FormQuestion::findOrFail($company)->logic);
    }

    public function test_changing_only_a_rule_counts_as_an_edit_and_is_unpublished_until_published(): void
    {
        $form = $this->employerForm();
        $revision = $form->draft_revision;
        $questions = FormQuestion::where('form_id', $form->id)->orderBy('sort_order')->get()->map(fn ($q) => ['id' => $q->id, 'type' => $q->type, 'label' => $q->label, 'is_required' => $q->is_required, 'logic' => $q->logic])->all();
        $questions[1]['logic'] = $this->when($questions[0]['id'], 'equals', 'no');

        $res = $this->as($this->owner)->putJson("/api/operations/forms/{$form->id}", ['questions' => $questions])->assertOk();

        $this->assertSame($revision + 1, $form->refresh()->draft_revision);
        $this->assertTrue($res->json('data.has_unpublished_changes'));
        $log = ActivityLog::where('entity_id', $form->id)->where('action', 'form.questions_changed')->latest('id')->first();
        $this->assertContains('اسم الشركة', $log->new_values['edited']);
    }

    public function test_the_review_step_is_saved_returned_and_part_of_unpublished_changes(): void
    {
        $form = $this->employerForm();
        $this->assertNull($this->as($this->owner)->getJson("/api/operations/forms/{$form->id}")->json('data.flow'));

        $res = $this->as($this->owner)->putJson("/api/operations/forms/{$form->id}", ['flow' => ['review_step' => true, 'unknown' => 'x']])->assertOk();
        $this->assertSame(['review_step' => true], $res->json('data.flow'));
        $this->assertTrue($res->json('data.has_unpublished_changes'));

        $this->as($this->owner)->putJson("/api/operations/forms/{$form->id}", ['flow' => ['review_step' => false]])->assertOk()->assertJsonPath('data.flow', null);
        $this->assertNull($form->refresh()->flow);
        // Not mentioning it leaves it alone.
        $this->as($this->owner)->putJson("/api/operations/forms/{$form->id}", ['flow' => ['review_step' => true]])->assertOk();
        $this->as($this->owner)->putJson("/api/operations/forms/{$form->id}", ['title' => 'عنوان جديد'])->assertOk()->assertJsonPath('data.flow.review_step', true);
    }

    public function test_only_someone_who_may_edit_the_form_can_change_its_rules(): void
    {
        $form = $this->employerForm();
        $viewer = $this->makeUser('community_manager');
        $this->as($this->owner)->postJson("/api/operations/forms/{$form->id}/collaborators", ['user_id' => $viewer->id, 'access_level' => 'viewer'])->assertCreated();
        $outsider = $this->makeUser('operations_manager');
        $company = $this->id($form, 'اسم الشركة');
        $payload = ['questions' => [['id' => $this->id($form, 'هل أنت موظف؟'), 'type' => 'yes_no', 'label' => 'هل أنت موظف؟'], ['id' => $company, 'type' => 'text', 'label' => 'اسم الشركة', 'logic' => null]]];
        $before = FormQuestion::findOrFail($company)->logic;

        $this->as($viewer)->putJson("/api/operations/forms/{$form->id}", $payload)->assertForbidden();
        $this->as($outsider)->putJson("/api/operations/forms/{$form->id}", $payload)->assertForbidden();
        $this->assertSame($before, FormQuestion::findOrFail($company)->logic);

        $editor = $this->makeUser('operations_manager');
        $this->as($this->owner)->postJson("/api/operations/forms/{$form->id}/collaborators", ['user_id' => $editor->id, 'access_level' => 'editor'])->assertCreated();
        $this->as($editor)->putJson("/api/operations/forms/{$form->id}", $payload)->assertOk();
        $this->assertNull(FormQuestion::findOrFail($company)->logic);
    }

    public function test_a_rule_may_name_an_unsaved_block_by_ref_exactly_as_the_builder_sends_it(): void
    {
        // The builder sends no `id` for an unsaved block (it is not stored yet); the one a rule reads says which it is with `ref`.
        $res = $this->as($this->owner)->postJson('/api/operations/forms', ['title' => 'نموذج', 'questions' => [
            ['ref' => -1, 'type' => 'yes_no', 'label' => 'هل أنت موظف؟'],
            ['type' => 'text', 'label' => 'اسم الشركة', 'logic' => $this->when(-1, 'equals', 'yes')],
            ['type' => 'text', 'label' => 'ملاحظات'],
        ]])->assertCreated();
        $form = DynamicForm::findOrFail($res->json('data.id'));

        $yesNo = $this->id($form, 'هل أنت موظف؟');
        $this->assertSame($yesNo, FormQuestion::findOrFail($this->id($form, 'اسم الشركة'))->logic['conditions'][0]['question']);
        $this->assertNull(FormQuestion::findOrFail($this->id($form, 'ملاحظات'))->logic);
    }

    public function test_a_ref_that_no_block_of_the_save_carries_is_refused_like_any_missing_source(): void
    {
        $res = $this->as($this->owner)->postJson('/api/operations/forms', ['title' => 'نموذج', 'questions' => [
            ['ref' => -1, 'type' => 'yes_no', 'label' => 'هل أنت موظف؟'],
            ['type' => 'text', 'label' => 'اسم الشركة', 'logic' => $this->when(-9, 'equals', 'yes')],
        ]])->assertStatus(422);

        $this->assertArrayHasKey('questions.1.logic.conditions.0.question', $res->json('errors'));
        $this->assertSame(0, DynamicForm::where('title', 'نموذج')->count(), 'nothing of the refused save was kept');
    }

    public function test_a_ref_is_always_negative_so_it_can_never_stand_for_a_stored_block(): void
    {
        $form = $this->employerForm();
        $yesNo = $this->id($form, 'هل أنت موظف؟');
        $company = $this->id($form, 'اسم الشركة');
        $before = FormQuestion::findOrFail($company)->logic;

        // Another block pretends to be the stored «هل أنت موظف؟» block by claiming its id as its ref.
        $res = $this->as($this->owner)->putJson("/api/operations/forms/{$form->id}", ['questions' => [
            ['id' => $yesNo, 'type' => 'yes_no', 'label' => 'هل أنت موظف؟'],
            ['id' => $company, 'type' => 'text', 'label' => 'اسم الشركة', 'logic' => $this->when($yesNo, 'equals', 'no')],
            ['ref' => $yesNo, 'type' => 'text', 'label' => 'منتحل'],
        ]])->assertStatus(422);

        $this->assertArrayHasKey('questions.2.ref', $res->json('errors'));
        $this->assertSame($before, FormQuestion::findOrFail($company)->logic);
        $this->assertSame(0, FormQuestion::where('form_id', $form->id)->where('label', 'منتحل')->count());
    }

    public function test_a_stored_block_is_still_found_by_its_id_when_the_same_save_also_creates_blocks_by_ref(): void
    {
        $form = $this->employerForm();
        $yesNo = $this->id($form, 'هل أنت موظف؟');
        $questions = FormQuestion::where('form_id', $form->id)->orderBy('sort_order')->get()
            ->map(fn ($q) => ['id' => $q->id, 'type' => $q->type, 'label' => $q->label, 'is_required' => $q->is_required, 'logic' => $q->logic])->all();
        $questions[] = ['ref' => -1, 'type' => 'text', 'label' => 'مدينة', 'logic' => $this->when($yesNo, 'equals', 'yes')];
        $questions[] = ['type' => 'text', 'label' => 'حي', 'logic' => $this->when(-1, 'is_not_empty')];

        $this->as($this->owner)->putJson("/api/operations/forms/{$form->id}", ['questions' => $questions])->assertOk();

        $city = $this->id($form, 'مدينة');
        $this->assertSame($yesNo, FormQuestion::findOrFail($city)->logic['conditions'][0]['question']);
        $this->assertSame($city, FormQuestion::findOrFail($this->id($form, 'حي'))->logic['conditions'][0]['question']);
    }

    // ── Publishing ───────────────────────────────────────────────────────────

    public function test_a_draft_may_hold_a_rule_that_reads_a_later_block_but_publishing_refuses_it(): void
    {
        $form = $this->create([
            ['id' => -1, 'type' => 'text', 'label' => 'الأول', 'logic' => $this->when(-2, 'is_not_empty')],
            ['id' => -2, 'type' => 'text', 'label' => 'الثاني'],
        ], [], publish: false);

        $res = $this->as($this->owner)->postJson("/api/operations/forms/{$form->id}/publish")->assertStatus(422);
        $this->assertStringContainsString('«الأول»', $res->json('errors.status.0'));
        $this->assertStringContainsString('يسبقه', $res->json('errors.status.0'));
        $this->assertNull($form->refresh()->published_version_id);

        // Reordering fixes it.
        $ids = FormQuestion::where('form_id', $form->id)->orderBy('sort_order')->pluck('id')->all();
        $questions = FormQuestion::where('form_id', $form->id)->get()->keyBy('id');
        $payload = [
            ['id' => $ids[1], 'type' => 'text', 'label' => 'الثاني'],
            ['id' => $ids[0], 'type' => 'text', 'label' => 'الأول', 'logic' => $questions[$ids[0]]->logic],
        ];
        $this->as($this->owner)->putJson("/api/operations/forms/{$form->id}", ['questions' => $payload])->assertOk();
        $this->as($this->owner)->postJson("/api/operations/forms/{$form->id}/publish")->assertOk();
    }

    public function test_publishing_refuses_a_circular_chain_and_names_it(): void
    {
        $form = $this->create([
            ['id' => -1, 'type' => 'text', 'label' => 'أ'],
            ['id' => -2, 'type' => 'text', 'label' => 'ب', 'logic' => $this->when(-1, 'is_not_empty')],
            ['id' => -3, 'type' => 'text', 'label' => 'ج', 'logic' => $this->when(-2, 'is_not_empty')],
        ], [], publish: false);
        // Make «أ» read «ج»: a ring (the draft accepts it; only publishing is strict).
        $q = FormQuestion::where('form_id', $form->id)->orderBy('sort_order')->get();
        $payload = $q->map(fn ($x) => ['id' => $x->id, 'type' => 'text', 'label' => $x->label, 'logic' => $x->logic])->all();
        $payload[0]['logic'] = $this->when($q[2]->id, 'is_not_empty');
        $this->as($this->owner)->putJson("/api/operations/forms/{$form->id}", ['questions' => $payload])->assertOk();

        $res = $this->as($this->owner)->postJson("/api/operations/forms/{$form->id}/publish")->assertStatus(422);

        $this->assertStringContainsString('حلقة', $res->json('errors.status.0'));
    }

    public function test_publishing_refuses_a_rule_whose_source_option_was_removed_after_the_rule_was_written(): void
    {
        $form = $this->create([
            ['id' => -1, 'type' => 'select', 'label' => 'المدينة', 'options' => ['الرياض', 'جدة']],
            ['id' => -2, 'type' => 'text', 'label' => 'حي', 'logic' => $this->when(-1, 'equals', 'جدة')],
        ], [], publish: false);
        $city = $this->id($form, 'المدينة');
        $district = FormQuestion::findOrFail($this->id($form, 'حي'));

        // Their editor drops «جدة» but, not sending the rule, leaves it in place.
        $this->as($this->owner)->putJson("/api/operations/forms/{$form->id}", ['questions' => [
            ['id' => $city, 'type' => 'select', 'label' => 'المدينة', 'options' => ['الرياض', 'الدمام']],
            ['id' => $district->id, 'type' => 'text', 'label' => 'حي'],
        ]])->assertOk();

        $res = $this->as($this->owner)->postJson("/api/operations/forms/{$form->id}/publish")->assertStatus(422);
        $this->assertStringContainsString('«حي»', $res->json('errors.status.0'));
        $this->assertStringContainsString('خيارات', $res->json('errors.status.0'));
    }

    public function test_publishing_refuses_a_rule_that_reads_a_deleted_block(): void
    {
        $form = $this->create([
            ['id' => -1, 'type' => 'text', 'label' => 'المصدر'],
            ['id' => -2, 'type' => 'text', 'label' => 'التابع', 'logic' => $this->when(-1, 'is_not_empty')],
        ], [], publish: false);
        $source = $this->id($form, 'المصدر');
        $dependent = $this->id($form, 'التابع');

        // The legacy single-question endpoint removes the source and knows nothing of rules.
        $this->as($this->owner)->deleteJson("/api/admin/forms/{$form->id}/questions/$source")->assertOk();

        $res = $this->as($this->owner)->postJson("/api/operations/forms/{$form->id}/publish")->assertStatus(422);
        $this->assertStringContainsString('غير موجود أو محذوف', $res->json('errors.status.0'));
        $this->assertNotNull(FormQuestion::findOrFail($dependent)->logic);
    }

    // ── What a version freezes ───────────────────────────────────────────────

    public function test_a_form_without_rules_has_exactly_the_snapshot_and_hash_it_always_had(): void
    {
        $form = $this->create([['id' => -1, 'type' => 'text', 'label' => 'الاسم', 'is_required' => true], ['id' => -2, 'type' => 'page_break'], ['id' => -3, 'type' => 'text', 'label' => 'العمر']]);
        $version = FormVersion::findOrFail($form->published_version_id);

        foreach ($version->schema['questions'] as $q) {
            $this->assertArrayNotHasKey('logic', $q);
        }
        $this->assertArrayNotHasKey('flow', $version->schema['form']);
        // The hash of the content is what it is for the same blocks with the Phase 4 code: no key was added.
        $legacy = ['format' => 1, 'form' => ['title' => 'نموذج مشروط', 'description' => null, 'type' => 'general'], 'questions' => $version->schema['questions']];
        $this->assertSame(FormSnapshotService::hash($legacy), $version->schema_hash);
    }

    public function test_the_published_version_carries_the_rules_and_the_flow_and_never_changes_afterwards(): void
    {
        $form = $this->employerForm(['flow' => ['review_step' => true]]);
        $version = FormVersion::findOrFail($form->published_version_id);
        $company = collect($version->schema['questions'])->firstWhere('label', 'اسم الشركة');
        $this->assertSame('equals', $company['logic']['conditions'][0]['operator']);
        $this->assertSame(['review_step' => true], $version->schema['form']['flow']);
        $hash = $version->schema_hash;

        // Edit the draft: rule removed, flow off.
        $questions = FormQuestion::where('form_id', $form->id)->orderBy('sort_order')->get()->map(fn ($q) => ['id' => $q->id, 'type' => $q->type, 'label' => $q->label, 'is_required' => $q->is_required, 'logic' => null])->all();
        $this->as($this->owner)->putJson("/api/operations/forms/{$form->id}", ['questions' => $questions, 'flow' => ['review_step' => false]])->assertOk();

        $version->refresh();
        $this->assertSame($hash, $version->schema_hash);
        $this->assertSame('equals', collect($version->schema['questions'])->firstWhere('label', 'اسم الشركة')['logic']['conditions'][0]['operator']);

        // The public form still serves the version, rules and flow included.
        $this->app['auth']->forgetGuards();
        $public = $this->getJson("/api/forms/{$form->slug}")->assertOk();
        $this->assertSame('equals', collect($public->json('data.questions'))->firstWhere('label', 'اسم الشركة')['logic']['conditions'][0]['operator']);
        $this->assertSame(['review_step' => true], $public->json('data.flow'));
    }

    public function test_restoring_a_version_restores_its_rules_and_flow(): void
    {
        $form = $this->employerForm(['flow' => ['review_step' => true]]);
        $v1 = FormVersion::findOrFail($form->published_version_id);

        // Version 2: no rules, no review step, different content.
        $questions = FormQuestion::where('form_id', $form->id)->orderBy('sort_order')->get()->map(fn ($q) => ['id' => $q->id, 'type' => $q->type, 'label' => $q->label.' 2', 'is_required' => false, 'logic' => null])->all();
        $this->as($this->owner)->putJson("/api/operations/forms/{$form->id}", ['questions' => $questions, 'flow' => ['review_step' => false]])->assertOk();
        $this->as($this->owner)->postJson("/api/operations/forms/{$form->id}/publish")->assertOk();
        $this->assertNull(FormQuestion::where('form_id', $form->id)->orderBy('sort_order')->get()[1]->logic);

        $res = $this->as($this->owner)->postJson("/api/operations/forms/{$form->id}/versions/{$v1->id}/restore")->assertOk();

        $this->assertSame(['review_step' => true], $res->json('data.flow'));
        $company = collect($res->json('data.questions'))->firstWhere('label', 'اسم الشركة');
        $this->assertSame($this->id($form, 'هل أنت موظف؟'), $company['logic']['conditions'][0]['question']);
        // The restored draft is version 1 again, hash included (it is not the published version, which is 2).
        $draft = FormQuestion::where('form_id', $form->id)->orderBy('sort_order')->get();
        $content = FormSnapshotService::content($form->refresh()->getAttributes(), $draft);
        $this->assertSame(json_encode($v1->schema['questions'], JSON_UNESCAPED_UNICODE), json_encode($content['questions'], JSON_UNESCAPED_UNICODE));
        $this->assertSame($v1->schema['form'], $content['form']);
        $this->assertSame($v1->schema_hash, FormSnapshotService::hash(FormSnapshotService::content($form->refresh()->getAttributes(), $draft)));
    }

    public function test_restoring_points_a_rule_at_the_block_that_now_stands_for_its_source(): void
    {
        $form = $this->create([
            ['id' => -1, 'type' => 'text', 'label' => 'السؤال'],
            ['id' => -2, 'type' => 'text', 'label' => 'التابع', 'logic' => $this->when(-1, 'is_not_empty')],
        ]);
        $v1 = FormVersion::findOrFail($form->published_version_id);
        $source = $this->id($form, 'السؤال');

        // The source gets an answer, then its type changes in the draft and it is published (allowed only by recreating the block):
        // restoring version 1 must re-create it under a new id, because its answers belong to the old type.
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/forms/{$form->slug}/submit", ['answers' => [$source => 'x']])->assertCreated();
        FormQuestion::findOrFail($source)->forceFill(['type' => 'number'])->save();

        $res = $this->as($this->owner)->postJson("/api/operations/forms/{$form->id}/versions/{$v1->id}/restore")->assertOk();

        $questions = collect($res->json('data.questions'));
        $newSource = $questions->firstWhere('label', 'السؤال');
        $this->assertNotSame($source, $newSource['id'], 'the answered block kept its old type, so the version\'s block is a new one');
        $dependent = $questions->firstWhere('label', 'التابع');
        $this->assertSame($newSource['id'], $dependent['logic']['conditions'][0]['question']);
    }

    // ── Enforcement on submission ────────────────────────────────────────────

    public function test_a_hidden_required_question_does_not_block_and_leaves_no_answer(): void
    {
        $form = $this->employerForm();
        $yesNo = $this->id($form, 'هل أنت موظف؟');
        $company = $this->id($form, 'اسم الشركة');

        $res = $this->submit($form, [$yesNo => 'no'])->assertCreated();

        $answers = FormAnswer::where('submission_id', $res->json('data.submission_id'))->pluck('value', 'question_id');
        $this->assertSame('no', $answers[$yesNo]);
        $this->assertNull($answers[$company]);
    }

    public function test_a_visible_required_question_still_blocks_and_nothing_is_stored(): void
    {
        $form = $this->employerForm();
        $yesNo = $this->id($form, 'هل أنت موظف؟');

        $this->submit($form, [$yesNo => 'yes'])->assertStatus(422)->assertJsonValidationErrors('answers.'.$this->id($form, 'اسم الشركة'));
        $this->assertSame(0, FormSubmission::count());
    }

    public function test_an_answer_sent_for_a_hidden_question_is_discarded_never_stored(): void
    {
        $form = $this->employerForm();
        $yesNo = $this->id($form, 'هل أنت موظف؟');
        $company = $this->id($form, 'اسم الشركة');

        $res = $this->submit($form, [$yesNo => 'no', $company => 'شركة وهمية', $this->id($form, 'ملاحظات') => 'مرئي'])->assertCreated();

        $stored = FormAnswer::where('submission_id', $res->json('data.submission_id'))->pluck('value', 'question_id');
        $this->assertNull($stored[$company], 'a manipulated request cannot persist an answer the form hid');
        $this->assertSame('مرئي', $stored[$this->id($form, 'ملاحظات')]);
    }

    public function test_an_answer_for_a_hidden_question_is_not_even_validated(): void
    {
        $form = $this->create([
            ['id' => -1, 'type' => 'yes_no', 'label' => 'هل لديك سيارة؟'],
            ['id' => -2, 'type' => 'number', 'label' => 'عدد السيارات', 'logic' => $this->when(-1, 'equals', 'yes'), 'config' => ['validation' => ['min' => 1, 'max' => 5]]],
        ]);
        $car = $this->id($form, 'هل لديك سيارة؟');

        // «لا» hides the count, so a nonsense value for it is ignored rather than refused…
        $this->submit($form, [$car => 'no', $this->id($form, 'عدد السيارات') => 'عدد كبير جداً'])->assertCreated();
        // …but once it is visible it is validated against the version's own limits.
        $this->submit($form, [$car => 'yes', $this->id($form, 'عدد السيارات') => 99])->assertStatus(422);
        $this->submit($form, [$car => 'yes', $this->id($form, 'عدد السيارات') => 3])->assertCreated();
    }

    public function test_an_attachment_sent_for_a_hidden_question_is_never_stored(): void
    {
        $form = $this->create([
            ['id' => -1, 'type' => 'yes_no', 'label' => 'هل لديك سيرة؟'],
            ['id' => -2, 'type' => 'file', 'label' => 'السيرة', 'logic' => $this->when(-1, 'equals', 'yes')],
        ]);
        $cv = $this->id($form, 'السيرة');
        $this->app['auth']->forgetGuards();

        $this->post("/api/forms/{$form->slug}/submit", [
            'answers' => [$this->id($form, 'هل لديك سيرة؟') => 'no', $cv => $this->pdfUpload('cv.pdf')],
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame(1, FormSubmission::count());
        $this->assertNull(FormAnswer::where('question_id', $cv)->first()->file_path);
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_a_shown_attachment_is_stored_as_before(): void
    {
        $form = $this->create([
            ['id' => -1, 'type' => 'yes_no', 'label' => 'هل لديك سيرة؟'],
            ['id' => -2, 'type' => 'file', 'label' => 'السيرة', 'is_required' => true, 'logic' => $this->when(-1, 'equals', 'yes')],
        ]);
        $this->app['auth']->forgetGuards();

        $this->post("/api/forms/{$form->slug}/submit", ['answers' => [$this->id($form, 'هل لديك سيرة؟') => 'yes', $this->id($form, 'السيرة') => $this->pdfUpload('cv.pdf')]], ['Accept' => 'application/json'])->assertCreated();

        $this->assertNotNull(FormAnswer::where('question_id', $this->id($form, 'السيرة'))->first()->file_path);
    }

    public function test_hiding_cascades_to_what_depends_on_a_hidden_question(): void
    {
        $form = $this->create([
            ['id' => -1, 'type' => 'yes_no', 'label' => 'أ؟'],
            ['id' => -2, 'type' => 'text', 'label' => 'ب', 'logic' => $this->when(-1, 'equals', 'yes')],
            ['id' => -3, 'type' => 'text', 'label' => 'ج', 'is_required' => true, 'logic' => $this->when(-2, 'is_not_empty')],
        ]);
        $a = $this->id($form, 'أ؟');
        $b = $this->id($form, 'ب');
        $c = $this->id($form, 'ج');

        // «أ؟» = لا hides «ب»; the answer sent for «ب» does not exist, so «ج» (required) is hidden too.
        $this->submit($form, [$a => 'no', $b => 'نص', $c => 'x'])->assertCreated();
        $this->assertNull(FormAnswer::where('question_id', $c)->first()->value);
        // «أ؟» = نعم and «ب» answered shows «ج», which is required.
        $this->submit($form, [$a => 'yes', $b => 'نص'])->assertStatus(422)->assertJsonValidationErrors("answers.$c");
        $this->submit($form, [$a => 'yes', $b => 'نص', $c => 'تم'])->assertCreated();
        // «أ؟» = نعم but «ب» left empty: «ج» stays hidden.
        $this->submit($form, [$a => 'yes'])->assertCreated();
    }

    public function test_a_hidden_section_hides_every_question_in_it_and_the_next_section_is_unaffected(): void
    {
        $form = $this->create([
            ['id' => -1, 'type' => 'yes_no', 'label' => 'هل أنت طالب؟'],
            ['id' => -2, 'type' => 'section', 'label' => 'بيانات الطالب', 'logic' => $this->when(-1, 'equals', 'yes')],
            ['id' => -3, 'type' => 'text', 'label' => 'الجامعة', 'is_required' => true],
            ['id' => -4, 'type' => 'section', 'label' => 'عام'],
            ['id' => -5, 'type' => 'text', 'label' => 'الاسم', 'is_required' => true],
        ]);
        $student = $this->id($form, 'هل أنت طالب؟');

        $this->submit($form, [$student => 'no', $this->id($form, 'الاسم') => 'سارة'])->assertCreated();
        $this->submit($form, [$student => 'no'])->assertStatus(422)->assertJsonValidationErrors('answers.'.$this->id($form, 'الاسم'));
        $this->submit($form, [$student => 'yes', $this->id($form, 'الاسم') => 'سارة'])->assertStatus(422)->assertJsonValidationErrors('answers.'.$this->id($form, 'الجامعة'));
    }

    public function test_a_hidden_step_skips_its_required_questions_and_the_final_step_is_still_enforced(): void
    {
        $form = $this->create([
            ['id' => -1, 'type' => 'select', 'label' => 'النوع', 'options' => ['فرد', 'شركة'], 'is_required' => true],
            ['id' => -2, 'type' => 'page_break', 'label' => 'بيانات الشركة', 'logic' => $this->when(-1, 'equals', 'شركة')],
            ['id' => -3, 'type' => 'text', 'label' => 'السجل التجاري', 'is_required' => true],
            ['id' => -4, 'type' => 'page_break', 'label' => 'الختام'],
            ['id' => -5, 'type' => 'text', 'label' => 'ملاحظة أخيرة', 'is_required' => true],
        ]);
        $type = $this->id($form, 'النوع');
        $final = $this->id($form, 'ملاحظة أخيرة');

        $this->submit($form, [$type => 'فرد', $final => 'تمت'])->assertCreated();
        $this->submit($form, [$type => 'شركة', $final => 'تمت'])->assertStatus(422)->assertJsonValidationErrors('answers.'.$this->id($form, 'السجل التجاري'));
        $this->submit($form, [$type => 'فرد'])->assertStatus(422)->assertJsonValidationErrors("answers.$final");
    }

    public function test_a_partial_response_is_never_stored_as_a_completed_one(): void
    {
        $form = $this->create([
            ['id' => -1, 'type' => 'text', 'label' => 'الخطوة الأولى', 'is_required' => true],
            ['id' => -2, 'type' => 'page_break', 'label' => 'الثانية'],
            ['id' => -3, 'type' => 'text', 'label' => 'الخطوة الثانية', 'is_required' => true],
        ]);

        // A client that sends only the first step's answers is refused; the server has no "partial" state.
        $this->submit($form, [$this->id($form, 'الخطوة الأولى') => 'تم'])->assertStatus(422)->assertJsonValidationErrors('answers.'.$this->id($form, 'الخطوة الثانية'));
        $this->assertSame(0, FormSubmission::count());
        $this->assertSame(0, FormAnswer::count());
    }

    public function test_all_the_comparison_kinds_are_enforced_by_the_server(): void
    {
        $form = $this->create([
            ['id' => -1, 'type' => 'number', 'label' => 'العمر'],
            ['id' => -2, 'type' => 'text', 'label' => 'ولي الأمر', 'is_required' => true, 'logic' => $this->when(-1, 'lt', 18)],
            ['id' => -3, 'type' => 'date', 'label' => 'تاريخ الميلاد'],
            ['id' => -4, 'type' => 'text', 'label' => 'سبب التأخر', 'is_required' => true, 'logic' => $this->when(-3, 'gt', '2026-01-01')],
            ['id' => -5, 'type' => 'checkbox', 'label' => 'الاهتمامات', 'options' => ['برمجة', 'تصميم', 'تسويق']],
            ['id' => -6, 'type' => 'text', 'label' => 'نبذة', 'is_required' => true, 'logic' => $this->when(-5, 'any_of', ['برمجة', 'تصميم'])],
        ]);
        $age = $this->id($form, 'العمر');
        $guardian = $this->id($form, 'ولي الأمر');
        $born = $this->id($form, 'تاريخ الميلاد');
        $why = $this->id($form, 'سبب التأخر');
        $topics = $this->id($form, 'الاهتمامات');
        $bio = $this->id($form, 'نبذة');

        $this->submit($form, [$age => 17])->assertStatus(422)->assertJsonValidationErrors("answers.$guardian");
        $this->submit($form, [$age => 18])->assertCreated();
        $this->submit($form, [$age => '17.5', $guardian => 'خالد'])->assertCreated();
        $this->submit($form, [$born => '2026-06-01'])->assertStatus(422)->assertJsonValidationErrors("answers.$why");
        $this->submit($form, [$born => '2025-06-01'])->assertCreated();
        $this->submit($form, [$topics => ['برمجة']])->assertStatus(422)->assertJsonValidationErrors("answers.$bio");
        $this->submit($form, [$topics => ['تسويق']])->assertCreated();
        $this->submit($form, [$topics => ['تسويق', 'تصميم'], $bio => 'نبذة'])->assertCreated();
    }

    public function test_unanswered_semantics_hold_on_the_server(): void
    {
        $form = $this->create([
            ['id' => -1, 'type' => 'text', 'label' => 'الدولة'],
            ['id' => -2, 'type' => 'text', 'label' => 'سبب', 'is_required' => true, 'logic' => $this->when(-1, 'not_equals', 'السعودية')],
            ['id' => -3, 'type' => 'text', 'label' => 'محافظة', 'is_required' => true, 'logic' => $this->when(-1, 'equals', 'السعودية')],
        ]);

        // Nothing answered: «not equal» holds, «equal» does not.
        $this->submit($form, [])->assertStatus(422)->assertJsonValidationErrors(['answers.'.$this->id($form, 'سبب')])->assertJsonMissingValidationErrors('answers.'.$this->id($form, 'محافظة'));
    }

    public function test_the_visibility_a_client_claims_is_irrelevant(): void
    {
        $form = $this->employerForm();
        $yesNo = $this->id($form, 'هل أنت موظف؟');
        $company = $this->id($form, 'اسم الشركة');
        $this->app['auth']->forgetGuards();

        // Extra fields a manipulated client might add (claiming a question is visible, hidden, or already answered) are ignored.
        $res = $this->postJson("/api/forms/{$form->slug}/submit", ['answers' => [$yesNo => 'no', $company => 'x'], 'visible' => [$company], 'hidden' => [], 'logic' => ['skip' => true], 'metadata' => []])->assertCreated();

        $this->assertNull(FormAnswer::where('submission_id', $res->json('data.submission_id'))->where('question_id', $company)->value('value'));
    }

    // ── History ──────────────────────────────────────────────────────────────

    public function test_old_responses_stay_readable_after_the_rules_change_and_are_judged_by_their_own_version(): void
    {
        $form = $this->employerForm();
        $yesNo = $this->id($form, 'هل أنت موظف؟');
        $company = $this->id($form, 'اسم الشركة');
        $old = $this->submit($form, [$yesNo => 'yes', $company => 'شركة قديمة'])->assertCreated()->json('data.submission_id');

        // Version 2 removes the rule and makes the question optional.
        $questions = FormQuestion::where('form_id', $form->id)->orderBy('sort_order')->get()->map(fn ($q) => ['id' => $q->id, 'type' => $q->type, 'label' => $q->label, 'is_required' => false, 'logic' => null])->all();
        $this->as($this->owner)->putJson("/api/operations/forms/{$form->id}", ['questions' => $questions])->assertOk();
        $this->as($this->owner)->postJson("/api/operations/forms/{$form->id}/publish")->assertOk();

        $detail = $this->as($this->owner)->getJson("/api/operations/forms/{$form->id}/submissions/$old")->assertOk();
        $this->assertSame('شركة قديمة', collect($detail->json('data.answers'))->firstWhere('question_id', $company)['value']);
        // New submissions follow the new version: no rule, so «no» with a company name keeps the company name.
        $new = $this->submit($form, [$yesNo => 'no', $company => 'شركة'])->assertCreated()->json('data.submission_id');
        $this->assertSame('شركة', FormAnswer::where('submission_id', $new)->where('question_id', $company)->value('value'));
    }

    public function test_a_question_hidden_in_a_response_cannot_be_corrected_but_a_visible_one_can(): void
    {
        $form = $this->employerForm();
        $yesNo = $this->id($form, 'هل أنت موظف؟');
        $company = $this->id($form, 'اسم الشركة');
        $notes = $this->id($form, 'ملاحظات');
        $id = $this->submit($form, [$yesNo => 'no', $notes => 'أ'])->assertCreated()->json('data.submission_id');
        $url = "/api/operations/forms/{$form->id}/submissions/$id/answers";

        $this->as($this->owner)->putJson($url, ['corrections' => [$company => 'شركة']])->assertStatus(422)->assertJsonValidationErrors("corrections.$company");
        $this->as($this->owner)->putJson($url, ['corrections' => [$notes => 'ب']])->assertOk()->assertJsonPath('changed', 1);
        // Correcting the source so that the question becomes visible lets it be corrected in the same request.
        $this->as($this->owner)->putJson($url, ['corrections' => [$yesNo => 'yes', $company => 'شركة']])->assertOk()->assertJsonPath('changed', 2);
        $this->assertSame('شركة', FormAnswer::where('submission_id', $id)->where('question_id', $company)->value('value'));
    }

    public function test_responses_and_exports_show_a_hidden_question_as_empty(): void
    {
        $form = $this->employerForm();
        $yesNo = $this->id($form, 'هل أنت موظف؟');
        $id = $this->submit($form, [$yesNo => 'no'])->assertCreated()->json('data.submission_id');

        $row = collect($this->as($this->owner)->getJson("/api/operations/forms/{$form->id}/submissions")->json('data.data'))->firstWhere('id', $id);
        $this->assertNull(collect($row['answers'])->firstWhere('question_label', 'اسم الشركة')['value']);

        $export = $this->as($this->owner)->postJson("/api/operations/forms/{$form->id}/exports", ['format' => 'csv', 'columns' => ['id', 'q_'.$this->id($form, 'اسم الشركة')]])->assertCreated();
        $csv = $this->as($this->owner)->get("/api/operations/forms/{$form->id}/exports/".$export->json('data.id').'/download')->assertOk()->streamedContent();
        $this->assertSame("\xEF\xBB\xBF\"رقم الرد\",\"اسم الشركة\"\n{$id},\n", str_replace("\r\n", "\n", $csv));
    }
}
