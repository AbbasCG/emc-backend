<?php

namespace Tests\Feature\Forms;

use App\Models\ActivityLog;
use App\Models\DynamicForm;
use App\Models\FormExport;
use App\Models\FormShareLink;
use App\Models\FormSubmission;
use App\Models\User;
use App\Services\Forms\Export\SpreadsheetSafe;
use App\Services\Forms\FormExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\MakesFormResponses;
use Tests\Concerns\MakesForms;
use Tests\TestCase;

/**
 * Exporting responses to CSV, XLSX and PDF. The rule under test: whoever may READ a set of responses may export exactly that set,
 * and nobody else may export anything: there is no separate export permission.
 */
class FormResponseExportTest extends TestCase
{
    use MakesFormResponses;
    use MakesForms;
    use RefreshDatabase;

    private $deptA;

    private $deptB;

    private User $owner;

    private DynamicForm $form;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('private');
        $this->deptA = $this->makeDepartment('قسم أ');
        $this->deptB = $this->makeDepartment('قسم ب');
        $this->owner = $this->makeUser('operations_manager', $this->deptA);
        $this->form = $this->createPublishedForm($this->owner, [
            ['type' => 'text', 'label' => 'الاسم', 'is_required' => true],
            ['type' => 'select', 'label' => 'المدينة', 'options' => ['الرياض', 'جدة']],
            ['type' => 'yes_no', 'label' => 'هل حضرت؟'],
            ['type' => 'star_rating', 'label' => 'التقييم'],
            ['type' => 'checkbox', 'label' => 'الهوايات', 'options' => ['قراءة', 'سفر']],
            ['type' => 'file', 'label' => 'السيرة'],
        ], ['department_id' => $this->deptA->id]);
    }

    private function as(User $user)
    {
        return $this->actingAs($user, 'sanctum');
    }

    private function export(string $format = 'csv', array $filters = [], ?array $columns = null, ?User $user = null, ?DynamicForm $form = null)
    {
        $form ??= $this->form;

        return $this->as($user ?? $this->owner)->postJson("/api/operations/forms/{$form->id}/exports", ['format' => $format, 'filters' => $filters] + ($columns !== null ? ['columns' => $columns] : []));
    }

    private function download($created, ?User $user = null, ?DynamicForm $form = null)
    {
        return $this->as($user ?? $this->owner)->get('/api/operations/forms/'.($form ?? $this->form)->id.'/exports/'.$created->json('data.id').'/download');
    }

    private function body($response): string
    {
        $response->assertOk();

        return $response->baseResponse instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse
            ? (string) file_get_contents($response->baseResponse->getFile()->getPathname())
            : $response->streamedContent();
    }

    /** @return list<list<string>> rows of a CSV body, header first */
    private function csv(string $body): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body, 'UTF-8 byte-order mark so Excel reads Arabic');
        $h = fopen('php://memory', 'r+');
        fwrite($h, substr($body, 3));
        rewind($h);
        $rows = [];
        while (($r = fgetcsv($h, 0, ',', '"', '\\')) !== false) {
            $rows[] = $r;
        }

        return $rows;
    }

    /** @return list<list<string>> */
    private function exportedCsv(array $filters = [], ?array $columns = null, ?User $user = null): array
    {
        $created = $this->export('csv', $filters, $columns, $user)->assertCreated();

        return $this->csv($this->body($this->download($created, $user)));
    }

    private function share($user, string $level, bool $responses): void
    {
        $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/collaborators", ['user_id' => $user->id, 'access_level' => $level, 'can_view_responses' => $responses])->assertCreated();
    }

    /** Many responses at once, without going through the respondent flow (the shape is what the exports read). */
    private function bulkResponses(int $count, ?DynamicForm $form = null): void
    {
        $form ??= $this->form->refresh();
        $version = $form->publishedVersion;
        $name = $this->questionId($form, 'الاسم');
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = ['form_id' => $form->id, 'form_version_id' => $version->id, 'status' => 'new', 'submitted_at' => now()->subSeconds($count - $i), 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('form_submissions')->insert($chunk);
        }
        $ids = DB::table('form_submissions')->where('form_id', $form->id)->orderByDesc('id')->limit($count)->pluck('id');
        $answers = $ids->map(fn ($id) => ['submission_id' => $id, 'question_id' => $name, 'value' => "مجيب $id", 'created_at' => now(), 'updated_at' => now()])->all();
        foreach (array_chunk($answers, 500) as $chunk) {
            DB::table('form_answers')->insert($chunk);
        }
    }

    // ── Authorization: the same as reading the responses ─────────────────────

    public function test_whoever_may_read_a_set_of_responses_may_export_exactly_that_set_and_nobody_else(): void
    {
        $this->respond($this->form, ['الاسم' => 'سارة']);
        $people = [
            'owner'                 => $this->owner,
            'platform admin'        => $this->makeUser('admin'),
            'viewer with responses' => $this->makeUser('community_manager', $this->deptB),
            'viewer without'        => $this->makeUser('community_manager', $this->deptB),
            'editor with responses' => $this->makeUser('operations_manager', $this->deptB),
            'editor without'        => $this->makeUser('operations_manager', $this->deptB),
            'same department'       => $this->makeUser('operations_manager', $this->deptA),
            'other department'      => $this->makeUser('operations_manager', $this->deptB),
            'community same dept'   => $this->makeUser('community_manager', $this->deptA),
        ];
        $this->share($people['viewer with responses'], 'viewer', true);
        $this->share($people['viewer without'], 'viewer', false);
        $this->share($people['editor with responses'], 'editor', true);
        $this->share($people['editor without'], 'editor', false);

        $allowedEverywhere = [];
        foreach ([['private', 'restricted'], ['private', 'form_viewers'], ['organization', 'restricted'], ['organization', 'form_viewers']] as [$visibility, $responses]) {
            $this->as($this->owner)->putJson("/api/operations/forms/{$this->form->id}/settings", ['visibility' => $visibility, 'responses_visibility' => $responses])->assertOk();

            foreach ($people as $name => $person) {
                $canRead = $this->as($person)->getJson("/api/operations/forms/{$this->form->id}/submissions")->status();
                $canExport = $this->export('csv', [], null, $person)->status();

                $this->assertContains($canRead, [200, 403], "$name reads $visibility/$responses");
                $this->assertSame($canRead === 200, $canExport === 201, "$name: exporting must equal reading ($visibility / $responses): read=$canRead export=$canExport");
                if ($canRead === 403) {
                    $this->assertSame(403, $canExport, "$name: a refused export is a plain 403");
                }
                $allowedEverywhere["$visibility/$responses"][$name] = $canRead === 200;
            }
        }

        // The matrix is not vacuous: access really varies with the form's settings and with the person.
        $this->assertSame(['owner', 'platform admin', 'viewer with responses', 'editor with responses'], array_keys(array_filter($allowedEverywhere['private/restricted'])));
        $this->assertContains('same department', array_keys(array_filter($allowedEverywhere['organization/form_viewers'])));
        $this->assertNotContains('other department', array_keys(array_filter($allowedEverywhere['organization/form_viewers'])), 'no leak across departments');
    }

    public function test_a_refused_export_creates_no_record_no_file_and_no_history(): void
    {
        $this->respond($this->form, ['الاسم' => 'سارة']);
        $outsider = $this->makeUser('operations_manager', $this->deptB);
        $editor = $this->makeUser('operations_manager', $this->deptB);
        $this->share($editor, 'editor', false);

        foreach ([$outsider, $editor] as $person) {
            $this->export('csv', [], null, $person)->assertForbidden();
            $this->export('xlsx', [], null, $person)->assertForbidden();
            $this->export('pdf', [], null, $person)->assertForbidden();
        }
        $this->assertSame(0, FormExport::count());
        $this->assertSame([], Storage::disk('private')->allFiles());
        $this->assertSame(0, ActivityLog::where('action', 'form.responses_exported')->count());

        $this->app['auth']->forgetGuards();
        $this->postJson("/api/operations/forms/{$this->form->id}/exports", ['format' => 'csv'])->assertUnauthorized();
    }

    public function test_an_export_cannot_reach_another_forms_responses_by_any_parameter(): void
    {
        $other = $this->createPublishedForm($this->owner, [['type' => 'text', 'label' => 'س']]);
        $secret = $this->respond($other, ['س' => 'سري-في-نموذج-آخر']);
        $this->respond($this->form, ['الاسم' => 'علني']);

        $this->export('csv', ['search' => 'سري-في-نموذج-آخر'])->assertStatus(422);   // the search is inside this form: nothing matches
        $this->assertStringNotContainsString('سري-في-نموذج-آخر', json_encode($this->exportedCsv(), JSON_UNESCAPED_UNICODE));

        $this->export('csv', ['version' => $other->refresh()->published_version_id])->assertStatus(422)->assertJsonValidationErrors('version');
        $this->export('csv', ['search' => (string) $secret->id])->assertStatus(422);   // nothing matches: nothing to export
    }

    // ── Rows: exactly the filtered set ───────────────────────────────────────

    public function test_the_exported_rows_are_exactly_the_rows_the_table_shows_for_the_same_filters(): void
    {
        $reviewer = $this->makeUser('operations_manager', $this->deptA);
        $this->share($reviewer, 'editor', true);
        $link = FormShareLink::create(['form_id' => $this->form->id, 'label' => 'ملصق', 'token_hash' => hash('sha256', 'x'), 'token_hint' => 'xxxx', 'created_by' => $this->owner->id]);

        $statuses = ['new', 'in_review', 'needs_followup', 'completed', 'archived'];
        for ($i = 0; $i < 20; $i++) {
            $this->respond($this->form, ['الاسم' => "شخص $i"], [
                'status' => $statuses[$i % 5], 'submitted_at' => now()->subDays(30 - $i),
                'assigned_to' => $i % 3 === 0 ? $reviewer->id : null, 'share_link_id' => $i % 4 === 0 ? $link->id : null, 'corrected_at' => $i % 7 === 0 ? now() : null,
            ]);
        }
        $this->republish($this->owner, $this->form, fn ($qs) => array_map(fn ($q) => $q['label'] === 'الاسم' ? ['label' => 'الاسم الكامل'] + $q : $q, $qs));
        for ($i = 20; $i < 26; $i++) {
            $this->respond($this->form, ['الاسم الكامل' => "شخص $i"], ['status' => $statuses[$i % 5], 'submitted_at' => now()->subDays(26 - $i)]);
        }
        $v1 = (int) FormSubmission::orderBy('id')->first()->form_version_id;

        $filterSets = [
            [],
            ['status' => ['completed']],
            ['status' => ['new', 'archived']],
            ['assignee' => 'none'],
            ['assignee' => (string) $reviewer->id],
            ['version' => $v1],
            ['from' => now()->subDays(15)->format('Y-m-d'), 'to' => now()->subDays(8)->format('Y-m-d')],
            ['source' => 'link'],
            ['source' => 'public', 'status' => ['in_review']],
            ['corrected' => 1],
            ['search' => 'شخص 1'],
            ['search' => 'شخص 2', 'status' => ['needs_followup', 'completed'], 'from' => now()->subDays(20)->format('Y-m-d')],
        ];

        foreach ($filterSets as $n => $filters) {
            $tableIds = [];
            for ($page = 1, $last = 1; $page <= $last; $page++) {
                $res = $this->as($this->owner)->getJson("/api/operations/forms/{$this->form->id}/submissions?".http_build_query($filters + ['per_page' => 7, 'page' => $page]))->assertOk();
                $last = $res->json('data.meta.last_page');
                $tableIds = array_merge($tableIds, array_column($res->json('data.data'), 'id'));
            }

            $created = $this->export('csv', $filters, ['id']);
            if ($tableIds === []) {
                $created->assertStatus(422);

                continue;
            }
            $rows = $this->csv($this->body($this->download($created->assertCreated())));
            $exported = array_map('intval', array_column(array_slice($rows, 1), 0));

            $this->assertEqualsCanonicalizing($tableIds, $exported, "filter set #$n must export exactly the rows the table shows");
            $this->assertSame(count($tableIds), $created->json('data.row_count'));
        }
    }

    // ── Columns ──────────────────────────────────────────────────────────────

    public function test_only_the_selected_columns_are_exported_in_a_stable_order_and_unknown_ones_are_refused(): void
    {
        $this->respond($this->form, ['الاسم' => 'سارة', 'المدينة' => 'جدة', 'هل حضرت؟' => 'yes', 'التقييم' => '4']);
        $name = 'q_'.$this->questionId($this->form, 'الاسم');
        $city = 'q_'.$this->questionId($this->form, 'المدينة');

        $rows = $this->exportedCsv([], [$city, 'status', $name]);
        $this->assertSame([['الحالة', 'الاسم', 'المدينة'], ['جديد', 'سارة', 'جدة']], $rows, 'catalog order, not the order they were sent in');

        $this->export('csv', [], ['id', 'password'])->assertStatus(422)->assertJsonValidationErrors('columns');
        $this->export('csv', [], ['id', 'q_999999'])->assertStatus(422)->assertJsonValidationErrors('columns');
        $this->export('csv', [], [])->assertStatus(422)->assertJsonValidationErrors('columns');

        // By default the respondent's e-mail address is not included; it is only there when chosen.
        $default = $this->exportedCsv();
        $this->assertNotContains('البريد الإلكتروني للمُرسِل', $default[0]);
        $withEmail = $this->exportedCsv([], ['submitter_email']);
        $this->assertSame(['البريد الإلكتروني للمُرسِل'], $withEmail[0]);
    }

    public function test_answers_are_read_as_the_respondent_gave_them_not_as_raw_storage(): void
    {
        $this->respond($this->form, ['الاسم' => 'سارة', 'هل حضرت؟' => 'yes', 'التقييم' => '4', 'الهوايات' => ['قراءة', 'سفر']]);

        $rows = $this->exportedCsv([], ['q_'.$this->questionId($this->form, 'هل حضرت؟'), 'q_'.$this->questionId($this->form, 'التقييم'), 'q_'.$this->questionId($this->form, 'الهوايات')]);

        $this->assertSame([['هل حضرت؟', 'التقييم', 'الهوايات'], ['نعم', '4', 'قراءة، سفر']], $rows);
    }

    public function test_historical_versions_keep_their_own_labels_and_a_removed_question_keeps_its_column(): void
    {
        $old = $this->respond($this->form, ['الاسم' => 'قديم', 'المدينة' => 'جدة', 'الهوايات' => ['سفر']]);
        $cityId = $this->questionId($this->form, 'المدينة');
        $hobbiesId = $this->questionId($this->form, 'الهوايات');
        $v1 = (int) $old->form_version_id;
        $this->republish($this->owner, $this->form, fn ($qs) => array_values(array_filter(array_map(
            fn ($q) => $q['label'] === 'المدينة' ? ['label' => 'مدينة الإقامة'] + $q : $q, $qs
        ), fn ($q) => $q['label'] !== 'الهوايات')));
        $this->respond($this->form, ['الاسم' => 'جديد', 'مدينة الإقامة' => 'الرياض']);

        // One version: exactly that version's questions, with that version's labels.
        $v1Rows = $this->exportedCsv(['version' => $v1], ['id', "q_$cityId", "q_$hobbiesId"]);
        $this->assertSame(['رقم الرد', 'المدينة', 'الهوايات'], $v1Rows[0]);
        $this->assertSame(['جدة', 'سفر'], array_slice($v1Rows[1], 1));

        // All versions: a question appears once, under its newest label; the one a later version dropped is still there, marked.
        $all = $this->exportedCsv([], ['id', "q_$cityId", "q_$hobbiesId"]);
        $this->assertSame(['رقم الرد', 'مدينة الإقامة', 'الهوايات (سؤال محذوف)'], $all[0]);
        $byId = collect(array_slice($all, 1))->keyBy(0);
        $this->assertSame(['جدة', 'سفر'], array_slice($byId[(string) $old->id], 1), 'the old response keeps its own answers');
        $this->assertSame(['الرياض', ''], array_slice($byId->except((string) $old->id)->first(), 1));

        // The export's column catalogue for a version is that version's.
        $options = $this->as($this->owner)->getJson("/api/operations/forms/{$this->form->id}/response-options?version=$v1")->json('data.columns');
        $this->assertContains('المدينة', array_column($options, 'label'));
        $this->assertNotContains('مدينة الإقامة', array_column($options, 'label'));
    }

    public function test_internal_notes_history_and_corrections_are_never_part_of_an_export(): void
    {
        $s = $this->respond($this->form, ['الاسم' => 'سارة']);
        $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/submissions/{$s->id}/notes", ['body' => 'ملاحظة-داخلية-سرية'])->assertCreated();
        $this->as($this->owner)->putJson("/api/operations/forms/{$this->form->id}/submissions/{$s->id}/answers", ['corrections' => [$this->questionId($this->form, 'الاسم') => 'سارة-بعد-التصحيح'], 'reason' => 'سبب-التصحيح-السري'])->assertOk();

        foreach (['csv', 'xlsx'] as $format) {
            $created = $this->export($format, [], null)->assertCreated();
            $raw = $this->body($this->download($created));
            if ($format === 'xlsx') {
                $raw = $this->allXml($raw);
            }
            $this->assertStringContainsString('سارة-بعد-التصحيح', $raw, 'the corrected value is what is exported');
            $this->assertStringNotContainsString('ملاحظة-داخلية-سرية', $raw);
            $this->assertStringNotContainsString('سبب-التصحيح-السري', $raw);
            $this->assertStringNotContainsString('سارة'."\0", $raw);
        }
        $header = $this->exportedCsv(['corrected' => 1], ['id', 'corrected']);
        $this->assertSame('نعم', $header[1][1]);
        $labels = array_column($this->as($this->owner)->getJson("/api/operations/forms/{$this->form->id}/response-options")->json('data.columns'), 'label');
        foreach ($labels as $label) {
            $this->assertStringNotContainsString('ملاحظة', $label);
        }
    }

    // ── Spreadsheet safety ───────────────────────────────────────────────────

    public function test_text_from_respondents_can_never_become_a_formula_in_csv(): void
    {
        $evil = ['=1+1', '=HYPERLINK("http://evil.test","x")', '+cmd|\' /C calc\'!A0', '-2+3', '@SUM(A1:A9)', "\t=1+1", " =1+1", "\r=1", '＝１', '=cmd|"/c calc"!A1'];
        foreach ($evil as $i => $text) {
            $this->respond($this->form, ['الاسم' => $text]);
        }
        $this->respond($this->form, ['الاسم' => '-5']);
        $this->respond($this->form, ['الاسم' => '+966501234567']);
        $this->respond($this->form, ['الاسم' => 'عادي = عادي']);

        $rows = $this->exportedCsv([], ['q_'.$this->questionId($this->form, 'الاسم')]);
        $cells = array_column(array_slice($rows, 1), 0);

        foreach ($cells as $cell) {
            if (preg_match('/^[\s]*[=+\-@]/', $cell) === 1 && ! SpreadsheetSafe::isPlainNumber(ltrim($cell))) {
                $this->fail("A dangerous cell reached the file unprotected: $cell");
            }
            $this->assertNotContains($cell[0] ?? '', ["\t", "\r", "\n"], 'no cell begins with a control character a spreadsheet would act on');
        }
        $this->assertContains("'=1+1", $cells);
        $this->assertContains("'-2+3", $cells);
        $this->assertContains("'@SUM(A1:A9)", $cells);
        $this->assertContains('-5', $cells, 'a plain number is data, not a formula, and stays untouched');
        $this->assertContains('+966501234567', $cells);
        $this->assertContains('عادي = عادي', $cells);
    }

    public function test_text_from_respondents_can_never_become_a_formula_in_xlsx(): void
    {
        foreach (['=1+1', '+cmd|\' /C calc\'!A0', '-2+3', '@SUM(A1)', '=HYPERLINK("http://evil.test","x")'] as $text) {
            $this->respond($this->form, ['الاسم' => $text, 'التقييم' => '5']);
        }
        $created = $this->export('xlsx', [], null)->assertCreated();
        $bytes = $this->body($this->download($created));

        $sheetXml = $this->allXml($bytes);
        $this->assertStringNotContainsString('<f>', $sheetXml, 'no formula element anywhere in the workbook');
        $this->assertStringNotContainsString('<f ', $sheetXml);

        $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        file_put_contents($path, $bytes);
        $sheet = IOFactory::load($path)->getActiveSheet();
        @unlink($path);

        $this->assertTrue($sheet->getRightToLeft(), 'an Arabic right-to-left sheet');
        $formulas = 0;
        $values = [];
        foreach ($sheet->getRowIterator(2) as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $formulas += $cell->isFormula() ? 1 : 0;
                $values[] = $cell->getValue();
            }
        }
        $this->assertSame(0, $formulas);
        foreach (["'=1+1", "'-2+3", "'@SUM(A1)"] as $expected) {
            $this->assertContains($expected, $values);
        }
        foreach ($values as $v) {
            if (is_string($v)) {
                $this->assertDoesNotMatchRegularExpression('/^[=+@]/', $v);
            }
        }
        // The response number is a number too, so it sorts and filters as one in a spreadsheet.
        $this->assertIsNumeric($sheet->getCell('A2')->getValue());
        $this->assertSame('n', $sheet->getCell('A2')->getDataType());
        // A genuine number in a numeric column is a number.
        $this->assertContains(5.0, array_map(fn ($v) => is_numeric($v) ? (float) $v : null, $values));
    }

    public function test_the_safe_cell_helper_handles_the_edge_cases(): void
    {
        foreach (['', 'abc', 'عربي', '12', '-12', '+12.5', '-0,5', '١٢٣', 'a=b', 'x-y'] as $safe) {
            $this->assertSame($safe, SpreadsheetSafe::cell($safe), "left alone: $safe");
        }
        foreach (['=A1', '+A1', '-A1', '@A1', '= 1', '-1+1', '+1-1', '- 5', "\tx", "\rx", "\nx", ' =A1', "\u{00A0}=A1", '-1e5', '+1.2.3', '--1'] as $risky) {
            $this->assertSame("'".$risky, SpreadsheetSafe::cell($risky), 'neutralised: '.json_encode($risky));
        }
    }

    // ── Attachments ──────────────────────────────────────────────────────────

    public function test_attachments_are_exported_by_file_name_only_never_by_path_or_address(): void
    {
        $s = $this->respond($this->form, ['الاسم' => 'سارة', 'السيرة' => $this->pdfUpload('سيرة-سارة.pdf')]);
        $stored = $s->answers()->whereNotNull('file_path')->firstOrFail()->file_path;
        $uuid = pathinfo($stored, PATHINFO_FILENAME);

        foreach (['csv', 'xlsx', 'pdf'] as $format) {
            $created = $this->export($format)->assertCreated();
            $raw = $this->body($this->download($created));
            // For a workbook: the cell texts, without the XML namespace declarations every part carries.
            $text = $format === 'xlsx' ? preg_replace('/xmlns(:\w+)?="[^"]*"/', '', $this->allXml($raw, ['xl/sharedStrings.xml'])) : $raw;

            // A PDF carries its own metadata namespaces (http://ns.adobe.com/...), so there the test is for links and paths.
            $leaks = $format === 'pdf' ? [$stored, $uuid, 'form-uploads', '/storage/', '/URI', '/Annots'] : [$stored, $uuid, 'form-uploads', '/storage/', 'http://', 'https://'];
            foreach ($leaks as $leak) {
                $this->assertStringNotContainsString($leak, $text, "$format must not contain $leak");
            }
            if ($format !== 'pdf') {
                $this->assertStringContainsString('سيرة-سارة.pdf', $text, 'the display name is what identifies the attachment');
            }
        }
    }

    // ── Formats ──────────────────────────────────────────────────────────────

    public function test_pdf_is_a_real_pdf_and_is_limited_to_a_printable_size(): void
    {
        $this->respond($this->form, ['الاسم' => 'سارة العتيبي', 'المدينة' => 'جدة']);
        $created = $this->export('pdf', ['search' => 'سارة'])->assertCreated()->assertJsonPath('data.status', 'ready');
        $pdf = $this->body($this->download($created));
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(1500, strlen($pdf));

        $all = array_map(fn ($c) => $c['key'], $this->as($this->owner)->getJson("/api/operations/forms/{$this->form->id}/response-options")->json('data.columns'));
        $this->assertGreaterThan(12, count($all) + 7, 'sanity');
        $this->bulkResponses(501);
        $this->export('pdf')->assertStatus(422)->assertJsonValidationErrors('format');
        $this->export('pdf', ['search' => 'مجيب 50'])->assertCreated();   // narrowed down it is fine
    }

    public function test_a_pdf_of_responses_with_symbol_leading_latin_values_and_mixed_text_is_built(): void
    {
        foreach (['=1+1', '-2+3', '@SUM(A1:A9)', '+966501234567', 'اسم =1+1 عربي', 'ممتاز Excellent course 100% جداً'] as $name) {
            $this->respond($this->form, ['الاسم' => $name]);
        }

        $created = $this->export('pdf', [], ['id', 'q_'.$this->questionId($this->form, 'الاسم')])->assertCreated()->assertJsonPath('data.status', 'ready');

        $this->assertStringStartsWith('%PDF-', $this->body($this->download($created)));
    }

    public function test_a_workbook_too_large_for_memory_is_refused_with_a_pointer_to_csv_while_csv_streams_it(): void
    {
        $this->bulkResponses(5001);

        $this->export('xlsx')->assertStatus(422)->assertJsonValidationErrors('format');
        $this->export('xlsx', [], ['id', 'q_'.$this->questionId($this->form, 'الاسم')])->assertCreated()->assertJsonPath('data.row_count', 5001);
        $created = $this->export('csv')->assertCreated()->assertJsonPath('data.row_count', 5001);
        $rows = $this->csv($this->body($this->download($created)));
        $this->assertCount(5002, $rows);
        $this->assertCount(5001, array_unique(array_column(array_slice($rows, 1), 0)), 'every response once');
    }

    public function test_exports_larger_than_a_chunk_have_every_row_exactly_once(): void
    {
        $this->bulkResponses(1201);

        $created = $this->export('csv', [], ['id', 'q_'.$this->questionId($this->form, 'الاسم')])->assertCreated();
        $rows = array_slice($this->csv($this->body($this->download($created))), 1);

        $ids = array_column($rows, 0);
        $this->assertCount(1201, $ids);
        $this->assertCount(1201, array_unique($ids));
        $this->assertSame(FormSubmission::where('form_id', $this->form->id)->pluck('id')->sort()->values()->map(fn ($i) => (string) $i)->all(), collect($ids)->sort()->values()->all());
        $this->assertSame('مجيب '.$ids[0], $rows[0][1]);
    }

    public function test_an_empty_selection_and_a_bad_format_are_refused_in_words(): void
    {
        $this->export('csv')->assertStatus(422)->assertJsonValidationErrors('format');   // no responses at all
        $this->respond($this->form, ['الاسم' => 'سارة']);
        $this->export('docx')->assertStatus(422)->assertJsonValidationErrors('format');
        $this->export('csv', ['status' => ['archived']])->assertStatus(422)->assertJsonValidationErrors('format');
        $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/exports", [])->assertStatus(422)->assertJsonValidationErrors('format');
    }

    // ── Files: private, owned, expiring ──────────────────────────────────────

    public function test_files_are_private_named_by_the_server_and_only_the_requester_may_download_them(): void
    {
        $this->respond($this->form, ['الاسم' => 'سارة']);
        $admin = $this->makeUser('admin');
        $created = $this->export('csv')->assertCreated();
        $record = FormExport::findOrFail($created->json('data.id'));

        $this->assertTrue(Storage::disk('private')->exists($record->file_path));
        $this->assertMatchesRegularExpression('#^form-exports/'.$this->form->id.'/[0-9a-f-]{36}\.csv$#', $record->file_path);
        $this->assertFalse(Storage::disk('public')->exists($record->file_path));
        $this->assertStringNotContainsString($record->file_path, json_encode($created->json()), 'the path is never returned');

        $res = $this->download($created)->assertOk();
        $this->assertStringContainsString('attachment', $res->headers->get('Content-Disposition'));
        $this->assertStringContainsString("form-{$this->form->id}-responses-", $res->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));

        // Someone else, even a platform admin who may read the responses, gets their own export, not this one.
        $this->as($admin)->getJson("/api/operations/forms/{$this->form->id}/exports/{$record->id}")->assertNotFound();
        $this->download($created, $admin)->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->get("/api/operations/forms/{$this->form->id}/exports/{$record->id}/download")->assertUnauthorized();

        // The export belongs to its form: through another form's URL it does not exist.
        $other = $this->createPublishedForm($this->owner, [['type' => 'text', 'label' => 'س']]);
        $this->download($created, $this->owner, $other)->assertNotFound();
    }

    public function test_the_disk_exports_are_written_to_can_never_be_served_by_address(): void
    {
        $disk = config('filesystems.disks.'.FormExportService::DISK);

        // Never a served disk, never a public URL, and never inside a directory the web server exposes.
        $this->assertFalse($disk['serve'] ?? false, 'the export disk must not register an HTTP route');
        $this->assertArrayNotHasKey('url', $disk);
        $this->assertNotSame(config('filesystems.disks.public.root'), $disk['root']);
        $this->assertStringNotContainsString(str_replace('\\', '/', public_path()), str_replace('\\', '/', $disk['root']));
        $this->assertSame('private', FormExportService::DISK);

        // The file name is a random uuid chosen by the server, so no address can be guessed.
        $this->respond($this->form, ['الاسم' => 'سارة']);
        $path = FormExport::findOrFail($this->export('csv')->assertCreated()->json('data.id'))->file_path;
        $this->assertMatchesRegularExpression('#^form-exports/\d+/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.csv$#', $path);
    }

    public function test_the_export_job_is_a_single_attempt_that_times_out_before_the_queue_would_hand_it_to_another_worker(): void
    {
        $job = new \App\Jobs\GenerateFormExport(1);

        $this->assertSame(1, $job->tries);
        $this->assertTrue($job->failOnTimeout);
        foreach (['database', 'redis'] as $connection) {
            $this->assertLessThan((int) config("queue.connections.$connection.retry_after"), $job->timeout, "$connection: retry_after must outlast the job");
        }
    }

    public function test_downloads_stop_the_moment_access_to_the_responses_is_withdrawn(): void
    {
        $person = $this->makeUser('community_manager', $this->deptB);
        $this->share($person, 'viewer', true);
        $this->respond($this->form, ['الاسم' => 'سارة']);
        $created = $this->export('csv', [], null, $person)->assertCreated();
        $this->download($created, $person)->assertOk();

        $collaborator = $this->as($this->owner)->getJson("/api/operations/forms/{$this->form->id}/collaborators")->json('data.0.id');
        $this->as($this->owner)->deleteJson("/api/operations/forms/{$this->form->id}/collaborators/$collaborator")->assertOk();

        $this->download($created, $person)->assertForbidden();
        $this->as($person)->getJson("/api/operations/forms/{$this->form->id}/exports/".$created->json('data.id'))->assertForbidden();
    }

    public function test_access_is_judged_again_when_a_queued_file_is_built(): void
    {
        $person = $this->makeUser('community_manager', $this->deptB);
        $this->share($person, 'viewer', true);
        $this->respond($this->form, ['الاسم' => 'سارة']);
        $queued = FormExport::create(['form_id' => $this->form->id, 'user_id' => $person->id, 'format' => 'csv', 'status' => 'queued', 'filters' => [], 'columns' => ['id'], 'file_name' => 'x.csv']);

        // Access is withdrawn while the export waits in the queue.
        $collaborator = $this->as($this->owner)->getJson("/api/operations/forms/{$this->form->id}/collaborators")->json('data.0.id');
        $this->as($this->owner)->deleteJson("/api/operations/forms/{$this->form->id}/collaborators/$collaborator")->assertOk();
        app(FormExportService::class)->generate($queued);

        $queued->refresh();
        $this->assertSame('failed', $queued->status);
        $this->assertNull($queued->file_path);
        $this->assertSame([], Storage::disk('private')->allFiles());
        $this->assertStringContainsString('صلاحية', $queued->error);
    }

    public function test_files_expire_and_are_deleted(): void
    {
        $this->respond($this->form, ['الاسم' => 'سارة']);
        $keep = $this->export('csv')->assertCreated();
        $old = $this->export('csv')->assertCreated();
        $oldRecord = FormExport::findOrFail($old->json('data.id'));
        $this->assertEqualsWithDelta(24 * 3600, now()->diffInSeconds($oldRecord->expires_at, false), 5);

        $oldRecord->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->download($old)->assertStatus(410);

        Artisan::call('forms:purge-exports');

        $this->assertFalse(Storage::disk('private')->exists($oldRecord->file_path));
        $this->assertSame('expired', $oldRecord->refresh()->status);
        $this->assertNull($oldRecord->file_path);
        $this->download($old)->assertStatus(410);
        $this->download($keep)->assertOk();
        $this->assertCount(1, Storage::disk('private')->allFiles());
    }

    public function test_a_person_cannot_pile_up_unfinished_exports(): void
    {
        $this->respond($this->form, ['الاسم' => 'سارة']);
        for ($i = 0; $i < 3; $i++) {
            FormExport::create(['form_id' => $this->form->id, 'user_id' => $this->owner->id, 'format' => 'csv', 'status' => 'queued', 'file_name' => 'x.csv']);
        }

        $this->export('csv')->assertStatus(422)->assertJsonValidationErrors('format');
        $this->export('csv', [], null, $this->makeUser('admin'))->assertCreated();
    }

    public function test_an_export_nobody_picked_up_stops_blocking_the_person_and_is_reported_as_failed(): void
    {
        $this->respond($this->form, ['الاسم' => 'سارة']);
        for ($i = 0; $i < 3; $i++) {
            FormExport::create(['form_id' => $this->form->id, 'user_id' => $this->owner->id, 'format' => 'csv', 'status' => 'queued', 'file_name' => 'x.csv']);
        }
        $this->export('csv')->assertStatus(422);

        // 31 minutes later no worker has touched them: they no longer count, and the hourly purge settles them.
        $this->travel(31)->minutes();
        $this->export('csv')->assertCreated();
        Artisan::call('forms:purge-exports');

        $this->assertSame(3, FormExport::where('status', 'failed')->count());
        $this->assertStringContainsString('أنشئ تصديراً جديداً', (string) FormExport::where('status', 'failed')->first()->error);
        $this->assertSame(1, FormExport::where('status', 'ready')->count(), 'the export that was built meanwhile is untouched');
    }

    public function test_an_unfinished_export_cannot_be_downloaded_yet(): void
    {
        $record = FormExport::create(['form_id' => $this->form->id, 'user_id' => $this->owner->id, 'format' => 'csv', 'status' => 'queued', 'file_name' => 'x.csv']);

        $this->as($this->owner)->getJson("/api/operations/forms/{$this->form->id}/exports/{$record->id}")->assertOk()->assertJsonPath('data.status', 'queued')->assertJsonPath('data.ready', false);
        $this->as($this->owner)->get("/api/operations/forms/{$this->form->id}/exports/{$record->id}/download")->assertStatus(409);
    }

    public function test_every_export_is_recorded_in_the_forms_history_without_any_filter_text_or_answer(): void
    {
        $this->respond($this->form, ['الاسم' => 'سارة-سرية']);
        $this->export('xlsx', ['search' => 'سارة-سرية'])->assertCreated();

        $log = ActivityLog::where('entity_type', DynamicForm::class)->where('entity_id', $this->form->id)->where('action', 'form.responses_exported')->firstOrFail();
        $this->assertSame(['format' => 'xlsx', 'rows' => 1], $log->new_values);
        $this->assertSame($this->owner->id, (int) $log->user_id);
        $this->assertStringNotContainsString('سارة', json_encode($log->toArray(), JSON_UNESCAPED_UNICODE));
    }

    /** The text of every part of an XLSX (it is a ZIP of XML files). */
    private function allXml(string $bytes, ?array $only = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'z');
        file_put_contents($path, $bytes);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $all = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if ($only === null || in_array($zip->getNameIndex($i), $only, true)) {
                $all .= (string) $zip->getFromIndex($i);
            }
        }
        $zip->close();
        @unlink($path);

        return $all;
    }
}
