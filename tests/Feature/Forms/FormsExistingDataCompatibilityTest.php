<?php

namespace Tests\Feature\Forms;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\MakesForms;
use Tests\TestCase;

/**
 * Rows written by the old code (raw inserts, exactly the columns the 2026-05-14 migrations created) must keep
 * working: the public link, the admin screens, the answers' shapes and the new soft-delete columns all have to sit
 * on top of them without any data change.
 */
class FormsExistingDataCompatibilityTest extends TestCase
{
    use MakesForms;
    use RefreshDatabase;

    private int $formId;

    private array $q = [];

    private int $submissionId;

    protected function setUp(): void
    {
        parent::setUp();

        $creator = $this->makeUser('admin');
        $this->formId = DB::table('dynamic_forms')->insertGetId([
            'title' => 'نموذج قديم', 'slug' => 'old-form-abc12', 'description' => 'منذ شهور', 'type' => 'suggestion', 'department_id' => null,
            'status' => 'active', 'created_by' => $creator->id, 'created_at' => '2026-05-20 10:00:00', 'updated_at' => '2026-05-20 10:00:00',
        ]);
        foreach ([
            'name'  => ['الاسم', 'text', null, 1, 1],
            'pick'  => ['اختر', 'select', json_encode(['أ', 'ب'], JSON_UNESCAPED_UNICODE), 0, 2],
            'multi' => ['متعدد', 'checkbox', json_encode(['x', 'y']), 0, 3],
            'file'  => ['ملف', 'file', null, 0, 4],
        ] as $key => [$label, $type, $options, $required, $order]) {
            $this->q[$key] = DB::table('form_questions')->insertGetId([
                'form_id' => $this->formId, 'label' => $label, 'type' => $type, 'options' => $options, 'is_required' => $required, 'sort_order' => $order,
                'created_at' => '2026-05-20 10:00:00', 'updated_at' => '2026-05-20 10:00:00',
            ]);
        }

        $this->submissionId = DB::table('form_submissions')->insertGetId([
            'form_id' => $this->formId, 'user_id' => null, 'status' => 'new', 'submitted_at' => '2026-06-01 09:30:00', 'metadata' => null,
            'created_at' => '2026-06-01 09:30:00', 'updated_at' => '2026-06-01 09:30:00',
        ]);
        foreach ([
            [$this->q['name'], 'ليلى', null],
            [$this->q['pick'], 'ب', null],
            // the old code stored lists with PHP's default json_encode, i.e. unicode escaped
            [$this->q['multi'], json_encode(['x', 'y']), null],
            [$this->q['file'], null, 'form-uploads/Zk39dLegacyName'],
        ] as [$qid, $value, $file]) {
            DB::table('form_answers')->insert(['submission_id' => $this->submissionId, 'question_id' => $qid, 'value' => $value, 'file_path' => $file, 'created_at' => '2026-06-01 09:30:00', 'updated_at' => '2026-06-01 09:30:00']);
        }
    }

    public function test_the_new_columns_are_nullable_and_empty_for_every_old_row(): void
    {
        foreach (['dynamic_forms', 'form_questions'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'deleted_at'));
            $this->assertSame(0, DB::table($table)->whereNotNull('deleted_at')->count());
        }
    }

    public function test_the_existing_public_link_still_serves_the_old_form(): void
    {
        $res = $this->getJson('/api/forms/old-form-abc12')->assertOk();

        $res->assertJsonPath('data.title', 'نموذج قديم')->assertJsonCount(4, 'data.questions')->assertJsonPath('data.questions.0.required', true)
            ->assertJsonPath('data.questions.1.options', ['أ', 'ب'])->assertJsonPath('data.form_type', 'suggestion');
    }

    public function test_the_old_form_accepts_a_new_submission(): void
    {
        $this->postJson('/api/forms/old-form-abc12/submit', ['answers' => [$this->q['name'] => 'عمر', $this->q['pick'] => 'أ', $this->q['multi'] => ['y']]])->assertCreated();

        $this->assertSame(2, DB::table('form_submissions')->where('form_id', $this->formId)->count());
    }

    public function test_the_old_submission_reads_back_through_the_new_response_list(): void
    {
        $res = $this->actingAs($this->makeUser('admin'), 'sanctum')->getJson("/api/operations/forms/{$this->formId}/submissions")->assertOk();

        $row = $res->json('data.data.0');
        $this->assertSame($this->submissionId, $row['id']);
        $this->assertSame('زائر', $row['submitter_label']);
        $this->assertStringContainsString('الاسم: ليلى', $row['answers_preview']);
        $byQuestion = collect($row['answers'])->keyBy('question_id');
        $this->assertSame('ليلى', $byQuestion[$this->q['name']]['value']);
        $this->assertSame(['x', 'y'], $byQuestion[$this->q['multi']]['value'], 'a list stored the old way decodes to a list');
        $this->assertTrue($byQuestion[$this->q['file']]['has_file']);
        $this->assertNull($byQuestion[$this->q['file']]['file_name']);
    }

    public function test_the_old_form_opens_and_saves_in_the_editor_without_losing_anything(): void
    {
        $admin = $this->makeUser('admin');
        $detail = $this->actingAs($admin, 'sanctum')->getJson("/api/operations/forms/{$this->formId}")->assertOk();
        $questions = array_map(fn ($q) => ['id' => $q['id'], 'label' => $q['label'], 'type' => $q['type'], 'options' => $q['options'], 'required' => $q['required']], $detail->json('data.questions'));

        $this->actingAs($admin, 'sanctum')->putJson("/api/operations/forms/{$this->formId}", ['title' => 'نموذج قديم', 'slug' => 'old-form-abc12', 'form_type' => 'suggestion', 'questions' => $questions])->assertOk();

        $this->assertSame(4, DB::table('form_questions')->where('form_id', $this->formId)->whereNull('deleted_at')->count());
        $this->assertSame(4, DB::table('form_answers')->where('submission_id', $this->submissionId)->count());
        $this->assertEqualsCanonicalizing(array_values($this->q), DB::table('form_questions')->where('form_id', $this->formId)->pluck('id')->all(), 'the same question rows, so the old answers still point at them');
    }
}
