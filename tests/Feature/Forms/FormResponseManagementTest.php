<?php

namespace Tests\Feature\Forms;

use App\Models\ActivityLog;
use App\Models\DynamicForm;
use App\Models\FormShareLink;
use App\Models\FormSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesFormResponses;
use Tests\Concerns\MakesForms;
use Tests\TestCase;

/**
 * Managing the responses of a form: the filtered, paginated table; one response in detail; the review status, the assignment and the
 * internal notes with their history. Every refusal is checked through the same endpoints a browser uses.
 */
class FormResponseManagementTest extends TestCase
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
        Storage::fake('private');
        $this->deptA = $this->makeDepartment('قسم أ');
        $this->deptB = $this->makeDepartment('قسم ب');
        $this->owner = $this->makeUser('operations_manager', $this->deptA);
        $this->form = $this->createPublishedForm($this->owner, [
            ['type' => 'text', 'label' => 'الاسم', 'is_required' => true],
            ['type' => 'select', 'label' => 'المدينة', 'options' => ['الرياض', 'جدة']],
            ['type' => 'yes_no', 'label' => 'هل حضرت؟'],
        ]);
    }

    private function as(User $user)
    {
        return $this->actingAs($user, 'sanctum');
    }

    private function list(array $query = [], ?User $user = null, ?DynamicForm $form = null)
    {
        $form ??= $this->form;

        return $this->as($user ?? $this->owner)->getJson("/api/operations/forms/{$form->id}/submissions".($query ? '?'.http_build_query($query) : ''));
    }

    /** @return list<int> ids on the current page */
    private function ids($response): array
    {
        return array_map('intval', array_column($response->json('data.data'), 'id'));
    }

    private function url(FormSubmission $s, string $tail = '', ?DynamicForm $form = null): string
    {
        return '/api/operations/forms/'.($form ?? $this->form)->id.'/submissions/'.$s->id.$tail;
    }

    /** @return list<list<string>> */
    private function exportedCsv(array $filters, array $columns): array
    {
        $created = $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/exports", ['format' => 'csv', 'filters' => $filters, 'columns' => $columns])->assertCreated();
        $body = $this->as($this->owner)->get("/api/operations/forms/{$this->form->id}/exports/".$created->json('data.id').'/download')->assertOk()->streamedContent();
        $h = fopen('php://memory', 'r+');
        fwrite($h, substr($body, 3));
        rewind($h);
        $rows = [];
        while (($r = fgetcsv($h, 0, ',', '"', '\\')) !== false) {
            $rows[] = $r;
        }

        return $rows;
    }

    private function share($user, string $level, bool $responses): void
    {
        $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/collaborators", [
            'user_id' => $user->id, 'access_level' => $level, 'can_view_responses' => $responses,
        ])->assertCreated();
    }

    // ── The table: server-side pagination, sorting, filters ──────────────────

    public function test_the_table_is_paginated_on_the_server_and_newest_first(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->respond($this->form, ['الاسم' => "شخص $i"], ['submitted_at' => now()->subMinutes(100 - $i)]);
        }

        $page2 = $this->list(['per_page' => 10, 'page' => 2])->assertOk();
        $this->assertCount(10, $page2->json('data.data'));
        $this->assertSame(25, $page2->json('data.meta.total'));
        $this->assertSame(3, $page2->json('data.meta.last_page'));

        $first = $this->list(['per_page' => 10])->assertOk();
        $this->assertSame('شخص 25', $first->json('data.data.0.answers.0.value'));

        $oldestFirst = $this->list(['per_page' => 10, 'sort' => 'submitted_at', 'direction' => 'asc']);
        $this->assertSame('شخص 1', $oldestFirst->json('data.data.0.answers.0.value'));

        // Pages never overlap or skip a row.
        $seen = array_merge($this->ids($this->list(['per_page' => 10, 'page' => 1])), $this->ids($page2), $this->ids($this->list(['per_page' => 10, 'page' => 3])));
        $this->assertCount(25, array_unique($seen));

        $this->list(['per_page' => 101])->assertStatus(422)->assertJsonValidationErrors('per_page');
        $this->list(['sort' => 'password'])->assertStatus(422)->assertJsonValidationErrors('sort');
    }

    public function test_search_finds_answers_the_respondents_name_and_the_response_number_and_treats_wildcards_literally(): void
    {
        $respondent = User::factory()->create(['name' => 'فاطمة الزهراني', 'email' => 'fatima@example.test']);
        $a = $this->respond($this->form, ['الاسم' => 'سارة العتيبي', 'المدينة' => 'جدة']);
        $b = $this->respond($this->form, ['الاسم' => 'خالد'], ['user_id' => $respondent->id]);
        $this->respond($this->form, ['الاسم' => 'نورة']);

        $this->assertSame([$a->id], $this->ids($this->list(['search' => 'العتيبي'])));
        $this->assertSame([$b->id], $this->ids($this->list(['search' => 'الزهراني'])));
        $this->assertSame([$b->id], $this->ids($this->list(['search' => 'fatima@'])));
        $this->assertSame([$a->id], $this->ids($this->list(['search' => (string) $a->id])));
        $this->assertSame([], $this->ids($this->list(['search' => '%'])));
        $this->assertSame([], $this->ids($this->list(['search' => 'غير موجود'])));
    }

    public function test_status_assignee_version_date_source_and_corrected_filters(): void
    {
        $reviewer = $this->makeUser('operations_manager', $this->deptA);
        $this->share($reviewer, 'editor', true);
        $link = FormShareLink::create(['form_id' => $this->form->id, 'label' => 'ملصق', 'token_hash' => hash('sha256', 'x'), 'token_hint' => 'xxxx', 'created_by' => $this->owner->id]);

        $old = $this->respond($this->form, ['الاسم' => 'قديم'], ['submitted_at' => '2026-01-10 10:00:00', 'status' => 'completed']);
        $mid = $this->respond($this->form, ['الاسم' => 'وسط'], ['submitted_at' => '2026-02-15 22:59:30', 'status' => 'in_review', 'assigned_to' => $reviewer->id]);
        $this->republish($this->owner, $this->form, fn ($qs) => array_map(fn ($q) => $q['label'] === 'الاسم' ? ['label' => 'الاسم الكامل'] + $q : $q, $qs));
        $new = $this->respond($this->form, ['الاسم الكامل' => 'جديد'], ['submitted_at' => '2026-03-01 08:00:00', 'share_link_id' => $link->id, 'corrected_at' => now()]);
        $v1 = (int) $old->form_version_id;
        $v2 = (int) $new->form_version_id;
        $this->assertNotSame($v1, $v2);

        $this->assertEqualsCanonicalizing([$old->id], $this->ids($this->list(['status' => 'completed'])));
        $this->assertEqualsCanonicalizing([$old->id, $mid->id], $this->ids($this->list(['status' => ['completed', 'in_review']])));
        $this->assertEqualsCanonicalizing([$old->id, $mid->id], $this->ids($this->list(['status' => 'completed,in_review'])));
        $this->assertEqualsCanonicalizing([$old->id], $this->ids($this->list(['status' => 'reviewed'])), 'the old spelling still means completed');

        $this->assertSame([$mid->id], $this->ids($this->list(['assignee' => $reviewer->id])));
        $this->assertEqualsCanonicalizing([$old->id, $new->id], $this->ids($this->list(['assignee' => 'none'])));
        $this->assertSame([$mid->id], $this->ids($this->list(['assignee' => 'me'], $reviewer)));

        $this->assertEqualsCanonicalizing([$old->id, $mid->id], $this->ids($this->list(['version' => $v1])));
        $this->assertSame([$new->id], $this->ids($this->list(['version' => $v2])));

        // Both ends of a date range are included, to the end of the last day.
        $this->assertEqualsCanonicalizing([$mid->id, $new->id], $this->ids($this->list(['from' => '2026-02-15', 'to' => '2026-03-01'])));
        $this->assertSame([$mid->id], $this->ids($this->list(['from' => '2026-02-15', 'to' => '2026-02-15'])));
        $this->list(['from' => '2026-03-01', 'to' => '2026-01-01'])->assertStatus(422)->assertJsonValidationErrors('to');
        $this->list(['from' => '15/02/2026'])->assertStatus(422)->assertJsonValidationErrors('from');

        $this->assertSame([$new->id], $this->ids($this->list(['source' => 'link'])));
        $this->assertSame([$new->id], $this->ids($this->list(['source' => (string) $link->id])));
        $this->assertEqualsCanonicalizing([$old->id, $mid->id], $this->ids($this->list(['source' => 'public'])));
        $this->assertSame([$new->id], $this->ids($this->list(['corrected' => 1])));

        // Filters combine.
        $this->assertSame([$mid->id], $this->ids($this->list(['version' => $v1, 'status' => 'in_review'])));
        $this->assertSame([], $this->ids($this->list(['version' => $v2, 'status' => 'completed'])));
    }

    public function test_date_filters_follow_the_platform_display_timezone_not_utc(): void
    {
        // 23:30 UTC on the 14th is 00:30 on the 15th in Amsterdam (winter time, UTC+1): that is where people read it.
        $late = $this->respond($this->form, ['الاسم' => 'ليلاً'], ['submitted_at' => '2026-02-14 23:30:00']);
        $early = $this->respond($this->form, ['الاسم' => 'قبل'], ['submitted_at' => '2026-02-14 22:30:00']);

        $this->assertSame([$late->id], $this->ids($this->list(['from' => '2026-02-15', 'to' => '2026-02-15'])));
        $this->assertSame([$early->id], $this->ids($this->list(['from' => '2026-02-14', 'to' => '2026-02-14'])));

        $rows = $this->exportedCsv(['from' => '2026-02-15'], ['id', 'submitted_at']);
        $this->assertSame([[(string) $late->id, '2026-02-15 00:30']], array_slice($rows, 1));
    }

    public function test_a_version_of_another_form_matches_nothing(): void
    {
        $other = $this->createPublishedForm($this->owner, [['type' => 'text', 'label' => 'س']]);
        $this->respond($this->form, ['الاسم' => 'أ']);

        $this->assertSame([], $this->ids($this->list(['version' => $other->refresh()->published_version_id])));
    }

    public function test_status_counts_follow_the_other_filters_but_not_the_status_filter(): void
    {
        $this->respond($this->form, ['الاسم' => 'أ'], ['status' => 'new']);
        $this->respond($this->form, ['الاسم' => 'ب'], ['status' => 'new']);
        $this->respond($this->form, ['الاسم' => 'ج'], ['status' => 'completed']);
        $this->respond($this->form, ['الاسم' => 'د'], ['status' => 'reviewed']);   // legacy spelling counts as completed
        $this->respond($this->form, ['الاسم' => 'هـ'], ['status' => 'archived', 'submitted_at' => '2020-01-01 00:00:00']);

        $res = $this->list(['status' => 'new', 'from' => '2026-01-01'])->assertOk();
        $this->assertSame(['new' => 2, 'in_review' => 0, 'needs_followup' => 0, 'completed' => 2, 'archived' => 0], $res->json('data.status_counts'));
        $this->assertSame(2, $res->json('data.meta.total'));
    }

    public function test_the_table_shows_each_response_with_the_labels_of_the_version_it_was_given_for(): void
    {
        $old = $this->respond($this->form, ['الاسم' => 'قديم', 'المدينة' => 'جدة']);
        $this->republish($this->owner, $this->form, fn ($qs) => array_map(fn ($q) => $q['label'] === 'المدينة' ? ['label' => 'مدينة الإقامة', 'options' => ['الرياض', 'جدة', 'الدمام']] + $q : $q, $qs));
        $new = $this->respond($this->form, ['الاسم' => 'جديد', 'مدينة الإقامة' => 'الدمام']);

        $rows = collect($this->list()->json('data.data'))->keyBy('id');
        $labels = fn ($id) => collect($rows[$id]['answers'])->pluck('question_label')->all();
        $this->assertContains('المدينة', $labels($old->id));
        $this->assertNotContains('مدينة الإقامة', $labels($old->id));
        $this->assertContains('مدينة الإقامة', $labels($new->id));
        $this->assertSame(1, $rows[$old->id]['form_version']);
        $this->assertSame(2, $rows[$new->id]['form_version']);
    }

    // ── One response ─────────────────────────────────────────────────────────

    public function test_a_response_opens_in_detail_and_only_through_its_own_form(): void
    {
        $s = $this->respond($this->form, ['الاسم' => 'سارة']);
        $other = $this->createPublishedForm($this->owner, [['type' => 'text', 'label' => 'س']]);

        $this->as($this->owner)->getJson($this->url($s))->assertOk()
            ->assertJsonPath('data.id', $s->id)->assertJsonPath('data.status', 'new')->assertJsonPath('data.status_label', 'جديد')
            ->assertJsonPath('data.abilities.review', true)->assertJsonPath('data.abilities.correct', true)
            ->assertJsonPath('data.answers.0.question_label', 'الاسم');

        $this->as($this->owner)->getJson($this->url($s, '', $other))->assertNotFound();
        $this->as($this->owner)->putJson($this->url($s, '/status', $other), ['status' => 'completed'])->assertNotFound();
        $this->as($this->owner)->postJson($this->url($s, '/notes', $other), ['body' => 'x'])->assertNotFound();
        $this->as($this->owner)->getJson($this->url($s, '/history', $other))->assertNotFound();
        $this->as($this->owner)->putJson($this->url($s, '/assignment', $other), ['assignee_id' => null])->assertNotFound();
    }

    public function test_the_filter_options_offer_versions_links_columns_and_only_reviewers_get_the_people_list(): void
    {
        $viewer = $this->makeUser('community_manager', $this->deptB);
        $this->share($viewer, 'viewer', true);
        $this->respond($this->form, ['الاسم' => 'أ']);

        $owner = $this->as($this->owner)->getJson("/api/operations/forms/{$this->form->id}/response-options")->assertOk();
        $this->assertSame(['new', 'in_review', 'needs_followup', 'completed', 'archived'], array_column($owner->json('data.statuses'), 'value'));
        $this->assertSame(1, $owner->json('data.versions.0.responses'));
        $this->assertContains($this->owner->id, array_column($owner->json('data.assignable'), 'id'));
        $this->assertContains('q_'.$this->questionId($this->form, 'الاسم'), array_column($owner->json('data.columns'), 'key'));

        $viewerView = $this->as($viewer)->getJson("/api/operations/forms/{$this->form->id}/response-options")->assertOk();
        $this->assertSame([], $viewerView->json('data.assignable'));
    }

    // ── Who may see and who may review ───────────────────────────────────────

    public function test_viewers_read_the_responses_but_cannot_review_assign_or_read_notes_and_history(): void
    {
        $viewer = $this->makeUser('community_manager', $this->deptB);
        $this->share($viewer, 'viewer', true);
        $s = $this->respond($this->form, ['الاسم' => 'سارة']);
        $this->as($this->owner)->postJson($this->url($s, '/notes'), ['body' => 'ملاحظة داخلية سرية'])->assertCreated();

        $this->list(user: $viewer)->assertOk()->assertJsonPath('data.meta.total', 1);
        $detail = $this->as($viewer)->getJson($this->url($s))->assertOk()->assertJsonPath('data.abilities.review', false)->assertJsonPath('data.abilities.correct', false);
        $this->assertStringNotContainsString('ملاحظة داخلية سرية', $detail->getContent());
        $this->assertStringNotContainsString('ملاحظة داخلية سرية', $this->list(user: $viewer)->getContent());

        $this->as($viewer)->putJson($this->url($s, '/status'), ['status' => 'completed'])->assertForbidden();
        $this->as($viewer)->putJson($this->url($s, '/assignment'), ['assignee_id' => $this->owner->id])->assertForbidden();
        $this->as($viewer)->getJson($this->url($s, '/notes'))->assertForbidden();
        $this->as($viewer)->postJson($this->url($s, '/notes'), ['body' => 'x'])->assertForbidden();
        $this->as($viewer)->getJson($this->url($s, '/history'))->assertForbidden();
        $this->as($viewer)->putJson($this->url($s, '/answers'), ['corrections' => [1 => 'x']])->assertForbidden();
        $this->assertSame('new', $s->refresh()->status);
    }

    public function test_an_editor_without_response_access_sees_nothing_of_the_responses(): void
    {
        $editor = $this->makeUser('community_manager', $this->deptB);
        $this->share($editor, 'editor', false);
        $s = $this->respond($this->form, ['الاسم' => 'سارة']);

        $this->list(user: $editor)->assertForbidden();
        $this->as($editor)->getJson($this->url($s))->assertForbidden();
        $this->as($editor)->putJson($this->url($s, '/status'), ['status' => 'completed'])->assertForbidden();
        $this->as($editor)->getJson("/api/operations/forms/{$this->form->id}/response-options")->assertForbidden();
    }

    public function test_responses_do_not_cross_departments_unless_the_form_is_shared_or_organisation_wide(): void
    {
        $other = $this->makeUser('operations_manager', $this->deptB);
        $s = $this->respond($this->form, ['الاسم' => 'سارة']);

        // Private form (the default for a new one): another department's manager has no access at all.
        $this->list(user: $other)->assertForbidden();
        $this->as($other)->getJson($this->url($s))->assertForbidden();

        // Organisation-wide visibility scoped to department A with responses visible to form viewers: B is still outside it.
        $this->as($this->owner)->putJson("/api/operations/forms/{$this->form->id}", ['department_id' => $this->deptA->id])->assertOk();
        $this->as($this->owner)->putJson("/api/operations/forms/{$this->form->id}/settings", ['visibility' => 'organization', 'responses_visibility' => 'form_viewers'])->assertOk();
        $this->list(user: $other)->assertForbidden();

        $sameDept = $this->makeUser('operations_manager', $this->deptA);
        $this->list(user: $sameDept)->assertOk()->assertJsonPath('data.meta.total', 1);

        // A response id of another department's form is never reachable through a form you may read.
        $foreignForm = $this->createPublishedForm($other, [['type' => 'text', 'label' => 'س']]);
        $foreign = $this->respond($foreignForm, ['س' => 'سري']);
        $this->as($sameDept)->getJson($this->url($foreign))->assertNotFound();
        $this->as($sameDept)->getJson($this->url($foreign, '', $foreignForm))->assertForbidden();
    }

    // ── Status ───────────────────────────────────────────────────────────────

    public function test_the_status_changes_through_the_five_review_statuses_and_every_change_is_in_the_history(): void
    {
        $s = $this->respond($this->form, ['الاسم' => 'سارة']);

        foreach (['in_review', 'needs_followup', 'completed', 'archived', 'new'] as $status) {
            $this->as($this->owner)->putJson($this->url($s, '/status'), ['status' => $status])->assertOk()->assertJsonPath('data.status', $status)->assertJsonPath('changed', true);
        }
        $this->as($this->owner)->putJson($this->url($s, '/status'), ['status' => 'new'])->assertOk()->assertJsonPath('changed', false);

        $history = $this->as($this->owner)->getJson($this->url($s, '/history'))->assertOk()->json('data');
        $this->assertSame('submitted', $history[0]['type']);
        $changes = array_values(array_filter($history, fn ($h) => $h['type'] === 'status_changed'));
        $this->assertCount(5, $changes, 'the repeated status did not add a line');
        $this->assertSame(['جديد', 'قيد المراجعة'], [$changes[0]['from'], $changes[0]['to']]);
        $this->assertSame($this->owner->id, $changes[0]['actor']['id']);
        $this->assertSame(['مؤرشف', 'جديد'], [$changes[4]['from'], $changes[4]['to']]);
    }

    public function test_an_unknown_status_is_refused_and_the_old_reviewed_spelling_means_completed(): void
    {
        $s = $this->respond($this->form, ['الاسم' => 'سارة']);

        $this->as($this->owner)->putJson($this->url($s, '/status'), ['status' => 'banana'])->assertStatus(422)->assertJsonValidationErrors('status');
        $this->as($this->owner)->putJson($this->url($s, '/status'), [])->assertStatus(422);
        $this->as($this->owner)->putJson("/api/admin/forms/{$this->form->id}/submissions/{$s->id}/status", ['status' => 'reviewed'])->assertOk();
        $this->assertSame('completed', $s->refresh()->status);
    }

    public function test_the_migration_turns_existing_reviewed_responses_into_completed(): void
    {
        $s = $this->respond($this->form, ['الاسم' => 'سارة']);
        DB::table('form_submissions')->where('id', $s->id)->update(['status' => 'reviewed']);

        (require database_path('migrations/2026_10_21_100001_add_review_workflow_to_form_submissions.php'))->up();

        $this->assertSame('completed', $s->refresh()->status);
    }

    // ── Assignment ───────────────────────────────────────────────────────────

    public function test_a_response_is_assigned_to_someone_who_could_review_it_and_unassigned_again(): void
    {
        $reviewer = $this->makeUser('community_manager', $this->deptB);
        $this->share($reviewer, 'editor', true);
        $s = $this->respond($this->form, ['الاسم' => 'سارة']);

        $this->as($this->owner)->putJson($this->url($s, '/assignment'), ['assignee_id' => $reviewer->id])->assertOk()
            ->assertJsonPath('data.assignee.id', $reviewer->id)->assertJsonPath('data.assignee.name', $reviewer->name)->assertJsonPath('changed', true);
        $this->assertNotNull($s->refresh()->assigned_at);
        $this->as($this->owner)->putJson($this->url($s, '/assignment'), ['assignee_id' => $reviewer->id])->assertOk()->assertJsonPath('changed', false);

        // The assignee works the response themselves.
        $this->as($reviewer)->putJson($this->url($s, '/status'), ['status' => 'in_review'])->assertOk();

        $this->as($this->owner)->putJson($this->url($s, '/assignment'), ['assignee_id' => null])->assertOk()->assertJsonPath('data.assignee', null);
        $this->assertNull($s->refresh()->assigned_to);
        $this->assertNull($s->assigned_at);

        $types = array_column($this->as($this->owner)->getJson($this->url($s, '/history'))->json('data'), 'type');
        $this->assertSame(['submitted', 'assigned', 'status_changed', 'unassigned'], $types);
    }

    public function test_assignment_is_refused_for_anyone_who_could_not_open_the_response(): void
    {
        $s = $this->respond($this->form, ['الاسم' => 'سارة']);
        $outsider = $this->makeUser('operations_manager', $this->deptB);              // private form: no access
        $viewer = $this->makeUser('community_manager', $this->deptB);
        $this->share($viewer, 'viewer', true);                                          // may read, may not review
        $inactive = $this->makeUser('operations_manager', $this->deptA);
        $this->share($inactive, 'editor', true);
        $inactive->forceFill(['is_active' => false])->save();
        $student = $this->makeUser('student');

        foreach ([$outsider, $viewer, $inactive, $student] as $person) {
            $this->as($this->owner)->putJson($this->url($s, '/assignment'), ['assignee_id' => $person->id])
                ->assertStatus(422)->assertJsonValidationErrors('assignee_id');
        }
        $this->as($this->owner)->putJson($this->url($s, '/assignment'), ['assignee_id' => 999999])->assertStatus(422);
        $this->as($this->owner)->putJson($this->url($s, '/assignment'), [])->assertStatus(422);
        $this->assertNull($s->refresh()->assigned_to);

        $options = $this->as($this->owner)->getJson("/api/operations/forms/{$this->form->id}/response-options")->json('data.assignable');
        $this->assertEqualsCanonicalizing([$this->owner->id], array_column($options, 'id'));
    }

    public function test_the_assignee_list_and_assignee_details_carry_a_name_and_nothing_else_about_the_employee(): void
    {
        $reviewer = $this->makeUser('operations_manager', $this->deptA);
        $this->share($reviewer, 'editor', true);
        $s = $this->respond($this->form, ['الاسم' => 'سارة'], ['assigned_to' => $reviewer->id]);

        $row = $this->list()->json('data.data.0');
        $this->assertSame(['id', 'name'], array_keys($row['assignee']));
        $this->assertStringNotContainsString($reviewer->email, $this->list()->getContent());
        unset($s);
    }

    // ── Notes ────────────────────────────────────────────────────────────────

    public function test_internal_notes_are_added_by_reviewers_listed_in_order_and_never_edited(): void
    {
        $s = $this->respond($this->form, ['الاسم' => 'سارة']);

        $this->as($this->owner)->postJson($this->url($s, '/notes'), ['body' => '  اتصلنا بها وطلبت الانتظار  '])->assertCreated()->assertJsonPath('data.0.body', 'اتصلنا بها وطلبت الانتظار');
        $this->as($this->owner)->postJson($this->url($s, '/notes'), ['body' => 'تمت المتابعة'])->assertCreated();

        $notes = $this->as($this->owner)->getJson($this->url($s, '/notes'))->assertOk()->json('data');
        $this->assertSame(['اتصلنا بها وطلبت الانتظار', 'تمت المتابعة'], array_column($notes, 'body'));
        $this->assertSame($this->owner->id, $notes[0]['author']['id']);

        $this->as($this->owner)->putJson($this->url($s, '/notes/'.$notes[0]['id']), ['body' => 'x'])->assertStatus(404);
        $this->as($this->owner)->deleteJson($this->url($s, '/notes/'.$notes[0]['id']))->assertStatus(404);

        $this->as($this->owner)->postJson($this->url($s, '/notes'), ['body' => '   '])->assertStatus(422)->assertJsonValidationErrors('body');
        $this->as($this->owner)->postJson($this->url($s, '/notes'), ['body' => str_repeat('ا', 2001)])->assertStatus(422)->assertJsonValidationErrors('body');
        $this->assertCount(2, $s->notes);

        $types = array_column($this->as($this->owner)->getJson($this->url($s, '/history'))->json('data'), 'type');
        $this->assertSame(['submitted', 'note_added', 'note_added'], $types);
    }

    public function test_notes_history_and_assignees_never_reach_the_public_side(): void
    {
        $s = $this->respond($this->form, ['الاسم' => 'سارة']);
        $this->as($this->owner)->postJson($this->url($s, '/notes'), ['body' => 'ملاحظة داخلية سرية'])->assertCreated();
        $this->as($this->owner)->putJson($this->url($s, '/assignment'), ['assignee_id' => $this->owner->id])->assertOk();

        $this->app['auth']->forgetGuards();
        $page = $this->getJson("/api/forms/{$this->form->slug}")->assertOk();
        $submit = $this->postJson("/api/forms/{$this->form->slug}/submit", ['answers' => [$this->questionId($this->form, 'الاسم') => 'نورة']])->assertCreated();

        foreach ([$page->getContent(), $submit->getContent()] as $body) {
            $this->assertStringNotContainsString('ملاحظة داخلية سرية', $body);
            $this->assertStringNotContainsString($this->owner->name, $body);
            $this->assertStringNotContainsString('assignee', $body);
        }
        $this->assertSame(['submission_id'], array_keys($submit->json('data')));

        // And every staff route stays closed to a visitor.
        $this->getJson($this->url($s))->assertUnauthorized();
        $this->getJson($this->url($s, '/notes'))->assertUnauthorized();
        $this->getJson($this->url($s, '/history'))->assertUnauthorized();
    }

    public function test_the_form_history_records_that_responses_were_corrected_or_exported_without_any_answer_text(): void
    {
        $s = $this->respond($this->form, ['الاسم' => 'سارة المهدي']);
        $this->as($this->owner)->putJson($this->url($s, '/answers'), ['corrections' => [$this->questionId($this->form, 'الاسم') => 'سارة الجديدة']])->assertOk();

        $log = ActivityLog::query()->where('entity_type', DynamicForm::class)->where('entity_id', $this->form->id)->where('action', 'form.response_corrected')->firstOrFail();
        $this->assertSame(1, $log->new_values['count']);
        $this->assertStringNotContainsString('سارة', json_encode($log->new_values, JSON_UNESCAPED_UNICODE));
    }
}
