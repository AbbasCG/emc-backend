<?php

namespace Tests\Feature\Forms;

use App\Models\FormAnswer;
use App\Models\FormSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesForms;
use Tests\TestCase;

/**
 * Form-level and department-level authorization, enforced by Laravel (not by what the screen shows).
 *
 * The role middleware on the routes decides who may reach the form screens at all; these tests check what a person
 * who got in may then see and change: their department's forms, the forms nobody has assigned to a department, and
 * the ones they made themselves — never another department's.
 */
class FormAuthorizationTest extends TestCase
{
    use MakesForms;
    use RefreshDatabase;

    private $deptA;

    private $deptB;

    private $opsA;

    private $opsB;

    private $formA;

    private $formB;

    private $formOrg;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');

        $this->deptA = $this->makeDepartment('Dept A');
        $this->deptB = $this->makeDepartment('Dept B');
        $this->opsA = $this->makeUser('operations_manager', $this->deptA);
        $this->opsB = $this->makeUser('operations_manager', $this->deptB);

        $q = [['label' => 'سؤال', 'type' => 'text'], ['label' => 'مرفق', 'type' => 'file']];
        $this->formA = $this->makeForm(['title' => 'نموذج أ', 'department_id' => $this->deptA->id], $q);
        $this->formB = $this->makeForm(['title' => 'نموذج ب', 'department_id' => $this->deptB->id], $q);
        $this->formOrg = $this->makeForm(['title' => 'نموذج عام للمؤسسة', 'department_id' => null], $q);
    }

    /** One response (with an attachment) on a form, made through the real public endpoint. */
    private function respond($form): array
    {
        [$text, $file] = $form->questions->all();
        $res = $this->post("/api/forms/{$form->slug}/submit", ['answers' => [$text->id => 'خاص', $file->id => $this->pdfUpload('secret.pdf')]], ['Accept' => 'application/json'])->assertCreated();
        $submission = FormSubmission::findOrFail($res->json('data.submission_id'));
        $answer = FormAnswer::where('submission_id', $submission->id)->where('question_id', $file->id)->firstOrFail();

        return [$submission, $answer];
    }

    // ── The role gate in front of the screens ────────────────────────────────

    public function test_people_without_a_forms_role_are_turned_away(): void
    {
        foreach (['student', 'volunteer', 'instructor', 'support_agent'] as $role) {
            $user = $this->makeUser($role);
            $this->actingAs($user, 'sanctum')->getJson('/api/operations/forms')->assertForbidden();
            $this->actingAs($user, 'sanctum')->postJson('/api/operations/forms', ['title' => 'x'])->assertForbidden();
        }
    }

    public function test_an_anonymous_visitor_gets_no_form_data(): void
    {
        $this->getJson('/api/operations/forms')->assertUnauthorized();
        $this->getJson("/api/operations/forms/{$this->formA->id}")->assertUnauthorized();
        $this->getJson("/api/admin/forms/{$this->formA->id}/submissions")->assertUnauthorized();
    }

    // ── Department isolation: lists ──────────────────────────────────────────

    public function test_the_list_holds_only_the_forms_a_person_may_see(): void
    {
        $titles = fn ($user) => collect($this->actingAs($user, 'sanctum')->getJson('/api/operations/forms')->assertOk()->json('data.data'))->pluck('title')->sort()->values()->all();

        $this->assertSame(['نموذج أ', 'نموذج عام للمؤسسة'], $titles($this->opsA));
        $this->assertSame(['نموذج ب', 'نموذج عام للمؤسسة'], $titles($this->opsB));
        $this->assertCount(3, $titles($this->makeUser('admin')), 'a platform-wide role sees every form');
        $this->assertCount(3, $titles($this->makeUser('super_admin')));
        $this->assertCount(3, $titles($this->makeUser('tech_admin')));
    }

    public function test_a_person_with_no_department_sees_only_unassigned_forms_and_their_own(): void
    {
        $loner = $this->makeUser('operations_manager');
        $own = $this->makeForm(['title' => 'نموذجي', 'department_id' => $this->deptB->id], [], $loner);

        $titles = collect($this->actingAs($loner, 'sanctum')->getJson('/api/operations/forms')->assertOk()->json('data.data'))->pluck('title')->sort()->values()->all();

        $this->assertEqualsCanonicalizing(['نموذجي', 'نموذج عام للمؤسسة'], $titles);
        $this->actingAs($loner, 'sanctum')->getJson("/api/operations/forms/{$own->id}")->assertOk();
    }

    public function test_the_list_filters_cannot_be_used_to_reach_another_departments_forms(): void
    {
        $res = $this->actingAs($this->opsA, 'sanctum')->getJson('/api/operations/forms?search='.urlencode('نموذج ب'))->assertOk();

        $this->assertSame([], $res->json('data.data'));
    }

    // ── Department isolation: a single form ──────────────────────────────────

    public function test_another_departments_form_cannot_be_read_changed_or_deleted(): void
    {
        $ops = $this->opsA;
        $f = $this->formB;
        $q = $f->questions->first();

        $this->actingAs($ops, 'sanctum')->getJson("/api/operations/forms/{$f->id}")->assertForbidden();
        $this->actingAs($ops, 'sanctum')->putJson("/api/operations/forms/{$f->id}", ['title' => 'مخطوف'])->assertForbidden();
        $this->actingAs($ops, 'sanctum')->putJson("/api/operations/forms/{$f->id}", ['questions' => []])->assertForbidden();
        $this->actingAs($ops, 'sanctum')->getJson("/api/admin/forms/{$f->id}")->assertForbidden();
        $this->actingAs($ops, 'sanctum')->getJson("/api/admin/forms/{$f->id}/questions")->assertForbidden();
        $this->actingAs($ops, 'sanctum')->postJson("/api/admin/forms/{$f->id}/questions", ['label' => 'x', 'type' => 'text'])->assertForbidden();
        $this->actingAs($ops, 'sanctum')->putJson("/api/admin/forms/{$f->id}/questions/{$q->id}", ['label' => 'x'])->assertForbidden();
        $this->actingAs($ops, 'sanctum')->deleteJson("/api/admin/forms/{$f->id}/questions/{$q->id}")->assertForbidden();
        $this->actingAs($ops, 'sanctum')->deleteJson("/api/admin/forms/{$f->id}")->assertForbidden();

        $f->refresh();
        $this->assertSame('نموذج ب', $f->title);
        $this->assertSame(2, $f->questions()->count());
    }

    public function test_another_departments_responses_cannot_be_listed_or_changed(): void
    {
        [$submission] = $this->respond($this->formB);

        $this->actingAs($this->opsA, 'sanctum')->getJson("/api/operations/forms/{$this->formB->id}/submissions")->assertForbidden();
        $this->actingAs($this->opsA, 'sanctum')->getJson("/api/admin/forms/{$this->formB->id}/submissions")->assertForbidden();
        $this->actingAs($this->opsA, 'sanctum')->putJson("/api/admin/forms/{$this->formB->id}/submissions/{$submission->id}/status", ['status' => 'archived'])->assertForbidden();
        $this->assertSame('new', $submission->fresh()->status);

        $this->actingAs($this->opsB, 'sanctum')->getJson("/api/operations/forms/{$this->formB->id}/submissions")->assertOk()->assertJsonPath('data.meta.total', 1);
    }

    public function test_a_department_form_is_fully_workable_by_its_own_department(): void
    {
        $this->actingAs($this->opsA, 'sanctum')->putJson("/api/operations/forms/{$this->formA->id}", ['title' => 'معدّل من قسمه'])->assertOk();
        $this->actingAs($this->opsA, 'sanctum')->getJson("/api/operations/forms/{$this->formA->id}/submissions")->assertOk();
        $this->assertSame('معدّل من قسمه', $this->formA->fresh()->title);
    }

    public function test_unassigned_forms_stay_workable_by_anyone_with_forms_management(): void
    {
        $this->actingAs($this->opsB, 'sanctum')->putJson("/api/operations/forms/{$this->formOrg->id}", ['title' => 'عدّله ب'])->assertOk();
    }

    public function test_the_creator_keeps_access_to_their_own_form_even_in_another_department(): void
    {
        $mine = $this->makeForm(['department_id' => $this->deptB->id], [['label' => 'س', 'type' => 'text']], $this->opsA);

        $this->actingAs($this->opsA, 'sanctum')->getJson("/api/operations/forms/{$mine->id}")->assertOk();
        $this->actingAs($this->opsA, 'sanctum')->putJson("/api/operations/forms/{$mine->id}", ['title' => 'ok'])->assertOk();
    }

    // ── Creating and moving ──────────────────────────────────────────────────

    public function test_a_form_can_only_be_created_in_a_department_the_person_belongs_to(): void
    {
        $this->actingAs($this->opsA, 'sanctum')->postJson('/api/operations/forms', ['title' => 'في قسم غيري', 'department_id' => $this->deptB->id])
            ->assertStatus(422)->assertJsonValidationErrors('department_id');

        $this->actingAs($this->opsA, 'sanctum')->postJson('/api/operations/forms', ['title' => 'في قسمي', 'department_id' => $this->deptA->id])->assertCreated();
        $this->actingAs($this->opsA, 'sanctum')->postJson('/api/operations/forms', ['title' => 'للمؤسسة'])->assertCreated();
        $this->actingAs($this->makeUser('admin'), 'sanctum')->postJson('/api/operations/forms', ['title' => 'أي قسم', 'department_id' => $this->deptB->id])->assertCreated();
    }

    public function test_a_form_cannot_be_moved_into_a_department_the_person_does_not_belong_to(): void
    {
        $this->actingAs($this->opsA, 'sanctum')->putJson("/api/operations/forms/{$this->formA->id}", ['department_id' => $this->deptB->id])
            ->assertStatus(422)->assertJsonValidationErrors('department_id');

        $this->assertSame($this->deptA->id, (int) $this->formA->fresh()->department_id);
    }

    public function test_the_response_to_a_denied_request_reveals_nothing_about_the_form(): void
    {
        $res = $this->actingAs($this->opsA, 'sanctum')->getJson("/api/operations/forms/{$this->formB->id}")->assertForbidden();

        $this->assertStringNotContainsString('نموذج ب', $res->getContent());
        $this->assertStringNotContainsString($this->formB->slug, $res->getContent());
    }

    // ── Attachments ──────────────────────────────────────────────────────────

    public function test_an_attachment_is_downloadable_only_by_someone_who_may_read_the_responses(): void
    {
        [$submission, $answer] = $this->respond($this->formB);
        $url = "/api/operations/forms/{$this->formB->id}/submissions/{$submission->id}/answers/{$answer->id}/file";

        $this->getJson($url)->assertUnauthorized();
        $this->actingAs($this->makeUser('student'), 'sanctum')->getJson($url)->assertForbidden();
        $this->actingAs($this->opsA, 'sanctum')->getJson($url)->assertForbidden();

        $ok = $this->actingAs($this->opsB, 'sanctum')->get($url)->assertOk();
        $this->assertStringStartsWith('%PDF-', $ok->streamedContent());
        $this->actingAs($this->makeUser('admin'), 'sanctum')->get($url)->assertOk();
    }

    public function test_an_attachment_url_cannot_be_used_with_a_different_form_or_submission(): void
    {
        [$submissionB, $answerB] = $this->respond($this->formB);
        [$submissionA] = $this->respond($this->formA);
        $admin = $this->makeUser('admin');

        // the right answer, but a form it does not belong to
        $this->actingAs($admin, 'sanctum')->getJson("/api/operations/forms/{$this->formA->id}/submissions/{$submissionB->id}/answers/{$answerB->id}/file")->assertNotFound();
        // the right form, but a submission the answer does not belong to
        $this->actingAs($admin, 'sanctum')->getJson("/api/operations/forms/{$this->formB->id}/submissions/{$submissionA->id}/answers/{$answerB->id}/file")->assertNotFound();
        // opsA owns form A: pairing form A with B's submission/answer must not leak B's file
        $this->actingAs($this->opsA, 'sanctum')->getJson("/api/operations/forms/{$this->formA->id}/submissions/{$submissionB->id}/answers/{$answerB->id}/file")->assertNotFound();
    }
}
