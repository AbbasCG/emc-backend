<?php

namespace Tests\Feature\Forms;

use App\Models\DynamicForm;
use App\Models\FormVersion;
use App\Services\Forms\FormSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesForms;
use Tests\TestCase;

/**
 * The migration that gives every existing form an owner and a version. It is run here over rows written exactly as the
 * Phase 0 code wrote them (no owner, no version, no version reference on the responses) and must change nothing a person
 * could notice: same links, same questions, same answers, same access.
 */
class FormsPhaseOneBackfillTest extends TestCase
{
    use MakesForms;
    use RefreshDatabase;

    private array $ids = [];

    private $creator;

    private function backfill(): void
    {
        $migration = require base_path('database/migrations/2026_10_18_100003_backfill_form_ownership_and_versions.php');
        $migration->up();
    }

    private function legacyForm(string $slug, string $status, bool $deleted = false): int
    {
        return DB::table('dynamic_forms')->insertGetId([
            'title' => "نموذج $slug", 'slug' => $slug, 'description' => 'وصف', 'type' => 'general', 'status' => $status, 'created_by' => $this->creator->id,
            'created_at' => '2026-06-01 08:00:00', 'updated_at' => '2026-06-02 08:00:00', 'deleted_at' => $deleted ? '2026-07-01 08:00:00' : null,
        ]);
    }

    private function legacyQuestion(int $formId, string $label, string $type, int $order, ?array $options = null, bool $deleted = false): int
    {
        return DB::table('form_questions')->insertGetId([
            'form_id' => $formId, 'label' => $label, 'type' => $type, 'options' => $options ? json_encode($options, JSON_UNESCAPED_UNICODE) : null,
            'is_required' => $order === 1, 'sort_order' => $order, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $deleted ? now() : null,
        ]);
    }

    private function legacyResponse(int $formId, array $answers): int
    {
        $id = DB::table('form_submissions')->insertGetId(['form_id' => $formId, 'status' => 'new', 'submitted_at' => '2026-06-10 10:00:00', 'created_at' => now(), 'updated_at' => now()]);
        foreach ($answers as $questionId => $value) {
            DB::table('form_answers')->insert(['submission_id' => $id, 'question_id' => $questionId, 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $id;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->creator = $this->makeUser('operations_manager');

        $a = $this->ids['active'] = $this->legacyForm('legacy-active', 'active');
        $qa1 = $this->legacyQuestion($a, 'الاسم', 'text', 1);
        $qa2 = $this->legacyQuestion($a, 'اللون', 'select', 2, ['أحمر', 'أزرق']);
        $this->legacyQuestion($a, 'سؤال محذوف', 'text', 3, null, deleted: true);
        $this->legacyResponse($a, [$qa1 => 'سارة', $qa2 => 'أحمر']);
        $this->legacyResponse($a, [$qa1 => 'ليلى', $qa2 => 'أزرق']);

        $this->ids['draft'] = $this->legacyForm('legacy-draft', 'draft');
        $this->legacyQuestion($this->ids['draft'], 'س', 'text', 1);

        $c = $this->ids['closed'] = $this->legacyForm('legacy-closed', 'closed');
        $qc = $this->legacyQuestion($c, 'س', 'text', 1);
        $this->legacyResponse($c, [$qc => 'ج']);

        $this->ids['archived'] = $this->legacyForm('legacy-archived', 'archived');
        $this->legacyQuestion($this->ids['archived'], 'س', 'text', 1);

        $e = $this->ids['draft_with_responses'] = $this->legacyForm('legacy-draft-answered', 'draft');
        $qe = $this->legacyQuestion($e, 'س', 'text', 1);
        $this->legacyResponse($e, [$qe => 'قديمة']);

        $f = $this->ids['deleted'] = $this->legacyForm('legacy-deleted', 'active', deleted: true);
        $qf = $this->legacyQuestion($f, 'س', 'text', 1);
        $this->legacyResponse($f, [$qf => 'محذوفة']);
    }

    /** Plain arrays (not objects), so two snapshots can be compared by value. */
    private function dataChecksum(): array
    {
        return json_decode((string) json_encode([
            'forms'     => DB::table('dynamic_forms')->orderBy('id')->get(['id', 'title', 'slug', 'description', 'type', 'status', 'created_by', 'created_at', 'deleted_at']),
            'questions' => DB::table('form_questions')->orderBy('id')->get(),
            'answers'   => DB::table('form_answers')->orderBy('id')->get(),
            'responses' => DB::table('form_submissions')->orderBy('id')->get(['id', 'form_id', 'user_id', 'status', 'submitted_at', 'metadata']),
        ]), true);
    }

    public function test_every_form_keeps_its_creator_as_owner_including_a_deleted_one(): void
    {
        $this->backfill();

        foreach ($this->ids as $id) {
            $row = DB::table('dynamic_forms')->find($id);
            $this->assertSame($this->creator->id, (int) $row->owner_id);
            $this->assertSame($this->creator->id, (int) $row->created_by);
            $this->assertSame('organization', $row->visibility, 'access stays exactly as it was');
            $this->assertSame('form_viewers', $row->responses_visibility);
        }
    }

    public function test_an_owner_that_is_already_set_is_never_replaced(): void
    {
        $other = $this->makeUser('community_manager');
        DB::table('dynamic_forms')->where('id', $this->ids['draft'])->update(['owner_id' => $other->id]);

        $this->backfill();

        $this->assertSame($other->id, (int) DB::table('dynamic_forms')->find($this->ids['draft'])->owner_id);
    }

    public function test_published_forms_and_forms_with_responses_get_one_legacy_version_and_empty_drafts_get_none(): void
    {
        $this->backfill();

        foreach (['active', 'closed', 'archived', 'draft_with_responses', 'deleted'] as $key) {
            $versions = FormVersion::where('form_id', $this->ids[$key])->get();
            $this->assertCount(1, $versions, $key);
            $this->assertSame(1, $versions[0]->version_number);
            $this->assertSame('legacy', $versions[0]->origin);
            $this->assertNull($versions[0]->created_by, 'who published it was never recorded');
        }
        $this->assertSame(0, FormVersion::where('form_id', $this->ids['draft'])->count());
    }

    public function test_the_published_version_is_set_only_for_forms_that_were_published(): void
    {
        $this->backfill();

        foreach (['active', 'closed', 'archived', 'deleted'] as $key) {
            $row = DB::table('dynamic_forms')->find($this->ids[$key]);
            $this->assertNotNull($row->published_version_id, $key);
            $this->assertSame('2026-06-01 08:00:00', $row->published_at, 'the best fact available: when the form was created');
            $this->assertNull($row->published_by, 'unknown, and not invented');
        }
        foreach (['draft', 'draft_with_responses'] as $key) {
            $this->assertNull(DB::table('dynamic_forms')->find($this->ids[$key])->published_version_id, $key);
        }
    }

    public function test_the_legacy_version_holds_exactly_the_live_questions_and_not_the_removed_one(): void
    {
        $this->backfill();

        $schema = FormVersion::where('form_id', $this->ids['active'])->firstOrFail()->schema;

        $this->assertSame(['الاسم', 'اللون'], array_column($schema['questions'], 'label'));
        $this->assertSame(['أحمر', 'أزرق'], $schema['questions'][1]['options']);
        $this->assertTrue($schema['questions'][0]['required']);
        $this->assertSame('نموذج legacy-active', $schema['form']['title']);
    }

    public function test_every_existing_response_is_linked_to_its_forms_version_and_nothing_else_about_it_changes(): void
    {
        $before = $this->dataChecksum();

        $this->backfill();

        $this->assertSame($before, $this->dataChecksum(), 'forms, questions, answers and responses are byte for byte what they were');
        foreach (['active', 'closed', 'draft_with_responses', 'deleted'] as $key) {
            $versionId = FormVersion::where('form_id', $this->ids[$key])->value('id');
            $this->assertSame(0, DB::table('form_submissions')->where('form_id', $this->ids[$key])->where(fn ($q) => $q->whereNull('form_version_id')->orWhere('form_version_id', '!=', $versionId))->count(), $key);
        }
    }

    public function test_the_migration_is_safe_to_run_twice(): void
    {
        $this->backfill();
        $first = [DB::table('form_versions')->count(), DB::table('dynamic_forms')->pluck('published_version_id', 'id')->all()];

        $this->backfill();

        $this->assertSame($first, [DB::table('form_versions')->count(), DB::table('dynamic_forms')->pluck('published_version_id', 'id')->all()]);
    }

    public function test_the_public_link_serves_the_same_form_after_the_backfill_and_new_answers_use_the_version(): void
    {
        $before = $this->getJson('/api/forms/legacy-active')->assertOk()->json('data.questions');
        $this->backfill();

        $after = $this->getJson('/api/forms/legacy-active')->assertOk();
        $this->assertSame(array_column($before, 'label'), array_column($after->json('data.questions'), 'label'));
        $this->assertSame(array_column($before, 'options'), array_column($after->json('data.questions'), 'options'));
        $after->assertJsonPath('data.version', 1);

        $name = $after->json('data.questions.0.id');
        $color = $after->json('data.questions.1.id');
        $this->postJson('/api/forms/legacy-active/submit', ['answers' => [$name => 'جديد', $color => 'أحمر']])->assertCreated();
        $this->assertSame(FormVersion::where('form_id', $this->ids['active'])->value('id'), (int) DB::table('form_submissions')->where('form_id', $this->ids['active'])->max('form_version_id'));
    }

    public function test_after_the_backfill_the_draft_equals_the_published_version_so_nothing_looks_changed(): void
    {
        $this->backfill();
        $admin = $this->makeUser('admin');

        $this->actingAs($admin, 'sanctum')->getJson("/api/operations/forms/{$this->ids['active']}")->assertOk()
            ->assertJsonPath('data.has_unpublished_changes', false)->assertJsonPath('data.current_version', 1);

        $form = DynamicForm::findOrFail($this->ids['active']);
        $this->assertSame(FormVersion::where('form_id', $form->id)->value('schema_hash'), app(FormSnapshotService::class)->draftHash($form));
    }

    public function test_old_responses_read_back_through_the_new_screens_and_old_slugs_are_untouched(): void
    {
        $this->backfill();
        $admin = $this->makeUser('admin');

        $rows = $this->actingAs($admin, 'sanctum')->getJson("/api/operations/forms/{$this->ids['active']}/submissions")->assertOk()->json('data.data');

        $this->assertCount(2, $rows);
        $this->assertSame(['الاسم', 'اللون'], array_column($rows[0]['answers'], 'question_label'));
        $this->assertSame(1, $rows[0]['form_version']);
        foreach (['legacy-active', 'legacy-closed', 'legacy-archived', 'legacy-draft'] as $slug) {
            $this->assertSame(1, DB::table('dynamic_forms')->where('slug', $slug)->count());
        }
    }

    public function test_a_legacy_form_can_be_edited_and_republished_and_its_first_version_survives(): void
    {
        $this->backfill();
        $admin = $this->makeUser('admin');
        $name = DB::table('form_questions')->where('form_id', $this->ids['active'])->whereNull('deleted_at')->orderBy('sort_order')->first();

        $this->actingAs($admin, 'sanctum')->putJson("/api/operations/forms/{$this->ids['active']}", ['title' => 'عنوان جديد'])->assertOk()->assertJsonPath('data.has_unpublished_changes', true);
        $this->actingAs($admin, 'sanctum')->postJson("/api/operations/forms/{$this->ids['active']}/publish")->assertOk()->assertJsonPath('data.current_version', 2);

        $this->assertSame('نموذج legacy-active', FormVersion::where('form_id', $this->ids['active'])->where('version_number', 1)->first()->schema['form']['title']);
        $this->assertNotNull($name);
    }
}
