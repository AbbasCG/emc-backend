<?php

namespace Tests\Feature\Forms;

use App\Models\ActivityLog;
use App\Models\DynamicForm;
use App\Models\FormCollaborator;
use App\Models\FormVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesForms;
use Tests\TestCase;

/**
 * The form's history: who did what and when, in the platform's activity log, with nothing sensitive in it.
 */
class FormActivityHistoryTest extends TestCase
{
    use MakesForms;
    use RefreshDatabase;

    private $owner;

    private $editor;

    private $form;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->makeUser('operations_manager');
        $this->editor = $this->makeUser('community_manager');
        $res = $this->actingAs($this->owner, 'sanctum')->postJson('/api/operations/forms', [
            'title' => 'سجل', 'visibility' => 'organization',
            'questions' => [['label' => 'الاسم', 'type' => 'text'], ['label' => 'العمر', 'type' => 'number']],
        ])->assertCreated();
        $this->form = DynamicForm::findOrFail($res->json('data.id'));
        FormCollaborator::create(['form_id' => $this->form->id, 'user_id' => $this->editor->id, 'access_level' => 'editor', 'granted_by' => $this->owner->id]);
    }

    private function as($user)
    {
        return $this->actingAs($user, 'sanctum');
    }

    private function history(): array
    {
        return $this->as($this->owner)->getJson("/api/operations/forms/{$this->form->id}/activity")->assertOk()->json('data');
    }

    private function actions(): array
    {
        return array_column($this->history()['data'], 'action');
    }

    private function entry(string $action): array
    {
        return collect($this->history()['data'])->firstWhere('action', $action) ?? $this->fail("no $action entry");
    }

    public function test_creation_is_recorded_with_its_actor(): void
    {
        $created = collect($this->history()['data'])->last();

        $this->assertSame('created', $created['action']);
        $this->assertSame('إنشاء النموذج', $created['label']);
        $this->assertSame($this->owner->id, $created['actor']['id']);
        $this->assertSame($this->owner->name, $created['actor']['name']);
        $this->assertSame(2, $created['details']['question_count']);
        $this->assertNotNull($created['created_at']);
    }

    public function test_an_edit_is_attributed_to_whoever_made_it(): void
    {
        $first = $this->form->questions()->first();

        $this->as($this->editor)->putJson("/api/operations/forms/{$this->form->id}", ['title' => 'عنوان جديد', 'description' => 'وصف'])->assertOk();

        $entry = $this->entry('updated');
        $this->assertSame($this->editor->id, $entry['actor']['id']);
        $this->assertEqualsCanonicalizing(['العنوان', 'الوصف'], $entry['details']['fields']);
        $this->assertSame($this->owner->id, $this->entry('created')['actor']['id']);
        $this->assertNotNull($first);
    }

    public function test_question_changes_name_the_questions_and_reordering_is_its_own_entry(): void
    {
        [$name, $age] = $this->form->questions()->get()->all();

        $this->as($this->owner)->putJson("/api/operations/forms/{$this->form->id}", ['questions' => [
            ['id' => $age->id, 'label' => 'العمر', 'type' => 'number'],
            ['id' => $name->id, 'label' => 'الاسم الكامل', 'type' => 'text'],
            ['label' => 'المدينة', 'type' => 'text'],
        ]])->assertOk();

        $changed = $this->entry('questions_changed');
        $this->assertSame(['المدينة'], $changed['details']['added']);
        $this->assertSame(['الاسم الكامل'], $changed['details']['edited']);
        $this->assertSame([], $changed['details']['removed']);
        $this->assertContains('questions_reordered', $this->actions());

        $this->as($this->owner)->putJson("/api/operations/forms/{$this->form->id}", ['questions' => [['id' => $age->id, 'label' => 'العمر', 'type' => 'number']]])->assertOk();
        $this->assertEqualsCanonicalizing(['الاسم الكامل', 'المدينة'], $this->history()['data'][0]['details']['removed'] ?? $this->entry('questions_changed')['details']['removed']);
    }

    public function test_a_save_that_changes_nothing_writes_nothing(): void
    {
        $before = ActivityLog::count();

        $this->as($this->owner)->putJson("/api/operations/forms/{$this->form->id}", ['title' => 'سجل'])->assertOk();

        $this->assertSame($before, ActivityLog::count());
    }

    public function test_a_refused_change_leaves_no_history_and_changes_nothing(): void
    {
        $before = ActivityLog::count();

        $this->as($this->owner)->putJson("/api/operations/forms/{$this->form->id}", ['title' => 'لن يُحفظ', 'questions' => [['label' => 'س', 'type' => 'select']]])->assertStatus(422);
        $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/collaborators", ['user_id' => $this->owner->id, 'access_level' => 'viewer'])->assertStatus(422);

        $this->assertSame($before, ActivityLog::count());
        $this->assertSame('سجل', $this->form->fresh()->title);
    }

    public function test_publishing_and_every_status_change_are_recorded_with_the_version(): void
    {
        $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/publish", ['note' => 'الإطلاق'])->assertOk();
        $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/close")->assertOk();
        $this->as($this->editor)->postJson("/api/operations/forms/{$this->form->id}/reopen")->assertOk();
        $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/unpublish")->assertOk();
        $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/archive")->assertOk();
        $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/unarchive")->assertOk();

        $this->assertEqualsCanonicalizing(['published', 'closed', 'reopened', 'unpublished', 'archived', 'unarchived'], array_values(array_intersect($this->actions(), ['published', 'closed', 'reopened', 'unpublished', 'archived', 'unarchived'])));
        $published = $this->entry('published');
        $this->assertSame(1, $published['details']['version']);
        $this->assertSame('الإطلاق', $published['details']['note']);
        $this->assertSame($this->editor->id, $this->entry('reopened')['actor']['id']);
    }

    public function test_sharing_ownership_visibility_and_settings_are_recorded(): void
    {
        $guest = $this->makeUser('operations_manager');
        $heir = $this->makeUser('community_manager');
        $id = $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/collaborators", ['user_id' => $guest->id, 'access_level' => 'viewer'])->json('data.id');
        $this->as($this->owner)->putJson("/api/operations/forms/{$this->form->id}/collaborators/{$id}", ['access_level' => 'editor'])->assertOk();
        $this->as($this->owner)->deleteJson("/api/operations/forms/{$this->form->id}/collaborators/{$id}")->assertOk();
        $this->as($this->owner)->putJson("/api/operations/forms/{$this->form->id}/settings", ['visibility' => 'private', 'max_submissions' => 9])->assertOk();
        $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/ownership", ['owner_id' => $heir->id, 'keep_access' => 'viewer'])->assertOk();

        $this->assertSame($guest->name, $this->entry('collaborator_added')['details']['user']);
        $this->assertSame('editor', $this->entry('collaborator_updated')['details']['to']);
        $this->assertSame($guest->name, $this->entry('collaborator_removed')['details']['user']);
        $this->assertSame(['ظهور النموذج'], $this->entry('visibility_changed')['details']['fields']);
        $this->assertSame(['الحد الأقصى للإجابات'], $this->entry('settings_changed')['details']['fields']);
        $own = $this->entry('ownership_changed');
        $this->assertSame($this->owner->name, $own['details']['from']);
        $this->assertSame($heir->name, $own['details']['to']);
        $this->assertSame('viewer', $own['details']['previous_owner_access']);
    }

    public function test_restoring_a_version_is_recorded(): void
    {
        $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/publish")->assertOk();
        $v1 = FormVersion::firstOrFail();

        $this->as($this->editor)->postJson("/api/operations/forms/{$this->form->id}/versions/{$v1->id}/restore")->assertOk();

        $entry = $this->entry('version_restored');
        $this->assertSame(1, $entry['details']['from_version']);
        $this->assertSame($this->editor->id, $entry['actor']['id']);
    }

    public function test_the_history_never_holds_answers_tokens_or_contact_details(): void
    {
        $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/publish")->assertOk();
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $q = $this->form->questions()->first();
        $this->postJson("/api/forms/{$this->form->slug}/submit", ['answers' => [$q->id => 'SECRET-ANSWER-123'], 'metadata' => ['source' => 'secret-source']])->assertCreated();
        $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/collaborators", ['user_id' => $this->makeUser('operations_manager')->id, 'access_level' => 'viewer', 'can_view_responses' => true])->assertCreated();
        $this->as($this->owner)->putJson("/api/operations/forms/{$this->form->id}", ['title' => 'عنوان آخر'])->assertOk();

        $stored = json_encode(ActivityLog::where('entity_type', DynamicForm::class)->get()->toArray(), JSON_UNESCAPED_UNICODE);
        $api = json_encode($this->history(), JSON_UNESCAPED_UNICODE);

        foreach ([$stored, $api] as $blob) {
            $this->assertStringNotContainsString('SECRET-ANSWER-123', $blob);
            $this->assertStringNotContainsString('secret-source', $blob);
            $this->assertStringNotContainsString($this->owner->email, $blob);
            $this->assertStringNotContainsString($this->editor->email, $blob);
            $this->assertStringNotContainsString('Bearer', $blob);
            $this->assertStringNotContainsString('password', strtolower($blob));
        }
    }

    public function test_the_history_is_newest_first_and_paged(): void
    {
        // Consecutive saves of the same kind by one person are folded into one line (autosave), so alternate the kind
        // of change to get six distinct entries.
        for ($i = 1; $i <= 6; $i++) {
            $payload = $i % 2 === 1 ? ['title' => "عنوان $i"] : ['questions' => [['label' => "سؤال $i", 'type' => 'text']]];
            $this->as($this->owner)->putJson("/api/operations/forms/{$this->form->id}", $payload)->assertOk();
        }

        $page1 = $this->as($this->owner)->getJson("/api/operations/forms/{$this->form->id}/activity?per_page=3")->assertOk()->json('data');
        $page2 = $this->as($this->owner)->getJson("/api/operations/forms/{$this->form->id}/activity?per_page=3&page=2")->json('data');

        $this->assertCount(3, $page1['data']);
        $this->assertSame(7, $page1['meta']['total']);
        $this->assertSame(3, $page1['meta']['last_page']);
        $this->assertGreaterThan($page2['data'][0]['id'], $page1['data'][0]['id'], 'newest first, and the next page continues where it ended');
        $this->assertNotSame(array_column($page1['data'], 'id'), array_column($page2['data'], 'id'));
    }

    public function test_the_history_shows_only_this_forms_entries(): void
    {
        $other = $this->as($this->owner)->postJson('/api/operations/forms', ['title' => 'آخر', 'questions' => [['label' => 'س', 'type' => 'text']]])->json('data.id');

        $mine = array_column($this->history()['data'], 'id');
        $theirs = array_column($this->as($this->owner)->getJson("/api/operations/forms/{$other}/activity")->json('data.data'), 'id');

        $this->assertSame([], array_intersect($mine, $theirs));
    }

    public function test_who_may_read_the_history(): void
    {
        $viewer = $this->makeUser('operations_manager');
        FormCollaborator::create(['form_id' => $this->form->id, 'user_id' => $viewer->id, 'access_level' => 'viewer', 'granted_by' => $this->owner->id]);
        $stranger = $this->makeUser('operations_manager');
        $this->as($this->owner)->putJson("/api/operations/forms/{$this->form->id}/settings", ['visibility' => 'private'])->assertOk();

        $this->as($viewer)->getJson("/api/operations/forms/{$this->form->id}/activity")->assertOk();
        $this->as($this->makeUser('admin'))->getJson("/api/operations/forms/{$this->form->id}/activity")->assertOk();
        $this->as($stranger)->getJson("/api/operations/forms/{$this->form->id}/activity")->assertForbidden();
        $this->as($this->makeUser('student'))->getJson("/api/operations/forms/{$this->form->id}/activity")->assertForbidden();
    }

    public function test_deleting_a_form_is_recorded_and_its_history_survives(): void
    {
        $this->as($this->owner)->deleteJson("/api/admin/forms/{$this->form->id}")->assertOk();

        $this->assertDatabaseHas('activity_logs', ['entity_type' => DynamicForm::class, 'entity_id' => $this->form->id, 'action' => 'form.deleted', 'user_id' => $this->owner->id]);
        $this->assertGreaterThan(1, ActivityLog::where('entity_id', $this->form->id)->count());
    }
}
