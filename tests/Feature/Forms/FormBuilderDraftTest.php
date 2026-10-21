<?php

namespace Tests\Feature\Forms;

use App\Models\ActivityLog;
use App\Models\DynamicForm;
use App\Models\FormQuestion;
use App\Models\FormVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesForms;
use Tests\TestCase;

/**
 * Saving the draft from the builder: the revision that detects two editors, the history that does not drown in autosaves,
 * and who may do what.
 */
class FormBuilderDraftTest extends TestCase
{
    use MakesForms;
    use RefreshDatabase;

    private $owner;

    private $form;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->makeUser('operations_manager');
        $id = $this->as($this->owner)->postJson('/api/operations/forms', [
            'title' => 'مسودة', 'questions' => [['type' => 'text', 'label' => 'الاسم']],
        ])->assertCreated()->json('data.id');
        $this->form = DynamicForm::findOrFail($id);
    }

    private function as($user)
    {
        return $this->actingAs($user, 'sanctum');
    }

    private function save(array $payload, $user = null)
    {
        return $this->as($user ?? $this->owner)->putJson("/api/operations/forms/{$this->form->id}", $payload);
    }

    private function revision(): int
    {
        return (int) $this->form->fresh()->draft_revision;
    }

    private function questionPayload(string $label = 'الاسم', array $extra = []): array
    {
        return [array_merge(['id' => $this->form->questions()->first()->id, 'type' => 'text', 'label' => $label], $extra)];
    }

    // ── Order ────────────────────────────────────────────────────────────────

    public function test_a_block_added_in_the_middle_is_saved_in_the_middle(): void
    {
        $first = $this->form->questions()->first();
        $this->save(['questions' => [
            ['id' => $first->id, 'type' => 'text', 'label' => 'الاسم'],
            ['type' => 'email', 'label' => 'ثاني'],
            ['type' => 'url', 'label' => 'ثالث'],
        ]])->assertOk();
        $ids = $this->form->fresh()->questions()->pluck('id', 'label');

        // New blocks in the middle, between and around stored ones, in several shapes.
        $res = $this->save(['questions' => [
            ['type' => 'number', 'label' => 'جديد أول'],
            ['id' => $ids['الاسم'], 'type' => 'text', 'label' => 'الاسم'],
            ['type' => 'date', 'label' => 'جديد وسط'],
            ['id' => $ids['ثاني'], 'type' => 'email', 'label' => 'ثاني'],
            ['id' => $ids['ثالث'], 'type' => 'url', 'label' => 'ثالث'],
            ['type' => 'time', 'label' => 'جديد آخر'],
        ]])->assertOk();

        $this->assertSame(['جديد أول', 'الاسم', 'جديد وسط', 'ثاني', 'ثالث', 'جديد آخر'], array_column($res->json('data.questions'), 'label'));
        $this->assertSame([1, 2, 3, 4, 5, 6], array_column($res->json('data.questions'), 'sort_order'));
        $this->assertSame(['جديد أول', 'الاسم', 'جديد وسط', 'ثاني', 'ثالث', 'جديد آخر'], $this->form->fresh()->questions()->pluck('label')->all());
    }

    public function test_creating_a_form_keeps_the_order_of_blocks_too(): void
    {
        $res = $this->as($this->owner)->postJson('/api/operations/forms', ['title' => 'x', 'questions' => [
            ['type' => 'text', 'label' => 'واحد'], ['type' => 'email', 'label' => 'اثنان'], ['type' => 'url', 'label' => 'ثلاثة'],
        ]])->assertCreated();
        $this->assertSame(['واحد', 'اثنان', 'ثلاثة'], array_column($res->json('data.questions'), 'label'));
    }

    // ── Revision ─────────────────────────────────────────────────────────────

    public function test_a_new_form_starts_at_a_known_revision_and_every_real_change_moves_it(): void
    {
        $r0 = $this->revision();
        $this->assertSame($r0, $this->as($this->owner)->getJson("/api/operations/forms/{$this->form->id}")->json('data.draft_revision'));

        $this->save(['questions' => $this->questionPayload('الاسم الجديد')])->assertOk()->assertJsonPath('data.draft_revision', $r0 + 1);
        $this->save(['title' => 'عنوان آخر'])->assertOk()->assertJsonPath('data.draft_revision', $r0 + 2);
        $this->save(['questions' => array_merge($this->questionPayload('الاسم الجديد'), [['type' => 'email', 'label' => 'البريد']])])->assertOk()->assertJsonPath('data.draft_revision', $r0 + 3);
    }

    public function test_saving_what_is_already_saved_changes_nothing_and_leaves_no_trace(): void
    {
        $res = $this->save(['questions' => $this->questionPayload()])->assertOk();
        $r = $this->revision();
        $edited = $this->form->fresh()->updated_at;
        $log = ActivityLog::count();

        $again = $this->save(['questions' => $this->questionPayload()])->assertOk();

        $this->assertSame($r, $this->revision());
        $this->assertSame($r, $again->json('data.draft_revision'));
        $this->assertSame($log, ActivityLog::count(), 'no history for a save that changed nothing');
        $this->assertEquals($edited, $this->form->fresh()->updated_at);
    }

    // ── Two editors ──────────────────────────────────────────────────────────

    public function test_a_save_from_a_stale_revision_is_refused_with_the_current_draft(): void
    {
        $editor = $this->makeUser('operations_manager');
        $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/collaborators", ['user_id' => $editor->id, 'access_level' => 'editor'])->assertCreated();
        $loaded = $this->revision();

        // The collaborator saves first.
        $this->save(['base_revision' => $loaded, 'questions' => $this->questionPayload('تعديل الزميل')], $editor)->assertOk();

        // The owner, still on the revision they opened, tries to save.
        $res = $this->save(['base_revision' => $loaded, 'questions' => $this->questionPayload('تعديلي أنا')])->assertStatus(409);

        $res->assertJsonPath('code', 'draft_conflict')->assertJsonPath('success', false);
        $this->assertSame($loaded + 1, $res->json('data.draft_revision'), 'the answer carries the revision to continue from');
        $this->assertSame('تعديل الزميل', $res->json('data.questions.0.label'), 'and the draft as it is now, so nothing has to be guessed');
        $this->assertSame('تعديل الزميل', FormQuestion::first()->label, 'nothing of the stale save was written');
        $this->assertSame($loaded + 1, $this->revision());
    }

    public function test_the_editor_can_overwrite_on_purpose_and_it_is_recorded(): void
    {
        $editor = $this->makeUser('operations_manager');
        $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/collaborators", ['user_id' => $editor->id, 'access_level' => 'editor'])->assertCreated();
        $loaded = $this->revision();
        $this->save(['base_revision' => $loaded, 'questions' => $this->questionPayload('تعديل الزميل')], $editor)->assertOk();

        $res = $this->save(['base_revision' => $loaded, 'force' => true, 'questions' => $this->questionPayload('فوقه عن قصد')])->assertOk();

        $this->assertSame('فوقه عن قصد', FormQuestion::first()->label);
        $this->assertSame($loaded + 2, $res->json('data.draft_revision'));
        $entry = ActivityLog::where('entity_id', $this->form->id)->where('action', 'form.questions_changed')->latest('id')->first();
        $this->assertTrue((bool) ($entry->new_values['forced'] ?? false), 'an overwrite is visible in the history');
    }

    public function test_a_client_that_sends_no_revision_keeps_the_old_behaviour(): void
    {
        $this->save(['questions' => $this->questionPayload('أ')])->assertOk();
        $this->save(['questions' => $this->questionPayload('ب')])->assertOk();
        $this->assertSame('ب', FormQuestion::first()->label);
    }

    public function test_a_conflict_never_touches_the_published_version(): void
    {
        $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/publish")->assertOk();
        $loaded = $this->revision();
        $version = FormVersion::firstOrFail();
        $hash = $version->schema_hash;

        $this->save(['base_revision' => $loaded, 'questions' => $this->questionPayload('أول')])->assertOk();
        $this->save(['base_revision' => $loaded, 'questions' => $this->questionPayload('ثان')])->assertStatus(409);

        $this->assertSame($hash, $version->fresh()->schema_hash);
        $this->assertSame('الاسم', $version->fresh()->schema['questions'][0]['label']);
        $this->assertSame(1, FormVersion::count());
    }

    public function test_restoring_a_version_moves_the_revision_so_an_open_builder_is_told(): void
    {
        $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/publish")->assertOk();
        $v1 = FormVersion::firstOrFail();
        $this->save(['questions' => $this->questionPayload('بعد النشر')])->assertOk();
        $loaded = $this->revision();

        $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/versions/{$v1->id}/restore")->assertOk();

        $this->assertSame($loaded + 1, $this->revision());
        $this->save(['base_revision' => $loaded, 'questions' => $this->questionPayload('من نافذة قديمة')])->assertStatus(409);
    }

    public function test_the_single_question_endpoints_keep_the_revision_honest_too(): void
    {
        $r = $this->revision();
        $id = $this->as($this->owner)->postJson("/api/admin/forms/{$this->form->id}/questions", ['type' => 'star_rating', 'label' => 'التقييم', 'config' => ['scale' => ['max' => 7]]])
            ->assertCreated()->assertJsonPath('data.config.scale.max', 7)->json('data.id');
        $this->assertSame($r + 1, $this->revision());

        $this->as($this->owner)->putJson("/api/admin/forms/{$this->form->id}/questions/$id", ['help_text' => 'اختر نجمة'])->assertOk()->assertJsonPath('data.help_text', 'اختر نجمة')->assertJsonPath('data.config.scale.max', 7);
        $this->assertSame($r + 2, $this->revision());

        $this->as($this->owner)->deleteJson("/api/admin/forms/{$this->form->id}/questions/$id")->assertOk();
        $this->assertSame($r + 3, $this->revision());
    }

    // ── History ──────────────────────────────────────────────────────────────

    public function test_a_burst_of_autosaves_becomes_one_history_line_that_says_how_many_saves_it_covers(): void
    {
        foreach (['س', 'سؤ', 'سؤا', 'سؤال', 'سؤال ١'] as $label) {
            $this->save(['questions' => $this->questionPayload($label)])->assertOk();
        }

        $lines = ActivityLog::where('entity_id', $this->form->id)->where('action', 'form.questions_changed')->get();
        $this->assertCount(1, $lines);
        $this->assertSame(5, $lines[0]->new_values['saves']);
        $this->assertContains('سؤال ١', $lines[0]->new_values['edited']);

        $history = $this->as($this->owner)->getJson("/api/operations/forms/{$this->form->id}/activity")->assertOk()->json('data.data');
        $this->assertCount(2, $history, 'creation and the one folded edit');
    }

    public function test_history_does_not_fold_across_a_publication_another_person_or_a_different_change(): void
    {
        $editor = $this->makeUser('operations_manager');
        $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/collaborators", ['user_id' => $editor->id, 'access_level' => 'editor'])->assertCreated();

        $this->save(['questions' => $this->questionPayload('أ')])->assertOk();
        $this->save(['questions' => $this->questionPayload('ب')], $editor)->assertOk();          // another person
        $this->save(['questions' => $this->questionPayload('ج')])->assertOk();                   // back to the first
        $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/publish")->assertOk();
        $this->save(['questions' => $this->questionPayload('د')])->assertOk();                   // after a publication

        $this->assertSame(4, ActivityLog::where('entity_id', $this->form->id)->where('action', 'form.questions_changed')->count());
    }

    // ── Who may do what ──────────────────────────────────────────────────────

    public function test_a_viewer_can_read_and_preview_but_not_save_and_an_editor_cannot_read_responses(): void
    {
        $viewer = $this->makeUser('operations_manager');
        $editor = $this->makeUser('operations_manager');
        $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/collaborators", ['user_id' => $viewer->id, 'access_level' => 'viewer'])->assertCreated();
        $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/collaborators", ['user_id' => $editor->id, 'access_level' => 'editor', 'can_view_responses' => false])->assertCreated();

        $this->as($viewer)->getJson("/api/operations/forms/{$this->form->id}")->assertOk()->assertJsonPath('data.abilities.edit', false);
        $this->as($viewer)->getJson("/api/operations/forms/{$this->form->id}/preview")->assertOk();
        $this->save(['questions' => $this->questionPayload('تسلل')], $viewer)->assertForbidden();
        $this->as($viewer)->postJson("/api/admin/forms/{$this->form->id}/questions", ['type' => 'text', 'label' => 'تسلل'])->assertForbidden();
        $this->as($viewer)->deleteJson("/api/admin/forms/{$this->form->id}/questions/".$this->form->questions()->first()->id)->assertForbidden();
        $this->assertSame('الاسم', FormQuestion::first()->label);

        $this->save(['questions' => $this->questionPayload('تعديل مسموح')], $editor)->assertOk();
        $this->as($editor)->getJson("/api/operations/forms/{$this->form->id}/submissions")->assertForbidden();
        $this->as($editor)->postJson("/api/operations/forms/{$this->form->id}/collaborators", ['user_id' => $viewer->id, 'access_level' => 'editor'])->assertForbidden();
    }

    public function test_a_stranger_cannot_save_or_even_see_the_draft_and_the_conflict_answer_does_not_leak(): void
    {
        $stranger = $this->makeUser('operations_manager');
        $this->save(['base_revision' => 0, 'questions' => $this->questionPayload('تسلل')], $stranger)->assertForbidden();
        $this->as($stranger)->getJson("/api/operations/forms/{$this->form->id}")->assertForbidden();
    }
}
